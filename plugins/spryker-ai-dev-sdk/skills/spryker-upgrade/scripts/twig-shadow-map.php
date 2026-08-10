<?php

/**
 * Twig/component shadow-map detector (upgrade use case: project overrides a frontend
 * template/component and the upgrade changed the vendor version of the same file).
 *
 * Covers two shadowing surfaces. The vendor package is resolved by globbing every installed
 * package for the module path under any Spryker namespace (SprykerShop, Spryker, SprykerFeature,
 * SprykerEco), because module code ships from the spryker-shop, spryker, spryker-feature and
 * spryker-eco composer vendors alike:
 *   - Yves themes:        src/Pyz/Yves/<Module>/Theme/<theme>/<rel>
 *                         shadows vendor/<vendor>/<pkg>/src/<Ns>/Yves/<Module>/Theme/default/<rel>
 *   - Zed presentation:   src/Pyz/Zed/<Module>/Presentation/<rel>
 *                         shadows vendor/<vendor>/<pkg>/src/<Ns>/Zed/<Module>/Presentation/<rel>
 *
 * A project-level file fully shadows the vendor file via template resolution — vendor changes
 * never reach the page and can break surrounding code that expects the new markup.
 *
 * Usage:
 *   php $UP/twig-shadow-map.php snapshot   # BEFORE composer update
 *   php $UP/twig-shadow-map.php diff       # AFTER composer update
 *
 * snapshot: maps every shadowing project file to its vendor counterpart, records the vendor
 *           file hash, copies the vendor file into state/vendor-baseline/ (merge base), and
 *           records the full vendor file listing of every shadowed module scope.
 * diff:     reports (exit 1) every shadowed vendor file that CHANGED or was REMOVED, with a
 *           ready three-way merge command — plus, informationally (no exit-code impact),
 *           NEW vendor files that appeared inside a module scope the project overrides.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
$stateDir = spryker_upgrade_state_dir($root);
$mapFile = $stateDir . '/twig-shadow-map.json';
$baselineDir = $stateDir . '/vendor-baseline';
$reportFile = $stateDir . '/twig-conflicts-report.json';

$extensions = ['twig', 'scss', 'ts', 'js', 'css'];

$mode = $argv[1] ?? '';
if (!in_array($mode, ['snapshot', 'diff'], true)) {
    fwrite(STDERR, "Usage: twig-shadow-map.php <snapshot|diff>\n");
    exit(2);
}

/**
 * @return list<string> relative paths under $dir with matching extensions
 */
function listFiles(string $dir, array $extensions): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (in_array($file->getExtension(), $extensions, true)) {
            $files[] = substr($file->getPathname(), strlen($dir) + 1);
        }
    }
    sort($files);

    return $files;
}

/**
 * Shadowing scopes: pairs of (project root that shadows, vendor root being shadowed).
 *
 * @return list<array{module: string, projectRoot: string, vendorRoot: string}>
 */
function collectScopes(string $root): array
{
    $scopes = [];

    // Vendor packages ship module code under several namespaces (SprykerShop, Spryker,
    // SprykerEco, SprykerFeature) and several composer vendors (spryker-shop/*, spryker/*,
    // spryker-eco/*, spryker-feature/*). Rather than assuming one mapping, resolve the vendor
    // root for a module by globbing every installed package for the expected suffix path.
    $namespaces = ['SprykerShop', 'Spryker', 'SprykerFeature', 'SprykerEco'];

    /**
     * @return list<string> matching vendor roots, repo-relative
     */
    $resolveVendorRoots = function (string $suffix) use ($root, $namespaces): array {
        $found = [];
        foreach ($namespaces as $ns) {
            // vendor/<composerVendor>/<package>/src/<Ns>/<Layer>/<Module>/<...>
            foreach (glob(sprintf('%s/vendor/*/*/src/%s/%s', $root, $ns, $suffix)) ?: [] as $dir) {
                if (is_dir($dir)) {
                    $found[] = str_replace($root . '/', '', $dir);
                }
            }
        }

        return array_values(array_unique($found));
    };

    // Yves themes: any project theme dir falls back onto the vendor module's Theme/default.
    $yvesDir = $root . '/src/Pyz/Yves';
    if (is_dir($yvesDir)) {
        foreach (new DirectoryIterator($yvesDir) as $moduleDir) {
            if ($moduleDir->isDot() || !$moduleDir->isDir()) {
                continue;
            }
            $module = $moduleDir->getFilename();
            $projectThemeRoot = $moduleDir->getPathname() . '/Theme';
            if (!is_dir($projectThemeRoot)) {
                continue;
            }
            $vendorRoots = $resolveVendorRoots(sprintf('Yves/%s/Theme/default', $module));
            if ($vendorRoots === []) {
                continue; // project-only module — shadows nothing
            }
            foreach (new DirectoryIterator($projectThemeRoot) as $themeDir) {
                if ($themeDir->isDot() || !$themeDir->isDir()) {
                    continue;
                }
                foreach ($vendorRoots as $vendorRoot) {
                    $scopes[] = [
                        'module' => $module,
                        'projectRoot' => str_replace($root . '/', '', $themeDir->getPathname()),
                        'vendorRoot' => $vendorRoot,
                    ];
                }
            }
        }
    }

    // Zed presentation (Backoffice twig, OMS mail templates, ...)
    $zedDir = $root . '/src/Pyz/Zed';
    if (is_dir($zedDir)) {
        foreach (new DirectoryIterator($zedDir) as $moduleDir) {
            if ($moduleDir->isDot() || !$moduleDir->isDir()) {
                continue;
            }
            $module = $moduleDir->getFilename();
            $projectRoot = $moduleDir->getPathname() . '/Presentation';
            if (!is_dir($projectRoot)) {
                continue;
            }
            foreach ($resolveVendorRoots(sprintf('Zed/%s/Presentation', $module)) as $vendorRoot) {
                $scopes[] = [
                    'module' => $module,
                    'projectRoot' => str_replace($root . '/', '', $projectRoot),
                    'vendorRoot' => $vendorRoot,
                ];
            }
        }
    }

    return $scopes;
}

/**
 * @return array{entries: list<array{project: string, vendor: string, hash: string}>,
 *               scopes: array<string, list<string>>}
 */
function buildShadowMap(string $root, array $extensions): array
{
    $entries = [];
    $scopeListings = [];

    foreach (collectScopes($root) as $scope) {
        // Full vendor listing per scope — needed to spot NEW vendor files later.
        $scopeListings[$scope['vendorRoot']] = listFiles($root . '/' . $scope['vendorRoot'], $extensions);

        foreach (listFiles($root . '/' . $scope['projectRoot'], $extensions) as $relPath) {
            $vendorFile = $scope['vendorRoot'] . '/' . $relPath;
            if (!is_file($root . '/' . $vendorFile)) {
                continue; // project-new file, shadows nothing
            }
            $entries[] = [
                'project' => $scope['projectRoot'] . '/' . $relPath,
                'vendor' => $vendorFile,
                'hash' => md5_file($root . '/' . $vendorFile),
            ];
        }
    }

    usort($entries, fn ($a, $b) => strcmp($a['project'], $b['project']));
    ksort($scopeListings);

    return ['entries' => $entries, 'scopes' => $scopeListings];
}

$current = buildShadowMap($root, $extensions);

if ($mode === 'snapshot') {
    if (is_dir($baselineDir)) {
        exec('rm -rf ' . escapeshellarg($baselineDir));
    }
    foreach ($current['entries'] as $entry) {
        $target = $baselineDir . '/' . $entry['vendor'];
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        copy($root . '/' . $entry['vendor'], $target);
    }
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0775, true);
    }
    file_put_contents($mapFile, json_encode([
        'createdAt' => date('c'),
        'entries' => $current['entries'],
        'scopes' => $current['scopes'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    printf(
        "Snapshot written: %s\n  %d project files shadow a vendor counterpart across %d module scopes\n  vendor baselines copied to %s\n",
        str_replace($root . '/', '', $mapFile),
        count($current['entries']),
        count($current['scopes']),
        str_replace($root . '/', '', $baselineDir)
    );
    exit(0);
}

// diff
if (!is_file($mapFile)) {
    fwrite(STDERR, "No snapshot found at $mapFile — run 'snapshot' before upgrading.\n");
    exit(2);
}

$snapshot = json_decode((string)file_get_contents($mapFile), true);
$conflicts = [];
$info = [];

foreach ($snapshot['entries'] as $entry) {
    if (!is_file($root . '/' . $entry['project'])) {
        continue; // project override was removed meanwhile
    }
    $vendorAbs = $root . '/' . $entry['vendor'];
    $baseline = spryker_upgrade_rel($baselineDir, $root) . '/' . $entry['vendor'];

    if (!is_file($vendorAbs)) {
        $conflicts[] = $entry + [
            'type' => 'VENDOR_FILE_REMOVED',
            'mergeCommand' => null,
            'detail' => 'The vendor template/component this file shadows was removed or renamed. '
                . 'Check the module changelog/migration guide; the project override may now be fully detached.',
        ];
        continue;
    }

    if (md5_file($vendorAbs) !== $entry['hash']) {
        $conflicts[] = $entry + [
            'type' => 'VENDOR_FILE_CHANGED',
            'mergeCommand' => sprintf('git merge-file -p %s %s %s', $entry['project'], $baseline, $entry['vendor']),
            'detail' => 'Vendor changed this file, but the project override shadows it — '
                . 'the change is NOT active in the shop. Port it via three-way merge.',
        ];
    }
}

// NEW vendor files inside scopes the project overrides (informational: often a template was
// split, or a new sub-component must be referenced by the overridden parent).
foreach ($snapshot['scopes'] ?? [] as $vendorRoot => $oldListing) {
    $newListing = listFiles($root . '/' . $vendorRoot, $extensions);
    foreach (array_diff($newListing, $oldListing) as $added) {
        $info[] = [
            'type' => 'NEW_VENDOR_FILE',
            'vendor' => $vendorRoot . '/' . $added,
            'detail' => 'New vendor file appeared in a module scope the project overrides — '
                . 'check whether overridden templates must reference/include it.',
        ];
    }
}

file_put_contents($reportFile, json_encode([
    'createdAt' => date('c'),
    'conflicts' => $conflicts,
    'info' => $info,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

foreach ($info as $i) {
    printf("[%s] %s\n  %s\n\n", $i['type'], $i['vendor'], $i['detail']);
}

if ($conflicts === []) {
    printf("OK: none of the %d shadowed vendor files changed. (%d informational notes)\n", count($snapshot['entries']), count($info));
    exit(0);
}

printf("FOUND %d shadowed vendor file(s) affected by the upgrade:\n\n", count($conflicts));
foreach ($conflicts as $c) {
    printf("[%s]\n  project: %s\n  vendor:  %s\n  %s\n", $c['type'], $c['project'], $c['vendor'], $c['detail']);
    if ($c['mergeCommand']) {
        printf("  merge:   %s\n", $c['mergeCommand']);
    }
    echo "\n";
}
echo "Full report: " . str_replace($root . '/', '', $reportFile) . "\n";
exit(1);
