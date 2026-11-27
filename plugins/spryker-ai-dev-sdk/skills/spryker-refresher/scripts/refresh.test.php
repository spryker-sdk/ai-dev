<?php

declare(strict_types=1);

/** Zero-dependency tests for refresh.php. Run: php scripts/refresh.test.php */

require __DIR__ . '/refresh.php';

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

function write_file(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function git(string $dir, string ...$args): string
{
    $command = 'git -C ' . escapeshellarg($dir) . ' -c user.name=test -c user.email=test@example.com -c commit.gpgsign=false';
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    return trim((string)shell_exec($command . ' 2>&1'));
}

/**
 * @return array{0: int, 1: string} exit code, combined output
 */
function refresh(string $projectDir, array $args, array $env = []): array
{
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/refresh.php'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectDir,
        array_merge(getenv(), $env)
    );
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string)$output];
}

/**
 * The commands of the printed plan, in order, without the reasons.
 *
 * @return list<string>
 */
function plan_commands(string $output): array
{
    preg_match_all('/^\s*\d+\. docker\/sdk (.+?)  — /m', $output, $m);

    return $m[1];
}

$tmp = sys_get_temp_dir() . '/refresher_' . getmypid();
@mkdir($tmp, 0777, true);
$tmp = (string)realpath($tmp);

// --- fixture project: committed base, then changes in the working tree ---
$project = $tmp . '/project';
$stub = $tmp . '/stub';
@mkdir($stub, 0777, true);
$lock = static fn (array $packages): string => json_encode(['packages' => array_map(static fn (string $n, string $v): array => ['name' => $n, 'version' => $v], array_keys($packages), $packages)]);
$base = [
    'composer.json' => '{}',
    'composer.lock' => $lock(['spryker/kernel' => '3.70.0', 'spryker/cart' => '7.0.0']),
    'config/Shared/config_default.php' => "<?php\n",
    'config/Zed/oms/Demo01.xml' => "<statemachine/>\n",
    'deploy.dev.yml' => "groups:\n    EU:\n        applications:\n            glue_eu:\n                application: glue\n            glue_backend_eu:\n                application: glue-backend\n",
    'public/Glue/index.php' => "<?php\n",
    'public/GlueBackend/index.php' => "<?php\n",
    'src/Generated/Api/Storefront/.keep' => '',
    'src/Pyz/Zed/Cart/CartDependencyProvider.php' => "<?php\n",
    'src/Pyz/Zed/Cart/Business/CartFacade.php' => "<?php\n",
    'src/Pyz/Zed/Cart/Communication/Controller/IndexController.php' => "<?php\nclass IndexController {\n    public function indexAction() {}\n}\n",
    'src/Pyz/Zed/Cart/Presentation/Index/index.twig' => "{{ x }}\n",
    'src/Pyz/Zed/Old/Business/OldFacade.php' => "<?php\n",
    'src/Pyz/Yves/CartPage/Theme/default/views/cart/cart.twig' => "{{ x }}\n",
    'src/Pyz/Yves/CartPage/Theme/default/components/molecules/cart-item/cart-item.scss' => "a {}\n",
    'src/Pyz/Zed/ProductOffer/assets/Zed/js/main.js' => "x\n",
    'src/Pyz/Zed/MerchantPortalGui/Presentation/Components/src/app/a.ts' => "x\n",
    'config/Zed/navigation.xml' => "<config/>\n",
    'src/Pyz/Glue/CartsRestApi/Plugin/CartsResourceRoutePlugin.php' => "<?php\n",
    'src/Pyz/Glue/CartsRestApi/CartsRestApiDependencyProvider.php' => "<?php\n",
    'src/Pyz/Zed/Cart/Communication/Plugin/Router/CartRouteProviderPlugin.php' => "<?php\n",
    'data/import/common/common/glossary.csv' => "key,translation,locale\na,A,en_US\n",
    'src/Pyz/Zed/Translator/data/Cart/en_US.csv' => "a,A\n",
    'src/Pyz/Shared/Search/Schema/page.json' => "{}\n",
    'data/import/common/common/product_abstract.csv' => "abstract_sku,name\n001,A\n",
    'data/import/common/common/product_price.csv' => "abstract_sku,price\n001,100\n",
    'data/import/common/common/category.csv' => "category_key,name\nc1,One\nc2,Two\n",
    'src/Pyz/Zed/Cart/Communication/Plugin/Publisher/CartPublisherPlugin.php' => "<?php\n",
    'src/Pyz/Shared/Cart/Transfer/cart.transfer.xml' => "<transfers/>\n",
    'src/Pyz/Zed/Cart/Persistence/Propel/Schema/spy_cart.schema.xml' => "<database/>\n",
    'src/Pyz/Glue/CartsRestApi/resources/api/storefront/carts.resource.yml' => "a: 1\n",
];
foreach ($base as $path => $contents) {
    write_file($project . '/' . $path, $contents);
}
write_file($project . '/vendor/spryker/cart/src/Spryker/Zed/Cart/Business/CartFacade.php', "<?php\n");
write_file($project . '/vendor/spryker/cart/src/Spryker/Zed/Cart/Persistence/Propel/Schema/spy_cart.schema.xml', "<database/>\n");
write_file($project . '/vendor/spryker/cart/package.json', json_encode(['dependencies' => ['left-pad' => '1.0.0']]));
git($project, 'init', '-q');
git($project, 'add', '-A');
git($project, 'commit', '-q', '-m', 'base');
write_file($project . '/docker/sdk', "#!/bin/sh\necho \"\$*\" >> \"\$STUB_DIR/calls.log\"\ncase \"\$*\" in\n  *'console list --raw'*) cat \"\$STUB_DIR/console-list.txt\"; exit 0;;\n  *'ls -d /data/node_modules'*) exit \$(cat \"\$STUB_DIR/node.exit\" 2>/dev/null || echo 0);;\nesac\nif [ -n \"\$STUB_FAIL\" ] && echo \"\$*\" | grep -q -- \"\$STUB_FAIL\"; then\n  i=1; while [ \$i -le 30 ]; do echo \"line \$i\"; i=\$((i+1)); done\n  exit 3\nfi\necho \"ran \$*\"\n");
chmod($project . '/docker/sdk', 0755);
write_file($stub . '/console-list.txt', "data:import:product-abstract   Imports\ndata:import:category   Imports\ntranslator:generate-cache   Builds\n");
$env = ['STUB_DIR' => $stub];

// --- one row at a time ---
$rows = [
    'transfer' => [['src/Pyz/Shared/Cart/Transfer/cart.transfer.xml'], ['console transfer:generate']],
    'schema' => [['src/Pyz/Zed/Cart/Persistence/Propel/Schema/spy_cart.schema.xml'], ['console propel:install']],
    'php-layout and class resolver (added php)' => [['A:src/Pyz/Zed/Cart/Business/Model/Writer.php'], ['cli composer dumpautoload --apcu', 'console cache:class-resolver:build']],
    'class resolver on a changed php (the wider condition)' => [['src/Pyz/Zed/Cart/Business/CartFacade.php'], ['console cache:class-resolver:build']],
    'new module' => [['A:src/Pyz/Zed/Wishlist/Business/WishlistFacade.php'], ['cli composer dumpautoload --apcu', 'console dev:ide-auto-completion:generate', 'console cache:class-resolver:build', 'cli "vendor/bin/phpstan clear-result-cache"']],
    'dependency provider' => [['src/Pyz/Zed/Cart/CartDependencyProvider.php'], ['console cache:empty-all', 'console cache:class-resolver:build']],
    'config' => [['config/Shared/config_default.php'], ['console cache:empty-all']],
    'yves twig edited' => [['src/Pyz/Yves/CartPage/Theme/default/views/cart/cart.twig'], ['cli "rm -rf src/Generated/Yves/Twig/codeBucket"']],
    'yves twig added' => [['A:src/Pyz/Yves/CartPage/Theme/default/views/cart/new.twig'], ['cli "rm -rf src/Generated/Yves/Twig/codeBucket"', 'console twig:cache:warmer']],
    'yves assets' => [['src/Pyz/Yves/CartPage/Theme/default/components/molecules/cart-item/cart-item.scss'], ['console frontend:yves:build']],
    'zed assets' => [['src/Pyz/Zed/ProductOffer/assets/Zed/js/main.js'], ['console frontend:zed:build']],
    'merchant portal components' => [['src/Pyz/Zed/MerchantPortalGui/Presentation/Components/src/app/a.ts'], ['console frontend:mp:build']],
    'zed twig added' => [['A:src/Pyz/Zed/Cart/Presentation/Index/list.twig'], ['console twig:cache:warmer']],
    'zed twig edited needs nothing' => [['src/Pyz/Zed/Cart/Presentation/Index/index.twig'], []],
    'navigation' => [['config/Zed/navigation.xml'], ['console navigation:cache:remove', 'console navigation:build-cache']],
    'rest api route plugin' => [['src/Pyz/Glue/CartsRestApi/Plugin/CartsResourceRoutePlugin.php'], ['console rest-api:remove-validation-cache', 'console rest-api:build-request-validation-cache', 'console cache:class-resolver:build']],
    'api platform resource' => [['src/Pyz/Glue/CartsRestApi/resources/api/storefront/carts.resource.yml'], ['cli "vendor/bin/glue api:generate"', 'cli "GLUE_APPLICATION=GLUE_BACKEND vendor/bin/glue api:generate"', 'cli "vendor/bin/glue cache:clear"', 'cli "GLUE_APPLICATION=GLUE_BACKEND vendor/bin/glue cache:clear"', 'cli "vendor/bin/glue cache:warmup"', 'cli "GLUE_APPLICATION=GLUE_BACKEND vendor/bin/glue cache:warmup"']],
    'oms' => [['config/Zed/oms/Demo01.xml'], ['console oms:process-cache:warm-up']],
    'glossary' => [['data/import/common/common/glossary.csv'], ['console data:import:glossary']],
    'translator' => [['src/Pyz/Zed/Translator/data/Cart/en_US.csv'], ['console translator:generate-cache']],
    'search schema' => [['src/Pyz/Shared/Search/Schema/page.json'], ['console search:source-map:remove', 'console search:setup:source-map', 'console search:setup:sources']],
];
foreach ($rows as $name => [$files, $expected]) {
    $files = array_map(static fn (string $f): string => preg_match('/^[AMDR]:/', $f) ? $f : 'M:' . $f, $files);
    [, $out] = refresh($project, array_merge(['plan'], $files, ['--print-only']), $env);
    check("plan row: $name", plan_commands($out) === $expected);
}
check('plan: the API Platform row skips a Glue application that does not exist', str_contains(refresh($project, ['plan', 'M:src/Pyz/Glue/CartsRestApi/resources/api/storefront/carts.resource.yml', '--print-only'], $env)[1], 'GLUE_APPLICATION=GLUE_STOREFRONT steps: skipped (no such Glue application'));
check('plan: a *RestApi dependency provider triggers the API Platform row', in_array('cli "vendor/bin/glue api:generate"', plan_commands(refresh($project, ['plan', 'M:src/Pyz/Glue/CartsRestApi/CartsRestApiDependencyProvider.php', '--print-only'], $env)[1]), true));
[, $overrideOut] = refresh($project, ['plan', 'M:src/Pyz/Zed/Cart/Business/CartFacade.php', '--print-only'], $env);
check('plan: an override of a vendor class is named', str_contains($overrideOut, 'overrides Spryker\\Zed\\Cart\\Business\\CartFacade'));

// --- router: a decision until --router names the applications ---
[, $routerOut] = refresh($project, ['plan', 'M:src/Pyz/Zed/Cart/Communication/Plugin/Router/CartRouteProviderPlugin.php', '--print-only'], $env);
check('plan row: router -> a decision without --router', plan_commands($routerOut) === ['console cache:class-resolver:build'] && str_contains($routerOut, "Needs a decision:\n  - router cache warm-up for src/Pyz/Zed/Cart/Communication/Plugin/Router/CartRouteProviderPlugin.php"));
[, $routerOut] = refresh($project, ['plan', 'M:src/Pyz/Zed/Cart/Communication/Plugin/Router/CartRouteProviderPlugin.php', '--router', 'backoffice,merchant-portal', '--print-only'], $env);
check('plan row: router with --router', array_slice(plan_commands($routerOut), 0, 2) === ['console router:cache:warm-up:backoffice', 'console router:cache:warm-up:merchant-portal']);
file_put_contents($project . '/src/Pyz/Zed/Cart/Communication/Controller/IndexController.php', "<?php\nclass IndexController {\n    public function indexAction() {}\n    public function listAction() {}\n}\n");
check('plan row: a new Zed action is a router decision', str_contains(refresh($project, ['plan', 'src/Pyz/Zed/Cart/Communication/Controller/IndexController.php', '--print-only'], $env)[1], 'router cache warm-up for src/Pyz/Zed/Cart/Communication/Controller/IndexController.php'));
git($project, 'checkout', '-q', '--', 'src/Pyz/Zed/Cart/Communication/Controller/IndexController.php');
check('plan: a controller body edit is no router decision', !str_contains(refresh($project, ['plan', 'M:src/Pyz/Zed/Cart/Communication/Controller/IndexController.php', '--print-only'], $env)[1], 'router cache'));

// --- data import: rows added only, from the diff; DMS drain per store ---
file_put_contents($project . '/data/import/common/common/product_abstract.csv', "abstract_sku,name\n001,A\n002,B\n");
file_put_contents($project . '/data/import/common/common/product_price.csv', "abstract_sku,price\n001,200\n");
file_put_contents($project . '/data/import/common/common/category.csv', "category_key,name\nc1,One\n");
[, $importOut] = refresh($project, ['plan', 'data/import/common/common/product_abstract.csv', 'data/import/common/common/product_price.csv', 'data/import/common/common/category.csv', '--print-only'], $env);
check('plan row: data import, rows added only -> data:import:<entity>', plan_commands($importOut) === ['console data:import:product-abstract'] && str_contains($importOut, '1 row(s) added only'));
check('plan row: data import, a changed price value -> manual', str_contains($importOut, 'data/import/common/common/product_price.csv: a changed value on product-price'));
check('plan row: data import, a removed row -> manual', str_contains($importOut, 'data/import/common/common/category.csv: 1 row(s) removed'));
file_put_contents($project . '/deploy.dev.yml', file_get_contents($project . '/deploy.dev.yml') . "image:\n    environment:\n        SPRYKER_DYNAMIC_STORE_MODE: true\n");
[, $dmsOut] = refresh($project, ['plan', 'data/import/common/common/product_abstract.csv', '--print-only'], $env);
check('plan row: DMS drain -> a store decision without --store', str_contains($dmsOut, 'the queue drain after the import runs once per store') && str_contains($dmsOut, 'rabbitmqctl list_queues'));
[, $dmsOut] = refresh($project, ['plan', 'data/import/common/common/product_abstract.csv', '--store', 'DE', '--store', 'AT', '--print-only'], $env);
check('plan row: DMS drain once per store, after the import', plan_commands($dmsOut) === ['console data:import:product-abstract', 'cli "APPLICATION_STORE=AT vendor/bin/console queue:worker:start --stop-when-empty"', 'cli "APPLICATION_STORE=DE vendor/bin/console queue:worker:start --stop-when-empty"']);
git($project, 'checkout', '-q', '--', 'data', 'deploy.dev.yml');

// --- manual rows and symptoms ---
[, $manualOut] = refresh($project, ['plan', 'M:src/Pyz/Zed/Cart/Communication/Plugin/Publisher/CartPublisherPlugin.php', 'M:src/Pyz/Zed/Cart/CartDependencyProvider.php', '--print-only'], $env);
check('plan row: publisher -> manual queue worker restart', str_contains($manualOut, 'queue workers need a manual restart'));
check('plan row: wiring -> manual smoke check', str_contains($manualOut, 'run the wiring smoke check'));
check('plan row: symptom zed-twig-stale', plan_commands(refresh($project, ['plan', '--symptom', 'zed-twig-stale', '--print-only'], $env)[1]) === ['cli "rm -f src/Generated/Zed/Twig/codeBucket/.pathCache"', 'console twig:cache:warmer']);
check('plan row: symptom yves-twig-stale', plan_commands(refresh($project, ['plan', '--symptom', 'yves-twig-stale', '--print-only'], $env)[1]) === ['cli "rm -rf src/Generated/Yves/Twig/codeBucket"']);

// --- composer.lock: the chain a changed module needs ---
file_put_contents($project . '/composer.lock', $lock(['spryker/kernel' => '3.70.0', 'spryker/cart' => '7.1.0']));
[, $lockOut] = refresh($project, ['plan', 'composer.lock', '--print-only'], $env);
check('plan row: composer.lock -> the module chain in order', plan_commands($lockOut) === ['console transfer:generate', 'console propel:install', 'console transfer:entity:generate', 'cli composer dump-autoload', 'console dev:ide-auto-completion:generate', 'console cache:class-resolver:build', 'cli "vendor/bin/phpstan clear-result-cache"']);
check('plan row: composer.lock -> schema note and package.json decision', str_contains($lockOut, 'it changes the database schema') && str_contains($lockOut, 'frontend:project:install-dependencies: run it when a package.json of a changed module changed (spryker/cart'));
[, $frontendOut] = refresh($project, ['plan', 'composer.lock', 'M:vendor/spryker/cart/package.json', 'M:src/Pyz/Yves/CartPage/Theme/default/components/molecules/cart-item/cart-item.scss'], $env);
check('plan row: a changed vendor package.json -> install-dependencies before the build, dependency check announced', array_slice(plan_commands($frontendOut), -3) === ['console frontend:project:install-dependencies', 'console frontend:yves:build', 'cli "vendor/bin/phpstan clear-result-cache"'] && str_contains($frontendOut, 'the run checks the dependencies of spryker/cart'));

// --- order with several rows at once, and renamed and deleted files, from git ---
git($project, 'checkout', '-q', '--', 'composer.lock');
file_put_contents($project . '/src/Pyz/Shared/Cart/Transfer/cart.transfer.xml', "<transfers><x/></transfers>\n");
file_put_contents($project . '/config/Zed/navigation.xml', "<config><x/></config>\n");
file_put_contents($project . '/src/Pyz/Yves/CartPage/Theme/default/components/molecules/cart-item/cart-item.scss', "b {}\n");
file_put_contents($project . '/data/import/common/common/glossary.csv', "key,translation,locale\na,A,en_US\nb,B,en_US\n");
git($project, 'mv', 'src/Pyz/Zed/Cart/Business/CartFacade.php', 'src/Pyz/Zed/Cart/Business/CartFacadeRenamed.php');
git($project, 'rm', '-q', 'src/Pyz/Zed/Old/Business/OldFacade.php');
write_file($project . '/src/Pyz/Zed/Wishlist/Business/WishlistFacade.php', "<?php\n");
[$gitCode, $gitOut] = refresh($project, ['plan'], $env);
check('plan from git: exit 0 and plan.json written', $gitCode === 0 && is_file($project . '/.ai-dev/refresher/plan.json') && str_contains($gitOut, 'Plan: .ai-dev/refresher/plan.json'));
check('plan from git: all rows at once, in the group order, each command once', plan_commands($gitOut) === [
    'console navigation:cache:remove',
    'console transfer:generate',
    'cli composer dumpautoload --apcu',
    'console dev:ide-auto-completion:generate',
    'console navigation:build-cache',
    'console cache:class-resolver:build',
    'console data:import:glossary',
    'console frontend:yves:build',
    'cli "vendor/bin/phpstan clear-result-cache"',
]);
$plan = json_decode((string)file_get_contents($project . '/.ai-dev/refresher/plan.json'), true);
$statuses = array_column($plan['files'], 'status', 'path');
check('plan from git: a renamed file (R, old path kept) and a deleted file (D)', ($statuses['src/Pyz/Zed/Cart/Business/CartFacadeRenamed.php'] ?? '') === 'R' && ($statuses['src/Pyz/Zed/Old/Business/OldFacade.php'] ?? '') === 'D' && ($statuses['src/Pyz/Zed/Wishlist/Business/WishlistFacade.php'] ?? '') === 'A');
check('plan from git: the renamed file names its old path and the vendor class it overrides', str_contains($gitOut, 'src/Pyz/Zed/Cart/Business/CartFacade.php -> src/Pyz/Zed/Cart/Business/CartFacadeRenamed.php (R) [php-layout]') && str_contains(implode("\n", array_merge(...array_column($plan['steps'], 'reasons'))), 'overrides Spryker\\Zed\\Cart\\Business\\CartFacade'));
$groups = array_map(static fn (array $s): int => RF_COMMANDS[$s['key']]['group'], $plan['steps']);
$sorted = $groups;
sort($sorted);
check('plan from git: steps follow the group order', $groups === $sorted && count($plan['steps']) === count(array_unique(array_map(static fn (array $s): string => $s['key'] . '|' . $s['param'], $plan['steps']))));
check('plan from git: the deleted php file leads to dumpautoload', in_array('src/Pyz/Zed/Old/Business/OldFacade.php (D) [php-layout]', $plan['steps'][array_search('composer:dumpautoload-apcu', array_column($plan['steps'], 'key'), true)]['reasons'], true));
check('plan from git: frontend:yves:build after the data import, class resolver after the cache builds', array_search('frontend:yves:build', array_column($plan['steps'], 'key'), true) > array_search('data:import:glossary', array_column($plan['steps'], 'key'), true) && array_search('cache:class-resolver:build', array_column($plan['steps'], 'key'), true) > array_search('navigation:build-cache', array_column($plan['steps'], 'key'), true));

// --- Glue applications ---
$context = rf_context($project, 'HEAD', 'deploy.dev.yml', true);
$glue = rf_glue_apps($context);
check('glue lookup: index.php and a deploy application -> resolved', $glue['apps'] === ['Glue' => '', 'GlueBackend' => 'GLUE_BACKEND']);
write_file($project . '/public/GlueStorefront/index.php', "<?php\n");
$glue = rf_glue_apps(rf_context($project, 'HEAD', 'deploy.dev.yml', true));
check('glue lookup: index.php without an entry point in the deploy file -> a decision, not a run', !isset($glue['apps']['GlueStorefront']) && str_contains(implode("\n", $glue['decisions']), 'Glue application GlueStorefront'));
file_put_contents($project . '/deploy.storefront.yml', "applications:\n    x:\n        entry-point: GlueStorefront\n");
check('glue lookup: entry-point: GlueStorefront in the deploy file given -> resolved', isset(rf_glue_apps(rf_context($project, 'HEAD', 'deploy.storefront.yml', true))['apps']['GlueStorefront']));
file_put_contents($project . '/deploy.anchors.yml', "x-glue: &glue\n    application: glue-storefront\ngroups:\n    EU:\n        applications:\n            glue_storefront_eu:\n                <<: *glue\n");
$anchorsPlan = rf_plan(rf_context($project, 'HEAD', 'deploy.anchors.yml', true), [], ['store' => [], 'router' => [], 'symptom' => []]);
check('deploy file with anchors or merge keys and no symfony/yaml -> a decision, not a silent plan', str_contains(implode("\n", $anchorsPlan['decisions']), 'deploy.anchors.yml line 1 uses an anchor (&name)'));
unlink($project . '/public/GlueStorefront/index.php');
check('glue lookup: GLUE_STOREFRONT without index.php -> skipped', str_contains(implode("\n", rf_glue_apps($context)['skipped']), 'skipped (no such Glue application: no public/GlueStorefront/index.php)'));

// --- run ---
[, $gitOut] = refresh($project, ['plan'], $env);
@unlink($stub . '/calls.log');
[$runCode, $runOut] = refresh($project, ['run'], $env);
$runLines = array_values(array_filter(explode("\n", rtrim($runOut)), static fn (string $l): bool => preg_match('/^\s*\d+  /', $l) === 1));
check('run: one line per command with number, command, exit code and log file', $runCode === 0 && count($runLines) === count($plan['steps']) && preg_match('#^ 1  docker/sdk console navigation:cache:remove  exit 0  \.ai-dev/refresher/logs/01-navigation-cache-remove\.log$#', $runLines[0]) === 1);
check('run: the full output is in the log file', str_contains((string)file_get_contents($project . '/.ai-dev/refresher/logs/01-navigation-cache-remove.log'), 'ran console navigation:cache:remove'));
check('run: each command as its own process, in plan order', (static function () use ($stub, $plan): bool {
    $calls = array_values(array_filter(explode("\n", trim((string)file_get_contents($stub . '/calls.log'))), static fn (string $c): bool => !str_contains($c, 'ls -d')));

    return count($calls) === count($plan['steps']) && $calls[0] === 'console navigation:cache:remove';
})());
[$failCode, $failOut] = refresh($project, ['run'], $env + ['STUB_FAIL' => 'transfer:generate']);
check('run: stops at the first non-zero exit', $failCode === 1 && str_contains($failOut, 'docker/sdk console transfer:generate  exit 3') && !str_contains($failOut, 'dumpautoload') && str_contains($failOut, 'Stopped at step 2. Steps 3-9 did not run (resume with --from 2).'));
check('run: prints the last 20 lines of the failing log', str_contains($failOut, "line 30\nexit 3\n") && str_contains($failOut, "line 12\n") && !str_contains($failOut, "line 11\n"));
[$resumeCode, $resumeOut] = refresh($project, ['run', '--from', '4'], $env);
check('run --from resumes at a step, after the plan line', $resumeCode === 0 && !str_contains($resumeOut, 'transfer:generate') && preg_match('/^Plan: created \S+, \d+ file\(s\), 9 step\(s\); this run: steps 4-9\.\n 4  docker\/sdk/', $resumeOut) === 1);
file_put_contents($stub . '/node.exit', '2');
[, $nodeOut] = refresh($project, ['run'], $env);
check('run: no /data/node_modules -> docker/sdk up --assets instead of frontend:yves:build', str_contains($nodeOut, 'docker/sdk up --assets  exit 0'));
unlink($stub . '/node.exit');

$tampered = json_decode((string)file_get_contents($project . '/.ai-dev/refresher/plan.json'), true);
$tampered['steps'][] = ['key' => 'reset', 'param' => null, 'reasons' => ['x']];
file_put_contents($project . '/.ai-dev/refresher/plan.json', json_encode($tampered));
@unlink($stub . '/calls.log');
[$outsideCode, $outsideOut] = refresh($project, ['run'], $env);
check('run: a command outside the mapping -> exit 2, nothing runs', $outsideCode === 2 && str_contains($outsideOut, 'is not a command of the mapping') && !is_file($stub . '/calls.log'));
$tampered['steps'] = [['key' => 'data:import', 'param' => 'x; rm -rf /', 'reasons' => ['x']]];
file_put_contents($project . '/.ai-dev/refresher/plan.json', json_encode($tampered));
check('run: a parameter that is not a plain name -> exit 2', refresh($project, ['run'], $env)[0] === 2 && !is_file($stub . '/calls.log'));
$tampered['steps'] = [['key' => 'data:import', 'param' => 'stock', 'reasons' => ['x']]];
file_put_contents($project . '/.ai-dev/refresher/plan.json', json_encode($tampered));
[$listCode, $listOut] = refresh($project, ['run'], $env);
check('run: an importer missing from console list does not run', $listCode === 1 && str_contains($listOut, '`data:import:stock` is not in docker/sdk console list'));

[$tableCode, $tableOut] = refresh($project, ['table']);
check('table: every row of the mapping, and the run order', $tableCode === 0 && substr_count($tableOut, "\n| ") === count(RF_ROWS) && str_contains($tableOut, '6 class resolver build (cache:class-resolver:build)'));
check('plan: no file and nothing changed -> exit 2', refresh($tmp, ['plan'], $env)[0] === 2);
check('plan: unknown option -> exit 2', refresh($project, ['plan', '--everything'], $env)[0] === 2);

[$typoCode, $typoOut] = refresh($project, ['plan', 'M:src/Pyz/Zed/Cart/Communication/Controller/IndexController.php', 'src/Pyz/Zed/Cart/Typo.php', '--print-only'], $env);
check('plan: a path that is neither on disk nor in the base commit -> "Not found", no command for it', $typoCode === 0 && str_contains($typoOut, 'Not found') && str_contains($typoOut, '  - src/Pyz/Zed/Cart/Typo.php') && !str_contains($typoOut, 'Typo.php (D)'));
[$onlyTypoCode, $onlyTypoOut] = refresh($project, ['plan', 'src/Pyz/Zed/Cart/Typo.php', '--print-only'], $env);
check('plan: only paths that never existed -> exit 2, the paths named', $onlyTypoCode === 2 && str_contains($onlyTypoOut, 'Not found') && str_contains($onlyTypoOut, 'src/Pyz/Zed/Cart/Typo.php'));
check('plan: D:<path> names a deleted file whatever git holds', str_contains(refresh($project, ['plan', 'D:src/Pyz/Zed/Cart/Typo.php', '--print-only'], $env)[1], 'Typo.php (D)'));

refresh($project, ['plan', 'A:src/Pyz/Zed/Cart/Communication/Plugin/Router/CartRouteProviderPlugin.php', 'M:src/Pyz/Zed/Cart/CartDependencyProvider.php'], $env);
[$openCode, $openOut] = refresh($project, ['run'], $env);
check('run: ends with the open decisions and the manual items of the plan', $openCode === 0 && str_contains($openOut, "Open decisions (their steps did not run") && str_contains($openOut, 'router cache warm-up') && str_contains($openOut, 'Manual items: 1 (the skill does not run these') && !str_contains($openOut, 'wiring smoke check') && strpos($openOut, 'Open decisions') > strpos($openOut, 'step(s) exited 0'));
refresh($project, ['plan', 'M:config/Zed/navigation.xml'], $env);
check('run: no open decision and no manual item -> no such lines', !str_contains(refresh($project, ['run'], $env)[1], 'Open decisions') && !str_contains(refresh($project, ['run'], $env)[1], 'Manual items'));

// --- review fixes: a clean base, then one case at a time ---
@unlink($project . '/.ai-dev/refresher/plan.json');
git($project, 'add', '-A');
git($project, 'commit', '-q', '-m', 'base 2');
$commands = static fn (array $args): array => plan_commands(refresh($project, array_merge(['plan'], $args, ['--print-only']), $env)[1]);

// a path without a status after a commit: no guess, a decision
write_file($project . '/src/Pyz/Zed/Review/Business/ReviewFacade.php', "<?php\n");
write_file($project . '/src/Pyz/Zed/Review/Communication/Controller/IndexController.php', "<?php\n");
write_file($project . '/src/Pyz/Zed/Review/Presentation/Index/index.twig', "{{ x }}\n");
git($project, 'add', '-A');
git($project, 'commit', '-q', '-m', 'new module');
$reviewFiles = ['src/Pyz/Zed/Review/Business/ReviewFacade.php', 'src/Pyz/Zed/Review/Communication/Controller/IndexController.php', 'src/Pyz/Zed/Review/Presentation/Index/index.twig'];
[$unknownCode, $unknownOut] = refresh($project, array_merge(['plan'], $reviewFiles, ['--print-only']), $env);
check('plan: a bare path in the base commit without a diff -> "status unknown" decision, no step', $unknownCode === 0 && plan_commands($unknownOut) === [] && str_contains($unknownOut, "Needs a decision:\n  - status unknown for src/Pyz/Zed/Review/Business/ReviewFacade.php, ") && str_contains($unknownOut, 'A:<path>, M:<path>, D:<path>') && !str_contains($unknownOut, 'No row matches'));
$reviewSteps = $commands(array_merge($reviewFiles, ['--base', 'HEAD~1']));
check('plan: the same paths with --base before the commit -> the new-module chain', in_array('cli composer dumpautoload --apcu', $reviewSteps, true) && in_array('console dev:ide-auto-completion:generate', $reviewSteps, true) && in_array('console twig:cache:warmer', $reviewSteps, true) && in_array('cli "vendor/bin/phpstan clear-result-cache"', $reviewSteps, true));
$reviewSteps = $commands(array_map(static fn (string $f): string => 'A:' . $f, $reviewFiles));
check('plan: the same paths named A: after the commit -> the new-module chain', in_array('console dev:ide-auto-completion:generate', $reviewSteps, true) && in_array('console twig:cache:warmer', $reviewSteps, true));
file_put_contents($project . '/composer.lock', $lock(['spryker/kernel' => '3.71.0', 'spryker/cart' => '7.0.0']));
git($project, 'commit', '-q', '-am', 'lock');
[, $lockOut] = refresh($project, ['plan', 'M:composer.lock', '--print-only'], $env);
check('plan: composer.lock with 0 package(s) changed -> a decision, no step', plan_commands($lockOut) === [] && str_contains($lockOut, 'composer.lock: 0 package(s) changed against HEAD'));
check('plan: composer.lock with --base before the commit -> the module chain', in_array('console transfer:generate', $commands(['M:composer.lock', '--base', 'HEAD~1']), true));

// a missing deploy file, and the API Platform row without a resolved Glue application
rename($project . '/deploy.dev.yml', $project . '/deploy.moved.yml');
[, $noDeployOut] = refresh($project, ['plan', 'M:src/Pyz/Glue/CartsRestApi/resources/api/storefront/carts.resource.yml', 'A:data/import/common/common/stock.csv', '--print-only'], $env);
check('plan: no deploy file -> a decision for the Glue and DMS steps', plan_commands($noDeployOut) === ['console data:import:stock'] && str_contains($noDeployOut, 'deploy file deploy.dev.yml not found') && str_contains($noDeployOut, '--deploy-file <the active deploy file>'));
rename($project . '/deploy.moved.yml', $project . '/deploy.dev.yml');
[, $noGlueOut] = refresh($project, ['plan', 'M:src/Pyz/Glue/CartsRestApi/resources/api/storefront/carts.resource.yml', '--deploy-file', 'deploy.storefront.yml', '--print-only'], $env);
check('plan: API Platform row and no resolved Glue application -> a decision', plan_commands($noGlueOut) === [] && str_contains($noGlueOut, 'no Glue application is resolved') && str_contains($noGlueOut, 'Glue application GlueBackend: public/GlueBackend/index.php exists'));

// --symptom alone does not read git
file_put_contents($project . '/src/Pyz/Zed/Cart/Persistence/Propel/Schema/spy_cart.schema.xml', "<database><table/></database>\n");
check('plan: --symptom without files ignores the changed tree', $commands(['--symptom', 'zed-twig-stale']) === ['cli "rm -f src/Generated/Zed/Twig/codeBucket/.pathCache"', 'console twig:cache:warmer']);
git($project, 'checkout', '-q', '--', 'src');

// the order of several data imports
$importArgs = ['A:data/import/common/common/stock.csv', 'A:data/import/common/common/product_stock.csv', 'A:data/import/common/common/category.csv', 'A:data/import/common/common/category_template.csv'];
check('plan: several imports without a data import config keep the order of the files given', $commands($importArgs) === ['console data:import:stock', 'console data:import:product-stock', 'console data:import:category', 'console data:import:category-template']);
write_file($project . '/data/import/local/full_EU.yml', "version: 0\nactions:\n  - data_entity: category-template\n    source: a.csv\n  - data_entity: category\n    source: b.csv\n  - data_entity: stock\n    source: c.csv\n  - data_entity: product-stock\n    source: d.csv\n");
[, $orderOut] = refresh($project, array_merge(['plan'], array_reverse($importArgs), ['--print-only']), $env);
check('plan: several imports follow the data import config', plan_commands($orderOut) === ['console data:import:category-template', 'console data:import:category', 'console data:import:stock', 'console data:import:product-stock'] && str_contains($orderOut, 'Data import order: data/import/local/full_EU.yml.'));
unlink($project . '/data/import/local/full_EU.yml');

// CSV names, deleted CSV and Twig files, absolute paths
[, $entityOut] = refresh($project, ['plan', 'A:data/import/common/common/product_price.DE.csv', '--print-only'], $env);
check('plan: a CSV name that is no plain entity -> a decision, no step', plan_commands($entityOut) === [] && str_contains($entityOut, 'gives no plain data import entity ("product-price.de")'));
[, $deletedCsvOut] = refresh($project, ['plan', 'D:data/import/common/common/category.csv', '--print-only'], $env);
check('plan: a deleted CSV -> manual, no step', plan_commands($deletedCsvOut) === [] && str_contains($deletedCsvOut, 'category.csv was deleted: no importer deletes rows'));
check('plan: a deleted Yves Twig file -> cache remove and warmer', $commands(['D:src/Pyz/Yves/CartPage/Theme/default/views/cart/cart.twig']) === ['cli "rm -rf src/Generated/Yves/Twig/codeBucket"', 'console twig:cache:warmer']);
check('plan: a renamed Zed Twig file -> warmer', $commands(['R:src/Pyz/Zed/Cart/Presentation/Index/index.twig->src/Pyz/Zed/Cart/Presentation/Index/main.twig']) === ['console twig:cache:warmer']);
file_put_contents($project . '/config/Zed/navigation.xml', "<config><y/></config>\n");
[, $absoluteOut] = refresh($project, ['plan', $project . '/config/Zed/navigation.xml', 'M:' . $project . '/config/Zed/oms/Demo01.xml', '--print-only'], $env);
check('plan: an absolute path under the project root -> a relative path', plan_commands($absoluteOut) === ['console navigation:cache:remove', 'console navigation:build-cache', 'console oms:process-cache:warm-up'] && str_contains($absoluteOut, '— config/Zed/navigation.xml (M) [navigation]'));
git($project, 'checkout', '-q', '--', 'config');

// rows the old text had
check('plan row: RabbitMq client config -> manual queue worker restart', str_contains(refresh($project, ['plan', 'M:src/Pyz/Client/RabbitMq/RabbitMqConfig.php', '--print-only'], $env)[1], 'queue workers need a manual restart'));
check('plan row: GlueApplicationDependencyProvider -> REST API row', array_slice($commands(['M:src/Pyz/Glue/GlueApplication/GlueApplicationDependencyProvider.php']), 0, 3) === ['console cache:empty-all', 'console rest-api:remove-validation-cache', 'console rest-api:build-request-validation-cache']);
check('plan row: config/Zed/navigation-secondary.xml -> navigation row', $commands(['M:config/Zed/navigation-secondary.xml']) === ['console navigation:cache:remove', 'console navigation:build-cache']);
check('plan row: a rename into a new module directory -> new-module row', in_array('console dev:ide-auto-completion:generate', $commands(['R:src/Pyz/Zed/Cart/Business/Model/Reader.php->src/Pyz/Zed/CartReader/Business/Reader.php']), true));
write_file($project . '/vendor/spryker-shop/cart-page/src/SprykerShop/Yves/CartPage/Theme/default/package.json', json_encode(['dependencies' => ['right-pad' => '1.0.0']]));
file_put_contents($project . '/composer.lock', $lock(['spryker/kernel' => '3.71.0', 'spryker/cart' => '7.0.0', 'spryker-shop/cart-page' => '1.0.0']));
git($project, 'add', '-A');
git($project, 'commit', '-q', '-m', 'cart page');
file_put_contents($project . '/composer.lock', $lock(['spryker/kernel' => '3.71.0', 'spryker/cart' => '7.0.0', 'spryker-shop/cart-page' => '1.1.0']));
check('plan row: composer.lock -> a package.json below the package root counts', str_contains(refresh($project, ['plan', 'composer.lock', '--print-only'], $env)[1], 'changed (spryker-shop/cart-page have one)'));
[, $nestedOut] = refresh($project, ['plan', 'composer.lock', 'M:vendor/spryker-shop/cart-page/src/SprykerShop/Yves/CartPage/Theme/default/package.json', 'M:src/Pyz/Yves/CartPage/Theme/default/components/molecules/cart-item/cart-item.scss', '--print-only'], $env);
check('plan row: a nested vendor package.json -> install-dependencies and the dependency check of its package', in_array('console frontend:project:install-dependencies', plan_commands($nestedOut), true) && str_contains($nestedOut, 'checks the dependencies of spryker-shop/cart-page in /data/node_modules'));
git($project, 'checkout', '-q', '--', 'composer.lock');

// bounded lists
$publishers = array_map(static fn (string $m): string => "M:src/Pyz/Zed/$m/Communication/Plugin/Publisher/{$m}PublisherPlugin.php", ['Cart', 'Wishlist', 'Review']);
[, $groupOut] = refresh($project, array_merge(['plan'], $publishers, ['--print-only']), $env);
check('plan: the same manual text for several files -> one line with (+N files)', substr_count($groupOut, 'queue workers need a manual restart') === 1 && str_contains($groupOut, 'CartPublisherPlugin.php (+2 files)'));
[, $capOut] = refresh($project, array_merge(['plan'], array_map(static fn (int $i): string => "M:docs/page$i.md", range(1, 12)), ['--print-only']), $env);
check('plan: "No row matches" holds at most 10 files, then (+N more)', substr_count($capOut, '  - docs/page') === 10 && str_contains($capOut, '  - (+2 more)'));

// another project namespace
file_put_contents($project . '/config/Shared/config_default.php', "<?php\n\$config[KernelConstants::PROJECT_NAMESPACES] = ['Acme'];\n");
write_file($project . '/src/Acme/Zed/Cart/Business/CartFacade.php', "<?php\n");
check('plan: another project namespace -> its rows match', $commands(['A:src/Acme/Zed/Loyalty/Business/LoyaltyFacade.php']) === ['cli composer dumpautoload --apcu', 'console dev:ide-auto-completion:generate', 'console cache:class-resolver:build', 'cli "vendor/bin/phpstan clear-result-cache"']);
check('plan: a namespace that is not a project namespace -> no php row', $commands(['M:src/Pyz/Zed/Cart/Business/CartFacade.php']) === []);
git($project, 'checkout', '-q', '--', 'config');
@unlink($project . '/src/Acme/Zed/Cart/Business/CartFacade.php');

// run: --only, --skip, --from range, an unfinished log
refresh($project, ['plan', 'M:config/Zed/navigation.xml'], $env);
@unlink($stub . '/calls.log');
[$skipCode, $skipOut] = refresh($project, ['run', '--skip', '1'], $env);
check('run --skip: the skipped step does not run', $skipCode === 0 && trim((string)file_get_contents($stub . '/calls.log')) === 'console navigation:build-cache' && str_contains($skipOut, 'this run: steps 2.') && str_contains($skipOut, 'All 1 step(s) exited 0.'));
@unlink($stub . '/calls.log');
refresh($project, ['run', '--only', '1'], $env);
check('run --only: only the named step runs', trim((string)file_get_contents($stub . '/calls.log')) === 'console navigation:cache:remove');
[$rangeCode, $rangeOut] = refresh($project, ['run', '--from', '9'], $env);
check('run --from out of range -> exit 2', $rangeCode === 2 && str_contains($rangeOut, '--from takes one step number between 1 and 2'));
check('run --only out of range -> exit 2', refresh($project, ['run', '--only', '1,5'], $env)[0] === 2);
file_put_contents($project . '/.ai-dev/refresher/logs/02-navigation-build-cache.log', "started\n");
[$staleCode, $staleOut] = refresh($project, ['run', '--from', '2'], $env);
check('run --from: the log of the step has no exit line -> exit 2', $staleCode === 2 && str_contains($staleOut, 'has no exit line: the step may still run'));
check('run --from --force: runs the step', refresh($project, ['run', '--from', '2', '--force'], $env)[0] === 0);
check('run --from: a finished log -> runs the step', refresh($project, ['run', '--from', '2'], $env)[0] === 0);

$cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($cleanup as $entry) {
    $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($tmp);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
