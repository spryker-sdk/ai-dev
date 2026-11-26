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
 *   php $UP/check-plugin-usage.php --baseline   # Phase 0: also keeps the result in plugin-usage-baseline.json
 *   php $UP/check-plugin-usage.php              # later: marks each finding inBaseline, lists the new ones
 *   [--limit N]                                 # findings printed (default 10, 0 = counts only)
 *
 * Exit code 1 when MISSING plugins are found; 0 otherwise (deprecations are warnings).
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/bootstrap.php';

$options = spryker_upgrade_output_options($argv, true);
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
$report = [
    'createdAt' => date('c'),
    'vendorPluginsImported' => count($usages),
    'missing' => $missing,
    'deprecated' => $deprecated,
    'projectPluginsNeedingPorting' => $portingNeeded,
];
$report = spryker_upgrade_compare_with_baseline(
    $stateDir,
    'plugin-usage',
    $report,
    ['missing', 'deprecated', 'projectPluginsNeedingPorting'],
    static fn (string $list, array $entry): string => $list . ' ' . ($entry['plugin'] ?? $entry['projectPlugin'] ?? ''),
    $options['baseline']
) ?? $report;
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$findings = [];
foreach ($report['missing'] as $m) {
    $findings[] = spryker_upgrade_finding('MISSING', $m['plugin'] . ' (used in: ' . implode(', ', $m['usedIn']) . ')', $m);
}
foreach ($report['deprecated'] as $d) {
    $findings[] = spryker_upgrade_finding('DEPRECATED', $d['plugin'] . ' (used in: ' . implode(', ', $d['usedIn']) . ')', $d);
}
foreach ($report['projectPluginsNeedingPorting'] as $p) {
    $findings[] = spryker_upgrade_finding('PORTING', $p['projectPlugin'] . ': ' . $p['reason'], $p);
}
spryker_upgrade_print_summary(
    'check-plugin-usage',
    ['missing' => count($missing), 'deprecated' => count($deprecated), 'porting' => count($portingNeeded)],
    $findings,
    $reportFile,
    $root,
    $options['limit'],
    array_values(array_filter([spryker_upgrade_baseline_note($stateDir, 'plugin-usage', $options['baseline'], $findings !== [])]))
);
exit($missing === [] ? 0 : 1);
