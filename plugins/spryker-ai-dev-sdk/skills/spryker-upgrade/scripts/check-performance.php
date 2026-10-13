<?php

/**
 * Performance fixes of the Spryker performance guidelines, read from a local copy of the
 * spryker-docs repository at upgrade time: the page list, the per-page fix status file, the gate
 * that every page and fix is accounted for, code-pattern candidates, and the ProductGroupWidget
 * damage check.
 *
 * --init reads the guideline index (docs/dg/dev/guidelines/performance-guidelines/performance-guidelines.md
 * inside --docs, default <state>/spryker-docs) and writes performance-fixes.json with one entry per
 * page: every page the index links (in or outside the guideline folder) and every page of the folder
 * and its subfolders the index does not link. A linked page missing from the docs copy is listed with
 * inDocs false; in a git copy, inRepository says whether the repository has it. Re-running keeps
 * the recorded data and adds pages that appeared.
 *
 * Fixes are derived per page by the agent and recorded with --set or --import. A fix ID is
 * <page slug>-<n>; the slug is the page file name without .md. Every fix the project can act on is
 * sized at detection: S a config value or flag, M a package update or a change in a few files, L a
 * code refactor, a schema change or many files; with the files it would touch and the expected gain
 * the page names. S fixes are applied without a decision; every M and L fix takes the developer's
 * decision (apply, defer, skip), recorded with --decide.
 *
 * Default run (Phase 3 and Lane 2): when spryker-shop/shop-ui crossed 1.103.0 between the Phase 0
 * lock and the current one, whether a project template of the product-card, product-item or
 * product-list-item molecule renders ProductGroupWidget, and whether the project uses product groups
 * (a product-group entry in a data/import manifest with rows, or rows in
 * spy_product_abstract_group_storage in the storage-search-counts-before.json snapshot). shop-ui
 * 1.103.0 removes ProductGroupWidget from the `groups` blocks of those molecules, so product groups
 * that rendered before stop rendering.
 *
 * Usage:
 *   php $UP/check-performance.php [--before-lock <file>]      ProductGroupWidget check, performance-report.json
 *   php $UP/check-performance.php --scan-code                 code-pattern candidates, performance-code-scan.json
 *   php $UP/check-performance.php --init [--docs <dir>]       performance-fixes.json from the guideline index
 *   php $UP/check-performance.php --set <ID> --status <status> [--title <t>] [--detected <t>] [--size S|M|L]
 *       [--touches <a,b>] [--gain <t>] [--files <a,b>] [--reason <t>] [--evidence <t>]
 *   php $UP/check-performance.php --no-fix <page> --reason <t> --evidence <t>
 *   php $UP/check-performance.php --import <file.json>        {"<fix ID>": {...}, "<page slug>": {"status": "no-fix", ...}}
 *   php $UP/check-performance.php --decisions                 M and L fixes waiting for the developer's decision
 *   php $UP/check-performance.php --decide <ID> --decision apply|defer|skip [--reason <t>]
 *   php $UP/check-performance.php --backlog                   the deferred fixes, as report table rows
 *   php $UP/check-performance.php --unreachable --evidence <t>  docs not reachable: open pages become not-checked
 *   php $UP/check-performance.php --verify                    every page and fix accounted for
 *
 * The writing modes hold an exclusive lock on performance-fixes.json.lock, so parallel sub-agents can
 * record at the same time.
 *
 * Fix statuses: pending (title, detected, size, touches, gain), applied (files and evidence),
 * not-applicable, not-applied and reverted (reason and evidence), deferred and skipped (set by
 * --decide only, with the developer's reason). Every status but not-applicable needs size, touches
 * and gain. Page statuses: pending, derived (has fixes), no-fix and not-checked (reason and evidence).
 *
 * Exit codes. Default run: 0 no behaviour lost, 1 ProductGroupWidget rendering lost, 2 no
 * composer.lock. --verify: 0 every page and fix accounted for, 1 otherwise or no status file.
 * --init: 0 written, 2 no index page. --set / --no-fix / --import / --decide / --unreachable: 0
 * written, 2 rejected. --decisions / --backlog: 0. Every mode: 2 usage error.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/bootstrap.php';

const PF_SHOP_UI_BREAK = '1.103.0';
const PF_MOLECULES = ['product-card', 'product-item', 'product-list-item'];
const PF_GROUP_STORAGE_TABLE = 'spy_product_abstract_group_storage';
const PF_DOCS_HOST = 'https://docs.spryker.com';
const PF_INDEX_PATH = 'docs/dg/dev/guidelines/performance-guidelines/performance-guidelines.md';
const PF_STATUS_FILE = 'performance-fixes.json';
const PF_FIX_STATUSES = ['pending', 'applied', 'not-applicable', 'not-applied', 'deferred', 'skipped', 'reverted'];
const PF_SIZES = ['S', 'M', 'L'];
const PF_DECISIONS = [
    'apply' => ['pending', 'applied', 'not-applied', 'reverted'],
    'defer' => ['deferred'],
    'skip' => ['skipped'],
];
const PF_DECISION_STATUS = ['apply' => 'pending', 'defer' => 'deferred', 'skip' => 'skipped'];
const PF_PAGE_REASON_STATUSES = ['no-fix', 'not-checked'];
const PF_SCAN_IN_LOOP = [
    'query-in-loop' => '/->(findOne|findOneOrCreate|findPk|find)\(\s*\)/',
    'facade-call-in-loop' => '/[Ff]acade(\(\))?->\w+\(/',
    'entity-save-in-loop' => '/->(save|delete)\(\s*(\$\w+)?\s*\)/',
    'storage-read-in-loop' => '/[Ss]torage\w*(\(\))?->get\(/',
    'http-call-in-loop' => '/[Hh]ttp\w*(\(\))?->(request|send|get|post|put|patch|delete)\(/',
];
const PF_SCAN_ANYWHERE = [
    'query-join' => '/->join(?!With)[A-Z]\w*\(/',
    'storage-key-scan' => '/->(getKeys|keys|scan)\(/',
    'twig-create-template' => '/->createTemplate\(/',
    'sql-function-in-where' => '/->where\(.*\b(DATE|LOWER|UPPER|YEAR|MONTH|DAY|SUBSTRING|TRIM)\(/i',
    'storage-write' => '/[Ss]torage\w*(\(\))?->set\(/',
];
const PF_SCAN_REQUEST_LAYERS = [
    'http-client-in-request-layer' => '/new\s+(\\\\?GuzzleHttp\\\\)?Client\(|GuzzleHttp\\\\Client\b/',
];

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
 * Line numbers inside the `{ … }` body of a foreach, for or while loop.
 *
 * @return array<int, true>
 */
function pf_loop_body_lines(string $code): array
{
    $lines = [];
    $depth = 0;
    $loopDepths = [];
    $inHeader = false;
    $headerParens = 0;
    $awaitBody = false;
    $line = 1;
    foreach (token_get_all($code) as $token) {
        $type = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $line = is_array($token) ? $token[2] : $line;
        if (in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $line += substr_count($text, "\n");

            continue;
        }
        if ($loopDepths !== []) {
            for ($l = $line; $l <= $line + substr_count($text, "\n"); $l++) {
                $lines[$l] = true;
            }
        }
        $line += substr_count($text, "\n");
        if (in_array($type, [T_FOREACH, T_FOR, T_WHILE], true)) {
            $inHeader = true;
            $headerParens = 0;

            continue;
        }
        if ($inHeader) {
            $headerParens += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
            if ($text === ')' && $headerParens === 0) {
                $inHeader = false;
                $awaitBody = true;
            }

            continue;
        }
        if ($awaitBody) {
            $awaitBody = false;
            if ($text === '{') {
                $depth++;
                $loopDepths[] = $depth;

                continue;
            }
        }
        if ($text === '{' || $type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        } elseif ($text === '}') {
            if ($loopDepths !== [] && end($loopDepths) === $depth) {
                array_pop($loopDepths);
            }
            $depth--;
        }
    }

    return $lines;
}

/**
 * Code-pattern candidates in the project's own PHP code, by pattern name: `file:line: code`.
 *
 * @param list<string> $namespaces
 *
 * @return array<string, list<string>>
 */
function pf_scan_code(string $root, array $namespaces): array
{
    $hits = array_fill_keys(array_merge(array_keys(PF_SCAN_IN_LOOP), array_keys(PF_SCAN_ANYWHERE), array_keys(PF_SCAN_REQUEST_LAYERS)), []);
    foreach ($namespaces as $namespace) {
        $dir = $root . '/src/' . $namespace;
        if (!is_dir($dir)) {
            continue;
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        foreach ($files as $file) {
            $code = (string)file_get_contents($file);
            $rel = spryker_upgrade_rel($file, $root);
            $loopLines = pf_loop_body_lines($code);
            $requestLayer = preg_match('#/(Yves|Glue|Client)/#', $rel) === 1;
            foreach (preg_split('/\R/', $code) ?: [] as $index => $text) {
                $lineNumber = $index + 1;
                $patterns = PF_SCAN_ANYWHERE + (isset($loopLines[$lineNumber]) ? PF_SCAN_IN_LOOP : []) + ($requestLayer ? PF_SCAN_REQUEST_LAYERS : []);
                foreach ($patterns as $id => $pattern) {
                    if (preg_match($pattern, $text) === 1) {
                        $hits[$id][] = sprintf('%s:%d: %s', $rel, $lineNumber, trim($text));
                    }
                }
            }
        }
    }

    return $hits;
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
 * The `title:` of a page's front matter.
 */
function pf_front_matter_title(string $markdown): ?string
{
    if (preg_match('/\A---\R(.*?)\R---/s', $markdown, $m) !== 1 || preg_match('/^title:\s*(.+?)\s*$/m', $m[1], $t) !== 1) {
        return null;
    }
    $title = trim($t[1], " \t\"'");

    return $title === '' ? null : $title;
}

/**
 * The repository path of a docs.spryker.com link (`/docs/….html`), or null for any other target.
 */
function pf_link_to_path(string $href): ?string
{
    $href = (string)preg_replace('~^https?://docs\.spryker\.com~', '', trim($href));
    $href = (string)preg_replace('/[#?].*$/', '', $href);
    if (preg_match('~^/?(docs/[^\s]+)\.html$~', $href, $m) !== 1) {
        return null;
    }

    return $m[1] . '.md';
}

/**
 * Markdown links of a page in order of appearance, one per target path.
 *
 * @return list<array{path: string, text: string}>
 */
function pf_page_links(string $markdown): array
{
    $links = [];
    preg_match_all('/\[([^\]]+)\]\(\s*([^)\s]+)[^)]*\)/', $markdown, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $path = pf_link_to_path($match[2]);
        if ($path !== null && !isset($links[$path])) {
            $links[$path] = ['path' => $path, 'text' => trim($match[1])];
        }
    }

    return array_values($links);
}

/**
 * Markdown files under a folder of the docs copy, recursively, as repository paths.
 *
 * @return list<string>
 */
function pf_folder_pages(string $docsDir, string $folder): array
{
    $dir = $docsDir . '/' . $folder;
    if (!is_dir($dir)) {
        return [];
    }
    $pages = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'md') {
            $pages[] = $folder . substr($file->getPathname(), strlen($dir));
        }
    }
    sort($pages);

    return $pages;
}

/**
 * For a git copy of the docs, which of the paths the checked-out commit has (checked out or not).
 * Null when the copy is not a git work tree.
 *
 * @param list<string> $paths
 *
 * @return array<string, bool>|null
 */
function pf_repository_has(string $docsDir, array $paths): ?array
{
    if (!file_exists($docsDir . '/.git')) {
        return null;
    }
    if ($paths === []) {
        return [];
    }
    [$code, $stdout] = spryker_upgrade_git($docsDir, array_merge(['ls-tree', '-r', '--name-only', 'HEAD', '--'], $paths));
    if ($code !== 0) {
        return null;
    }
    $present = array_flip(array_filter(array_map('trim', explode("\n", $stdout))));

    return array_combine($paths, array_map(static fn(string $p): bool => isset($present[$p]), $paths));
}

/**
 * The guideline pages of a docs copy: every page the index links, then every page of the index's
 * folder the index does not link. Null when the index is missing.
 *
 * @return list<array{path: string, title: string, source: string, inDocs: bool, inRepository: ?bool}>|null
 */
function pf_guideline_pages(string $docsDir): ?array
{
    $indexFile = $docsDir . '/' . PF_INDEX_PATH;
    if (!is_file($indexFile)) {
        return null;
    }
    $candidates = [];
    foreach (pf_page_links((string)file_get_contents($indexFile)) as $link) {
        if ($link['path'] !== PF_INDEX_PATH) {
            $candidates[$link['path']] = ['source' => 'index', 'text' => $link['text']];
        }
    }
    foreach (pf_folder_pages($docsDir, dirname(PF_INDEX_PATH)) as $path) {
        if ($path !== PF_INDEX_PATH && !isset($candidates[$path])) {
            $candidates[$path] = ['source' => 'folder', 'text' => ''];
        }
    }
    $absent = array_values(array_filter(array_keys($candidates), static fn(string $p): bool => !is_file($docsDir . '/' . $p)));
    $inRepository = pf_repository_has($docsDir, $absent);
    $pages = [];
    foreach ($candidates as $path => $candidate) {
        $file = $docsDir . '/' . $path;
        $inDocs = is_file($file);
        $title = $inDocs ? pf_front_matter_title((string)file_get_contents($file)) : null;
        $pages[] = [
            'path' => $path,
            'title' => $title ?? ($candidate['text'] !== '' ? $candidate['text'] : basename($path, '.md')),
            'source' => $candidate['source'],
            'inDocs' => $inDocs,
            'inRepository' => $inDocs ? true : ($inRepository === null ? null : ($inRepository[$path] ?? false)),
        ];
    }

    return $pages;
}

/**
 * Page slugs by path: the file name without .md, prefixed with the parent folder when two pages
 * share a file name. Slugs already recorded for a path are kept.
 *
 * @param list<string> $paths
 * @param array<string, string> $recorded slug by path
 *
 * @return array<string, string>
 */
function pf_slugs(array $paths, array $recorded): array
{
    $names = array_count_values(array_map(static fn(string $p): string => basename($p, '.md'), $paths));
    $slugs = [];
    $taken = array_flip($recorded);
    foreach ($paths as $path) {
        if (isset($recorded[$path])) {
            $slugs[$path] = $recorded[$path];

            continue;
        }
        $name = basename($path, '.md');
        $slug = $names[$name] > 1 || isset($taken[$name]) ? basename(dirname($path)) . '-' . $name : $name;
        $slugs[$path] = $slug;
        $taken[$slug] = $path;
    }

    return $slugs;
}

function pf_page_url(string $path): string
{
    return PF_DOCS_HOST . '/' . preg_replace('/\.md$/', '.html', $path);
}

/**
 * The page status that follows from its fixes: derived with fixes, otherwise the recorded no-fix or
 * not-checked, otherwise pending.
 *
 * @param array<string, mixed> $page
 */
function pf_page_status(array $page): string
{
    if (($page['fixes'] ?? []) !== []) {
        return 'derived';
    }
    $status = (string)($page['status'] ?? '');

    return in_array($status, PF_PAGE_REASON_STATUSES, true) ? $status : 'pending';
}

/**
 * --init: the status file for the current page list, keeping every page and fix already recorded.
 *
 * @param list<array{path: string, title: string, source: string, inDocs: bool, inRepository: ?bool}> $pages
 * @param array<string, mixed>|null $existing
 * @param array<string, mixed> $docs
 *
 * @return array<string, mixed>
 */
function pf_init_status(array $pages, ?array $existing, array $docs): array
{
    $previous = [];
    foreach ((array)($existing['pages'] ?? []) as $slug => $page) {
        if (is_array($page) && isset($page['path'])) {
            $previous[(string)$page['path']] = ['slug' => (string)$slug] + $page;
        }
    }
    $paths = array_column($pages, 'path');
    $slugs = pf_slugs(array_values(array_unique(array_merge($paths, array_keys($previous)))), array_map(static fn(array $p): string => $p['slug'], $previous));
    $entries = [];
    foreach ($pages as $page) {
        $old = $previous[$page['path']] ?? [];
        if (($old['status'] ?? '') === 'not-checked') {
            $old = array_merge($old, ['status' => 'pending', 'reason' => '', 'evidence' => '']);
        }
        $entry = [
            'path' => $page['path'],
            'title' => $page['title'],
            'url' => pf_page_url($page['path']),
            'source' => $page['source'],
            'inDocs' => $page['inDocs'],
            'inRepository' => $page['inRepository'],
            'status' => (string)($old['status'] ?? 'pending'),
            'reason' => (string)($old['reason'] ?? ''),
            'evidence' => (string)($old['evidence'] ?? ''),
            'fixes' => array_values((array)($old['fixes'] ?? [])),
        ];
        $entry['status'] = pf_page_status($entry);
        $entries[$slugs[$page['path']]] = $entry;
    }
    foreach ($previous as $path => $old) {
        if (!in_array($path, $paths, true)) {
            $slug = $old['slug'];
            unset($old['slug']);
            $entries[$slug] = array_merge($old, ['inDocs' => false, 'source' => 'recorded']);
        }
    }

    return [
        'createdAt' => $existing['createdAt'] ?? date('c'),
        'guide' => pf_page_url(PF_INDEX_PATH),
        'docs' => $docs,
        'pages' => $entries,
    ];
}

/**
 * What keeps a fix entry from being recordable (pending allowed) or final (pending not allowed).
 *
 * @param array<string, mixed> $fix
 *
 * @return list<string>
 */
function pf_fix_problems(array $fix, bool $final): array
{
    $status = (string)($fix['status'] ?? '');
    $problems = [];
    if (!in_array($status, PF_FIX_STATUSES, true)) {
        return ["unknown status '$status' (" . implode(', ', PF_FIX_STATUSES) . ')'];
    }
    $filled = static fn(string $key): bool => trim((string)($fix[$key] ?? '')) !== '';
    if (!$filled('title')) {
        $problems[] = 'no title (the guideline item)';
    }
    if (!$filled('detected')) {
        $problems[] = 'no detected (what the project has)';
    }
    $size = (string)($fix['size'] ?? '');
    $decision = (string)($fix['decision'] ?? '');
    if ($size !== '' && !in_array($size, PF_SIZES, true)) {
        $problems[] = "unknown size '$size' (S, M or L)";
    }
    if ($status !== 'not-applicable') {
        if ($size === '') {
            $problems[] = 'no size (S, M or L)';
        }
        if (pf_list($fix['touches'] ?? []) === []) {
            $problems[] = 'no touches (the files the fix would touch)';
        }
        if (!$filled('gain')) {
            $problems[] = 'no gain (the expected gain the page names)';
        }
    }
    if ($decision !== '' && !isset(PF_DECISIONS[$decision])) {
        $problems[] = "unknown decision '$decision' (" . implode(', ', array_keys(PF_DECISIONS)) . ')';
    } elseif ($size === 'S' && $decision !== '') {
        $problems[] = 'an S fix takes no developer decision';
    } elseif ($decision !== '' && $status !== 'not-applicable' && !in_array($status, PF_DECISIONS[$decision], true)) {
        $problems[] = "status '$status' does not follow the developer decision '$decision'";
    } elseif (in_array($status, ['deferred', 'skipped'], true) && $decision === '') {
        $problems[] = "$status without the developer decision (--decide)";
    } elseif (in_array($size, ['M', 'L'], true) && $decision === '' && ($final || in_array($status, ['applied', 'reverted'], true))
        && !in_array($status, ['not-applicable', 'not-applied'], true)) {
        $problems[] = "$size fix without the developer decision (--decide)";
    }
    if ($status === 'pending') {
        if ($final) {
            $problems[] = "status 'pending' is not final";
        }

        return $problems;
    }
    if ($status === 'applied' && pf_list($fix['files'] ?? []) === []) {
        $problems[] = 'applied without the files it touched';
    }
    if ($status !== 'applied' && !$filled('reason')) {
        $problems[] = "$status without a reason";
    }
    if (!in_array($status, ['deferred', 'skipped'], true) && !$filled('evidence')) {
        $problems[] = 'no evidence';
    }

    return $problems;
}

/**
 * A comma-separated value or a list, trimmed, without empty entries.
 *
 * @return list<string>
 */
function pf_list(mixed $value): array
{
    $items = is_array($value) ? $value : explode(',', (string)$value);

    return array_values(array_filter(array_map(static fn($f): string => trim((string)$f), $items), static fn(string $f): bool => $f !== ''));
}

/**
 * Every fix entry with the slug of its page.
 *
 * @param array<string, mixed> $data
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function pf_all_fixes(array $data): array
{
    $all = [];
    foreach ((array)$data['pages'] as $slug => $page) {
        foreach ((array)($page['fixes'] ?? []) as $fix) {
            $all[] = [(string)$slug, $fix];
        }
    }

    return $all;
}

/**
 * The slug of a page given by slug or repository path.
 *
 * @param array<string, mixed> $data
 */
function pf_find_page(array $data, string $ref): ?string
{
    if (isset($data['pages'][$ref])) {
        return $ref;
    }
    foreach ((array)$data['pages'] as $slug => $page) {
        if (($page['path'] ?? null) === $ref) {
            return (string)$slug;
        }
    }

    return null;
}

/**
 * Applies fix and page entries after checking every one of them; nothing changes when one is rejected.
 * A key that names a page records the page (status no-fix); any other key is a fix ID.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $inputs
 *
 * @return list<string> rejections
 */
function pf_apply_entries(array &$data, array $inputs): array
{
    $next = $data;
    $rejections = [];
    foreach ($inputs as $key => $input) {
        $key = (string)$key;
        $input = is_array($input) ? $input : [];
        $pageSlug = pf_find_page($next, $key);
        if ($pageSlug !== null) {
            $page = $next['pages'][$pageSlug];
            $reason = trim((string)($input['reason'] ?? ''));
            $evidence = trim((string)($input['evidence'] ?? ''));
            $problems = [];
            if (($input['status'] ?? '') !== 'no-fix') {
                $problems[] = "a page takes status 'no-fix' (fixes are recorded under <slug>-<n>)";
            }
            if (($page['fixes'] ?? []) !== []) {
                $problems[] = 'the page has ' . count($page['fixes']) . ' fixes recorded';
            }
            if (!($page['inDocs'] ?? false) && ($page['inRepository'] ?? null) === true) {
                $problems[] = 'the repository has the page but the docs copy does not: add it to the copy and re-run --init';
            }
            if ($reason === '' || $evidence === '') {
                $problems[] = 'no-fix needs a reason and evidence';
            }
            foreach ($problems as $problem) {
                $rejections[] = "$pageSlug: $problem";
            }
            $next['pages'][$pageSlug] = array_merge($page, ['status' => 'no-fix', 'reason' => $reason, 'evidence' => $evidence]);

            continue;
        }
        if (preg_match('/^(.+)-(\d+)$/', $key, $m) !== 1 || !isset($next['pages'][$m[1]])) {
            $rejections[] = "$key: neither a page slug nor <page slug>-<n> of a page in performance-fixes.json";

            continue;
        }
        $pageSlug = $m[1];
        $page = $next['pages'][$pageSlug];
        if (!($page['inDocs'] ?? false)) {
            $rejections[] = "$key: page $pageSlug is not in the docs copy";

            continue;
        }
        $index = null;
        foreach ($page['fixes'] as $i => $fix) {
            if (($fix['id'] ?? null) === $key) {
                $index = $i;
            }
        }
        $old = $index === null ? [] : $page['fixes'][$index];
        $status = trim((string)($input['status'] ?? ''));
        if (array_key_exists('decision', $input) || in_array($status, ['deferred', 'skipped'], true)) {
            $rejections[] = "$key: the developer's decision and the statuses deferred and skipped are recorded with --decide";
        }
        $kept = static fn(string $field): string => trim((string)($input[$field] ?? '')) !== '' ? trim((string)$input[$field]) : (string)($old[$field] ?? '');
        $touches = pf_list($input['touches'] ?? []);
        $fix = [
            'id' => $key,
            'title' => $kept('title'),
            'detected' => $kept('detected'),
            'size' => strtoupper($kept('size')),
            'touches' => $touches !== [] ? $touches : pf_list($old['touches'] ?? []),
            'gain' => $kept('gain'),
            'decision' => (string)($old['decision'] ?? ''),
            'status' => $status,
            'files' => pf_list($input['files'] ?? []),
            'reason' => trim((string)($input['reason'] ?? '')),
            'evidence' => trim((string)($input['evidence'] ?? '')),
        ];
        foreach (pf_fix_problems($fix, false) as $problem) {
            $rejections[] = "$key: $problem";
        }
        if ($index === null) {
            $page['fixes'][] = $fix;
        } else {
            $page['fixes'][$index] = $fix;
        }
        $page['reason'] = '';
        $page['evidence'] = '';
        $page['status'] = pf_page_status($page);
        $next['pages'][$pageSlug] = $page;
    }
    if ($rejections === []) {
        $data = $next;
    }

    return $rejections;
}

/**
 * --decide: records the developer's decision on an M or L fix. apply keeps it pending for the
 * apply-verify-revert procedure; defer and skip set deferred or skipped with the developer's reason.
 *
 * @param array<string, mixed> $data
 *
 * @return list<string> rejections
 */
function pf_decide(array &$data, string $id, string $decision, string $reason): array
{
    if (!isset(PF_DECISION_STATUS[$decision])) {
        return ["$id: --decision is apply, defer or skip"];
    }
    foreach ((array)$data['pages'] as $slug => $page) {
        foreach ((array)($page['fixes'] ?? []) as $i => $fix) {
            if (($fix['id'] ?? null) !== $id) {
                continue;
            }
            $size = (string)($fix['size'] ?? '');
            $status = (string)($fix['status'] ?? '');
            $problems = [];
            if (!in_array($size, ['M', 'L'], true)) {
                $problems[] = $size === 'S' ? 'an S fix is applied without a decision' : 'no size recorded: --set the size first';
            }
            if (!in_array($status, ['pending', 'deferred', 'skipped'], true)) {
                $problems[] = "status '$status': the decision is taken on a pending, deferred or skipped fix";
            }
            if ($decision !== 'apply' && trim($reason) === '') {
                $problems[] = "$decision needs --reason (the developer's reason)";
            }
            $decided = array_merge($fix, [
                'decision' => $decision,
                'status' => PF_DECISION_STATUS[$decision],
                'files' => [],
                'reason' => $decision === 'apply' ? '' : trim($reason),
                'evidence' => '',
            ]);
            $problems = array_merge($problems, pf_fix_problems($decided, false));
            if ($problems !== []) {
                return array_map(static fn(string $p): string => "$id: $p", array_values(array_unique($problems)));
            }
            $data['pages'][$slug]['fixes'][$i] = $decided;

            return [];
        }
    }

    return ["$id: no such fix in performance-fixes.json"];
}

/**
 * --decisions: the M and L fixes still pending without the developer's decision.
 *
 * @param array<string, mixed> $data
 *
 * @return list<array<string, mixed>>
 */
function pf_open_decisions(array $data): array
{
    $open = [];
    foreach (pf_all_fixes($data) as [, $fix]) {
        if (($fix['status'] ?? '') === 'pending' && in_array($fix['size'] ?? '', ['M', 'L'], true) && ($fix['decision'] ?? '') === '') {
            $open[] = $fix;
        }
    }

    return $open;
}

/**
 * --backlog: the deferred fixes as rows of the report's Performance backlog table.
 *
 * @param array<string, mixed> $data
 *
 * @return list<string>
 */
function pf_backlog_rows(array $data): array
{
    $cell = static fn(string $text): string => str_replace(['|', "\n"], ['\\|', ' '], $text);
    $rows = [];
    foreach (pf_all_fixes($data) as [, $fix]) {
        if (($fix['status'] ?? '') !== 'deferred') {
            continue;
        }
        $rows[] = sprintf(
            '| `%s` | %s | %s | %s | %s | %s |',
            $fix['id'] ?? '',
            $cell((string)($fix['title'] ?? '')),
            $fix['size'] ?? '',
            implode(', ', array_map(static fn(string $f): string => "`$f`", pf_list($fix['touches'] ?? []))),
            $cell((string)($fix['gain'] ?? '')),
            $cell((string)($fix['reason'] ?? ''))
        );
    }

    return $rows;
}

/**
 * --unreachable: every page without fixes and without a recorded no-fix becomes not-checked.
 *
 * @param array<string, mixed>|null $data
 *
 * @return array<string, mixed>
 */
function pf_mark_unreachable(?array $data, string $evidence): array
{
    $data ??= ['createdAt' => date('c'), 'guide' => pf_page_url(PF_INDEX_PATH), 'docs' => [], 'pages' => []];
    $data['docs']['unreachable'] = $evidence;
    foreach ($data['pages'] as $slug => $page) {
        if (pf_page_status($page) === 'pending') {
            $data['pages'][$slug] = array_merge($page, ['status' => 'not-checked', 'reason' => 'docs unreachable', 'evidence' => $evidence]);
        }
    }

    return $data;
}

/**
 * --verify: problems per page and fix, and the counts per page and fix status.
 *
 * @param array<string, mixed> $data
 *
 * @return array{problems: list<string>, pages: array<string, int>, fixes: array<string, int>}
 */
function pf_verify(array $data): array
{
    $problems = [];
    $pageCounts = [];
    $fixCounts = [];
    if ($data['pages'] === [] && trim((string)($data['docs']['unreachable'] ?? '')) === '') {
        $problems[] = 'no pages: run --init against the docs copy, or --unreachable when the docs cannot be fetched';
    }
    foreach ($data['pages'] as $slug => $page) {
        $status = pf_page_status($page);
        $pageCounts[$status] = ($pageCounts[$status] ?? 0) + 1;
        if ($status === 'pending') {
            $problems[] = "$slug: no fix recorded and no no-fix reason";
        } elseif ($status !== 'derived' && (trim((string)($page['reason'] ?? '')) === '' || trim((string)($page['evidence'] ?? '')) === '')) {
            $problems[] = "$slug: $status without a reason and evidence";
        }
        foreach ((array)($page['fixes'] ?? []) as $fix) {
            $fixStatus = (string)($fix['status'] ?? '');
            $fixCounts[$fixStatus] = ($fixCounts[$fixStatus] ?? 0) + 1;
            foreach (pf_fix_problems($fix, true) as $problem) {
                $problems[] = ($fix['id'] ?? $slug . '-?') . ": $problem";
            }
        }
    }
    ksort($pageCounts);
    ksort($fixCounts);

    return ['problems' => $problems, 'pages' => $pageCounts, 'fixes' => $fixCounts];
}

/**
 * @return array<string, mixed>|null
 */
function pf_read_status(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);

    return is_array($data) && is_array($data['pages'] ?? null) ? $data : null;
}

/**
 * @param array<string, mixed> $data
 */
function pf_write_status(string $file, array $data): void
{
    $data['updatedAt'] = date('c');
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}


/**
 * The default run: the ProductGroupWidget check, performance-report.json.
 */
function pf_detect(string $root, string $stateDir, ?string $beforeLock): int
{
    if (!is_file($root . '/composer.lock')) {
        fwrite(STDERR, "composer.lock not found in the project root.\n");

        return 2;
    }
    $beforeLock ??= is_file($stateDir . '/composer.lock.before') ? $stateDir . '/composer.lock.before' : null;
    $namespaces = spryker_upgrade_project_namespaces($root);
    $productGroupWidget = pf_product_group_widget($root, $stateDir, $namespaces, pf_lock_versions($beforeLock), pf_lock_versions($root . '/composer.lock'));
    $reportFile = $stateDir . '/performance-report.json';

    file_put_contents($reportFile, json_encode([
        'createdAt' => date('c'),
        'beforeLock' => $beforeLock === null ? null : spryker_upgrade_rel($beforeLock, $root),
        'productGroupWidget' => $productGroupWidget,
        'findings' => $productGroupWidget['finding'] ? ['PRODUCT_GROUP_WIDGET_REMOVED'] : [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    fwrite(STDOUT, sprintf(
        "ProductGroupWidget — shop-ui %s -> %s: %s\n",
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

/**
 * --init output: the page list, and what to do about pages missing from the docs copy.
 *
 * @param array<string, mixed> $data
 */
function pf_print_pages(array $data, string $docsDir, string $statusFile, string $root): void
{
    $pages = $data['pages'];
    $linked = count(array_filter($pages, static fn(array $p): bool => $p['source'] === 'index'));
    $pending = count(array_filter($pages, static fn(array $p): bool => pf_page_status($p) === 'pending'));
    fwrite(STDOUT, sprintf(
        "%s: %d pages (%d linked from the index, %d only in the guideline folder), %d pending.\n\n",
        spryker_upgrade_rel($statusFile, $root),
        count($pages),
        $linked,
        count($pages) - $linked,
        $pending
    ));
    foreach ($pages as $slug => $page) {
        fwrite(STDOUT, sprintf("  %-55s %-8s %2d fixes  %s\n", $slug, pf_page_status($page), count($page['fixes']), $page['inDocs'] ? $page['path'] : 'NOT IN THE DOCS COPY ' . $page['path']));
    }
    $inRepository = [];
    $notInRepository = [];
    foreach ($pages as $slug => $page) {
        if (!$page['inDocs'] && $page['source'] !== 'recorded') {
            if ($page['inRepository'] === true) {
                $inRepository[] = dirname($page['path']);
            } else {
                $notInRepository[] = [$slug, $page['path'], $page['inRepository']];
            }
        }
    }
    if ($inRepository !== []) {
        fwrite(STDOUT, "\nLinked pages the repository has outside the checked-out folders. Add them, then run --init again:\n"
            . '  git -C ' . escapeshellarg($docsDir) . ' sparse-checkout add ' . implode(' ', array_map('escapeshellarg', array_values(array_unique($inRepository)))) . "\n");
    }
    foreach ($notInRepository as [$slug, $path, $known]) {
        fwrite(STDOUT, $known === false
            ? "\n$slug: the index links $path, which the repository does not have. Record it: --no-fix $slug --reason \"linked page not published\" --evidence \"git ls-tree: no $path\"\n"
            : "\n$slug: $path is not in the docs copy. Fetch https://raw.githubusercontent.com/spryker/spryker-docs/master/$path into the copy and run --init again; on 404 record --no-fix $slug.\n");
    }
}

function pf_usage(): string
{
    return "Usage: php check-performance.php [--before-lock <file>] | --scan-code | --init [--docs <dir>] | --verify\n"
        . "       | --set <ID> --status <status> [--title <t>] [--detected <t>] [--size S|M|L] [--touches <a,b>] [--gain <t>]\n"
        . "         [--files <a,b>] [--reason <t>] [--evidence <t>]\n"
        . "       | --no-fix <page> --reason <t> --evidence <t> | --import <file.json> | --unreachable --evidence <t>\n"
        . "       | --decisions | --decide <ID> --decision apply|defer|skip [--reason <t>] | --backlog\n";
}

function pf_main(array $argv): int
{
    $arguments = array_slice($argv, 1);
    $mode = 'detect';
    $options = [];
    $valued = ['--before-lock', '--docs', '--set', '--status', '--title', '--detected', '--size', '--touches', '--gain', '--files', '--reason', '--evidence', '--import', '--no-fix', '--decide', '--decision'];
    $modes = [
        '--scan-code' => 'scan', '--init' => 'init', '--verify' => 'verify', '--set' => 'set', '--import' => 'import', '--no-fix' => 'no-fix',
        '--unreachable' => 'unreachable', '--decide' => 'decide', '--decisions' => 'decisions', '--backlog' => 'backlog',
    ];
    for ($i = 0; $i < count($arguments); $i++) {
        $argument = $arguments[$i];
        if ($argument === '--help') {
            fwrite(STDOUT, pf_usage());

            return 0;
        }
        $name = str_contains($argument, '=') ? strstr($argument, '=', true) : $argument;
        $isValued = in_array($name, $valued, true);
        if (!$isValued && !isset($modes[$name])) {
            fwrite(STDERR, "Unknown argument: $argument\n" . pf_usage());

            return 2;
        }
        if (isset($modes[$name])) {
            if ($mode !== 'detect') {
                fwrite(STDERR, "One mode per run.\n" . pf_usage());

                return 2;
            }
            $mode = $modes[$name];
        }
        if ($isValued) {
            if (str_contains($argument, '=')) {
                $options[$name] = substr($argument, strlen($name) + 1);
            } elseif (isset($arguments[$i + 1])) {
                $options[$name] = $arguments[++$i];
            } else {
                fwrite(STDERR, "$name needs a value.\n" . pf_usage());

                return 2;
            }
        }
    }
    if (isset($options['--decision']) !== ($mode === 'decide')) {
        fwrite(STDERR, "--decide and --decision go together.\n" . pf_usage());

        return 2;
    }
    $root = spryker_upgrade_project_root();
    $stateDir = spryker_upgrade_state_dir($root);
    $statusFile = $stateDir . '/' . PF_STATUS_FILE;
    if (in_array($mode, ['init', 'set', 'import', 'no-fix', 'unreachable', 'decide'], true)) {
        $lock = fopen($statusFile . '.lock', 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }
    }

    if ($mode === 'detect') {
        if (isset($options['--before-lock']) && !is_file($options['--before-lock'])) {
            fwrite(STDERR, "--before-lock file not found: {$options['--before-lock']}\n");

            return 2;
        }

        return pf_detect($root, $stateDir, $options['--before-lock'] ?? null);
    }
    if ($mode === 'scan') {
        $hits = pf_scan_code($root, spryker_upgrade_project_namespaces($root));
        $scanFile = $stateDir . '/performance-code-scan.json';
        file_put_contents($scanFile, json_encode(['createdAt' => date('c'), 'candidates' => $hits], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        foreach ($hits as $pattern => $lines) {
            fwrite(STDOUT, sprintf("  %-30s %d candidates\n", $pattern, count($lines)));
            foreach (array_slice($lines, 0, 5) as $line) {
                fwrite(STDOUT, "      $line\n");
            }
        }
        fwrite(STDOUT, "\nCandidates, not findings: read each one against the guideline page it concerns. Full list: " . spryker_upgrade_rel($scanFile, $root) . "\n");

        return 0;
    }
    if ($mode === 'init') {
        $docsDir = rtrim($options['--docs'] ?? $stateDir . '/spryker-docs', '/');
        $pages = pf_guideline_pages($docsDir);
        if ($pages === null) {
            fwrite(STDERR, "No guideline index at $docsDir/" . PF_INDEX_PATH . ". Fetch the docs first, or record --unreachable when they cannot be fetched.\n");

            return 2;
        }
        $commit = null;
        if (file_exists($docsDir . '/.git')) {
            [$code, $stdout] = spryker_upgrade_git($docsDir, ['rev-parse', 'HEAD']);
            $commit = $code === 0 ? trim($stdout) : null;
        }
        $data = pf_init_status($pages, pf_read_status($statusFile), ['dir' => spryker_upgrade_rel($docsDir, $root), 'commit' => $commit, 'readAt' => date('c')]);
        pf_write_status($statusFile, $data);
        pf_print_pages($data, $docsDir, $statusFile, $root);

        return 0;
    }
    $data = pf_read_status($statusFile);
    if ($mode === 'verify') {
        if ($data === null) {
            fwrite(STDOUT, 'FAIL: no readable ' . PF_STATUS_FILE . " — run --init, then record every page.\n");

            return 1;
        }
        $result = pf_verify($data);
        $format = static fn(array $counts): string => $counts === [] ? 'none' : implode(', ', array_map(static fn(string $s, int $n): string => "$n $s", array_keys($counts), $counts));
        fwrite(STDOUT, sprintf("%d pages: %s\n%d fixes: %s\n", count($data['pages']), $format($result['pages']), array_sum($result['fixes']), $format($result['fixes'])));
        foreach ($result['problems'] as $problem) {
            fwrite(STDOUT, "  OPEN $problem\n");
        }
        if ($result['problems'] !== []) {
            fwrite(STDOUT, "\nFAIL: " . count($result['problems']) . " open. Phase 7 does not start until this exits 0.\n");

            return 1;
        }
        $unreachable = trim((string)($data['docs']['unreachable'] ?? ''));
        fwrite(STDOUT, $unreachable !== ''
            ? "OK, NOT RUN: the guideline docs were unreachable ($unreachable). The report states that Phase 6.2 did not run for the not-checked pages.\n"
            : "OK: every page has fixes or a no-fix reason; every fix has a final status, its files or reason, and evidence;\n"
                . "every applicable fix a size; every M and L fix the developer's decision.\n");

        return 0;
    }
    if ($mode === 'unreachable') {
        $evidence = trim($options['--evidence'] ?? '');
        if ($evidence === '') {
            fwrite(STDERR, "--unreachable needs --evidence with the failed fetch output.\n");

            return 2;
        }
        pf_write_status($statusFile, pf_mark_unreachable($data, $evidence));
        fwrite(STDOUT, "Recorded: guideline docs unreachable; pages without fixes are not-checked.\n");

        return 0;
    }
    if ($data === null) {
        fwrite(STDERR, 'No readable ' . PF_STATUS_FILE . " — run --init first.\n");

        return 2;
    }
    if ($mode === 'decisions') {
        $open = pf_open_decisions($data);
        fwrite(STDOUT, $open === [] ? "No M or L fix waits for a decision.\n" : count($open) . " M and L fixes wait for the developer's decision (apply now / defer / skip):\n");
        foreach ($open as $fix) {
            fwrite(STDOUT, sprintf(
                "  %s [%s] %s\n      files: %s\n      expected gain: %s\n",
                $fix['id'],
                $fix['size'],
                $fix['title'],
                implode(', ', pf_list($fix['touches'] ?? [])),
                $fix['gain'] ?? ''
            ));
        }

        return 0;
    }
    if ($mode === 'backlog') {
        $rows = pf_backlog_rows($data);
        fwrite(STDOUT, $rows === []
            ? "No deferred fix.\n"
            : "| ID | Fix | Size | Files | Expected gain | Reason |\n|---|---|---|---|---|---|\n" . implode("\n", $rows) . "\n");

        return 0;
    }
    if ($mode === 'decide') {
        $rejections = pf_decide($data, $options['--decide'], $options['--decision'], $options['--reason'] ?? '');
        if ($rejections !== []) {
            fwrite(STDERR, "Nothing written:\n  " . implode("\n  ", $rejections) . "\n");

            return 2;
        }
        pf_write_status($statusFile, $data);
        fwrite(STDOUT, "Recorded {$options['--decide']}: {$options['--decision']}.\n");

        return 0;
    }
    if ($mode === 'set') {
        $inputs = [$options['--set'] => [
            'status' => $options['--status'] ?? '',
            'title' => $options['--title'] ?? '',
            'detected' => $options['--detected'] ?? '',
            'size' => $options['--size'] ?? '',
            'touches' => $options['--touches'] ?? '',
            'gain' => $options['--gain'] ?? '',
            'files' => $options['--files'] ?? '',
            'reason' => $options['--reason'] ?? '',
            'evidence' => $options['--evidence'] ?? '',
        ]];
    } elseif ($mode === 'no-fix') {
        $inputs = [$options['--no-fix'] => ['status' => 'no-fix', 'reason' => $options['--reason'] ?? '', 'evidence' => $options['--evidence'] ?? '']];
    } else {
        $inputs = is_file($options['--import']) ? json_decode((string)file_get_contents($options['--import']), true) : null;
        if (!is_array($inputs) || $inputs === [] || array_is_list($inputs)) {
            fwrite(STDERR, "--import needs a JSON object keyed by fix ID or page slug: {$options['--import']}\n");

            return 2;
        }
    }
    $rejections = pf_apply_entries($data, $inputs);
    if ($rejections !== []) {
        fwrite(STDERR, "Nothing written:\n  " . implode("\n  ", $rejections) . "\n");

        return 2;
    }
    pf_write_status($statusFile, $data);
    fwrite(STDOUT, 'Recorded ' . implode(', ', array_map('strval', array_keys($inputs))) . ".\n");

    return 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(pf_main($argv));
}
