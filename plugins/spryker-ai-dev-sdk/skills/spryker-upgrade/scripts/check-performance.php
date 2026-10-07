<?php

/**
 * Performance check against the Spryker Yves widget performance guideline (release group SOL-477).
 *
 * Reports, from composer.lock, the project's own classes and config/Shared/config_*.php:
 *   (a) the SOL-477 packages present in the lock, each at or below its minimum version;
 *   (b) whether the navigation cache is enabled (a project ContentNavigationWidgetConfig whose
 *       isNavigationCacheEnabled() returns true) and NAVIGATION_REVALIDATION_TIME_IN_SECONDS is set;
 *   (c) when spryker-shop/shop-ui crossed 1.103.0 between the Phase 0 lock and the current one:
 *       whether a project template of the product-card, product-item or product-list-item molecule
 *       renders ProductGroupWidget, and whether the project uses product groups (a product-group
 *       entry in a data/import manifest with rows, or rows in spy_product_abstract_group_storage in
 *       the storage-search-counts-before.json snapshot).
 *
 * (a) and (b) are recommendations: new capabilities, offered at the new-features gate. (c) is
 * existing behaviour disappearing: shop-ui 1.103.0 removes ProductGroupWidget from the `groups`
 * blocks of those molecules, so product groups that rendered before stop rendering.
 *
 * Usage:
 *   php $UP/check-performance.php [--before-lock <file>]
 *
 * The Phase 0 lock defaults to .spryker-upgrade/state/composer.lock.before. Report:
 * performance-report.json. Exit 0 no behaviour lost, 1 ProductGroupWidget rendering lost (c),
 * 2 usage error or no composer.lock.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/bootstrap.php';

const PF_SOL477_PACKAGES = [
    'spryker/navigation-storage' => '1.12.0',
    'spryker/product-group-storage' => '1.6.0',
    'spryker/product-storage' => '1.49.0',
    'spryker/router' => '1.26.0',
    'spryker/store-storage' => '1.3.0',
    'spryker-shop/catalog-page' => '1.35.0',
    'spryker-shop/cms-block-widget' => '2.4.0',
    'spryker-shop/content-navigation-widget' => '1.6.0',
    'spryker-shop/product-group-widget' => '1.12.0',
    'spryker-shop/product-review-widget' => '1.18.0',
    'spryker-shop/shop-application' => '1.17.0',
    'spryker-shop/shop-ui' => '1.103.0',
];
const PF_SHOP_UI_BREAK = '1.103.0';
const PF_MOLECULES = ['product-card', 'product-item', 'product-list-item'];
const PF_GROUP_STORAGE_TABLE = 'spy_product_abstract_group_storage';
const PF_GUIDE_URL = 'https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/yves-performance-best-practice.html';

/**
 * Installed versions by package name from a composer.lock (packages and packages-dev).
 *
 * @return array<string, string>
 */
function pf_lock_versions(?string $file): array
{
    if ($file === null || !is_file($file)) {
        return [];
    }
    $lock = json_decode((string)file_get_contents($file), true) ?: [];
    $versions = [];
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
        if (isset($package['name'], $package['version'])) {
            $versions[(string)$package['name']] = (string)$package['version'];
        }
    }

    return $versions;
}

/**
 * A comparable x.y.z version, or null for a branch version.
 */
function pf_version(?string $version): ?string
{
    if ($version === null || preg_match('/^v?(\d+(?:\.\d+){0,3})/', $version, $m) !== 1) {
        return null;
    }

    return $m[1];
}

/**
 * (a) Each SOL-477 package with its installed version and status: absent, below, at-or-above, unknown.
 *
 * @param array<string, string> $versions
 *
 * @return list<array{package: string, minimum: string, installed: ?string, status: string}>
 */
function pf_package_rows(array $versions): array
{
    $rows = [];
    foreach (PF_SOL477_PACKAGES as $package => $minimum) {
        $installed = $versions[$package] ?? null;
        $comparable = pf_version($installed);
        $rows[] = [
            'package' => $package,
            'minimum' => $minimum,
            'installed' => $installed,
            'status' => match (true) {
                $installed === null => 'absent',
                $comparable === null => 'unknown',
                version_compare($comparable, $minimum, '>=') => 'at-or-above',
                default => 'below',
            },
        ];
    }

    return $rows;
}

/**
 * Whether a method in a PHP class returns literally `true` and nothing else. Null when the class
 * does not declare the method.
 */
function pf_method_returns_true(string $code, string $method): ?bool
{
    $tokens = array_values(array_filter(
        token_get_all($code),
        static fn($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $name = $tokens[$i + 1] ?? null;
        if (!is_array($name) || strcasecmp($name[1], $method) !== 0) {
            continue;
        }
        $j = $i;
        while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
            $j++;
        }
        if (($tokens[$j] ?? ';') === ';') {
            return false;
        }
        $body = '';
        $depth = 0;
        for (; $j < $count; $j++) {
            $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            $depth += $text === '{' ? 1 : ($text === '}' ? -1 : 0);
            $body .= strtolower($text);
            if ($depth === 0) {
                break;
            }
        }

        return $body === '{returntrue;}';
    }

    return null;
}

/**
 * (b) Navigation cache: the project config override and the revalidation constant per config file.
 *
 * @param list<string> $namespaces
 * @param array<string, string> $versions
 *
 * @return array{packageVersion: ?string, supported: bool, configClass: ?string, enabled: bool,
 *               revalidation: array<string, string>}
 */
function pf_navigation_cache(string $root, array $namespaces, array $versions): array
{
    $packageVersion = $versions['spryker-shop/content-navigation-widget'] ?? null;
    $comparable = pf_version($packageVersion);
    $configClass = null;
    $enabled = false;
    foreach ($namespaces as $namespace) {
        $file = $root . '/src/' . $namespace . '/Yves/ContentNavigationWidget/ContentNavigationWidgetConfig.php';
        if (!is_file($file)) {
            continue;
        }
        $configClass = spryker_upgrade_rel($file, $root);
        if (pf_method_returns_true((string)file_get_contents($file), 'isNavigationCacheEnabled') === true) {
            $enabled = true;

            break;
        }
    }
    $revalidation = [];
    foreach (glob($root . '/config/Shared/config_*.php') ?: [] as $configFile) {
        if (preg_match('/NAVIGATION_REVALIDATION_TIME_IN_SECONDS\]\s*=\s*([^;]+);/', (string)file_get_contents($configFile), $m)) {
            $revalidation[spryker_upgrade_rel($configFile, $root)] = trim($m[1]);
        }
    }

    return [
        'packageVersion' => $packageVersion,
        'supported' => $comparable !== null && version_compare($comparable, '1.6.0', '>='),
        'configClass' => $configClass,
        'enabled' => $enabled,
        'revalidation' => $revalidation,
    ];
}

/**
 * Whether a Twig template renders ProductGroupWidget as a widget.
 */
function pf_renders_product_group_widget(string $twig): bool
{
    return preg_match('/(?:\{%-?\s*widget(?:Global)?\s+|findWidget\(\s*|widgetGlobal\(\s*)[\'"]ProductGroupWidget[\'"]/', $twig) === 1;
}

/**
 * Project templates of the three molecules, any project namespace and module, with whether each
 * renders ProductGroupWidget.
 *
 * @param list<string> $namespaces
 *
 * @return array<string, list<array{file: string, rendersWidget: bool}>>
 */
function pf_molecule_templates(string $root, array $namespaces): array
{
    $templates = array_fill_keys(PF_MOLECULES, []);
    foreach ($namespaces as $namespace) {
        foreach (PF_MOLECULES as $molecule) {
            $pattern = sprintf('%s/src/%s/Yves/*/Theme/*/components/molecules/%s/*.twig', $root, $namespace, $molecule);
            foreach (glob($pattern) ?: [] as $file) {
                $templates[$molecule][] = [
                    'file' => spryker_upgrade_rel($file, $root),
                    'rendersWidget' => pf_renders_product_group_widget((string)file_get_contents($file)),
                ];
            }
        }
    }

    return $templates;
}

/**
 * Whether the project uses product groups, with the evidence: data/import manifests with a
 * product-group entry whose source has rows (or cannot be read), and rows in the group storage table
 * of the Phase 0 storage-search-counts snapshot.
 *
 * @return array{used: bool, evidence: list<string>}
 */
function pf_product_groups_used(string $root, string $stateDir): array
{
    $evidence = [];
    $importDir = $root . '/data/import';
    if (is_dir($importDir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($importDir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!in_array($file->getExtension(), ['yml', 'yaml'], true)) {
                continue;
            }
            $yaml = (string)file_get_contents($file->getPathname());
            if (!preg_match_all('/data_entity:\s*[\'"]?product-group[\'"]?\s*\n\s*source:\s*[\'"]?([^\s\'"]+)/', $yaml, $matches)) {
                continue;
            }
            foreach ($matches[1] as $source) {
                $sourceFile = $root . '/' . ltrim($source, '/');
                $rows = is_file($sourceFile) ? max(0, count(array_filter(explode("\n", (string)file_get_contents($sourceFile)), 'trim')) - 1) : null;
                if ($rows === 0) {
                    continue;
                }
                $evidence[] = sprintf(
                    '%s imports product-group from %s (%s)',
                    spryker_upgrade_rel($file->getPathname(), $root),
                    $source,
                    $rows === null ? 'source file not found' : $rows . ' rows'
                );
            }
        }
    }
    $countsFile = $stateDir . '/storage-search-counts-before.json';
    if (is_file($countsFile)) {
        $counts = json_decode((string)file_get_contents($countsFile), true) ?: [];
        $rows = (int)($counts['tables'][PF_GROUP_STORAGE_TABLE] ?? 0);
        if ($rows > 0) {
            $evidence[] = sprintf('%s has %d rows in storage-search-counts-before.json', PF_GROUP_STORAGE_TABLE, $rows);
        }
    }
    sort($evidence);

    return ['used' => $evidence !== [], 'evidence' => $evidence];
}

/**
 * (c) The ProductGroupWidget check. `crossed` is null when the Phase 0 lock is unavailable.
 *
 * @param array<string, string> $before
 * @param array<string, string> $now
 * @param list<string> $namespaces
 *
 * @return array{shopUiBefore: ?string, shopUiNow: ?string, crossed: ?bool, widgetPackageBefore: ?string,
 *               templates: array<string, list<array{file: string, rendersWidget: bool}>>, rendered: bool,
 *               productGroups: array{used: bool, evidence: list<string>}, finding: bool}
 */
function pf_product_group_widget(string $root, string $stateDir, array $namespaces, array $before, array $now): array
{
    $shopUiBefore = pf_version($before['spryker-shop/shop-ui'] ?? null);
    $shopUiNow = pf_version($now['spryker-shop/shop-ui'] ?? null);
    $crossed = $before === [] ? null : $shopUiBefore !== null && $shopUiNow !== null
        && version_compare($shopUiBefore, PF_SHOP_UI_BREAK, '<') && version_compare($shopUiNow, PF_SHOP_UI_BREAK, '>=');
    $templates = pf_molecule_templates($root, $namespaces);
    $rendered = false;
    foreach ($templates as $files) {
        foreach ($files as $template) {
            $rendered = $rendered || $template['rendersWidget'];
        }
    }
    $groups = pf_product_groups_used($root, $stateDir);
    $widgetPackageBefore = $before['spryker-shop/product-group-widget'] ?? null;

    return [
        'shopUiBefore' => $before['spryker-shop/shop-ui'] ?? null,
        'shopUiNow' => $now['spryker-shop/shop-ui'] ?? null,
        'crossed' => $crossed,
        'widgetPackageBefore' => $widgetPackageBefore,
        'templates' => $templates,
        'rendered' => $rendered,
        'productGroups' => $groups,
        'finding' => $crossed === true && $widgetPackageBefore !== null && $groups['used'] && !$rendered,
    ];
}

/**
 * Recommendation lines for (a) and (b).
 *
 * @param list<array{package: string, minimum: string, installed: ?string, status: string}> $packages
 * @param array{packageVersion: ?string, supported: bool, configClass: ?string, enabled: bool, revalidation: array<string, string>} $navigation
 *
 * @return list<string>
 */
function pf_recommendations(array $packages, array $navigation): array
{
    $recommendations = [];
    foreach ($packages as $row) {
        if ($row['status'] === 'below') {
            $recommendations[] = sprintf('%s %s is below %s (SOL-477 package set)', $row['package'], $row['installed'], $row['minimum']);
        }
    }
    if ($navigation['supported'] && !$navigation['enabled']) {
        $recommendations[] = 'navigation cache not enabled: override ContentNavigationWidgetConfig::isNavigationCacheEnabled() to return true';
    }
    if ($navigation['supported'] && $navigation['revalidation'] === []) {
        $recommendations[] = 'ContentNavigationWidgetConstants::NAVIGATION_REVALIDATION_TIME_IN_SECONDS not set (3600 in config_default.php, 300 for docker dev)';
    }

    return $recommendations;
}

function pf_main(array $argv): int
{
    $arguments = array_slice($argv, 1);
    $beforeLock = null;
    for ($i = 0; $i < count($arguments); $i++) {
        if ($arguments[$i] === '--help') {
            fwrite(STDOUT, "Usage: php check-performance.php [--before-lock <composer.lock of Phase 0>]\n");

            return 0;
        }
        if ($arguments[$i] === '--before-lock' && isset($arguments[$i + 1])) {
            $beforeLock = $arguments[++$i];
        } elseif (str_starts_with($arguments[$i], '--before-lock=')) {
            $beforeLock = substr($arguments[$i], 14);
        } else {
            fwrite(STDERR, "Unknown argument: {$arguments[$i]}\nUsage: php check-performance.php [--before-lock <file>]\n");

            return 2;
        }
    }
    $root = spryker_upgrade_project_root();
    $stateDir = spryker_upgrade_state_dir($root);
    if (!is_file($root . '/composer.lock')) {
        fwrite(STDERR, "composer.lock not found in the project root.\n");

        return 2;
    }
    if ($beforeLock !== null && !is_file($beforeLock)) {
        fwrite(STDERR, "--before-lock file not found: $beforeLock\n");

        return 2;
    }
    $beforeLock ??= is_file($stateDir . '/composer.lock.before') ? $stateDir . '/composer.lock.before' : null;

    $namespaces = spryker_upgrade_project_namespaces($root);
    $now = pf_lock_versions($root . '/composer.lock');
    $before = pf_lock_versions($beforeLock);
    $packages = pf_package_rows($now);
    $navigation = pf_navigation_cache($root, $namespaces, $now);
    $productGroupWidget = pf_product_group_widget($root, $stateDir, $namespaces, $before, $now);
    $recommendations = pf_recommendations($packages, $navigation);
    $reportFile = $stateDir . '/performance-report.json';

    file_put_contents($reportFile, json_encode([
        'createdAt' => date('c'),
        'guide' => PF_GUIDE_URL,
        'beforeLock' => $beforeLock === null ? null : spryker_upgrade_rel($beforeLock, $root),
        'packages' => $packages,
        'navigationCache' => $navigation,
        'productGroupWidget' => $productGroupWidget,
        'recommendations' => $recommendations,
        'findings' => $productGroupWidget['finding'] ? ['PRODUCT_GROUP_WIDGET_REMOVED'] : [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    fwrite(STDOUT, "Yves widget performance (SOL-477) — " . PF_GUIDE_URL . "\n\n");
    foreach ($packages as $row) {
        if ($row['status'] !== 'absent') {
            fwrite(STDOUT, sprintf("  %-40s %-12s min %-9s %s\n", $row['package'], $row['installed'], $row['minimum'], strtoupper($row['status'])));
        }
    }
    fwrite(STDOUT, sprintf(
        "\n  navigation cache: %s; revalidation constant: %s\n",
        !$navigation['supported'] ? 'not supported by the installed content-navigation-widget' : ($navigation['enabled'] ? 'enabled' : 'not enabled'),
        $navigation['revalidation'] === [] ? 'not set' : implode(', ', array_map(static fn(string $f, string $v): string => "$f = $v", array_keys($navigation['revalidation']), $navigation['revalidation']))
    ));
    foreach ($recommendations as $recommendation) {
        fwrite(STDOUT, "  RECOMMENDATION: $recommendation\n");
    }

    fwrite(STDOUT, sprintf(
        "\n  shop-ui %s -> %s: %s\n",
        $productGroupWidget['shopUiBefore'] ?? '-',
        $productGroupWidget['shopUiNow'] ?? '-',
        match ($productGroupWidget['crossed']) {
            null => 'Phase 0 lock not found (composer.lock.before or --before-lock), crossing of ' . PF_SHOP_UI_BREAK . ' not checked',
            true => 'crossed ' . PF_SHOP_UI_BREAK . ' (ProductGroupWidget removed from the product-card, product-item and product-list-item molecules)',
            false => 'did not cross ' . PF_SHOP_UI_BREAK,
        }
    ));
    if ($productGroupWidget['crossed'] === true) {
        fwrite(STDOUT, sprintf(
            "  product groups used: %s; a project molecule template renders ProductGroupWidget: %s\n",
            $productGroupWidget['productGroups']['used'] ? 'yes (' . implode('; ', $productGroupWidget['productGroups']['evidence']) . ')' : 'no evidence',
            $productGroupWidget['rendered'] ? 'yes' : 'no'
        ));
    }
    fwrite(STDOUT, "\nFull report: " . spryker_upgrade_rel($reportFile, $root) . "\n");
    if ($productGroupWidget['finding']) {
        fwrite(STDOUT, "\nFOUND: product groups do not render on product cards and lists after the upgrade. Restore the ProductGroupWidget call in the\n"
            . "`groups` block of the project's product-card, product-item and product-list-item molecule templates.\n");

        return 1;
    }

    return 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(pf_main($argv));
}
