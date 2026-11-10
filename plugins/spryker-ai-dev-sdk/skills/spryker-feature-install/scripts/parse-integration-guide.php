<?php

/**
 * Integration-guide parser: turns one or more Spryker feature integration guides into the rows of
 * a feature-install checklist.
 *
 * A guide is a long Markdown page that mixes mandatory steps, optional scenarios, demo data, API
 * examples and verification notes. Following it from the prose loses steps without notice; a row
 * per step makes every step either decided or visibly open. A step the parser cannot classify
 * becomes a row of kind `unknown` — it is never dropped.
 *
 * Reads both guide styles: the classic one (numbered `1) Install the required modules` steps under
 * `Install feature core` / `Install feature frontend`) and the newer one (unnumbered `##` steps,
 * API endpoints and demo data inline). Accepts the include body (`_includes/.../install-*.md`) or the
 * docs page that wraps it; `{% include x.md %}` resolves against `--docs-root`.
 *
 * Usage:
 *   php parse-integration-guide.php <guide.md>... --out <checklist.json> [--release <tag>]
 *       [--docs-root <spryker-docs checkout>] [--append] [--force]
 *
 *   --release    replaces {{page.release_tag}}; default: release_version from <docs-root>/_config.yml
 *   --append     adds the guides to an existing checklist; a guide already in it is skipped
 *   --force      overwrites an existing checklist (its decisions are lost)
 *
 * Exit codes: 0 parsed, every row classified; 1 parsed with `unknown` rows or an unresolved release
 * placeholder (the checklist is written; the rows need a manual decision); 2 usage or input error.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

const PIG_SCHEMA_VERSION = 1;

/** Commands a local install runs that a deploy recipe usually does not. */
const PIG_FRESH_INSTALL_COMMANDS = [
    'setup:init-db',
    'propel:install',
    'data:import',
    'publish:trigger-events',
    'sync:data',
    'search:setup',
    'acl-entity:synchronize',
];

const PIG_OPTIONAL_HEADING = '/^(add the |enable |optional|integrate |install .*\(optional\))/i';

/**
 * @return list<array{text: string, file: string, line: int}>
 */
function pig_load_lines(string $path, ?string $docsRoot, array &$errors, int $depth = 0): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        $errors[] = "cannot read $path";

        return [];
    }
    $raw = (string)preg_replace('/\A---\n.*?\n---\n/s', '', $raw);
    $lines = [];
    $inFence = false;
    foreach (explode("\n", $raw) as $index => $text) {
        if (preg_match('/^\s*```/', $text)) {
            $inFence = !$inFence;
        }
        if (!$inFence && preg_match('/^\s*\{%\s*include\s+(\S+\.md)\s*%\}/', $text, $m)) {
            $target = $docsRoot !== null ? rtrim($docsRoot, '/') . '/_includes/' . $m[1] : null;
            if ($target !== null && is_file($target) && $depth < 5) {
                array_push($lines, ...pig_load_lines($target, $docsRoot, $errors, $depth + 1));

                continue;
            }
            $errors[] = "unresolved include {$m[1]} at " . basename($path) . ':' . ($index + 1);
        }
        $lines[] = ['text' => $text, 'file' => $path, 'line' => $index + 1];
    }

    return $lines;
}

function pig_release_from_docs(?string $docsRoot): ?string
{
    if ($docsRoot === null || !is_file($docsRoot . '/_config.yml')) {
        return null;
    }
    $config = (string)file_get_contents($docsRoot . '/_config.yml');

    return preg_match('/^release_version:\s*"?([0-9.]+)"?/m', $config, $m) ? $m[1] : null;
}

function pig_substitute_release(string $text, ?string $release): string
{
    if ($release === null) {
        return $text;
    }

    return (string)preg_replace('/\{\{\s*page\.release_tag\s*\}\}/', $release, $text);
}

function pig_clean_heading(string $heading): array
{
    $heading = trim(strip_tags($heading));
    $numbered = (bool)preg_match('/^\d+[.)]\s+/', $heading);
    $heading = trim((string)preg_replace('/^\d+[.)]\s+/', '', $heading));

    return [$heading, $numbered];
}

/**
 * A path-like header right above a code block: `**src/Pyz/...**`, `<summary>config/...</summary>`.
 */
function pig_header_path(string $line): ?string
{
    $line = trim($line);
    if (preg_match('/^\*\*\s*>?\s*(\/?[A-Za-z0-9_.\-\/]+)\s*\*\*:?$/', $line, $m)
        || preg_match('/<summary>\s*(\/?[A-Za-z0-9_.\-\/]+)\s*(<\/summary>)?\s*$/', $line, $m)) {
        return pig_normalize_path($m[1]);
    }

    return null;
}

function pig_normalize_path(string $path): ?string
{
    $path = ltrim(trim($path), '/>');
    $path = (string)preg_replace('#^src/data/#', 'data/', $path);
    if (!str_contains($path, '/') && !preg_match('/\.(json|yml|yaml|xml|php)$/', $path)) {
        return null;
    }

    return $path;
}

/**
 * A code block with no header: derive the file from the namespace and the class it declares, from
 * `$config[...]` assignments (the config files), from `key,text,locale` lines (the glossary), or
 * from deploy-file top-level keys. A guide that names no deploy file means every deploy file the
 * project has: `deploy.*.yml`.
 */
function pig_infer_path(string $code, string $lang): ?string
{
    if ($lang === 'php' && preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $code, $ns)
        && preg_match('/^(?:abstract\s+|final\s+)?(?:class|interface)\s+(\w+)/m', $code, $cl)) {
        return 'src/' . str_replace('\\', '/', $ns[1]) . '/' . $cl[1] . '.php';
    }
    if ($lang === 'php' && preg_match('/^\s*\$config\[/m', $code)) {
        return 'config/Shared/config_default.php';
    }
    if (in_array($lang, ['csv', 'yaml', 'yml', 'text', ''], true) && preg_match('/^[a-z0-9_.\-]+,.+,[a-z]{2}_[A-Z]{2}\s*$/m', $code)) {
        return 'data/import/glossary.csv';
    }
    if (in_array($lang, ['yaml', 'yml'], true) && preg_match('/^(image|regions|groups|services|docker|environment):/m', $code)) {
        return 'deploy.*.yml';
    }

    return null;
}

function pig_classify_path(string $path, string $code): string
{
    $file = basename($path);
    $rules = [
        ['/\.transfer\.xml$/', 'transfer'],
        ['/\.schema\.xml$/', str_contains($code, 'name="event"') ? 'event' : 'schema'],
        ['#(^|/)config/Zed/(oms|StateMachine)/#', 'oms'],
        ['/navigation\.xml$/', 'navigation'],
        ['/glossary\.csv$/', 'glossary'],
        ['#(^|/)data/import/.*\.csv$#', 'data-row'],
        ['#(^|/)data/import/.*\.ya?ml$#', 'data-import'],
        ['#^config/install/#', 'recipe'],
        ['/^deploy[^\/]*\.ya?ml$/', 'deploy'],
        ['/RouterDependencyProvider\.php$/', 'route'],
        ['/ShopApplicationDependencyProvider\.php$/', 'widget'],
        ['/(QueueDependencyProvider|RabbitMqConfig)\.php$/', 'queue'],
        ['/DependencyProvider\.php$/', 'plugin'],
        ['#SearchElasticsearch|/Search/Schema/|\.index\.json$|\.map\.json$#', 'search-index'],
        ['#(Config|Constants)\.php$|^config/Shared/#', 'config'],
        ['#\.(twig|ts|js|scss|css|html)$|^frontend/|(^|/)package\.json$|tsconfig.*\.json$#', 'frontend'],
        ['/\.php$/', 'code'],
    ];
    foreach ($rules as [$pattern, $kind]) {
        if (preg_match($pattern, $path) || preg_match($pattern, $file)) {
            return $kind;
        }
    }

    return 'unknown';
}

const PIG_WIRED_CLASS = '/\bnew\s+(\w+(?:Plugin|Widget|Console|Subscriber))\s*\(|\b(\w+(?:Plugin|Widget|Console|Subscriber))::class/';

/**
 * @return array{plugins: list<string>, names: list<string>} the fully-qualified classes a
 *         DependencyProvider snippet wires, and every short name they go by (class and alias)
 */
function pig_plugins_in(string $code): array
{
    $uses = [];
    if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+?\\\\(\w+))(?:\s+as\s+(\w+))?;/m', $code, $m, PREG_SET_ORDER)) {
        foreach ($m as $use) {
            $uses[($use[3] ?? '') !== '' ? $use[3] : $use[2]] = $use[1];
        }
    }
    $plugins = [];
    $names = [];
    if (preg_match_all('/\bnew\s+(\w+)\s*\(|\b(\w+)::class/', $code, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $short = $hit[1] !== '' ? $hit[1] : $hit[2];
            if (!isset($uses[$short]) && !preg_match(PIG_WIRED_CLASS, 'new ' . $short . '(')) {
                continue;
            }
            $fqcn = $uses[$short] ?? $short;
            $plugins[] = $fqcn;
            $names[] = $short;
            $names[] = fi_short_name($fqcn);
        }
    }

    return ['plugins' => array_values(array_unique($plugins)), 'names' => array_values(array_unique($names))];
}

/**
 * @return array<string, string> package => constraint, from one `composer require` line
 */
function pig_composer_packages(string $command): array
{
    $args = trim((string)preg_replace('/^.*?composer\s+require\s+/', '', $command));
    $args = (string)preg_replace('/(\S+\/\S+?):\s+("[^"]*"|\S+)/', '$1:$2', $args);
    $packages = [];
    foreach (preg_split('/\s+/', $args) ?: [] as $token) {
        if ($token === '' || str_starts_with($token, '-')) {
            continue;
        }
        if (preg_match('/^([a-z0-9_.\-]+\/[a-z0-9_.\-]+)(?::(.*))?$/', $token, $m)) {
            $packages[$m[1]] = trim($m[2] ?? '', '"\'');
        }
    }

    return $packages;
}

function pig_section_tag(array $stack): string
{
    foreach ($stack as $heading) {
        if (preg_match('/demo data/i', $heading['title'])) {
            return 'demo-data';
        }
    }
    $top = $stack[0] ?? null;
    if ($top !== null && !$top['numbered'] && preg_match(PIG_OPTIONAL_HEADING, $top['title'])) {
        return 'optional-scenario:' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($top['title'])), '-');
    }

    return 'required';
}

/**
 * Translations and import configuration are what a feature needs to work; a demo-data section
 * holds sample rows. Any other data row is a project decision the parser cannot make: null.
 */
function pig_data_class(string $kind, string $sectionTag): ?string
{
    return match (true) {
        $sectionTag === 'demo-data' => 'demo-data',
        $kind === 'glossary', $kind === 'data-import' => 'feature-data',
        default => null,
    };
}

function pig_kind_tag(string $kind, string $sectionTag, string $target): string
{
    if ($sectionTag !== 'required') {
        return $sectionTag;
    }

    return match (true) {
        $kind === 'console' && in_array(fi_command_name($target), PIG_FRESH_INSTALL_COMMANDS, true),
        $kind === 'console' && str_starts_with(fi_command_name($target), 'data:import') => 'fresh-install-only',
        default => 'required',
    };
}

/**
 * @return list<array{header: list<string>, rows: list<list<string>>}>
 */
function pig_parse_table(array $tableLines): array
{
    $cells = static fn (string $line): array => array_map(
        static fn (string $cell): string => trim(html_entity_decode($cell, ENT_QUOTES | ENT_HTML5)),
        explode('|', trim(trim($line), '|'))
    );
    $header = array_map('strtoupper', $cells($tableLines[0]));
    $rows = [];
    foreach (array_slice($tableLines, 1) as $line) {
        if (preg_match('/^\s*\|?[\s:\-|]+\|?\s*$/', $line)) {
            continue;
        }
        $rows[] = $cells($line);
    }

    return ['header' => $header, 'rows' => $rows];
}

function pig_link(string $cell): array
{
    return preg_match('/\[([^\]]+)\]\(([^)]+)\)/', $cell, $m) ? [$m[1], $m[2]] : [trim($cell), null];
}

/**
 * Parse one guide into its metadata and rows. Row ids are assigned by the caller.
 *
 * @return array{guide: array<string, mixed>, rows: list<array<string, mixed>>, errors: list<string>}
 */
function pig_parse_guide(string $path, ?string $docsRoot, ?string $release): array
{
    $errors = [];
    $lines = pig_load_lines($path, $docsRoot, $errors);
    $guideName = basename($path);
    $guide = ['file' => $guideName, 'prerequisites' => [], 'modules' => [], 'plugin_tables' => [], 'env' => []];
    $rows = [];
    $stack = [];
    $fence = null;
    $info = null;
    $lastText = '';
    $table = [];
    $tableStart = 0;
    $tablePlugins = [];
    $linkedGuides = [];

    $addRow = static function (array $row) use (&$rows, &$stack, $guideName): void {
        $sectionTag = pig_section_tag($stack);
        $row = ['guide' => $guideName, 'section' => implode(' > ', array_column($stack, 'title'))] + $row;
        $row['tag'] = pig_kind_tag($row['kind'], $sectionTag, (string)$row['target']);
        if (in_array($row['kind'], FI_DATA_KINDS, true)) {
            $row['data_class'] = pig_data_class($row['kind'], $sectionTag);
        }
        $rows[] = $row + ['source' => 'guide', 'reference' => null, 'decision' => null, 'status' => 'open'];
    };

    $flushTable = static function () use (&$table, &$tableStart, &$guide, &$stack, &$tablePlugins): void {
        if (count($table) < 2) {
            $table = [];

            return;
        }
        $parsed = pig_parse_table($table);
        $header = $parsed['header'];
        $section = implode(' > ', array_column($stack, 'title'));
        if (($header[0] ?? '') === 'MODULE' && in_array('EXPECTED DIRECTORY', $header, true)) {
            foreach ($parsed['rows'] as $cells) {
                $guide['modules'][] = ['module' => $cells[0] ?? '', 'directory' => trim($cells[1] ?? '', '` ')];
            }
        } elseif (in_array($header[0] ?? '', ['NAME', 'FEATURE'], true) && in_array('INSTALLATION GUIDE', $header, true)) {
            foreach ($parsed['rows'] as $cells) {
                [$linkText, $url] = pig_link($cells[2] ?? '');
                $guide['prerequisites'][] = ['name' => $cells[0] ?? '', 'version' => $cells[1] ?? '', 'guide' => $linkText, 'url' => $url];
            }
        } elseif (($header[0] ?? '') === 'PLUGIN' && in_array('NAMESPACE', $header, true)) {
            $nsColumn = (int)array_search('NAMESPACE', $header, true);
            foreach ($parsed['rows'] as $cells) {
                $class = trim(trim($cells[0] ?? ''), '`');
                $namespace = trim(trim($cells[$nsColumn] ?? ''), '`\\');
                $fqcn = $namespace !== '' ? $namespace . '\\' . $class : $class;
                $guide['plugin_tables'][] = ['plugin' => $fqcn, 'section' => $section];
                $tablePlugins[$fqcn] = ['line' => $tableStart, 'stack' => $stack];
            }
        }
        $table = [];
    };

    foreach ($lines as $entry) {
        $text = pig_substitute_release($entry['text'], $release);
        $lineNo = $entry['line'];

        if ($fence !== null) {
            if (preg_match('/^\s*```/', $text)) {
                $code = implode("\n", $fence['body']);
                $lang = $fence['lang'];
                $span = [$fence['start'], $lineNo];
                if ($info !== null && $info['verification']) {
                    $info['text'] .= ' [' . ($lang !== '' ? $lang : 'code') . ' block]';
                } elseif (in_array($lang, ['bash', 'shell', 'sh', 'console'], true)) {
                    foreach ($fence['body'] as $offset => $commandLine) {
                        $command = trim((string)preg_replace('/^\$\s*/', '', trim($commandLine)));
                        if ($command === '' || str_starts_with($command, '#')) {
                            continue;
                        }
                        $at = $fence['start'] + 1 + $offset;
                        if (preg_match('/\bcomposer\s+require\b/', $command)) {
                            $addRow(['lines' => [$at, $at], 'kind' => 'composer', 'target' => $command, 'packages' => pig_composer_packages($command)]);
                        } else {
                            $addRow(['lines' => [$at, $at], 'kind' => 'console', 'target' => $command]);
                        }
                    }
                } elseif (in_array($lang, ['json', 'http'], true) || preg_match('/^`?(GET|POST|PATCH|PUT|DELETE)\s/', $fence['header_text'])) {
                    // an API request or response example, not a step
                } else {
                    $targetPath = $fence['header'] ?? pig_infer_path($code, $lang);
                    $kind = $targetPath !== null ? pig_classify_path($targetPath, $code) : 'unknown';
                    $row = ['lines' => $span, 'kind' => $kind, 'target' => $targetPath ?? '(no file named above the block)'];
                    if ($fence['header'] === null && $targetPath !== null) {
                        $row['inferred_target'] = true;
                    }
                    if (in_array($kind, ['plugin', 'route', 'widget', 'queue'], true)) {
                        $wired = pig_plugins_in($code);
                        $row['plugins'] = $wired['plugins'];
                        foreach (array_keys($tablePlugins) as $listed) {
                            if (in_array(fi_short_name($listed), $wired['names'], true)) {
                                unset($tablePlugins[$listed]);
                            }
                        }
                    }
                    if (preg_match_all("/getenv\\(\\s*'([A-Z0-9_]+)'/", $code, $env)) {
                        $row['env'] = array_values(array_unique($env[1]));
                        $guide['env'] = array_values(array_unique(array_merge($guide['env'], $row['env'])));
                    }
                    $addRow($row);
                }
                $fence = null;
                continue;
            }
            $fence['body'][] = $text;
            continue;
        }

        if (preg_match('/^\s*\|/', $text)) {
            if ($table === []) {
                $tableStart = $lineNo;
            }
            $table[] = $text;
            continue;
        }
        if ($table !== []) {
            $flushTable();
        }

        if (preg_match('/^\s*```\s*([a-zA-Z]*)/', $text, $m)) {
            $fence = [
                'lang' => strtolower($m[1]),
                'start' => $lineNo,
                'body' => [],
                'header' => pig_header_path($lastText),
                'header_text' => trim($lastText),
            ];
            continue;
        }
        if (preg_match('/\{%\s*info_block\s+\w+\s+"([^"]*)"\s*%\}/', $text, $m)) {
            $info = ['title' => $m[1], 'verification' => stripos($m[1], 'verification') !== false, 'start' => $lineNo, 'text' => ''];
            continue;
        }
        if (preg_match('/\{%\s*endinfo_block\s*%\}/', $text)) {
            if ($info !== null && $info['verification']) {
                $summary = trim((string)preg_replace('/\s+/', ' ', strip_tags($info['text'])));
                $addRow(['lines' => [$info['start'], $lineNo], 'kind' => 'verification', 'target' => mb_substr($summary, 0, 300)]);
            }
            $info = null;
            continue;
        }
        if ($info === null && preg_match('/^(#{2,4})\s+(.+)$/', $text, $m)) {
            [$title, $numbered] = pig_clean_heading($m[2]);
            $level = strlen($m[1]);
            $stack = array_values(array_filter($stack, static fn (array $h): bool => $h['level'] < $level));
            $stack[] = ['level' => $level, 'title' => $title, 'numbered' => $numbered];
            $lastText = '';
            continue;
        }
        if ($info !== null) {
            $info['text'] .= ' ' . trim($text);
        }
        $inPrerequisites = $stack !== [] && preg_match('/^(prerequisites|features .* depends on|install the required features)/i', end($stack)['title']);
        if (($info === null || !$info['verification']) && !$inPrerequisites
            && preg_match_all('/\[([^\]]+)\]\((\/docs\/[^)#\s]+\.html)(?:#[^)]*)?\)/', $text, $links, PREG_SET_ORDER)) {
            foreach ($links as $link) {
                if (!in_array($link[2], $linkedGuides, true)) {
                    $linkedGuides[] = $link[2];
                    $addRow(['lines' => [$lineNo, $lineNo], 'kind' => 'linked-guide', 'target' => $link[2], 'note' => $link[1]]);
                }
            }
        }
        if (trim($text) !== '') {
            $lastText = $text;
        }
    }
    if ($table !== []) {
        $flushTable();
    }

    foreach ($tablePlugins as $plugin => $origin) {
        $saved = $stack;
        $stack = $origin['stack'];
        $addRow(['lines' => [$origin['line'], $origin['line']], 'kind' => 'plugin', 'target' => $plugin, 'plugins' => [$plugin], 'note' => 'listed in a plugin table; no wiring snippet in the guide']);
        $stack = $saved;
    }

    return ['guide' => $guide, 'rows' => $rows, 'errors' => $errors];
}

/**
 * @return array{schema: int, release: ?string, guides: list<array<string, mixed>>, rows: list<array<string, mixed>>}
 */
function pig_build_checklist(array $guidePaths, ?string $docsRoot, ?string $release, ?array $existing, array &$errors): array
{
    $checklist = $existing ?? ['schema' => PIG_SCHEMA_VERSION, 'release' => $release, 'guides' => [], 'rows' => []];
    $known = array_column($checklist['guides'], 'file');
    $next = count($checklist['rows']) + 1;
    foreach ($guidePaths as $path) {
        if (in_array(basename($path), $known, true)) {
            $errors[] = 'skipped ' . basename($path) . ': already in the checklist';
            continue;
        }
        $parsed = pig_parse_guide($path, $docsRoot, $release);
        array_push($errors, ...$parsed['errors']);
        $checklist['guides'][] = $parsed['guide'];
        foreach ($parsed['rows'] as $row) {
            $checklist['rows'][] = ['id' => sprintf('R%03d', $next++)] + $row;
        }
    }

    return $checklist;
}

/**
 * @return array<string, array<string, int>>
 */
function pig_summary(array $checklist): array
{
    $byKind = [];
    $byTag = [];
    foreach ($checklist['rows'] as $row) {
        $byKind[$row['kind']] = ($byKind[$row['kind']] ?? 0) + 1;
        $byTag[$row['tag']] = ($byTag[$row['tag']] ?? 0) + 1;
    }
    ksort($byKind);
    ksort($byTag);

    return ['kind' => $byKind, 'tag' => $byTag];
}

function pig_main(array $argv): int
{
    $guides = [];
    $options = ['out' => null, 'release' => null, 'docs-root' => null, 'append' => false, 'force' => false];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (in_array($arg, ['--append', '--force'], true)) {
            $options[substr($arg, 2)] = true;
        } elseif (in_array($arg, ['--out', '--release', '--docs-root'], true)) {
            $options[substr($arg, 2)] = $argv[++$i] ?? null;
        } elseif (str_starts_with($arg, '--')) {
            fwrite(STDERR, "unknown option $arg\n");

            return 2;
        } else {
            $guides[] = $arg;
        }
    }
    if ($guides === [] || $options['out'] === null) {
        fwrite(STDERR, "usage: php parse-integration-guide.php <guide.md>... --out <checklist.json> [--release <tag>] [--docs-root <dir>] [--append] [--force]\n");

        return 2;
    }
    $existing = null;
    if (is_file($options['out'])) {
        if ($options['append']) {
            $existing = json_decode((string)file_get_contents($options['out']), true);
            if (!is_array($existing) || !isset($existing['rows'])) {
                fwrite(STDERR, "{$options['out']} is not a checklist\n");

                return 2;
            }
        } elseif (!$options['force']) {
            fwrite(STDERR, "{$options['out']} exists: pass --append to add guides, or --force to overwrite it and lose its decisions\n");

            return 2;
        }
    }
    $release = $options['release'] ?? $existing['release'] ?? pig_release_from_docs($options['docs-root']);
    $errors = [];
    $checklist = pig_build_checklist($guides, $options['docs-root'], $release, $existing, $errors);
    $checklist['release'] = $release;
    if (!is_dir(dirname($options['out']))) {
        mkdir(dirname($options['out']), 0777, true);
    }
    file_put_contents($options['out'], json_encode($checklist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

    $summary = pig_summary($checklist);
    fwrite(STDOUT, sprintf("%d rows from %d guide(s) -> %s\n", count($checklist['rows']), count($checklist['guides']), $options['out']));
    foreach ($summary as $axis => $counts) {
        fwrite(STDOUT, sprintf("  %-5s %s\n", $axis, implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($counts), $counts))));
    }
    foreach ($errors as $error) {
        fwrite(STDOUT, "  note  $error\n");
    }
    $unknown = $summary['kind']['unknown'] ?? 0;
    $placeholder = str_contains((string)file_get_contents($options['out']), 'release_tag');
    if ($release === null || $placeholder) {
        fwrite(STDOUT, "  {{page.release_tag}} is not resolved: pass --release <tag> or --docs-root\n");
    }
    if ($unknown > 0) {
        fwrite(STDOUT, "  $unknown unknown row(s): read the guide at the row's lines and set kind and decision by hand\n");
    }

    return ($unknown > 0 || $release === null || $placeholder) ? 1 : 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(pig_main($argv));
}
