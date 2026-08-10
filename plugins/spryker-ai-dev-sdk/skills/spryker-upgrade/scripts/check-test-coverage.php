<?php

/**
 * Test-coverage-vs-upgrade-risk detector (run BEFORE the upgrade starts).
 *
 * An upgrade is only safe to the extent that its result can be verified. The question is not
 * "what is the project's overall coverage" — it is much narrower and much more useful:
 *
 *     for every place this project customises core, is there a test that would notice if the
 *     upgrade silently unhooked it?
 *
 * That surface is exactly what the other detectors flag later:
 *   - Pyz classes that override vendor methods              (Lane 1 damage)
 *   - vendor plugins wired in Pyz dependency providers      (Lane 3 damage)
 *   - shadowed Twig/presentation files                      (Lane 2 damage)
 * A dead override, a replaced plugin stack and a stale template all fail *quietly* — the shop keeps
 * booting, so only a test tells you the behaviour left with the upgrade.
 *
 * The scan is static (no class loading, no infrastructure), so it runs on host PHP before anything
 * is touched. Coverage is attributed per <Layer>/<Module>, from two signals:
 *   1. a test directory tests/<*Test>/<Layer>/<Module>/ that contains real Cest/Test files —
 *      directories holding only _support/ helpers are NOT coverage, and several usually do;
 *   2. any test file anywhere that references a `Pyz\<Layer>\<Module>\` class.
 *
 * Usage:
 *   php $UP/check-test-coverage.php              # report
 *   php $UP/check-test-coverage.php --all         # include covered + LOW modules
 *   php $UP/check-test-coverage.php --top=20      # limit the printed gap list
 *
 * Exit code 1 when a HIGH-risk module has no test at all — that is the signal to propose writing
 * characterization tests before the upgrade, not to abandon the upgrade.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
require $root . '/vendor/autoload.php';

$stateDir = spryker_upgrade_state_dir($root);
$reportFile = $stateDir . '/test-coverage-report.json';

$showAll = in_array('--all', $argv, true);
$top = 25;
foreach ($argv as $arg) {
    if (preg_match('/^--top=(\d+)$/', $arg, $m)) {
        $top = (int)$m[1];
    }
}

/**
 * Resolve the parent FQCN from source text (same approach as check-typed-members.php: never load
 * the child class — it may be exactly the one that fatals).
 */
function resolveParentFromSource(string $src): ?string
{
    if (!preg_match('/^(?:final\s+|abstract\s+)?class\s+\w+\s+extends\s+([\w\\\\]+)/m', $src, $m)) {
        return null;
    }
    $parent = $m[1];
    if (str_starts_with($parent, '\\')) {
        return ltrim($parent, '\\');
    }
    if (preg_match('/^use\s+([\w\\\\]+)\s+as\s+' . preg_quote($parent, '/') . '\s*;/m', $src, $u)) {
        return $u[1];
    }
    if (preg_match('/^use\s+([\w\\\\]*\\\\' . preg_quote($parent, '/') . ')\s*;/m', $src, $u)) {
        return $u[1];
    }
    if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) {
        return trim($ns[1]) . '\\' . $parent;
    }

    return null;
}

function isVendorClass(string $fqcn): bool
{
    foreach (['Spryker\\', 'SprykerShop\\', 'SprykerEco\\', 'SprykerSdk\\', 'SprykerFeature\\'] as $prefix) {
        if (str_starts_with($fqcn, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * Which risk dimension a project file belongs to. Drives both the weight and the suggested test
 * type, because "override in a Business model" and "override in a controller" are not verified the
 * same way.
 */
function classifyFile(string $relPath, string $fileName): string
{
    if (str_ends_with($fileName, 'DependencyProvider.php')) {
        return 'wiring';
    }
    if (str_ends_with($fileName, 'Config.php')) {
        return 'config';
    }
    if (str_contains($relPath, '/Controller/') || str_ends_with($fileName, 'Controller.php')) {
        return 'controller';
    }
    if (
        str_contains($relPath, '/Business/')
        || str_contains($relPath, '/Model/')
        || str_ends_with($fileName, 'Facade.php')
        || str_ends_with($fileName, 'Service.php')
        || str_ends_with($fileName, 'Client.php')
    ) {
        return 'business';
    }
    if (str_contains($relPath, '/Plugin/')) {
        return 'plugin';
    }

    return 'other';
}

// ---------------------------------------------------------------------------
// 1. Build the risk surface, per <Layer>/<Module>.
// ---------------------------------------------------------------------------

/** @var array<string, array<string, mixed>> $modules */
$modules = [];

function moduleSlot(array &$modules, string $layer, string $module): array
{
    $key = $layer . '/' . $module;
    $modules[$key] ??= [
        'module' => $key,
        'layer' => $layer,
        // logicOverrides is the number that matters: overridden methods carrying business logic.
        // Dependency-provider and Config overrides are counted separately — they are wiring, and a
        // 200-plugin dependency provider is not 200 times the risk of a 1-plugin one.
        'logicOverrides' => 0,
        'wiringOverrides' => 0,
        'business' => 0,
        'controller' => 0,
        'plugin' => 0,
        'wiring' => 0,
        'config' => 0,
        'presentation' => 0,
        'files' => [],
    ];

    return [$key, $modules[$key]];
}

$srcRoot = $root . '/src/Pyz';
if (!is_dir($srcRoot)) {
    fwrite(STDERR, "No src/Pyz directory found at $srcRoot\n");
    exit(2);
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relPath = str_replace($root . '/', '', $file->getPathname());
    $ext = $file->getExtension();
    if (!in_array($ext, ['php', 'twig'], true)) {
        continue;
    }
    // src/Pyz/<Layer>/<Module>/...
    if (!preg_match('#^src/Pyz/([^/]+)/([^/]+)/#', $relPath, $mm)) {
        continue;
    }
    [$layer, $module] = [$mm[1], $mm[2]];

    if ($ext === 'twig') {
        [$key] = moduleSlot($modules, $layer, $module);
        $modules[$key]['presentation']++;

        continue;
    }

    $src = (string)file_get_contents($file->getPathname());
    $kind = classifyFile($relPath, $file->getFilename());

    // Count vendor plugin registrations — the Lane 3 surface.
    $pluginImports = 0;
    if ($kind === 'wiring' && preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $src, $u)) {
        foreach ($u[1] as $fqcn) {
            if (isVendorClass($fqcn) && str_contains($fqcn, 'Plugin')) {
                $pluginImports++;
            }
        }
    }

    // Count methods that actually override a vendor parent — the Lane 1 surface.
    $overridden = [];
    $parentClass = resolveParentFromSource($src);
    if ($parentClass !== null && isVendorClass($parentClass)) {
        try {
            $parentRef = new ReflectionClass($parentClass);
        } catch (Throwable) {
            $parentRef = null;
        }
        if ($parentRef !== null && preg_match_all('/function\s+(\w+)\s*\(/', $src, $fm)) {
            foreach (array_unique($fm[1]) as $method) {
                if ($parentRef->hasMethod($method)) {
                    $overridden[] = $method;
                }
            }
        }
    }

    if ($overridden === [] && $pluginImports === 0) {
        continue;
    }

    [$key] = moduleSlot($modules, $layer, $module);
    if (in_array($kind, ['wiring', 'config'], true)) {
        $modules[$key]['wiringOverrides'] += count($overridden);
    } else {
        $modules[$key]['logicOverrides'] += count($overridden);
    }
    if (!in_array($kind, ['wiring', 'config'], true) && $overridden !== []) {
        $modules[$key][$kind === 'other' ? 'business' : $kind]++;
    }
    if ($pluginImports > 0) {
        $modules[$key]['wiring'] += $pluginImports;
    }
    $modules[$key]['files'][] = [
        'file' => $relPath,
        'kind' => $kind,
        'overrides' => $overridden,
        'wiredVendorPlugins' => $pluginImports,
    ];
}

// ---------------------------------------------------------------------------
// 2. Index the tests.
// ---------------------------------------------------------------------------

$testsRoot = $root . '/tests';
$testedModuleDirs = [];   // "<Layer>/<Module>" => test file count
$referencedModules = [];  // "<Layer>/<Module>" => referencing test files
$supportOnlyDirs = [];
$testFiles = 0;

if (is_dir($testsRoot)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relPath = str_replace($root . '/', '', $file->getPathname());
        if (str_contains($relPath, '/cypress-tests/') || str_contains($relPath, '/vendor/')) {
            continue;
        }
        $name = $file->getFilename();
        $isTest = str_ends_with($name, 'Cest.php') || str_ends_with($name, 'Test.php');

        // tests/<NamespaceRoot>/<Layer>/<Module>/...
        if ($isTest && preg_match('#^tests/[^/]+/([^/]+)/([^/]+)/#', $relPath, $tm)) {
            $testedModuleDirs[$tm[1] . '/' . $tm[2]] = ($testedModuleDirs[$tm[1] . '/' . $tm[2]] ?? 0) + 1;
        }
        if (!$isTest) {
            continue;
        }
        $testFiles++;

        $src = (string)file_get_contents($file->getPathname());
        if (preg_match_all('/Pyz\\\\([A-Za-z]+)\\\\([A-Za-z0-9]+)\\\\/', $src, $rm, PREG_SET_ORDER)) {
            foreach ($rm as $hit) {
                $referencedModules[$hit[1] . '/' . $hit[2]][$relPath] = true;
            }
        }
    }

    // Module test dirs that hold only _support helpers — they look like coverage but are not.
    foreach (glob($testsRoot . '/*/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $rel = str_replace($root . '/', '', $dir);
        if (str_contains($rel, '/cypress-tests/')) {
            continue;
        }
        if (!preg_match('#^tests/[^/]+/([^/]+)/([^/]+)$#', $rel, $dm)) {
            continue;
        }
        $key = $dm[1] . '/' . $dm[2];
        if (!isset($testedModuleDirs[$key])) {
            $supportOnlyDirs[$key] = $rel;
        }
    }
}

// ---------------------------------------------------------------------------
// 3. Score and classify.
// ---------------------------------------------------------------------------

foreach ($modules as $key => &$m) {
    // Logic overrides dominate: they are what fails *silently* when core moves the seam. Wiring is
    // capped, because a dependency provider registering 190 plugins is not 190 units of risk — it
    // is one file whose stack contents need asserting.
    $m['score'] = $m['logicOverrides'] * 5
        + min($m['wiring'], 25)
        + min($m['presentation'], 40)
        + min($m['wiringOverrides'], 10);

    $m['testFiles'] = $testedModuleDirs[$key] ?? 0;
    $m['referencedBy'] = array_keys($referencedModules[$key] ?? []);
    $m['covered'] = $m['testFiles'] > 0 || $m['referencedBy'] !== [];
    $m['supportOnlyDir'] = $supportOnlyDirs[$key] ?? null;

    $m['risk'] = match (true) {
        $m['logicOverrides'] >= 5, $m['presentation'] >= 25 => 'HIGH',
        $m['logicOverrides'] >= 1, $m['presentation'] >= 5, $m['wiring'] >= 30 => 'MEDIUM',
        default => 'LOW',
    };

    $suggest = [];
    if ($m['business'] > 0) {
        $suggest[] = 'Business/Unit test pinning the overridden model output (characterization test)';
    }
    if ($m['controller'] > 0) {
        $suggest[] = 'Functional/Presentation test for the overridden controller action';
    }
    if ($m['plugin'] > 0) {
        $suggest[] = 'Unit test on the project plugin, asserting the interface contract it implements';
    }
    if ($m['wiring'] > 0) {
        $suggest[] = sprintf(
            'Wiring assertion: %d vendor plugin(s) registered here — assert the stack contents so a replaced stack fails loudly',
            $m['wiring']
        );
    }
    if ($m['presentation'] > 0) {
        $suggest[] = sprintf('Acceptance/Cypress step covering the %d overridden template(s)', $m['presentation']);
    }
    $m['suggestedTests'] = $suggest;
}
unset($m);

uasort($modules, static fn(array $a, array $b): int => [$b['covered'] ? 0 : 1, $b['score']] <=> [$a['covered'] ? 0 : 1, $a['score']]);

$totals = [
    'modulesWithCustomisation' => count($modules),
    'coveredModules' => count(array_filter($modules, static fn(array $m): bool => $m['covered'])),
    'logicOverrides' => array_sum(array_column($modules, 'logicOverrides')),
    'logicOverridesInUncoveredModules' => array_sum(array_map(
        static fn(array $m): int => $m['covered'] ? 0 : $m['logicOverrides'],
        $modules
    )),
    'wiringOverrides' => array_sum(array_column($modules, 'wiringOverrides')),
    'wiredVendorPlugins' => array_sum(array_column($modules, 'wiring')),
    'overriddenTemplates' => array_sum(array_column($modules, 'presentation')),
    'testFiles' => $testFiles,
    'supportOnlyTestDirs' => count($supportOnlyDirs),
];

$byRisk = ['HIGH' => [], 'MEDIUM' => [], 'LOW' => []];
foreach ($modules as $m) {
    $byRisk[$m['risk']][] = $m;
}
$uncoveredHigh = array_values(array_filter($byRisk['HIGH'], static fn(array $m): bool => !$m['covered']));
$uncoveredMedium = array_values(array_filter($byRisk['MEDIUM'], static fn(array $m): bool => !$m['covered']));

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}
file_put_contents($reportFile, json_encode([
    'createdAt' => date('c'),
    'totals' => $totals,
    'riskCounts' => array_map('count', $byRisk),
    'uncoveredHigh' => array_column($uncoveredHigh, 'module'),
    'supportOnlyTestDirs' => $supportOnlyDirs,
    'modules' => array_values($modules),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// ---------------------------------------------------------------------------
// 4. Report.
// ---------------------------------------------------------------------------

printf("Upgrade risk surface vs. test coverage\n%s\n", str_repeat('=', 70));
printf(
    "Pyz modules customising core: %d  (covered by some test: %d, uncovered: %d)\n",
    $totals['modulesWithCustomisation'],
    $totals['coveredModules'],
    $totals['modulesWithCustomisation'] - $totals['coveredModules']
);
printf(
    "Business-logic overrides:     %d  (%d of them in modules with NO test)\n",
    $totals['logicOverrides'],
    $totals['logicOverridesInUncoveredModules']
);
printf("Wiring/config overrides:      %d  (dependency providers + Config classes)\n", $totals['wiringOverrides']);
printf("Wired vendor plugins:         %d\n", $totals['wiredVendorPlugins']);
printf("Overridden templates:         %d\n", $totals['overriddenTemplates']);
printf(
    "Test files found:             %d  (module test dirs holding only _support helpers: %d)\n\n",
    $totals['testFiles'],
    $totals['supportOnlyTestDirs']
);
printf(
    "Risk: HIGH %d (uncovered %d) | MEDIUM %d (uncovered %d) | LOW %d\n\n",
    count($byRisk['HIGH']),
    count($uncoveredHigh),
    count($byRisk['MEDIUM']),
    count($uncoveredMedium),
    count($byRisk['LOW'])
);

$print = $showAll
    ? array_values($modules)
    : array_merge($uncoveredHigh, $uncoveredMedium);

if ($print === []) {
    echo "OK: every HIGH/MEDIUM-risk customised module has at least one test.\n";
} else {
    printf(
        "%s (showing %d of %d) — write these BEFORE the upgrade, they are the baseline it is verified against:\n\n",
        $showAll ? 'All customised modules' : 'Coverage gaps, highest risk first',
        min($top, count($print)),
        count($print)
    );
    foreach (array_slice($print, 0, $top) as $m) {
        printf(
            "[%-6s score %3d] %s%s\n",
            $m['risk'],
            $m['score'],
            $m['module'],
            $m['covered'] ? sprintf('  (covered: %d test file(s))', $m['testFiles']) : ''
        );
        printf(
            "  surface: %d logic override(s) in %d business / %d controller / %d plugin file(s), "
            . "%d wired vendor plugin(s), %d overridden template(s)\n",
            $m['logicOverrides'],
            $m['business'],
            $m['controller'],
            $m['plugin'],
            $m['wiring'],
            $m['presentation']
        );
        if ($m['supportOnlyDir'] !== null) {
            printf("  NOTE:    %s exists but contains only _support helpers — not coverage\n", $m['supportOnlyDir']);
        }
        foreach ($m['suggestedTests'] as $s) {
            printf("  suggest: %s\n", $s);
        }
        echo "\n";
    }
}

echo 'Full report: ' . str_replace($root . '/', '', $reportFile) . "\n";

if ($uncoveredHigh !== []) {
    printf(
        "\n%d HIGH-risk module(s) have no test at all. Propose characterization tests for these before\n"
        . "starting the upgrade — without them a dead override or replaced plugin stack fails silently.\n",
        count($uncoveredHigh)
    );
    exit(1);
}

exit(0);
