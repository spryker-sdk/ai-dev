<?php

declare(strict_types=1);

/** Zero-dependency tests for the spryker-feature-install scripts. Run: php scripts/feature-install.test.php */

require __DIR__ . '/parse-integration-guide.php';
require __DIR__ . '/check-checklist.php';
require __DIR__ . '/check-lock-diff.php';
require __DIR__ . '/check-feature-installed.php';
require __DIR__ . '/diff-release-steps.php';
require __DIR__ . '/find-feature-change.php';

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

/**
 * @return array{0: int, 1: string} exit code, combined output
 */
function run_script(string $script, array $args, string $projectDir): array
{
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/' . $script], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectDir,
        array_merge(getenv(), ['SPRYKER_PROJECT_ROOT' => $projectDir])
    );
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string)$output];
}

function write_file(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function temp_dir(string $name): string
{
    $dir = sys_get_temp_dir() . '/fi-test-' . $name . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    return $dir;
}

function parse_fixture(string $name): array
{
    $errors = [];

    return pig_build_checklist([__DIR__ . '/fixtures/guides/' . $name . '.txt'], null, '202610.0', null, $errors);
}

/**
 * @return list<array<string, mixed>>
 */
function rows_where(array $checklist, callable $filter): array
{
    return array_values(array_filter($checklist['rows'], $filter));
}

function count_kind(array $checklist, string $kind): int
{
    return count(rows_where($checklist, static fn (array $r): bool => $r['kind'] === $kind));
}

function decided_row(array $overrides): array
{
    return $overrides + ['id' => 'R001', 'kind' => 'config', 'target' => 'config/Shared/config_default.php', 'tag' => 'required', 'source' => 'guide', 'reference' => null, 'decision' => 'apply-guide', 'status' => 'done'];
}

echo "parse-integration-guide: classic style (Order Amendment)\n";
$oa = parse_fixture('order-amendment');
check('no unknown rows', count_kind($oa, 'unknown') === 0);
check('composer line with a space after the colon keeps the constraint', ($oa['rows'][0]['packages']['spryker-feature/order-amendment'] ?? null) === '202610.0');
check('every composer line is a row', count_kind($oa, 'composer') === 5);
check('the four OMS files are rows', count_kind($oa, 'oms') === 4);
check('every Verification block is a row', count_kind($oa, 'verification') === 27);
check('module table read', count($oa['guides'][0]['modules']) === 11);
check('prerequisites read with their guide link', ($oa['guides'][0]['prerequisites'][0]['url'] ?? null) !== null);
$scenarios = array_unique(array_filter(array_column($oa['rows'], 'tag'), static fn (string $t): bool => str_starts_with($t, 'optional-scenario:')));
check('the three optional scenarios are tagged apart', count($scenarios) === 3);
check('a numbered ## step stays required', rows_where($oa, static fn (array $r): bool => $r['target'] === 'console transfer:generate')[0]['tag'] === 'required');
check('propel:install is fresh-install-only', rows_where($oa, static fn (array $r): bool => $r['target'] === 'console propel:install')[0]['tag'] === 'fresh-install-only');
check('glossary in a required section is feature-data', rows_where($oa, static fn (array $r): bool => $r['kind'] === 'glossary' && $r['tag'] === 'required')[0]['data_class'] === 'feature-data');
$inferred = rows_where($oa, static fn (array $r): bool => !empty($r['inferred_target']));
check('a block without a header gets its file from namespace and class', ($inferred[0]['target'] ?? '') === 'src/Pyz/Zed/SalesOrderAmendment/SalesOrderAmendmentConfig.php');
$tableOnly = array_column(rows_where($oa, static fn (array $r): bool => isset($r['note']) && $r['kind'] === 'plugin'), 'target');
check('a plugin listed in a required table but wired only in a scenario stays a row', in_array('Spryker\Zed\ProductBundle\Communication\Plugin\CartReorder\OriginalOrderBundleItemCartPreReorderPlugin', $tableOnly, true));
check('a wired class without the Plugin suffix is matched to its table entry', !in_array('Spryker\Zed\GiftCardMailConnector\Communication\Plugin\Checkout\SendEmailToGiftCardUser', $tableOnly, true));

echo "parse-integration-guide: newer style (SSP Asset Management)\n";
$ssp = parse_fixture('ssp-asset-management');
check('no unknown rows', count_kind($ssp, 'unknown') === 0);
check('API request and response examples are not rows', count_kind($ssp, 'verification') === 9 && rows_where($ssp, static fn (array $r): bool => $r['kind'] !== 'verification' && str_contains((string)$r['target'], 'https://')) === []);
check('demo data section rows are demo-data', count(rows_where($ssp, static fn (array $r): bool => $r['tag'] === 'demo-data')) === 6);
check('a demo CSV gets data_class demo-data', rows_where($ssp, static fn (array $r): bool => $r['kind'] === 'data-row')[0]['data_class'] === 'demo-data');
check('steps on a linked page are rows', in_array('/docs/pbc/all/self-service-portal/latest/install/ssp-glossary-data-import.html', array_column($ssp['rows'], 'target'), true));
check('environment variables in config snippets are collected', in_array('SPRYKER_S3_SSP_ASSETS_BUCKET', $ssp['guides'][0]['env'], true));
$sspTableOnly = array_map('fi_short_name', array_column(rows_where($ssp, static fn (array $r): bool => isset($r['note']) && $r['kind'] === 'plugin'), 'target'));
check('a plugin wired under an alias is matched to its table entry', !in_array('SearchSspAssetToCompanyBusinessUnitWritePublisherPlugin', $sspTableOnly, true));
check('a table plugin with no wiring snippet stays a row', $sspTableOnly === ['SspItemAssetSelectorWidget']);
check('the Backend API block is an optional scenario', count(rows_where($ssp, static fn (array $r): bool => $r['tag'] === 'optional-scenario:enable-the-backend-api-endpoints')) === 3);
check('schema with the event behavior is kind event', count_kind($ssp, 'event') === 1);

echo "parse-integration-guide: Glue API guide\n";
$glue = parse_fixture('multiple-carts-glue-api');
check('all rows required, none unknown', count($glue['rows']) === 8 && count_kind($glue, 'unknown') === 0);
check('module-level composer line', ($glue['rows'][0]['packages']['spryker/multi-carts-rest-api'] ?? null) === '^1.0.0');
check('three prerequisites', count($glue['guides'][0]['prerequisites']) === 3);
check('<summary> header names the file', rows_where($glue, static fn (array $r): bool => $r['kind'] === 'plugin')[0]['target'] === 'src/Pyz/Zed/CartsRestApi/CartsRestApiDependencyProvider.php');

echo "parse-integration-guide: units\n";
check('header **path**', pig_header_path('**src/Pyz/Zed/Sales/SalesDependencyProvider.php**') === 'src/Pyz/Zed/Sales/SalesDependencyProvider.php');
check('header with a stray > and leading slash', pig_header_path('**>/data/import/local/full_EU.yml**') === 'data/import/local/full_EU.yml');
check('header <summary> without its closing tag', pig_header_path('    <summary>src/Pyz/Zed/Oms/OmsDependencyProvider.php') === 'src/Pyz/Zed/Oms/OmsDependencyProvider.php');
check('src/data/import is data/import', pig_header_path('**src/data/import/glossary.csv**') === 'data/import/glossary.csv');
check('a sentence is not a header', pig_header_path('1. Add the following configuration:') === null);
check('two packages, flags ignored', pig_composer_packages('composer require a/b: "^1.3.0" c/d:"^2.0" --update-with-dependencies') === ['a/b' => '^1.3.0', 'c/d' => '^2.0']);
check('package without a constraint', pig_composer_packages('composer require spryker/x') === ['spryker/x' => '']);
check('a header-less key,text,locale block is the glossary', pig_infer_path("a.b,Compare products,en_US\na.b,Vergleichen,de_DE", 'csv') === 'data/import/glossary.csv');
check('a header-less block with deploy-file keys goes to every deploy file', pig_infer_path("image:\n    environment:\n        X: 1", 'yml') === 'deploy.*.yml' && pig_classify_path('deploy.*.yml', '') === 'deploy');
check('an html file is frontend', pig_classify_path('public/Yves/push_notification_spa/index.html', '') === 'frontend');
check('table cells decode HTML entities', pig_parse_table(['| NAME | VERSION |', '|---|---|', '| Mailing &amp; Notifications | 1 |'])['rows'][0][0] === 'Mailing & Notifications');
check('$config block goes to config_default.php', pig_infer_path("\$config[KernelConstants::X] = 1;", 'php') === 'config/Shared/config_default.php');

$docs = temp_dir('docs');
write_file($docs . '/_config.yml', "release_version: \"202610.0\"\n");
write_file($docs . '/_includes/pbc/x/install-the-x-feature.md', "## Install feature core\n\n### 1) Install the required modules\n\n```bash\ncomposer require spryker-feature/x:\"{{page.release_tag}}\" --update-with-dependencies\n```\n");
write_file($docs . '/docs/install-the-x-feature.md', "---\ntitle: Install the X feature\ntemplate: feature-integration-guide-template\n---\n\n{% include pbc/x/install-the-x-feature.md %} <!-- To edit, see the include -->\n");
$out = $docs . '/state/checklist.json';
[$exit, $output] = run_script('parse-integration-guide.php', [$docs . '/docs/install-the-x-feature.md', '--docs-root', $docs, '--out', $out], $docs);
$parsed = fi_load_checklist($out);
check('wrapper page resolves its include and the release from _config.yml', $exit === 0 && ($parsed['rows'][0]['packages']['spryker-feature/x'] ?? null) === '202610.0');
[$exit] = run_script('parse-integration-guide.php', [$docs . '/docs/install-the-x-feature.md', '--out', $out], $docs);
check('an existing checklist is not overwritten without --force', $exit === 2);
[$exit, $output] = run_script('parse-integration-guide.php', [$docs . '/docs/install-the-x-feature.md', '--out', $out, '--append'], $docs);
check('--append skips a guide already in the checklist', $exit === 0 && str_contains($output, 'already in the checklist') && count(fi_load_checklist($out)['rows']) === 1);
[$exit, $output] = run_script('parse-integration-guide.php', [$docs . '/_includes/pbc/x/install-the-x-feature.md', '--out', $docs . '/state/no-release.json'], $docs);
check('an unresolved release placeholder exits 1', $exit === 1 && str_contains($output, 'not resolved'));

echo "check-checklist\n";
$undecided = parse_fixture('multiple-carts-glue-api');
check('a fresh checklist fails decide: no decisions', in_array(true, array_map(static fn (string $p): bool => str_contains($p, 'no decision'), cc_problems($undecided, 'decide')), true));
$clean = ['rows' => [decided_row([]), decided_row(['id' => 'R002', 'kind' => 'verification', 'verdict' => 'PASS'])]];
check('a decided, done, verified checklist passes report', cc_problems($clean, 'report') === []);
check('skip without a reason fails', cc_problems(['rows' => [decided_row(['decision' => 'skip:', 'status' => 'skipped'])]], 'decide') !== []);
check('apply-reference without a reference fails', cc_problems(['rows' => [decided_row(['decision' => 'apply-reference'])]], 'decide') !== []);
check('a data row without data_class fails', cc_problems(['rows' => [decided_row(['kind' => 'data-row', 'target' => 'data/import/x.csv'])]], 'decide') !== []);
check('a skipped data row needs no data_class', cc_problems(['rows' => [decided_row(['kind' => 'data-row', 'decision' => 'skip:demo data declined', 'status' => 'skipped'])]], 'report') === []);
check('an unknown kind fails', cc_problems(['rows' => [decided_row(['kind' => 'unknown', 'lines' => [3, 9]])]], 'decide') !== []);
check('an open row passes decide but fails report', cc_problems(['rows' => [decided_row(['status' => 'open'])]], 'decide') === [] && cc_problems(['rows' => [decided_row(['status' => 'open'])]], 'report') !== []);
check('a verification row without a verdict fails report', cc_problems(['rows' => [decided_row(['kind' => 'verification'])]], 'report') !== []);
check('ACCEPTED without a verdict_note fails', cc_problems(['rows' => [decided_row(['kind' => 'verification', 'verdict' => 'ACCEPTED'])]], 'report') !== []);
check('ACCEPTED with a verdict_note passes', cc_problems(['rows' => [decided_row(['kind' => 'verification', 'verdict' => 'ACCEPTED', 'verdict_note' => 'developer: covered by the API test'])]], 'report') === []);

echo "check-lock-diff\n";
$lockDir = temp_dir('lock');
$lock = static fn (array $packages): string => json_encode(['packages' => array_map(static fn (string $n, array $p): array => ['name' => $n] + $p, array_keys($packages), $packages)]);
write_file($lockDir . '/before.lock', $lock(['spryker/kernel' => ['version' => '3.70.0'], 'spryker/unrelated' => ['version' => '1.0.0']]));
write_file($lockDir . '/composer.lock', $lock([
    'spryker-feature/x' => ['version' => '202610.0', 'require' => ['spryker/x' => '^1.0']],
    'spryker/x' => ['version' => '1.0.0', 'require' => ['spryker/kernel' => '^3.71']],
    'spryker/kernel' => ['version' => '3.71.0'],
    'spryker/unrelated' => ['version' => '1.1.0'],
]));
write_file($lockDir . '/composer.json', json_encode(['require' => ['spryker-feature/x' => '^202610.0', 'spryker-feature/y' => 'dev-master as 202610.0']]));
[$exit, $output] = run_script('check-lock-diff.php', ['--before', 'before.lock', '--feature', 'spryker-feature/x'], $lockDir);
check('a move outside the feature tree exits 1 and is named', $exit === 1 && str_contains($output, 'spryker/unrelated') && !str_contains($output, 'spryker/kernel   '));
check('a dev constraint outside the install is only a note', str_contains($output, 'note: other dev constraints') && str_contains($output, 'spryker-feature/y'));
write_file($lockDir . '/composer.json', json_encode(['require' => ['spryker-feature/x' => 'dev-master as 202610.0']]));
write_file($lockDir . '/composer.lock', $lock(['spryker-feature/x' => ['version' => 'dev-master'], 'spryker/kernel' => ['version' => '3.70.0'], 'spryker/unrelated' => ['version' => '1.0.0']]));
[$exit, $output] = run_script('check-lock-diff.php', ['--before', 'before.lock', '--feature', 'spryker-feature/x'], $lockDir);
check('a dev constraint on the required feature exits 1', $exit === 1 && str_contains($output, 'Dev constraint on a package this install requires'));
check('tree walk follows require', array_keys(cld_tree(['a/a' => ['require' => ['b/b' => '1']], 'b/b' => ['require' => ['c/c' => '1']], 'c/c' => [], 'd/d' => []], ['a/a'])) === ['a/a', 'b/b', 'c/c']);

echo "check-feature-installed\n";
$project = temp_dir('project');
write_file($project . '/composer.json', json_encode(['autoload' => ['psr-4' => ['Pyz\\' => 'src/Pyz/']]]));
write_file($project . '/src/Pyz/Zed/Cart/CartDependencyProvider.php', "<?php\nuse Spryker\\Zed\\A\\APlugin;\nreturn [new APlugin(), new BPlugin()];\n");
mkdir($project . '/vendor/spryker/a', 0777, true);
$installed = [
    'guides' => [['file' => 'g.md', 'modules' => [['module' => 'A', 'directory' => 'vendor/spryker/a'], ['module' => 'B', 'directory' => 'vendor/spryker/b']]]],
    'rows' => [
        decided_row(['id' => 'R001', 'kind' => 'plugin', 'target' => 'src/Pyz/Zed/Cart/CartDependencyProvider.php', 'plugins' => ['Spryker\Zed\A\APlugin', 'Spryker\Zed\B\BPlugin'], 'decision' => null]),
        decided_row(['id' => 'R002', 'kind' => 'plugin', 'target' => 'src/Pyz/Zed/Cart/CartDependencyProvider.php', 'plugins' => ['Spryker\Zed\A\APlugin', 'Spryker\Zed\C\CPlugin'], 'decision' => 'apply-guide']),
        decided_row(['id' => 'R003', 'kind' => 'plugin', 'target' => 'Spryker\Zed\B\BPlugin', 'plugins' => ['Spryker\Zed\B\BPlugin'], 'decision' => null]),
    ],
];
fi_save_checklist($project . '/checklist.json', $installed);
[$exit, $output] = run_script('check-feature-installed.php', ['checklist.json', '--mark'], $project);
$marked = fi_load_checklist($project . '/checklist.json');
check('module table: installed and missing', str_contains($output, '1 of 2 modules installed') && str_contains($output, 'vendor/spryker/b'));
check('partial wiring names the missing plugin', str_contains($output, 'missing: CPlugin'));
check('--mark sets already-present on fully present undecided rows only', $marked['rows'][0]['decision'] === 'already-present' && $marked['rows'][1]['decision'] === 'apply-guide' && $marked['rows'][2]['decision'] === 'already-present');
[$exit] = run_script('check-feature-installed.php', ['checklist.json', '--wiring'], $project);
check('--wiring exits 1 while a decided row is partial', $exit === 1);
write_file($project . '/src/Pyz/Zed/Cart/CartDependencyProvider.php', "<?php\nreturn [new APlugin(), new BPlugin(), new CPlugin()];\n");
[$exit] = run_script('check-feature-installed.php', ['checklist.json', '--wiring'], $project);
check('--wiring exits 0 once every decided row is present', $exit === 0);
write_file($project . '/vendor/spryker/a/src/Spryker/Zed/A/APlugin.php', "<?php\n");
check('a plugin class in vendor/ is available', cfi_class_available($project, 'Spryker\Zed\A\APlugin'));
check('a plugin of a module the project does not install is unavailable', !cfi_class_available($project, 'Spryker\Zed\Merchant\MerchantPlugin'));
[, $output] = run_script('check-feature-installed.php', ['checklist.json'], $project);
check('the report names plugins whose module is not installed', str_contains($output, 'not in vendor/ (module not installed): BPlugin'));
check('a class of the same name from another namespace is not the plugin', !cfi_mentions("use Spryker\\Zed\\Old\\XPlugin;\nnew XPlugin();", 'Spryker\Zed\New\XPlugin'));
check('the imported class of that namespace is the plugin', cfi_mentions("use Spryker\\Zed\\New\\XPlugin;\nnew XPlugin();", 'Spryker\Zed\New\XPlugin'));
check('guide src/Pyz paths map to other project namespaces', cfi_project_files($project, 'src/Pyz/Zed/Cart/CartDependencyProvider.php', ['Demo', 'Pyz']) === ['src/Pyz/Zed/Cart/CartDependencyProvider.php']);

echo "diff-release-steps\n";
$recipeProject = temp_dir('recipe');
write_file($recipeProject . '/config/install/production.yml', "sections:\n    diff:\n        propel-migrate:\n            command: 'vendor/bin/console propel:migrate -vvv --no-ansi'\n    scheduler-start:\n        queue-setup:\n            command: 'vendor/bin/console queue:setup'\n");
write_file($recipeProject . '/config/install/docker.yml', "sections:\n    build:\n        generate-transfers:\n            command: 'vendor/bin/console transfer:generate'\n    demodata:\n        import:\n            command: 'vendor/bin/console data:import glossary'\n");
$releaseChecklist = ['rows' => [
    decided_row(['id' => 'R001', 'kind' => 'console', 'target' => 'console transfer:generate']),
    decided_row(['id' => 'R002', 'kind' => 'console', 'target' => 'console propel:install']),
    decided_row(['id' => 'R003', 'kind' => 'glossary', 'target' => 'data/import/glossary.csv', 'data_class' => 'feature-data']),
    decided_row(['id' => 'R004', 'kind' => 'console', 'target' => 'console setup:init-db', 'decision' => 'skip:not needed']),
    decided_row(['id' => 'R005', 'kind' => 'config', 'env' => ['SPRYKER_X_BUCKET']]),
    decided_row(['id' => 'R006', 'kind' => 'plugin', 'plugins' => ['Spryker\Zed\X\Communication\Plugin\Publisher\XWritePublisherPlugin']]),
    decided_row(['id' => 'R007', 'kind' => 'data-row', 'target' => 'data/import/common/x.csv', 'data_class' => 'demo-data']),
]];
fi_save_checklist($recipeProject . '/checklist.json', $releaseChecklist);
[$exit, $output] = run_script('diff-release-steps.php', ['checklist.json'], $recipeProject);
$lineOf = static function (string $output, string $step): string {
    foreach (explode("\n", $output) as $line) {
        if (str_starts_with($line, '| ' . $step . ' ')) {
            return $line;
        }
    }

    return '';
};
check('a command in the build section is covered', str_contains($lineOf($output, 'transfer:generate'), 'covered'));
check('propel:install is covered by the deploy propel:migrate', str_contains($lineOf($output, 'propel:migrate'), 'covered') && $lineOf($output, 'propel:install') === '');
check('a command only in a section the deploy does not run is to prove', str_contains($lineOf($output, 'data:import glossary'), 'to prove'));
check('a skipped row is no candidate', !str_contains($output, 'setup:init-db'));
check('an environment variable is listed to set', str_contains($lineOf($output, 'env SPRYKER_X_BUCKET'), 'set it'));
check('a new publisher needs a republish', str_contains($output, 'publish:trigger-events -r <resource of XWritePublisherPlugin>'));
check('demo-data rows are no release step', !str_contains($output, 'x.csv'));
check('uncovered candidates exit 1', $exit === 1);

echo "find-feature-change\n";
$repo = temp_dir('reference');
$git = static function (string ...$args) use ($repo): string {
    $command = 'git -C ' . escapeshellarg($repo) . ' -c user.name=test -c user.email=test@example.com -c commit.gpgsign=false';
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    return trim((string)shell_exec($command . ' 2>&1'));
};
$git('init', '-q', '-b', 'master');
write_file($repo . '/composer.json', "{\n    \"require\": {}\n}\n");
$git('add', '.');
$git('commit', '-q', '-m', 'init');
$git('checkout', '-q', '-b', 'feature/x');
write_file($repo . '/composer.json', "{\n    \"require\": {\n        \"spryker-feature/x\": \"^202610.0\"\n    }\n}\n");
write_file($repo . '/src/Pyz/Zed/Cart/CartDependencyProvider.php', "<?php\n");
write_file($repo . '/src/Pyz/Yves/CartPage/Theme/default/views/cart/cart.twig', "{# cart #}\n");
write_file($repo . '/tests/PyzTest/Zed/Cart/CartTest.php', "<?php\n");
$git('add', '.');
$git('commit', '-q', '-m', 'add feature x');
$git('checkout', '-q', 'master');
$git('merge', '-q', '--no-ff', 'feature/x', '-m', 'Merge pull request #12 from org/feature/x');
$refChecklist = ['schema' => 1, 'release' => '202610.0', 'guides' => [], 'rows' => [decided_row(['id' => 'R001', 'kind' => 'plugin', 'target' => 'src/Pyz/Zed/Cart/CartDependencyProvider.php'])]];
fi_save_checklist($repo . '/../ref-checklist-' . basename($repo) . '.json', $refChecklist);
$refPath = $repo . '/../ref-checklist-' . basename($repo) . '.json';
[$exit, $output] = run_script('find-feature-change.php', ['--repo', $repo, '--package', 'spryker-feature/x', '--checklist', $refPath, '--add-rows'], $repo);
$withRef = fi_load_checklist($refPath);
check('the merge of the first commit that required the package is found', $exit === 0 && str_contains($output, 'Merge pull request #12') && ($withRef['reference_change']['pr'] ?? null) === 12);
check('a changed file no row names becomes a reference-only row', array_column(rows_where($withRef, static fn (array $r): bool => $r['source'] === 'reference-only'), 'target') === ['src/Pyz/Yves/CartPage/Theme/default/views/cart/cart.twig']);
check('a reference-only row is classified and undecided', ($withRef['rows'][1]['kind'] ?? '') === 'frontend' && $withRef['rows'][1]['decision'] === null);
check('tests and composer files are not steps', str_contains($output, '2 not a step'));
[$exit] = run_script('find-feature-change.php', ['--repo', $repo, '--package', 'spryker-feature/never'], $repo);
check('a package the reference never required exits 1', $exit === 1);

echo "\n$count checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
