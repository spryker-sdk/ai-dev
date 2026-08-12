<?php

/**
 * Vendor-class replacement detector (upgrade use case: the project declares a class in a VENDOR's
 * own namespace and force-loads it, so the vendor's implementation is never loaded at all).
 *
 * This is the most dangerous override style in a Spryker project and the only one no other detector
 * can see. `check-dead-overrides.php` looks for Pyz classes extending a vendor parent;
 * `check-typed-members.php` compares a child against its parent. A file that simply *is*
 * `Spryker\Zed\Gui\Communication\Table\AbstractTable` has no parent to compare against — it replaces
 * the class outright, usually as a frozen copy of some older version.
 *
 * Why it matters on upgrade: the copy keeps working, so nothing turns red, but
 *   - every upstream change to that class (bug fixes, security fixes, new API) is silently discarded;
 *   - the frozen copy can break against NEW callers in core that expect the current implementation;
 *   - if a later release moves the class, the replacement becomes an orphan nobody notices.
 *
 * Detection is structural: read the project's own autoload map from composer.json, then flag any
 * class declared under src/ whose namespace belongs to somebody else.
 *
 * Usage:
 *   php $UP/check-vendor-class-replacement.php [<scanDir> ...]     # defaults to src/
 *
 * Exit code 1 when a vendor-owned class is replaced.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
$stateDir = spryker_upgrade_state_dir($root);
$reportFile = $stateDir . '/vendor-class-replacement-report.json';

$dirs = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
if ($dirs === []) {
    $dirs = ['src'];
}

$composer = json_decode((string)file_get_contents($root . '/composer.json'), true);

/**
 * Namespace roots the project legitimately owns, from its own autoload declarations. Anything else
 * declared under src/ is somebody else's namespace.
 *
 * @return list<string>
 */
function projectNamespaceRoots(array $composer): array
{
    $roots = [];
    foreach (['autoload', 'autoload-dev'] as $section) {
        foreach (['psr-4', 'psr-0'] as $standard) {
            foreach (array_keys($composer[$section][$standard] ?? []) as $prefix) {
                $roots[] = rtrim((string)$prefix, '\\');
            }
        }
    }

    return array_values(array_unique(array_filter($roots)));
}

/**
 * Files composer includes EAGERLY (`autoload.files`). A class listed here always wins: it is
 * declared before the autoloader ever gets a chance to load the vendor file.
 *
 * @return array<string, true>
 */
function eagerlyIncludedFiles(array $composer): array
{
    $files = [];
    foreach (['autoload', 'autoload-dev'] as $section) {
        foreach ($composer[$section]['files'] ?? [] as $file) {
            $files[ltrim((string)$file, './')] = true;
        }
    }

    return $files;
}

/**
 * Where the vendor tree would put a given FQCN, according to composer's generated PSR-4 map.
 *
 * @return list<string> candidate absolute file paths
 */
function vendorCandidatePaths(string $fqcn, array $psr4): array
{
    $candidates = [];
    foreach ($psr4 as $prefix => $dirs) {
        $prefix = (string)$prefix;
        if ($prefix === '' || !str_starts_with($fqcn, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix))) . '.php';
        foreach ((array)$dirs as $dir) {
            $candidates[] = rtrim((string)$dir, '/') . '/' . $relative;
        }
    }

    return $candidates;
}

$projectRoots = projectNamespaceRoots($composer);
$eagerFiles = eagerlyIncludedFiles($composer);

$psr4Map = is_file($root . '/vendor/composer/autoload_psr4.php')
    ? (array)require $root . '/vendor/composer/autoload_psr4.php'
    : [];
$classMap = is_file($root . '/vendor/composer/autoload_classmap.php')
    ? (array)require $root . '/vendor/composer/autoload_classmap.php'
    : [];

$problems = [];
$scanned = 0;

foreach ($dirs as $dir) {
    $abs = str_starts_with($dir, '/') ? $dir : $root . '/' . $dir;
    if (!is_dir($abs)) {
        fwrite(STDERR, "Skipping missing directory: $dir\n");
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relPath = str_replace($root . '/', '', $file->getPathname());
        // Generated code legitimately lives in its own namespaces and is regenerated, not maintained.
        if (str_starts_with($relPath, 'src/Generated/') || str_starts_with($relPath, 'src/Orm/')) {
            continue;
        }

        $src = (string)file_get_contents($file->getPathname());
        if (!preg_match('/^(?:final\s+|abstract\s+)?(class|interface|trait|enum)\s+(\w+)/m', $src, $cm)) {
            continue;
        }
        $scanned++;

        $namespace = preg_match('/^namespace\s+([^;{]+)[;{]/m', $src, $ns) ? trim($ns[1]) : '';
        $shortName = $cm[2];
        $fqcn = $namespace === '' ? $shortName : $namespace . '\\' . $shortName;
        $isEager = isset($eagerFiles[$relPath]);

        // A class copied WITHOUT its namespace replaces nothing — it lands in the global namespace.
        if ($namespace === '') {
            $problems[] = [
                'kind' => 'GLOBAL_NAMESPACE_COPY',
                'class' => '\\' . $shortName,
                'file' => $relPath,
                'lines' => substr_count($src, "\n") + 1,
                'eagerlyIncluded' => $isEager,
                'vendorFile' => null,
                'detail' => 'Declared in the GLOBAL namespace, so it overrides nothing — the vendor class '
                    . 'of the same short name is still the one in use. Either it is dead code that is '
                    . 'nevertheless parsed on every request, or something references it as \\' . $shortName . '.',
            ];

            continue;
        }

        $nsRoot = explode('\\', $namespace)[0];
        if (in_array($nsRoot, $projectRoots, true) || in_array($namespace, $projectRoots, true)) {
            continue; // project's own namespace — normal customisation, other detectors cover it
        }
        // A project root may be deeper than one segment (e.g. "Custom\CodeSniffer").
        foreach ($projectRoots as $projectRoot) {
            if (str_starts_with($namespace . '\\', $projectRoot . '\\')) {
                continue 3;
            }
        }

        $vendorFile = null;
        foreach (vendorCandidatePaths($fqcn, $psr4Map) as $candidate) {
            if (is_file($candidate)) {
                $vendorFile = str_replace($root . '/', '', $candidate);
                break;
            }
        }
        if ($vendorFile === null && isset($classMap[$fqcn])) {
            $mapped = str_replace($root . '/', '', (string)$classMap[$fqcn]);
            if ($mapped !== $relPath) {
                $vendorFile = $mapped;
            }
        }

        if ($vendorFile !== null) {
            $vendorLines = substr_count((string)file_get_contents($root . '/' . $vendorFile), "\n") + 1;
            $problems[] = [
                'kind' => 'VENDOR_CLASS_REPLACED',
                'class' => $fqcn,
                'file' => $relPath,
                'lines' => substr_count($src, "\n") + 1,
                'eagerlyIncluded' => $isEager,
                'vendorFile' => $vendorFile,
                'vendorLines' => $vendorLines,
                'diffCommand' => sprintf('diff -u %s %s', $vendorFile, $relPath),
                'detail' => $isEager
                    ? 'Force-loaded via composer autoload.files, so THIS file wins and the vendor '
                        . 'implementation is never loaded. Every upstream change to the class — including '
                        . 'anything the target release ships — is silently discarded.'
                    : 'Both this file and the vendor file declare the class; which one wins depends on '
                        . 'autoload order, which makes behaviour dependent on how the autoloader was dumped.',
            ];

            continue;
        }

        $problems[] = [
            'kind' => 'VENDOR_NAMESPACE_ADDITION',
            'class' => $fqcn,
            'file' => $relPath,
            'lines' => substr_count($src, "\n") + 1,
            'eagerlyIncluded' => $isEager,
            'vendorFile' => null,
            'detail' => 'Declared inside a namespace the project does not own, but no vendor file '
                . 'currently provides this class. It works today; the day upstream adds a class of the '
                . 'same name, the two collide.',
        ];
    }
}

usort($problems, static function (array $a, array $b): int {
    $rank = ['VENDOR_CLASS_REPLACED' => 0, 'VENDOR_NAMESPACE_ADDITION' => 1, 'GLOBAL_NAMESPACE_COPY' => 2];

    return [$rank[$a['kind']], -$a['lines']] <=> [$rank[$b['kind']], -$b['lines']];
});

file_put_contents($reportFile, json_encode([
    'createdAt' => date('c'),
    'scannedDirs' => $dirs,
    'projectNamespaceRoots' => $projectRoots,
    'classesScanned' => $scanned,
    'problems' => $problems,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf(
    "Scanned %d class declaration(s) across: %s\nProject-owned namespace roots: %s\n\n",
    $scanned,
    implode(', ', $dirs),
    implode(', ', $projectRoots)
);

if ($problems === []) {
    echo "OK: no class is declared in a namespace the project does not own.\n";
    exit(0);
}

$replaced = array_filter($problems, static fn(array $p): bool => $p['kind'] === 'VENDOR_CLASS_REPLACED');
printf("FOUND %d class(es) declared outside the project's own namespaces:\n\n", count($problems));
foreach ($problems as $p) {
    printf("[%s] %s\n  file:   %s (%d lines%s)\n", $p['kind'], $p['class'], $p['file'], $p['lines'], $p['eagerlyIncluded'] ? ', force-loaded via autoload.files' : '');
    if ($p['vendorFile'] !== null) {
        printf("  vendor: %s (%d lines)\n  diff:   %s\n", $p['vendorFile'], $p['vendorLines'], $p['diffCommand']);
    }
    printf("  %s\n\n", $p['detail']);
}

echo 'Full report: ' . spryker_upgrade_rel($reportFile, $root) . "\n";

if ($replaced !== []) {
    printf(
        "\n%d vendor-owned class(es) are replaced outright. Each needs a decision BEFORE the upgrade:\n"
        . "  - diff the copy against the CURRENT vendor file to see what the project actually changed;\n"
        . "  - re-express that delta as a subclass or a plugin if an extension point exists;\n"
        . "  - if none exists, re-copy from the target release's version and re-apply the delta, and\n"
        . "    record it as upgrade debt with an extension-point request to the vendor.\n",
        count($replaced)
    );
    exit(1);
}

exit(0);
