<?php

/**
 * Plugin-stack detector (upgrade use case: a plugin stack the project wired/extended was
 * replaced by another plugin stack in a new module version).
 *
 * Scans every Pyz dependency provider for imported vendor plugin classes and reports:
 *   - MISSING:    plugin class no longer exists after the upgrade (stack was replaced/removed)
 *   - DEPRECATED: plugin class is marked @deprecated (the deprecation text usually names
 *                 the replacement — act before it becomes MISSING)
 *
 * Also lists project-owned plugins that implement a deprecated or removed vendor interface,
 * since those must be ported by hand, not just rewired.
 *
 * Usage:
 *   php $UP/check-plugin-usage.php        # run any time (before AND after upgrade)
 *
 * Exit code 1 when MISSING plugins are found; 0 otherwise (deprecations are warnings).
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
require $root . '/vendor/autoload.php';

$stateDir = spryker_upgrade_state_dir($root);
$reportFile = $stateDir . '/plugin-usage-report.json';

function isVendorClass(string $fqcn): bool
{
    foreach (['Spryker\\', 'SprykerShop\\', 'SprykerEco\\', 'SprykerSdk\\'] as $prefix) {
        if (str_starts_with($fqcn, $prefix)) {
            return true;
        }
    }

    return false;
}

// 1. Collect vendor plugin imports from all Pyz dependency providers.
$usages = []; // fqcn => list of project files using it
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src/Pyz', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!str_ends_with($file->getFilename(), 'DependencyProvider.php')) {
        continue;
    }
    $code = (string)file_get_contents($file->getPathname());
    if (!preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $code, $m)) {
        continue;
    }
    foreach ($m[1] as $fqcn) {
        if (!isVendorClass($fqcn) || !str_contains($fqcn, 'Plugin')) {
            continue;
        }
        $usages[$fqcn][] = str_replace($root . '/', '', $file->getPathname());
    }
}

// 2. Classify each imported vendor plugin.
$missing = [];
$deprecated = [];
foreach ($usages as $fqcn => $files) {
    $exists = false;
    try {
        $exists = class_exists($fqcn) || interface_exists($fqcn);
    } catch (Throwable) {
        // class file exists but no longer loads — treat as missing
    }

    if (!$exists) {
        $missing[] = ['plugin' => $fqcn, 'usedIn' => array_values(array_unique($files))];
        continue;
    }

    $doc = (new ReflectionClass($fqcn))->getDocComment() ?: '';
    if (preg_match('/@deprecated\s*(.*)$/mi', $doc, $dm)) {
        $note = trim($dm[1]);
        // pull continuation lines of the deprecation note out of the docblock
        if (preg_match('/@deprecated\s*((?:.|\n)*?)(?:\n\s*\*\s*@|\n\s*\*\/)/', $doc, $dm2)) {
            $note = trim((string)preg_replace('/\n\s*\*\s?/', ' ', $dm2[1]));
        }
        $deprecated[] = [
            'plugin' => $fqcn,
            'note' => $note !== '' ? $note : '(no deprecation note)',
            'usedIn' => array_values(array_unique($files)),
        ];
    }
}

// 3. Project-owned plugins built on vendor plugin interfaces/base classes that are
//    deprecated or gone: these need PORTING, not rewiring.
$portingNeeded = [];
$pluginFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src/Pyz', FilesystemIterator::SKIP_DOTS)
);
foreach ($pluginFiles as $file) {
    if ($file->getExtension() !== 'php' || !str_contains($file->getPathname(), 'Plugin')) {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($root . '/src/Pyz/') , -4);
    $fqcn = 'Pyz\\' . str_replace('/', '\\', $relative);
    try {
        if (!class_exists($fqcn)) {
            continue;
        }
        $ref = new ReflectionClass($fqcn);
    } catch (Throwable $e) {
        $portingNeeded[] = [
            'projectPlugin' => $fqcn,
            'reason' => 'Class no longer loads — its vendor base class/interface was likely removed: ' . $e->getMessage(),
        ];
        continue;
    }

    foreach (array_merge([$ref->getParentClass() ?: null], array_map(
        fn ($i) => new ReflectionClass($i),
        $ref->getInterfaceNames()
    )) as $ancestor) {
        if ($ancestor === null || !isVendorClass($ancestor->getName())) {
            continue;
        }
        $doc = $ancestor->getDocComment() ?: '';
        if (str_contains($doc, '@deprecated')) {
            $portingNeeded[] = [
                'projectPlugin' => $fqcn,
                'reason' => sprintf('Implements/extends deprecated vendor type %s', $ancestor->getName()),
            ];
            break;
        }
    }
}

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}
file_put_contents($reportFile, json_encode([
    'createdAt' => date('c'),
    'vendorPluginsImported' => count($usages),
    'missing' => $missing,
    'deprecated' => $deprecated,
    'projectPluginsNeedingPorting' => $portingNeeded,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf("Scanned dependency providers: %d distinct vendor plugins imported.\n\n", count($usages));

if ($missing !== []) {
    printf("MISSING (%d) — plugin class gone, stack likely replaced:\n", count($missing));
    foreach ($missing as $m) {
        printf("  %s\n    used in: %s\n", $m['plugin'], implode(', ', $m['usedIn']));
    }
    echo "\n";
}
if ($deprecated !== []) {
    printf("DEPRECATED (%d) — replacement usually named in the note:\n", count($deprecated));
    foreach ($deprecated as $d) {
        printf("  %s\n    note:    %s\n    used in: %s\n", $d['plugin'], $d['note'], implode(', ', $d['usedIn']));
    }
    echo "\n";
}
if ($portingNeeded !== []) {
    printf("PROJECT PLUGINS NEEDING PORTING (%d) — built on deprecated/removed vendor types:\n", count($portingNeeded));
    foreach ($portingNeeded as $p) {
        printf("  %s\n    %s\n", $p['projectPlugin'], $p['reason']);
    }
    echo "\n";
}
if ($missing === [] && $deprecated === [] && $portingNeeded === []) {
    echo "OK: no missing or deprecated vendor plugins in use.\n";
}

echo "Full report: " . str_replace($root . '/', '', $reportFile) . "\n";
exit($missing === [] ? 0 : 1);
