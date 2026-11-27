<?php

/**
 * The post-change command chain of a Spryker project: which console commands a set of changed files
 * needs, in which order, and running them.
 *
 * Usage (from the project root):
 *   php refresh.php plan [<file>...] [--base <ref>] [--store <STORE>]... [--router <app>[,<app>]]
 *                        [--symptom yves-twig-stale|zed-twig-stale]... [--deploy-file <file>] [--print-only]
 *   php refresh.php run [--from <step>]
 *   php refresh.php table
 *
 * plan: the changed files come from the arguments, or from git (the working tree and untracked files
 *   against HEAD, or against --base). An argument is a path, or <status>:<path> with status A, M or
 *   D, or R:<old>-><new>. A bare path is A when the base commit does not have it, D when it is gone,
 *   else M; a bare path that is neither on disk nor in the base commit is listed as "not found" and
 *   gets no command. Prints the commands in the order of the mapping groups, each with the files and rows that
 *   caused it; "manual" items the skill never runs; "needs a decision" items that a flag resolves.
 *   Writes .ai-dev/refresher/plan.json (--print-only: writes nothing).
 * run: runs plan.json step by step through docker/sdk (inside `script` for a pseudo-terminal), one
 *   log file per step under .ai-dev/refresher/logs/, one line per step; stops at the first non-zero
 *   exit and prints the last 20 lines of its log. Each step is rebuilt from the mapping by its key:
 *   a command the mapping does not hold does not run. At its end it prints the decisions the plan
 *   left open and the manual items.
 * table: prints the mapping for a reader.
 *
 * Exit codes. plan: 0 written, 2 usage error or no file. run: 0 every step exited 0, 1 a step failed,
 * 2 no plan or a step outside the mapping. table: 0.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../spryker-upgrade/scripts/run-tools.php';
require_once __DIR__ . '/../../spryker-runtime/scripts/deploy-file.php';

const RF_STATE = '.ai-dev/refresher';
const RF_TAIL_LINES = 20;
const RF_GROUPS = [
    1 => 'cache removes',
    2 => 'codegen',
    3 => 'autoload',
    4 => 'IDE/locator stubs',
    5 => 'cache builds / warmups',
    6 => 'class resolver build',
    7 => 'data imports',
    8 => 'frontend builds',
    9 => 'stale-analysis cache clear',
];

/**
 * Every command the skill runs, in run order: group, then the order in this list. `call` is the
 * docker/sdk argument list; `{app}`, `{entity}` and `{store}` are filled from the step's parameters.
 */
const RF_COMMANDS = [
    'cache:empty-all' => ['group' => 1, 'call' => ['console', 'cache:empty-all']],
    'navigation:cache:remove' => ['group' => 1, 'call' => ['console', 'navigation:cache:remove']],
    'rest-api:remove-validation-cache' => ['group' => 1, 'call' => ['console', 'rest-api:remove-validation-cache']],
    'search:source-map:remove' => ['group' => 1, 'call' => ['console', 'search:source-map:remove']],
    'yves-twig-cache:remove' => ['group' => 1, 'call' => ['cli', 'rm -rf src/Generated/Yves/Twig/codeBucket']],
    'zed-twig-path-cache:remove' => ['group' => 1, 'call' => ['cli', 'rm -f src/Generated/Zed/Twig/codeBucket/.pathCache']],
    'transfer:generate' => ['group' => 2, 'call' => ['console', 'transfer:generate']],
    'propel:install' => ['group' => 2, 'call' => ['console', 'propel:install']],
    'transfer:entity:generate' => ['group' => 2, 'call' => ['console', 'transfer:entity:generate']],
    'glue:api:generate' => ['group' => 2, 'call' => ['cli', '{glue} api:generate'], 'param' => 'app'],
    'composer:dump-autoload' => ['group' => 3, 'call' => ['cli', 'composer', 'dump-autoload']],
    'composer:dumpautoload-apcu' => ['group' => 3, 'call' => ['cli', 'composer', 'dumpautoload', '--apcu']],
    'dev:ide-auto-completion:generate' => ['group' => 4, 'call' => ['console', 'dev:ide-auto-completion:generate']],
    'twig:cache:warmer' => ['group' => 5, 'call' => ['console', 'twig:cache:warmer']],
    'navigation:build-cache' => ['group' => 5, 'call' => ['console', 'navigation:build-cache']],
    'rest-api:build-request-validation-cache' => ['group' => 5, 'call' => ['console', 'rest-api:build-request-validation-cache']],
    'search:setup:source-map' => ['group' => 5, 'call' => ['console', 'search:setup:source-map']],
    'search:setup:sources' => ['group' => 5, 'call' => ['console', 'search:setup:sources']],
    'oms:process-cache:warm-up' => ['group' => 5, 'call' => ['console', 'oms:process-cache:warm-up']],
    'translator:generate-cache' => ['group' => 5, 'call' => ['console', 'translator:generate-cache'], 'verify' => 'translator:generate-cache'],
    'router:cache:warm-up' => ['group' => 5, 'call' => ['console', '{router}'], 'param' => 'router'],
    'glue:cache:clear' => ['group' => 5, 'call' => ['cli', '{glue} cache:clear'], 'param' => 'app'],
    'glue:cache:warmup' => ['group' => 5, 'call' => ['cli', '{glue} cache:warmup'], 'param' => 'app'],
    'cache:class-resolver:build' => ['group' => 6, 'call' => ['console', 'cache:class-resolver:build']],
    'data:import:glossary' => ['group' => 7, 'call' => ['console', 'data:import:glossary']],
    'data:import' => ['group' => 7, 'call' => ['console', 'data:import:{entity}'], 'param' => 'entity', 'verify' => 'data:import:{entity}'],
    'queue:drain' => ['group' => 7, 'call' => ['cli', 'APPLICATION_STORE={store} vendor/bin/console queue:worker:start --stop-when-empty'], 'param' => 'store'],
    'frontend:project:install-dependencies' => ['group' => 8, 'call' => ['console', 'frontend:project:install-dependencies']],
    'frontend:yves:build' => ['group' => 8, 'call' => ['console', 'frontend:yves:build'], 'nodeModules' => true],
    'frontend:zed:build' => ['group' => 8, 'call' => ['console', 'frontend:zed:build']],
    'frontend:mp:build' => ['group' => 8, 'call' => ['console', 'frontend:mp:build']],
    'phpstan:clear-result-cache' => ['group' => 9, 'call' => ['cli', 'vendor/bin/phpstan clear-result-cache']],
];

/**
 * The Glue applications the skill knows: public/<dir>/index.php → GLUE_APPLICATION value ('' = the
 * default application).
 */
const RF_GLUE_APPS = ['Glue' => '', 'GlueBackend' => 'GLUE_BACKEND', 'GlueStorefront' => 'GLUE_STOREFRONT'];

const RF_ROUTERS = [
    'zed' => 'router:cache:warm-up',
    'backoffice' => 'router:cache:warm-up:backoffice',
    'backend-gateway' => 'router:cache:warm-up:backend-gateway',
    'merchant-portal' => 'router:cache:warm-up:merchant-portal',
];

/**
 * Data import entities whose importer looks rows up by value: a changed value is not re-imported.
 */
const RF_VALUE_KEYED_IMPORTS = ['product-price', 'product-price-schedule', 'discount-amount', 'cms-block'];

/**
 * The mapping: trigger → commands, for the table and for the plan reasons. The matching itself is
 * rf_match_file(); each row id there is a row here.
 */
const RF_ROWS = [
    'transfer' => ['*.transfer.xml changed', 'transfer:generate'],
    'schema' => ['*.schema.xml changed (project layer)', 'propel:install'],
    'php-layout' => ['.php added, renamed, moved or deleted under a project namespace in src/', 'composer dumpautoload --apcu'],
    'php-class-resolver' => ['.php added, changed, renamed or deleted under a project namespace in src/ (an override of a vendor class is named)', 'cache:class-resolver:build'],
    'new-module' => ['new module directory under src/<Ns>/{Shared,Zed,Client,Yves,Glue,Service}/<Module>/', 'dev:ide-auto-completion:generate, then vendor/bin/phpstan clear-result-cache (last)'],
    'dependency-provider' => ['*DependencyProvider.php changed (body or new file)', 'cache:empty-all'],
    'config' => ['config/**/*.php or config_default*.php changed', 'cache:empty-all'],
    'yves-twig' => ['Yves Twig changed; added, renamed or deleted also warms the path map', 'rm -rf src/Generated/Yves/Twig/codeBucket (+ twig:cache:warmer). Never data/cache/Yves/<env>'],
    'yves-assets' => ['Yves Theme/** JS, TS, SCSS or LESS changed', 'frontend:yves:build when /data/node_modules exists in the cli container, else docker/sdk up --assets'],
    'zed-assets' => ['Back Office assets/Zed/** JS, TS, SCSS or LESS changed', 'frontend:zed:build'],
    'mp-assets' => ['Merchant Portal Presentation/Components/** changed', 'frontend:mp:build'],
    'zed-twig-layout' => ['Twig file added, renamed or deleted in Zed or Merchant Portal', 'twig:cache:warmer'],
    'navigation' => ['navigation.xml changed', 'navigation:cache:remove, navigation:build-cache'],
    'rest-api' => ['Glue / SAPI / BAPI route or *RestApi* plugin changed', 'rest-api:remove-validation-cache, rest-api:build-request-validation-cache'],
    'api-platform' => ['resources/api/{storefront,backend}/*.resource.yml added or changed, or a *RestApi dependency provider changed, and src/Generated/Api/<App> exists', 'per Glue application: glue api:generate, glue cache:clear, glue cache:warmup'],
    'composer-lock' => ['composer.lock changed', 'transfer:generate; propel:install (a changed module ships *.schema.xml); transfer:entity:generate; composer dump-autoload; dev:ide-auto-completion:generate + phpstan cache clear; cache:class-resolver:build; glue api:generate (module ships API resources); router:cache:warm-up* (module ships route providers: decision); frontend:project:install-dependencies (changed module has package.json: decision)'],
    'frontend-dependencies' => ['a frontend build after vendor modules were added or updated', 'run: every dependency of the changed vendor package.json files exists in /data/node_modules, else stop (frontend:project:install-dependencies; a lock update needs the user)'],
    'router' => ['RouteProvider plugin added, changed or deleted, or a new Zed controller or action', 'router:cache:warm-up and/or :backoffice / :backend-gateway / :merchant-portal (decision: --router)'],
    'oms' => ['OMS XML (config/Zed/oms/*.xml) changed', 'oms:process-cache:warm-up'],
    'glossary' => ['glossary CSV changed (data/import/**/glossary*.csv)', 'data:import:glossary (Yves translations only, not Zed Back Office labels)'],
    'translator' => ['Zed translator CSV (src/<Ns>/Zed/Translator/data/<Module>/*.csv) changed', 'translator:generate-cache (run checks console list first)'],
    'search-schema' => ['search schema / index-map JSON (src/<Ns>/Shared/Search/Schema/*.json) changed', 'search:source-map:remove, search:setup:source-map, search:setup:sources'],
    'data-import' => ['data import CSV changed, rows added only (decided from the diff)', 'data:import:<entity> (run checks console list first); a removed row, or a changed value of product-price, product-price-schedule, discount-amount or cms-block: manual (project-data reset ladder)'],
    'dms-drain' => ['a data import runs in a DMS project (SPRYKER_DYNAMIC_STORE_MODE in the deploy file)', 'per store: APPLICATION_STORE=<STORE> vendor/bin/console queue:worker:start --stop-when-empty (decision: --store); then the broker depth check: manual'],
    'publisher' => ['publisher plugin or queue config changed', 'manual: queue workers need a restart'],
    'wiring' => ['a DependencyProvider, ApplicationServices.php or other container wiring changed', 'manual: the wiring smoke check (one Back Office route, one backend-gateway route)'],
    'symptom-zed-twig' => ['--symptom zed-twig-stale: Back Office shows a stale template after a clean refresh', 'rm -f src/Generated/Zed/Twig/codeBucket/.pathCache, twig:cache:warmer'],
    'symptom-yves-twig' => ['--symptom yves-twig-stale: a Yves template fix shows no effect, the file is right on disk and in the container', 'rm -rf src/Generated/Yves/Twig/codeBucket (+ twig:cache:warmer for a new override file)'],
];

/**
 * @return array{0: int, 1: string, 2: string}
 */
function rf_git(string $root, array $args): array
{
    return spryker_upgrade_git($root, $args);
}

function rf_has_git(string $root): bool
{
    return rf_git($root, ['rev-parse', '--git-dir'])[0] === 0;
}

function rf_in_base(string $root, string $base, string $path): bool
{
    return rf_git($root, ['cat-file', '-e', $base . ':' . $path])[0] === 0;
}

/**
 * The changed files with their status, from git: working tree and untracked files against $base.
 *
 * @return list<array{status: string, path: string, old: ?string}>
 */
function rf_files_from_git(string $root, string $base): array
{
    [$code, $out, $err] = rf_git($root, ['diff', '--name-status', '-M', $base, '--']);
    if ($code !== 0) {
        fwrite(STDERR, 'git diff failed: ' . trim($err) . "\n");
        exit(2);
    }
    $files = [];
    foreach (array_filter(explode("\n", $out)) as $line) {
        $parts = explode("\t", $line);
        $status = $parts[0][0];
        if ($status === 'R' || $status === 'C') {
            $files[] = ['status' => 'R', 'path' => $parts[2], 'old' => $parts[1]];
        } else {
            $files[] = ['status' => in_array($status, ['A', 'D'], true) ? $status : 'M', 'path' => $parts[1], 'old' => null];
        }
    }
    [, $untracked] = rf_git($root, ['ls-files', '--others', '--exclude-standard']);
    foreach (array_filter(explode("\n", $untracked)) as $path) {
        $files[] = ['status' => 'A', 'path' => $path, 'old' => null];
    }

    return $files;
}

/**
 * A bare path that is not on disk is a deleted file when the base commit has it. With git, a path
 * that is in neither place goes to $notFound: it never existed, so nothing is planned for it.
 *
 * @param list<string> $args
 * @param list<string> $notFound
 *
 * @return list<array{status: string, path: string, old: ?string}>
 */
function rf_files_from_args(string $root, array $args, string $base, bool $git, array &$notFound = []): array
{
    $files = [];
    foreach ($args as $arg) {
        if (preg_match('/^R:(.+)->(.+)$/', $arg, $m)) {
            $files[] = ['status' => 'R', 'path' => ltrim($m[2], './'), 'old' => ltrim($m[1], './')];
            continue;
        }
        if (preg_match('/^([AMD]):(.+)$/', $arg, $m)) {
            $files[] = ['status' => $m[1], 'path' => ltrim($m[2], './'), 'old' => null];
            continue;
        }
        $path = ltrim($arg, './');
        if ($git && !file_exists($root . '/' . $path) && !rf_in_base($root, $base, $path)) {
            $notFound[] = $path;
            continue;
        }
        $status = match (true) {
            !file_exists($root . '/' . $path) => 'D',
            $git && !rf_in_base($root, $base, $path) => 'A',
            default => 'M',
        };
        $files[] = ['status' => $status, 'path' => $path, 'old' => null];
    }

    return $files;
}

/**
 * Everything the matching needs to know about the project.
 *
 * @return array<string, mixed>
 */
function rf_context(string $root, string $base, string $deployFile, bool $git): array
{
    $loaded = df_load($root, $deployFile);
    $deploy = is_array($loaded) ? $loaded : null;

    return [
        'root' => $root,
        'base' => $base,
        'git' => $git,
        'namespaces' => spryker_upgrade_project_namespaces($root),
        'deployFile' => $deployFile,
        'deploy' => $deploy,
        'deployError' => is_string($loaded) ? $loaded : null,
        'entryPoints' => $deploy === null ? [] : df_glue_entry_points($deploy),
        'dms' => $deploy !== null && df_dynamic_store($deploy),
        'apiPlatform' => glob($root . '/src/Generated/Api/*', GLOB_ONLYDIR) !== [],
    ];
}

/**
 * The resolved Glue applications: name => GLUE_APPLICATION value, plus decisions and skipped notes.
 *
 * @return array{apps: array<string, string>, decisions: list<string>, skipped: list<string>}
 */
function rf_glue_apps(array $context): array
{
    $apps = [];
    $decisions = [];
    $skipped = [];
    $found = [];
    foreach (glob($context['root'] . '/public/Glue*/index.php') ?: [] as $index) {
        $found[] = basename(dirname($index));
    }
    foreach (RF_GLUE_APPS as $dir => $env) {
        $hasIndex = in_array($dir, $found, true);
        $declared = in_array($dir, $context['entryPoints'], true);
        if ($hasIndex && $declared) {
            $apps[$dir] = $env;
        } elseif ($hasIndex) {
            $decisions[] = sprintf('Glue application %s: public/%s/index.php exists, but %s declares no entry-point %s. Pass --deploy-file with the active deploy file, or leave it out.', $dir, $dir, $context['deployFile'], $dir);
        } else {
            $skipped[] = sprintf('%s steps: skipped (no such Glue application: no public/%s/index.php)', $env === '' ? 'default Glue application' : 'GLUE_APPLICATION=' . $env, $dir);
        }
    }
    foreach (array_diff($found, array_keys(RF_GLUE_APPS)) as $dir) {
        $decisions[] = sprintf('Glue application %s (public/%s/index.php): not a known application; run its glue commands by hand if it needs them.', $dir, $dir);
    }

    return ['apps' => $apps, 'decisions' => $decisions, 'skipped' => $skipped];
}

/**
 * The project namespace, layer and module of a path under src/, or null.
 *
 * @return array{ns: string, layer: string, module: string, rest: string}|null
 */
function rf_src_parts(array $context, string $path): ?array
{
    if (!preg_match('#^src/([^/]+)/([^/]+)/([^/]+)/(.+)$#', $path, $m) || !in_array($m[1], $context['namespaces'], true)) {
        return null;
    }

    return ['ns' => $m[1], 'layer' => $m[2], 'module' => $m[3], 'rest' => $m[4]];
}

/**
 * The vendor class a project PHP file overrides (same class name below the vendor root), or null.
 */
function rf_vendor_class(array $context, string $path): ?string
{
    if (!preg_match('#^src/[^/]+/(.+)\.php$#', $path, $m)) {
        return null;
    }
    foreach (['Spryker', 'SprykerShop', 'SprykerEco', 'SprykerFeature'] as $vendorNs) {
        $hits = glob($context['root'] . '/vendor/spryker*/*/src/' . $vendorNs . '/' . $m[1] . '.php') ?: [];
        if ($hits !== []) {
            return $vendorNs . '\\' . str_replace('/', '\\', $m[1]);
        }
    }

    return null;
}

/**
 * Is the module directory of a path new: no file of it in the base commit (with git), or every file
 * in it is in the added list (without git)?
 *
 * @param list<string> $added
 */
function rf_new_module(array $context, array $parts, array $added): bool
{
    $dir = sprintf('src/%s/%s/%s', $parts['ns'], $parts['layer'], $parts['module']);
    if ($context['git']) {
        return !rf_in_base($context['root'], $context['base'], $dir);
    }
    $present = [];
    if (is_dir($context['root'] . '/' . $dir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($context['root'] . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $present[] = substr($file->getPathname(), strlen($context['root']) + 1);
        }
    }

    return $present !== [] && array_diff($present, $added) === [];
}

/**
 * The diff of one file against the base: added and removed lines (without the +++ / --- headers).
 *
 * @return array{added: list<string>, removed: list<string>}|null null when there is no diff to read
 */
function rf_line_diff(array $context, string $path): ?array
{
    if (!$context['git']) {
        return null;
    }
    [$code, $out] = rf_git($context['root'], ['diff', '-U0', $context['base'], '--', $path]);
    if ($code !== 0) {
        return null;
    }
    $added = [];
    $removed = [];
    foreach (explode("\n", $out) as $line) {
        if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
            continue;
        }
        if (str_starts_with($line, '+')) {
            $added[] = substr($line, 1);
        } elseif (str_starts_with($line, '-')) {
            $removed[] = substr($line, 1);
        }
    }

    return ['added' => $added, 'removed' => $removed];
}

/**
 * The data import entity of a CSV: the file name, with underscores as dashes.
 */
function rf_entity(string $path): string
{
    return str_replace('_', '-', strtolower(basename($path, '.csv')));
}

/**
 * The packages composer.lock changed against the base: name => [old version or null, new version].
 *
 * @return array<string, array{0: ?string, 1: string}>|null null when the old lock cannot be read
 */
function rf_lock_changes(array $context): ?array
{
    if (!$context['git']) {
        return null;
    }
    [$code, $old] = rf_git($context['root'], ['show', $context['base'] . ':composer.lock']);
    $new = @file_get_contents($context['root'] . '/composer.lock');
    if ($code !== 0 || $new === false) {
        return null;
    }
    $versions = static function (string $json): array {
        $lock = json_decode($json, true);
        $map = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $map[$package['name']] = $package['version'];
        }

        return $map;
    };
    $before = $versions($old);
    $changes = [];
    foreach ($versions($new) as $name => $version) {
        if (($before[$name] ?? null) !== $version) {
            $changes[$name] = [$before[$name] ?? null, $version];
        }
    }

    return $changes;
}

/**
 * Files of a vendor package whose path matches $pattern (a regular expression), at most one.
 */
function rf_package_has(string $root, string $package, string $pattern): bool
{
    $dir = $root . '/vendor/' . $package;
    if (!is_dir($dir)) {
        return false;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (preg_match($pattern, substr($file->getPathname(), strlen($dir)))) {
            return true;
        }
    }

    return false;
}

/**
 * Match one changed file to the mapping rows.
 *
 * @param array{status: string, path: string, old: ?string} $file
 * @param list<string> $added
 *
 * @return list<array{row: string, steps?: list<array{0: string, 1?: string}>, manual?: string, decision?: string, note?: string}>
 */
function rf_match_file(array $context, array $file, array $added, array $glue): array
{
    $path = $file['path'];
    $status = $file['status'];
    $base = basename($path);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $parts = rf_src_parts($context, $path);
    $layoutChange = $status !== 'M';
    $hits = [];

    if (str_ends_with($base, '.transfer.xml')) {
        $hits[] = ['row' => 'transfer', 'steps' => [['transfer:generate']]];
    }
    if (str_ends_with($base, '.schema.xml') && $parts !== null) {
        $hits[] = ['row' => 'schema', 'steps' => [['propel:install']]];
    }
    if ($ext === 'php' && $parts !== null) {
        if ($layoutChange) {
            $hits[] = ['row' => 'php-layout', 'steps' => [['composer:dumpautoload-apcu']]];
        }
        $vendorClass = rf_vendor_class($context, $path) ?? ($file['old'] !== null ? rf_vendor_class($context, $file['old']) : null);
        $hits[] = ['row' => 'php-class-resolver', 'steps' => [['cache:class-resolver:build']], 'note' => $vendorClass !== null ? 'overrides ' . $vendorClass : null];
    }
    if ($parts !== null && $status === 'A' && in_array($parts['layer'], ['Shared', 'Zed', 'Client', 'Yves', 'Glue', 'Service'], true) && rf_new_module($context, $parts, $added)) {
        $hits[] = ['row' => 'new-module', 'steps' => [['dev:ide-auto-completion:generate'], ['phpstan:clear-result-cache']], 'note' => 'new module ' . $parts['layer'] . '/' . $parts['module']];
    }
    if (str_ends_with($base, 'DependencyProvider.php')) {
        $hits[] = ['row' => 'dependency-provider', 'steps' => [['cache:empty-all']]];
    }
    if ($ext === 'php' && (str_starts_with($path, 'config/') || str_starts_with($base, 'config_default'))) {
        $hits[] = ['row' => 'config', 'steps' => [['cache:empty-all']]];
    }
    if ($ext === 'twig' && $parts !== null && $parts['layer'] === 'Yves') {
        $steps = [['yves-twig-cache:remove']];
        if ($layoutChange) {
            $steps[] = ['twig:cache:warmer'];
        }
        $hits[] = ['row' => 'yves-twig', 'steps' => $steps];
    }
    if (in_array($ext, ['js', 'ts', 'scss', 'less'], true) && $parts !== null && $parts['layer'] === 'Yves' && str_contains($path, '/Theme/')) {
        $hits[] = ['row' => 'yves-assets', 'steps' => [['frontend:yves:build']], 'note' => 'the run uses docker/sdk up --assets when /data/node_modules is absent'];
    }
    if (in_array($ext, ['js', 'ts', 'scss', 'less'], true) && str_contains($path, '/assets/Zed/')) {
        $hits[] = ['row' => 'zed-assets', 'steps' => [['frontend:zed:build']]];
    }
    if (str_contains($path, '/Presentation/Components/')) {
        $hits[] = ['row' => 'mp-assets', 'steps' => [['frontend:mp:build']]];
    }
    if ($ext === 'twig' && $parts !== null && $parts['layer'] === 'Zed' && $layoutChange) {
        $hits[] = ['row' => 'zed-twig-layout', 'steps' => [['twig:cache:warmer']]];
    }
    if ($base === 'navigation.xml') {
        $hits[] = ['row' => 'navigation', 'steps' => [['navigation:cache:remove'], ['navigation:build-cache']]];
    }
    $restApiModule = $parts !== null && str_contains($parts['module'], 'RestApi');
    if ($ext === 'php' && $parts !== null && (($parts['layer'] === 'Glue' && str_contains($base, 'Route')) || ($restApiModule && str_contains($path, '/Plugin/')) || preg_match('/RestApi\w*Plugin\.php$/', $base))) {
        $hits[] = ['row' => 'rest-api', 'steps' => [['rest-api:remove-validation-cache'], ['rest-api:build-request-validation-cache']]];
    }
    $apiResource = preg_match('#resources/api/(storefront|backend)/[^/]+\.resource\.yml$#', $path) === 1 && $status !== 'D';
    $restApiProvider = $restApiModule && str_ends_with($base, 'DependencyProvider.php');
    if (($apiResource || $restApiProvider) && $context['apiPlatform']) {
        $steps = [];
        foreach (array_keys($glue['apps']) as $app) {
            $steps[] = ['glue:api:generate', $app];
            $steps[] = ['glue:cache:clear', $app];
            $steps[] = ['glue:cache:warmup', $app];
        }
        $hits[] = ['row' => 'api-platform', 'steps' => $steps, 'note' => $steps === [] ? 'no resolved Glue application' : null];
    } elseif ($apiResource || $restApiProvider) {
        $hits[] = ['row' => 'api-platform', 'steps' => [], 'note' => 'no src/Generated/Api/<App>: the project does not generate API Platform resources'];
    }
    $newAction = false;
    if ($ext === 'php' && $parts !== null && $parts['layer'] === 'Zed' && str_ends_with($base, 'Controller.php')) {
        $diff = $status === 'M' ? rf_line_diff($context, $path) : null;
        $newAction = $status === 'A' || ($diff !== null && preg_grep('/function\s+\w+Action\s*\(/', $diff['added']) !== []);
    }
    if (($ext === 'php' && str_contains($base, 'RouteProvider')) || $newAction) {
        $hits[] = ['row' => 'router', 'router' => true];
    }
    if (preg_match('#^config/Zed/oms/.+\.xml$#', $path)) {
        $hits[] = ['row' => 'oms', 'steps' => [['oms:process-cache:warm-up']]];
    }
    if ($ext === 'csv' && str_starts_with($path, 'data/import/') && str_starts_with($base, 'glossary')) {
        $hits[] = ['row' => 'glossary', 'steps' => [['data:import:glossary']], 'import' => true];
    } elseif ($ext === 'csv' && $parts !== null && $parts['layer'] === 'Zed' && $parts['module'] === 'Translator') {
        $hits[] = ['row' => 'translator', 'steps' => [['translator:generate-cache']]];
    } elseif ($ext === 'csv' && str_starts_with($path, 'data/import/')) {
        $hits[] = rf_match_import($context, $file);
    }
    if ($ext === 'json' && $parts !== null && $parts['layer'] === 'Shared' && $parts['module'] === 'Search' && str_starts_with($parts['rest'], 'Schema/')) {
        $hits[] = ['row' => 'search-schema', 'steps' => [['search:source-map:remove'], ['search:setup:source-map'], ['search:setup:sources']]];
    }
    if (preg_match('/Publisher|QueueDependencyProvider|QueueConfig/', $path) && $ext === 'php') {
        $hits[] = ['row' => 'publisher', 'manual' => 'queue workers need a manual restart (publisher plugin or queue config changed); project-specific'];
    }
    if (str_ends_with($base, 'DependencyProvider.php') || $base === 'ApplicationServices.php' || preg_match('#^config/Shared/.*[Ss]ervices?\.php$#', $path)) {
        $hits[] = ['row' => 'wiring', 'manual' => 'a clean exit code does not prove the application still serves: run the wiring smoke check (one Back Office route and one backend-gateway route)'];
    }

    return $hits;
}

/**
 * The data import row: rows added only → import; a removed row or a changed value of a value-keyed
 * importer → manual.
 *
 * @param array{status: string, path: string, old: ?string} $file
 *
 * @return array<string, mixed>
 */
function rf_match_import(array $context, array $file): array
{
    $entity = rf_entity($file['path']);
    if ($file['status'] === 'D') {
        return ['row' => 'data-import', 'manual' => sprintf('%s was deleted: no importer deletes rows. Hand it to project-data (reset ladder).', $file['path'])];
    }
    if ($file['status'] === 'A') {
        return ['row' => 'data-import', 'steps' => [['data:import', $entity]], 'import' => true, 'note' => 'new file'];
    }
    $diff = rf_line_diff($context, $file['path']);
    if ($diff === null) {
        return ['row' => 'data-import', 'decision' => sprintf('%s: no diff to read; import with data:import:%s only when rows were added and none removed or changed (a changed value of %s is never re-imported).', $file['path'], $entity, implode(', ', RF_VALUE_KEYED_IMPORTS))];
    }
    if ($diff['removed'] === []) {
        return ['row' => 'data-import', 'steps' => [['data:import', $entity]], 'import' => true, 'note' => sprintf('%d row(s) added only', count($diff['added']))];
    }
    $key = static fn (string $line): string => (string)strtok($line, ',');
    $changedKeys = array_intersect(array_map($key, $diff['removed']), array_map($key, $diff['added']));
    $removedOnly = array_diff(array_map($key, $diff['removed']), $changedKeys);
    if ($removedOnly !== []) {
        return ['row' => 'data-import', 'manual' => sprintf('%s: %d row(s) removed; no importer deletes rows. Hand it to project-data (reset ladder).', $file['path'], count($removedOnly))];
    }
    if (in_array($entity, RF_VALUE_KEYED_IMPORTS, true)) {
        return ['row' => 'data-import', 'manual' => sprintf('%s: a changed value on %s; a re-import duplicates the row or fails on a unique key. Hand it to project-data (reset ladder).', $file['path'], $entity)];
    }

    return ['row' => 'data-import', 'steps' => [['data:import', $entity]], 'import' => true, 'note' => sprintf('%d row(s) changed, %d added', count($changedKeys), count($diff['added']) - count($changedKeys))];
}

/**
 * The plan for a set of changed files.
 *
 * @param list<array{status: string, path: string, old: ?string}> $files
 * @param array{store: list<string>, router: list<string>, symptom: list<string>} $flags
 *
 * @return array{steps: list<array<string, mixed>>, manual: list<string>, decisions: list<string>, skipped: list<string>, unmatched: list<string>}
 */
function rf_plan(array $context, array $files, array $flags): array
{
    $glue = rf_glue_apps($context);
    $added = array_column(array_filter($files, static fn (array $f): bool => $f['status'] === 'A'), 'path');
    $steps = [];
    $manual = [];
    $decisions = [];
    if (($context['deployError'] ?? null) !== null) {
        $decisions[] = sprintf('%s. The plan does not use the deploy file (Glue entry points, dynamic store mode): pass --deploy-file with a file the reader parses, or check the Glue and per-store steps by hand.', $context['deployError']);
    }
    $unmatched = [];
    $imports = false;
    $routerFiles = [];
    $vendorFrontend = [];
    $add = static function (array $step, string $reason) use (&$steps): void {
        $key = $step[0] . '|' . ($step[1] ?? '');
        $steps[$key] ??= ['key' => $step[0], 'param' => $step[1] ?? null, 'reasons' => []];
        if (!in_array($reason, $steps[$key]['reasons'], true)) {
            $steps[$key]['reasons'][] = $reason;
        }
    };

    foreach ($files as $file) {
        $label = ($file['old'] !== null ? $file['old'] . ' -> ' : '') . $file['path'] . ' (' . $file['status'] . ')';
        if ($file['path'] === 'composer.lock') {
            $changes = rf_lock_changes($context);
            if ($changes === null) {
                $decisions[] = 'composer.lock: the previous lock cannot be read from git, so the changed modules are unknown; name them, or plan with --base <ref>.';
                continue;
            }
            $reason = sprintf('%s [composer-lock: %d package(s) changed]', $label, count($changes));
            foreach (['transfer:generate', 'transfer:entity:generate', 'composer:dump-autoload', 'dev:ide-auto-completion:generate', 'phpstan:clear-result-cache', 'cache:class-resolver:build'] as $key) {
                $add([$key], $reason);
            }
            foreach (array_keys($changes) as $package) {
                if (rf_package_has($context['root'], $package, '/\.schema\.xml$/')) {
                    $add(['propel:install'], sprintf('%s [composer-lock: %s ships *.schema.xml]', $label, $package));
                    $manual[] = sprintf('propel:install runs for %s: it changes the database schema; say so in the report.', $package);
                }
                if (rf_package_has($context['root'], $package, '#/resources/api/(storefront|backend)/[^/]+\.resource\.yml$#')) {
                    foreach (array_keys($glue['apps']) as $app) {
                        $add(['glue:api:generate', $app], sprintf('%s [composer-lock: %s ships API resources]', $label, $package));
                    }
                }
                if (rf_package_has($context['root'], $package, '/RouteProvider\w*\.php$/')) {
                    $routerFiles[] = sprintf('composer.lock (%s ships route providers)', $package);
                }
                if (is_file($context['root'] . '/vendor/' . $package . '/package.json')) {
                    $vendorFrontend[] = $package;
                }
            }
            if ($vendorFrontend !== []) {
                $decisions[] = sprintf('frontend:project:install-dependencies: run it when the package.json of a changed module changed (%s have one). Plan again with the file vendor/<package>/package.json named.', implode(', ', $vendorFrontend));
            }
            continue;
        }
        if (preg_match('#^vendor/(.+)/package\.json$#', $file['path'], $m)) {
            $add(['frontend:project:install-dependencies'], $label . ' [composer-lock: package.json of a changed module]');
            $vendorFrontend[] = $m[1];
            continue;
        }
        $hits = rf_match_file($context, $file, $added, $glue);
        if ($hits === []) {
            $unmatched[] = $file['path'];
        }
        foreach ($hits as $hit) {
            $reason = $label . ' [' . $hit['row'] . (isset($hit['note']) && $hit['note'] !== null ? ': ' . $hit['note'] : '') . ']';
            foreach ($hit['steps'] ?? [] as $step) {
                $add($step, $reason);
            }
            if (isset($hit['manual'])) {
                $manual[] = $hit['manual'] . ' — ' . $file['path'];
            }
            if (isset($hit['decision'])) {
                $decisions[] = $hit['decision'];
            }
            if (!empty($hit['router'])) {
                $routerFiles[] = $file['path'];
            }
            $imports = $imports || !empty($hit['import']);
        }
    }

    foreach ($flags['symptom'] as $symptom) {
        if ($symptom === 'zed-twig-stale') {
            $add(['zed-twig-path-cache:remove'], 'symptom zed-twig-stale [symptom-zed-twig]');
            $add(['twig:cache:warmer'], 'symptom zed-twig-stale [symptom-zed-twig]');
        } else {
            $add(['yves-twig-cache:remove'], 'symptom yves-twig-stale [symptom-yves-twig]');
        }
    }
    if ($routerFiles !== []) {
        if ($flags['router'] === []) {
            $decisions[] = sprintf('router cache warm-up for %s: name the applications the routes belong to with --router %s.', implode(', ', array_unique($routerFiles)), implode('|', array_keys(RF_ROUTERS)));
        }
        foreach ($flags['router'] as $router) {
            $add(['router:cache:warm-up', $router], implode(', ', array_unique($routerFiles)) . ' [router: --router ' . $router . ']');
        }
    }
    if ($imports && $context['dms']) {
        if ($flags['store'] === []) {
            $decisions[] = 'DMS project (SPRYKER_DYNAMIC_STORE_MODE in ' . $context['deployFile'] . '): the queue drain after the import runs once per store. Name each store with --store <STORE>.';
        }
        foreach ($flags['store'] as $store) {
            $add(['queue:drain', $store], 'data import in a DMS project [dms-drain: --store ' . $store . ']');
        }
        $manual[] = 'after the per-store drain, confirm the queues are empty: the broker container from `docker ps` (name contains `broker`), `rabbitmqctl list_queues -p /<vhost> name messages` all 0';
    }
    $hasFrontendBuild = array_filter($steps, static fn (array $s): bool => str_starts_with($s['key'], 'frontend:') && $s['key'] !== 'frontend:project:install-dependencies') !== [];
    if (array_filter($files, static fn (array $f): bool => preg_match('#^src/[^/]+/Yves/.+\.(twig|js|ts|scss|less)$#', $f['path']) === 1) !== []) {
        $manual[] = 'the storefront may need a browser hard refresh';
    }

    uasort($steps, static function (array $a, array $b): int {
        $order = array_flip(array_keys(RF_COMMANDS));

        return [RF_COMMANDS[$a['key']]['group'], $order[$a['key']], (string)$a['param']] <=> [RF_COMMANDS[$b['key']]['group'], $order[$b['key']], (string)$b['param']];
    });
    $usesGlue = array_filter($steps, static fn (array $s): bool => str_starts_with($s['key'], 'glue:')) !== [];

    return [
        'steps' => array_values($steps),
        'vendorFrontend' => $hasFrontendBuild ? array_values(array_unique($vendorFrontend)) : [],
        'manual' => array_values(array_unique($manual)),
        'decisions' => array_values(array_unique(array_merge($decisions, $usesGlue ? $glue['decisions'] : []))),
        'skipped' => $usesGlue ? $glue['skipped'] : [],
        'unmatched' => $unmatched,
    ];
}

/**
 * The docker/sdk arguments of a step, rebuilt from the mapping; null when the step is not in it.
 *
 * @return list<string>|null
 */
function rf_call(array $step): ?array
{
    $command = RF_COMMANDS[$step['key'] ?? ''] ?? null;
    if ($command === null) {
        return null;
    }
    $param = $step['param'] ?? null;
    $kind = $command['param'] ?? null;
    if (($kind === null) !== ($param === null)) {
        return null;
    }
    $value = match ($kind) {
        'app' => array_key_exists((string)$param, RF_GLUE_APPS) ? (RF_GLUE_APPS[$param] === '' ? 'vendor/bin/glue' : 'GLUE_APPLICATION=' . RF_GLUE_APPS[$param] . ' vendor/bin/glue') : null,
        'router' => RF_ROUTERS[(string)$param] ?? null,
        'entity' => preg_match('/^[a-z0-9][a-z0-9-]*$/', (string)$param) ? $param : null,
        'store' => preg_match('/^[A-Z][A-Z0-9_]*$/', (string)$param) ? $param : null,
        default => '',
    };
    if ($value === null) {
        return null;
    }

    return array_map(static fn (string $part): string => str_replace(['{glue}', '{router}', '{entity}', '{store}'], (string)$value, $part), $command['call']);
}

function rf_print_plan(array $plan, int $fileCount, string $planFile): void
{
    printf("Refresh plan: %d file(s), %d step(s)\n", $fileCount, count($plan['steps']));
    foreach ($plan['steps'] as $i => $step) {
        $reasons = $step['reasons'];
        printf(
            "%2d. docker/sdk %s  — %s%s\n",
            $i + 1,
            implode(' ', array_map(static fn (string $p): string => str_contains($p, ' ') ? '"' . $p . '"' : $p, (array)rf_call($step))),
            $reasons[0],
            count($reasons) > 1 ? sprintf(' (+%d more)', count($reasons) - 1) : ''
        );
    }
    if ($plan['vendorFrontend'] !== []) {
        printf("Before the frontend build the run checks the dependencies of %s in /data/node_modules.\n", implode(', ', $plan['vendorFrontend']));
    }
    foreach (['manual' => 'Manual (the skill does not run these)', 'decisions' => 'Needs a decision', 'skipped' => 'Skipped', 'unmatched' => 'No row matches', 'notFound' => 'Not found (not on disk and not in the base commit; nothing planned. A deleted file is named D:<path>)'] as $key => $title) {
        if (($plan[$key] ?? []) !== []) {
            echo $title . ":\n";
            foreach ($plan[$key] as $line) {
                echo '  - ' . $line . "\n";
            }
        }
    }
    echo 'Plan: ' . $planFile . "\n";
}

/**
 * @param list<string> $command
 */
function rf_execute(array $command, string $root, string $logFile): int
{
    return rt_execute(rt_wrap(array_merge(['docker/sdk'], $command), PHP_OS_FAMILY), $root, [], $logFile);
}

function rf_tail(string $logFile): string
{
    $lines = explode("\n", rtrim(to_plain_text((string)file_get_contents($logFile))));

    return implode("\n", array_slice($lines, -RF_TAIL_LINES)) . "\n";
}

/**
 * Run the plan. Container checks: console list for importers and translator:generate-cache,
 * /data/node_modules for the Yves build, the vendor frontend dependencies before a build.
 */
function rf_run(string $root, array $plan, int $from): int
{
    $logDir = $root . '/' . RF_STATE . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    foreach ($plan['steps'] as $i => $step) {
        if (rf_call($step) === null) {
            fwrite(STDERR, sprintf("Step %d is not a command of the mapping: %s. Nothing ran.\n", $i + 1, json_encode($step)));

            return 2;
        }
    }
    $consoleList = null;
    $checkedFrontend = false;
    $number = 0;
    foreach ($plan['steps'] as $i => $step) {
        $number = $i + 1;
        if ($number < $from) {
            continue;
        }
        $call = rf_call($step);
        $slug = sprintf('%02d-%s', $number, trim((string)preg_replace('/[^a-z0-9]+/i', '-', $step['key'] . '-' . ($step['param'] ?? '')), '-'));
        $logFile = $logDir . '/' . $slug . '.log';
        $verify = RF_COMMANDS[$step['key']]['verify'] ?? null;
        if ($verify !== null) {
            if ($consoleList === null) {
                rf_execute(['console', 'list', '--raw'], $root, $logDir . '/console-list.log');
                $consoleList = (string)file_get_contents($logDir . '/console-list.log');
            }
            $name = str_replace('{entity}', (string)$step['param'], $verify);
            if (!preg_match('/^' . preg_quote($name, '/') . '\s/m', to_plain_text($consoleList))) {
                printf("%2d  %s  not run: `%s` is not in docker/sdk console list  %s\n", $number, implode(' ', $call), $name, RF_STATE . '/logs/console-list.log');

                return 1;
            }
        }
        if (str_starts_with($step['key'], 'frontend:') && $step['key'] !== 'frontend:project:install-dependencies' && !$checkedFrontend && ($plan['vendorFrontend'] ?? []) !== []) {
            $checkedFrontend = true;
            $paths = [];
            foreach ($plan['vendorFrontend'] as $package) {
                $json = json_decode((string)@file_get_contents($root . '/vendor/' . $package . '/package.json'), true);
                foreach (array_keys(array_merge($json['dependencies'] ?? [], $json['devDependencies'] ?? [])) as $dependency) {
                    $paths[] = '/data/node_modules/' . $dependency;
                }
            }
            if ($paths !== []) {
                $checkLog = $logDir . '/' . $slug . '-dependencies.log';
                $code = rf_execute(['cli', 'ls -d ' . implode(' ', array_map('escapeshellarg', array_unique($paths)))], $root, $checkLog);
                printf("%2d  frontend dependency check  exit %d  %s\n", $number, $code, RF_STATE . '/logs/' . basename($checkLog));
                if ($code !== 0) {
                    echo "A dependency of a changed vendor module is missing: run frontend:project:install-dependencies first (plan again with vendor/<package>/package.json); a package missing from the lock needs a lock update the user approves.\n" . rf_tail($checkLog);

                    return 1;
                }
            }
        }
        if (!empty(RF_COMMANDS[$step['key']]['nodeModules'])) {
            $code = rf_execute(['cli', 'ls -d /data/node_modules'], $root, $logDir . '/' . $slug . '-node-modules.log');
            if ($code !== 0) {
                $call = ['up', '--assets'];
                printf("%2d  /data/node_modules is absent in the cli container: running docker/sdk up --assets instead of frontend:yves:build\n", $number);
            }
        }
        $code = rf_execute($call, $root, $logFile);
        printf("%2d  docker/sdk %s  exit %d  %s\n", $number, implode(' ', $call), $code, RF_STATE . '/logs/' . basename($logFile));
        if ($code !== 0) {
            printf("--- last %d lines of %s\n%s", RF_TAIL_LINES, RF_STATE . '/logs/' . basename($logFile), rf_tail($logFile));
            $left = count($plan['steps']) - $number;
            printf("Stopped at step %d.%s\n", $number, $left > 0 ? sprintf(' Steps %d-%d did not run (resume with --from %d).', $number + 1, count($plan['steps']), $number) : '');

            return 1;
        }
    }
    printf("All %d step(s) exited 0.\n", count($plan['steps']) - max(0, $from - 1));

    return 0;
}

/**
 * After a run: the decisions the plan left open (their steps are not in the run) and the manual items.
 */
function rf_print_open(array $plan): void
{
    $titles = [
        'decisions' => 'Open decisions (their steps did not run: plan again with the flag, or settle each by hand)',
        'manual' => 'Manual (the skill does not run these)',
    ];
    foreach ($titles as $key => $title) {
        $lines = (array)($plan[$key] ?? []);
        if ($lines === []) {
            continue;
        }
        echo $title . ":\n";
        foreach ($lines as $line) {
            echo '  - ' . $line . "\n";
        }
    }
}

function rf_print_table(): void
{
    echo "| Row | Trigger | Commands |\n|---|---|---|\n";
    foreach (RF_ROWS as $id => [$trigger, $commands]) {
        printf("| %s | %s | %s |\n", $id, $trigger, $commands);
    }
    echo "\nRun order (groups): ";
    $parts = [];
    foreach (RF_GROUPS as $group => $name) {
        $keys = array_keys(array_filter(RF_COMMANDS, static fn (array $c): bool => $c['group'] === $group));
        $parts[] = sprintf('%d %s (%s)', $group, $name, implode(', ', $keys));
    }
    echo implode('; ', $parts) . "\n";
}

function rf_main(array $argv): int
{
    $mode = $argv[1] ?? '';
    $args = array_slice($argv, 2);
    $root = (string)realpath((string)getcwd());
    if ($mode === 'table') {
        rf_print_table();

        return 0;
    }
    $planFile = RF_STATE . '/plan.json';
    if ($mode === 'run') {
        $from = 1;
        if (($args[0] ?? null) === '--from' && ctype_digit($args[1] ?? '')) {
            $from = (int)$args[1];
        } elseif ($args !== []) {
            fwrite(STDERR, "Usage: php refresh.php run [--from <step>]\n");

            return 2;
        }
        $plan = json_decode((string)@file_get_contents($root . '/' . $planFile), true);
        if (!is_array($plan) || !is_array($plan['steps'] ?? null)) {
            fwrite(STDERR, "No plan at $planFile: run `refresh.php plan` first.\n");

            return 2;
        }

        $code = rf_run($root, $plan, $from);
        if ($code !== 2) {
            rf_print_open($plan);
        }

        return $code;
    }
    if ($mode !== 'plan') {
        fwrite(STDERR, "Usage: php refresh.php plan [<file>...] [--base <ref>] [--store <STORE>]... [--router <app>[,<app>]] [--symptom yves-twig-stale|zed-twig-stale]... [--deploy-file <file>] [--print-only]\n"
            . "       php refresh.php run [--from <step>]\n       php refresh.php table\n");

        return 2;
    }

    $paths = [];
    $base = 'HEAD';
    $flags = ['store' => [], 'router' => [], 'symptom' => []];
    $deployFile = 'deploy.dev.yml';
    $printOnly = false;
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        $value = static function () use ($args, &$i, $arg): string {
            if (!isset($args[$i + 1])) {
                fwrite(STDERR, "$arg needs a value\n");
                exit(2);
            }

            return $args[++$i];
        };
        match ($arg) {
            '--base' => $base = $value(),
            '--deploy-file' => $deployFile = $value(),
            '--store' => $flags['store'][] = strtoupper($value()),
            '--router' => $flags['router'] = array_merge($flags['router'], explode(',', $value())),
            '--symptom' => $flags['symptom'][] = $value(),
            '--print-only' => $printOnly = true,
            default => $paths[] = $arg,
        };
    }
    foreach ($flags['router'] as $router) {
        if (!isset(RF_ROUTERS[$router])) {
            fwrite(STDERR, "--router takes " . implode(', ', array_keys(RF_ROUTERS)) . "\n");

            return 2;
        }
    }
    foreach ($flags['symptom'] as $symptom) {
        if (!in_array($symptom, ['yves-twig-stale', 'zed-twig-stale'], true)) {
            fwrite(STDERR, "--symptom takes yves-twig-stale or zed-twig-stale\n");

            return 2;
        }
    }
    foreach ($flags['store'] as $store) {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $store)) {
            fwrite(STDERR, "--store takes a store name such as DE\n");

            return 2;
        }
    }
    foreach ($paths as $path) {
        if (str_starts_with($path, '--')) {
            fwrite(STDERR, "Unknown option: $path\n");

            return 2;
        }
    }

    $git = rf_has_git($root);
    $notFound = [];
    $files = $paths !== [] ? rf_files_from_args($root, $paths, $base, $git, $notFound) : ($git ? rf_files_from_git($root, $base) : []);
    if ($files === [] && $flags['symptom'] === []) {
        fwrite(STDERR, match (true) {
            $notFound !== [] => sprintf("Not found (not on disk and not in %s): %s. Check the paths; a deleted file is named D:<path>.\n", $base, implode(', ', $notFound)),
            $git => "No changed files against $base.\n",
            default => "No files given and no git repository: name the changed files.\n",
        });

        return 2;
    }
    $context = rf_context($root, $base, $deployFile, $git);
    $plan = rf_plan($context, $files, $flags);
    $plan['notFound'] = $notFound;
    $plan['files'] = $files;
    $plan['createdAt'] = date('c');
    if ($printOnly) {
        rf_print_plan($plan, count($files), '(not written: --print-only)');

        return 0;
    }
    $stateDir = $root . '/' . RF_STATE;
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0775, true);
    }
    if (!is_file($stateDir . '/.gitignore')) {
        file_put_contents($stateDir . '/.gitignore', "*\n");
    }
    file_put_contents($root . '/' . $planFile, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    rf_print_plan($plan, count($files), $planFile);

    return 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(rf_main($argv));
}
