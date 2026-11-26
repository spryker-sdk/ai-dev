<?php

declare(strict_types=1);

/** Zero-dependency tests for the spryker-upgrade scripts. Run: php scripts/upgrade-scripts.test.php */

require __DIR__ . '/check-added-comments.php';
require __DIR__ . '/backoffice-smoke.php';
require __DIR__ . '/check-tooling-alignment.php';
require __DIR__ . '/check-baselines.php';
require __DIR__ . '/check-constant-overrides.php';
require __DIR__ . '/storage-search-counts.php';
require __DIR__ . '/check-performance.php';
require __DIR__ . '/compare-tool-runs.php';
require __DIR__ . '/run-detectors.php';

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
function run_script(string $script, array $args, string $projectDir, string $stateDir, array $env = []): array
{
    $environment = array_merge(getenv(), ['SPRYKER_PROJECT_ROOT' => $projectDir, 'SPRYKER_UPGRADE_STATE_DIR' => $stateDir], $env);
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/' . $script], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectDir,
        $environment
    );
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string)$output];
}

function git(string $dir, string ...$args): string
{
    $command = 'git -C ' . escapeshellarg($dir) . ' -c user.name=test -c user.email=test@example.com -c commit.gpgsign=false';
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    return trim((string)shell_exec($command . ' 2>&1'));
}

function write_file(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function fixture(string $path): string
{
    return (string)file_get_contents(__DIR__ . '/fixtures/' . $path);
}

function line_of(string $src, string $needle): int
{
    foreach (explode("\n", $src) as $i => $row) {
        if (str_contains($row, $needle)) {
            return $i + 1;
        }
    }

    return -1;
}

function read_json(string $file): array
{
    return is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
}

$tmp = sys_get_temp_dir() . '/upgrade_scripts_' . getmypid();
@mkdir($tmp, 0777, true);
$tmp = (string)realpath($tmp);

// --- check-test-coverage: factories are wiring, never business logic ---
$coverage = $tmp . '/coverage';
$coverageState = $coverage . '/.state';
write_file($coverage . '/composer.json', "{}\n");
write_file($coverage . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Spryker\Zed\Foo\Business {
    class FooBusinessFactory
    {
        public function createA() {} public function createB() {} public function createC() {}
        public function createD() {} public function createE() {} public function createF() {}
    }
}
namespace Spryker\Zed\Foo\Business\Model {
    class Calculator { public function calculate() {} }
}
namespace Spryker\Client\Bar {
    class BarFactory { public function createReader() {} }
}
namespace Spryker\Zed\Foo {
    class FooDependencyProvider { public function getPlugins() {} }
}
PHP);
write_file($coverage . '/src/Pyz/Zed/Foo/Business/FooBusinessFactory.php', fixture('coverage/FooBusinessFactory.php.fixture'));
write_file($coverage . '/src/Pyz/Zed/Foo/FooDependencyProvider.php', fixture('coverage/FooDependencyProvider.php.fixture'));
write_file($coverage . '/src/Pyz/Client/Bar/BarFactory.php', <<<'PHP'
<?php
namespace Pyz\Client\Bar;

class BarFactory extends \Spryker\Client\Bar\BarFactory
{
    public function createReader() {}
}
PHP);
write_file($coverage . '/src/Pyz/Zed/Baz/Business/Model/Calculator.php', fixture('coverage/Calculator.php.fixture'));
[$covCode, $covOut] = run_script('check-test-coverage.php', ['--all'], $coverage, $coverageState);
$covReport = read_json($coverageState . '/test-coverage-report.json');
$covModules = array_column($covReport['modules'] ?? [], null, 'module');
$zedFoo = $covModules['Zed/Foo'] ?? [];
check('coverage: BusinessFactory overrides are not logic overrides', ($zedFoo['logicOverrides'] ?? -1) === 0);
check('coverage: BusinessFactory + dependency provider overrides counted as wiring', ($zedFoo['wiringOverrides'] ?? 0) === 7);
check('coverage: factory-only module is LOW risk', ($zedFoo['risk'] ?? '') === 'LOW');
check('coverage: wiring module gets no test recommendation', ($zedFoo['suggestedTests'] ?? null) === []);
check('coverage: Client factory is wiring', ($covModules['Client/Bar']['logicOverrides'] ?? -1) === 0 && ($covModules['Client/Bar']['suggestedTests'] ?? null) === []);
check('coverage: factory file classified as wiring', in_array('wiring', array_column($zedFoo['files'] ?? [], 'kind'), true));
$zedBaz = $covModules['Zed/Baz'] ?? [];
check('coverage: Business model override stays a logic override', ($zedBaz['logicOverrides'] ?? 0) === 1);
check('coverage: logic override hint points at the Facade', str_contains(implode(' ', $zedBaz['suggestedTests'] ?? []), 'BazFacade'));
check('coverage: no wiring-assertion hint in output', !str_contains($covOut, 'Wiring assertion') && !str_contains($covOut, 'assert the stack'));
check('coverage: exit 0 without uncovered HIGH modules', $covCode === 0);

// --- check-added-comments: per-file classification ---
$php = <<<'PHP'
<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Pyz\Zed\Foo\Business;

#[\Attribute]
class Foo
{
    /**
     * Specification:
     * - Does a thing.
     * - Does another
     *   thing across lines.
     *
     * @api
     *
     * @param int $a
     *
     * @return int
     */
    public function run(int $a): int
    {
        // explains the next line
        $b = $a + 1; // trailing explanation
        $url = 'http://example.com'; $hash = "#not-a-comment";

        return $b;
    }

    /**
     * {@inheritDoc}
     */
    public function other(): void
    {
    }

    /**
     * upgrade-debt: remove once the vendor fix ships.
     * Temporary shim.
     */
    public function shim(): void
    {
        # hash explanation
        /* block explanation */
        // @phpstan-ignore-next-line
        $x = 1;
    }

    /**
     * Returns the value because of reasons.
     *
     * @return void
     */
    public function summary(): void
    {
    }
}
PHP;
$phpLines = ac_disallowed_comment_lines($php, 'php');
$reportedAt = static fn(string $needle): bool => isset($phpLines[line_of($php, $needle)]);
check('comments php: // explanation reported', $reportedAt('explains the next line'));
check('comments php: trailing comment reported as trailing', ($phpLines[line_of($php, 'trailing explanation')]['kind'] ?? '') === 'trailing');
check('comments php: @param allowed', !$reportedAt('@param int $a'));
check('comments php: @return / @api allowed', !$reportedAt('@return int') && !$reportedAt('@api'));
check('comments php: {@inheritDoc} allowed', !$reportedAt('{@inheritDoc}'));
check('comments php: Specification block allowed (line, bullets, continuation)', !$reportedAt('Specification:') && !$reportedAt('- Does a thing.') && !$reportedAt('thing across lines.'));
check('comments php: upgrade-debt docblock allowed', !$reportedAt('upgrade-debt') && !$reportedAt('Temporary shim.'));
check('comments php: #[Attribute] is not a comment', !$reportedAt('#[\Attribute]'));
check('comments php: # comment reported', $reportedAt('hash explanation'));
check('comments php: /* */ comment reported', $reportedAt('block explanation'));
check('comments php: docblock summary text reported', $reportedAt('Returns the value because of reasons.'));
check('comments php: license header allowed', !$reportedAt('This file is part of'));
check('comments php: a suppression is reported as kind suppression', ($phpLines[line_of($php, '@phpstan-ignore-next-line')]['kind'] ?? '') === 'suppression');
check('comments php: // inside a string is not a comment', !$reportedAt("'http://example.com'"));
check('comments php: exactly the expected lines reported', count($phpLines) === 6);

$js = <<<'JS'
const url = 'http://example.com';
const re = /\/\/not-a-comment/g;
const ratio = total / count; const other = 2 / 1;
// js explanation
let x = 1; /* trailing block */
const tpl = `a // b`;
/**
 * @param {number} a
 */
function f(a) { return a; }
JS;
$jsLines = ac_disallowed_comment_lines($js, 'js');
check('comments js: // explanation reported', isset($jsLines[line_of($js, 'js explanation')]));
check('comments js: trailing block reported as trailing', ($jsLines[line_of($js, 'trailing block')]['kind'] ?? '') === 'trailing');
check('comments js: strings, template literals and regex literals are not comments', count($jsLines) === 2);

$twig = "{# twig explanation #}\n<div>{{ value }}</div> {# trailing twig #}\n{# @var product \\Generated\\Shared\\Transfer\\ProductViewTransfer #}\n<p>{{ 'x' }}</p>\n";
$twigLines = ac_disallowed_comment_lines($twig, 'twig');
check('comments twig: {# #} comment reported', isset($twigLines[1]));
check('comments twig: trailing comment reported as trailing', ($twigLines[2]['kind'] ?? '') === 'trailing');
check('comments twig: @var type hint allowed', !isset($twigLines[3]) && count($twigLines) === 2);

$diff = "diff --git a/src/A.php b/src/A.php\n--- a/src/A.php\n+++ b/src/A.php\n@@ -3,0 +4,2 @@\n+one\n+two\n@@ -10 +12 @@\n-old\n+new\n"
    . "diff --git a/src/B.php b/src/B.php\n--- a/src/B.php\n+++ /dev/null\n@@ -1,2 +0,0 @@\n-x\n-y\n";
check('comments diff: added line numbers parsed, deleted file ignored', ac_parse_added_lines($diff) === ['src/A.php' => [4, 5, 12]]);
check('comments scope: src/Generated, src/Orm and vendor skipped', ac_in_scope('src/Generated/X.php') === null && ac_in_scope('src/Orm/X.php') === null && ac_in_scope('src/Pyz/vendor/x.js') === null);
check('comments scope: src php/js/ts/twig and config php in scope', ac_in_scope('src/Pyz/X.ts') === 'ts' && ac_in_scope('config/Shared/config_default.php') === 'php' && ac_in_scope('config/Shared/x.js') === null);

// --- check-added-comments: CLI against a git fixture ---
$project = $tmp . '/project';
$projectState = $project . '/.spryker-upgrade/state';
write_file($project . '/composer.json', "{}\n");
write_file($project . '/.gitignore', ".spryker-upgrade/\n");
write_file($project . '/src/Pyz/Zed/Foo/Business/Model/Existing.php', "<?php\n\nclass Existing\n{\n    public function a(): int\n    {\n        // pre-existing comment\n        return 1;\n    }\n}\n");
git($project, 'init', '-q');
git($project, 'add', '-A');
git($project, 'commit', '-q', '-m', 'base');
$head = git($project, 'rev-parse', 'HEAD');

[$noBaseCode] = run_script('check-added-comments.php', [], $project, $projectState);
check('comments CLI: no base ref -> exit 2', $noBaseCode === 2);
[$recordCode] = run_script('check-added-comments.php', ['--record-base'], $project, $projectState);
check('comments CLI: --record-base writes HEAD to base-ref', $recordCode === 0 && trim((string)@file_get_contents($projectState . '/base-ref')) === $head);

write_file($project . '/src/Pyz/Zed/Foo/Business/Model/Existing.php', "<?php\n\nclass Existing\n{\n    public function a(): int\n    {\n        // pre-existing comment\n        return 1;\n    }\n\n    public function b(): int\n    {\n        // new explanation\n        return 2;\n    }\n}\n");
write_file($project . '/src/Pyz/Yves/Foo/Theme/default/views/x.twig', "{# twig note #}\n<div></div>\n");
write_file($project . '/src/Generated/Foo.php', "<?php\n// generated note\n");
write_file($project . '/config/Shared/config_default.php', "<?php\n// config note\n");
git($project, 'add', '-A');
git($project, 'commit', '-q', '-m', 'upgrade');
[, $recordAgainOut] = run_script('check-added-comments.php', ['--record-base'], $project, $projectState);
check('comments CLI: second --record-base keeps the existing base', str_contains($recordAgainOut, 'Kept existing') && trim((string)file_get_contents($projectState . '/base-ref')) === $head);
write_file($project . '/src/Pyz/Zed/Foo/Business/Model/Untracked.php', "<?php\n\n// untracked note\nclass Untracked\n{\n}\n");

[$commentsCode, $commentsOut] = run_script('check-added-comments.php', [], $project, $projectState);
$commentsReport = read_json($projectState . '/added-comments-report.json');
$found = array_map(static fn(array $f): string => $f['file'] . ':' . $f['line'], $commentsReport['findings'] ?? []);
check('comments CLI: exit 1 when comments were added', $commentsCode === 1);
check('comments CLI: new explanation reported with file:line', in_array('src/Pyz/Zed/Foo/Business/Model/Existing.php:13', $found, true));
check('comments CLI: pre-existing comment not reported', !in_array('src/Pyz/Zed/Foo/Business/Model/Existing.php:7', $found, true));
check('comments CLI: twig and config comments reported', in_array('src/Pyz/Yves/Foo/Theme/default/views/x.twig:1', $found, true) && in_array('config/Shared/config_default.php:2', $found, true));
check('comments CLI: untracked file reported', in_array('src/Pyz/Zed/Foo/Business/Model/Untracked.php:3', $found, true));
check('comments CLI: src/Generated skipped', !str_contains($commentsOut, 'generated note'));
check('comments CLI: exactly four findings', count($found) === 4);

git($project, 'add', '-A');
git($project, 'commit', '-q', '-m', 'all');
[$cleanCode] = run_script('check-added-comments.php', ['--base', 'HEAD'], $project, $projectState);
check('comments CLI: --base HEAD with nothing added -> exit 0', $cleanCode === 0);
[$badBaseCode] = run_script('check-added-comments.php', ['--base', 'no-such-ref'], $project, $projectState);
check('comments CLI: unknown base ref -> exit 2', $badBaseCode === 2);

// --- check-tooling-alignment ---
$yaml = "version: '0.1'\nimage:\n    tag: spryker/php:8.3 # pinned\n    environment:\n        SPRYKER_X: 1\nservices:\n    database:\n        engine: postgres\n        version: '17'\n    search:\n        engine: opensearch\n        version: 2.x\n";
$scalars = ta_yaml_scalars($yaml);
check('tooling yaml: nested scalars by dotted path, quotes and comments stripped', ($scalars['image.tag'] ?? '') === 'spryker/php:8.3' && ($scalars['services.database.version'] ?? '') === '17' && ($scalars['image.environment.SPRYKER_X'] ?? '') === '1');

$reference = $tmp . '/reference';
write_file($reference . '/composer.json', json_encode(['require' => ['spryker/kernel' => '^3.0'], 'require-dev' => [
    'phpstan/phpstan' => '^2.1', 'spryker-sdk/evaluator' => '^1.0', 'codeception/codeception' => '^5.0', 'spryker/code-sniffer' => '^0.17',
    'spryker-sdk/spryk' => '^0.5',
]]));
write_file($reference . '/.git.docker', "1.66.0\n");
write_file($reference . '/deploy.dev.yml', $yaml);
write_file($reference . '/deploy.ci.yml', "image:\n    tag: spryker/php:8.3\n");

$tooling = $tmp . '/tooling';
$toolingState = $tooling . '/.state';
write_file($tooling . '/composer.json', json_encode(['require' => ['spryker/kernel' => '^3.0'], 'require-dev' => [
    'phpstan/phpstan' => '^1.10', 'spryker-sdk/evaluator' => '^1.0', 'spryker/code-sniffer' => '^0.17', 'phpstan/phpstan-deprecation-rules' => '^1.0',
    'spryker-sdk/spryk' => '^0.4',
]]));
mkdir($tooling . '/src/Pyz', 0777, true);
write_file($tooling . '/.git.docker', "1.50.0\n");
write_file($tooling . '/deploy.dev.yml', str_replace(['php:8.3', 'postgres'], ['php:8.2', 'mysql'], $yaml));

[$toolCode, $toolOut] = run_script('check-tooling-alignment.php', ['--reference', $reference], $tooling, $toolingState);
$toolReport = read_json($toolingState . '/tooling-alignment-report.json');
$toolRows = [];
foreach ($toolReport['rows'] ?? [] as $r) {
    $toolRows[$r['item']] = $r['status'];
}
check('tooling: exit 1 when tooling differs', $toolCode === 1);
check('tooling: phpstan constraint difference detected', ($toolRows['phpstan/phpstan'] ?? '') === 'differs');
check('tooling: same evaluator constraint is ok', ($toolRows['spryker-sdk/evaluator'] ?? '') === 'same');
check('tooling: package missing in project detected', ($toolRows['codeception/codeception'] ?? '') === 'missing-in-project');
check('tooling: project-only package is informational', ($toolRows['phpstan/phpstan-deprecation-rules'] ?? '') === 'project-only');
check('tooling: non-tooling packages ignored', !isset($toolRows['spryker/kernel']));
check('tooling: spryker-sdk packages outside the tooling set ignored', !isset($toolRows['spryker-sdk/spryk']));
check('tooling: missing-in-project and project-only rows are not failures', !ta_is_failing(['group' => 'composer', 'item' => 'x', 'project' => null, 'reference' => '^1', 'status' => 'missing-in-project']) && !ta_is_failing(['group' => 'composer', 'item' => 'x', 'project' => '^1', 'reference' => null, 'status' => 'project-only']));
check('tooling: a .git.docker missing in the project is a failure', ta_is_failing(['group' => 'docker-sdk', 'item' => '.git.docker', 'project' => null, 'reference' => '1.66.0', 'status' => 'missing-in-project']));
check('tooling: .git.docker pin difference detected', ($toolRows['.git.docker'] ?? '') === 'differs');
check('tooling: deploy image tag and database engine differences detected', ($toolRows['deploy.dev.yml image.tag'] ?? '') === 'differs' && ($toolRows['deploy.dev.yml services.database.engine'] ?? '') === 'differs');
check('tooling: equal deploy service version is ok', ($toolRows['deploy.dev.yml services.search.version'] ?? '') === 'same');
check('tooling: deploy file only in reference is not compared', !isset($toolRows['deploy.ci.yml image.tag']));
check('tooling: table printed', str_contains($toolOut, 'phpstan/phpstan') && str_contains($toolOut, '^2.1'));

$aligned = json_decode((string)file_get_contents($reference . '/composer.json'), true);
$aligned['require-dev']['phpstan/phpstan-deprecation-rules'] = '^1.0';
unset($aligned['require-dev']['codeception/codeception']);
write_file($tooling . '/composer.json', json_encode($aligned));
copy($reference . '/.git.docker', $tooling . '/.git.docker');
copy($reference . '/deploy.dev.yml', $tooling . '/deploy.dev.yml');
[$alignedCode] = run_script('check-tooling-alignment.php', ['--reference', $reference], $tooling, $toolingState);
check('tooling: exit 0 when aligned (project-only and missing-in-project packages allowed)', $alignedCode === 0);
[$noRefCode] = run_script('check-tooling-alignment.php', [], $tooling, $toolingState);
check('tooling: missing --reference -> exit 2', $noRefCode === 2);

// --- check-baselines ---
$baselineState = $tmp . '/baseline-state';
[$blCode, $blOut] = run_script('check-baselines.php', [], $tooling, $baselineState);
$allNames = ['base-ref', 'codecept-baseline.txt', 'phpstan-baseline-run.txt', 'sniff-baseline.txt', 'evaluator-baseline.txt', 'backoffice-smoke-baseline.json', 'constant-overrides-baseline.json', 'plugin-usage-baseline.json', 'config-constants-baseline.json', 'typed-members-baseline.json', 'storage-search-counts-before.json'];
check('baselines: exit 1 with an empty state dir', $blCode === 1);
check('baselines: every missing file named', array_filter($allNames, static fn(string $n): bool => !str_contains($blOut, $n)) === []);
$realResults = [
    'base-ref' => "0123456789abcdef0123456789abcdef01234567\n",
    'codecept-baseline.txt' => "Codeception PHP Testing Framework\n\e[32mFAILURES!\e[0m\r\nTests: 10, Assertions: 20, Failures: 1.\r\n",
    'phpstan-baseline-run.txt' => " 3/3 [============================] 100%\n\n [ERROR] Found 3 errors\n",
    'sniff-baseline.txt' => "Run Code Style Sniffer for PROJECT\n",
    'evaluator-baseline.txt' => "====================\nPHP_VERSION_CHECKER\n====================\nRead more: https://docs.spryker.com\n",
    'constant-overrides-baseline.json' => json_encode(['overrides' => []]) . "\n",
    'plugin-usage-baseline.json' => json_encode(['missing' => [], 'deprecated' => []]) . "\n",
    'config-constants-baseline.json' => json_encode(['problems' => []]) . "\n",
    'typed-members-baseline.json' => json_encode(['problems' => []]) . "\n",
    'storage-search-counts-before.json' => json_encode(['tables' => ['spy_product_abstract_storage' => 3], 'totalRows' => 3]) . "\n",
];
foreach ($realResults as $name => $contents) {
    file_put_contents($baselineState . '/' . $name, $contents);
}
[$blMissingBo] = run_script('check-baselines.php', [], $tooling, $baselineState);
[$blNoBo] = run_script('check-baselines.php', ['--no-backoffice'], $tooling, $baselineState);
check('baselines: Back Office baseline required by default', $blMissingBo === 1);
check('baselines: --no-backoffice makes it optional', $blNoBo === 0);
file_put_contents($baselineState . '/backoffice-smoke-baseline.json', "{}\n");
[$blBoEmpty, $blBoEmptyOut] = run_script('check-baselines.php', [], $tooling, $baselineState);
check('baselines: Back Office baseline without results -> exit 1, NO RESULT', $blBoEmpty === 1 && str_contains($blBoEmptyOut, 'NO RESULT'));
file_put_contents($baselineState . '/backoffice-smoke-baseline.json', json_encode(['results' => []]) . "\n");
[$blAll] = run_script('check-baselines.php', [], $tooling, $baselineState);
check('baselines: exit 0 when all present with a result', $blAll === 0);
check('baselines: bl_status lists the eleven files', array_keys(bl_status($baselineState, true)) === $allNames);
check('baselines content: a detector baseline without its finding list fails', bl_content_problem('plugin-usage-baseline.json', "{}\n") !== null && bl_content_problem('typed-members-baseline.json', json_encode(['problems' => []])) === null);
$pickFrom = [];
foreach (['A', 'A', 'A', 'A', 'B', 'C', 'C'] as $index => $category) {
    $pickFrom[] = spryker_upgrade_finding($category, 'finding ' . $index);
}
check('detector output: the limit keeps one finding of each category, then fills in order', array_map(static fn (array $f): string => $f['text'], spryker_upgrade_pick_findings($pickFrom, 4)) === ['finding 0', 'finding 1', 'finding 4', 'finding 5']);
check('detector output: a limit below the number of categories keeps the first categories; 0 keeps none', array_map(static fn (array $f): string => $f['category'], spryker_upgrade_pick_findings($pickFrom, 2)) === ['A', 'B'] && spryker_upgrade_pick_findings($pickFrom, 0) === []);
$noteState = $tmp . '/note-state';
mkdir($noteState, 0777, true);
check('detector output: findings without a Phase 0 result -> a note that names the file', str_contains((string)spryker_upgrade_baseline_note($noteState, 'plugin-usage', false, true), 'plugin-usage-baseline.json'));
file_put_contents($noteState . '/plugin-usage-baseline.json', "{}\n");
check('detector output: no note with a Phase 0 result, in the Phase 0 run, or without findings', spryker_upgrade_baseline_note($noteState, 'plugin-usage', false, true) === null && spryker_upgrade_baseline_note($noteState, 'typed-members', true, true) === null && spryker_upgrade_baseline_note($noteState, 'typed-members', false, false) === null);
check('baselines: --no-data leaves out the storage/search counts baseline', !in_array('storage-search-counts-before.json', array_keys(bl_status($baselineState, true, false)), true));
check('baselines content: a counts snapshot without rows fails', str_starts_with((string)bl_content_problem('storage-search-counts-before.json', json_encode(['tables' => ['a_storage' => 0], 'totalRows' => 0])), 'no rows'));
check('baselines content: a constant-overrides file without overrides list fails', bl_content_problem('constant-overrides-baseline.json', "{}\n") !== null);
rename($baselineState . '/storage-search-counts-before.json', $baselineState . '/counts.bak');
[$blNoCounts] = run_script('check-baselines.php', [], $tooling, $baselineState);
[$blNoData, $blNoDataOut] = run_script('check-baselines.php', ['--no-data'], $tooling, $baselineState);
check('baselines: counts baseline required by default', $blNoCounts === 1);
check('baselines: --no-data makes it optional and says the publish check is not done', $blNoData === 0 && str_contains($blNoDataOut, 'publish check'));
rename($baselineState . '/counts.bak', $baselineState . '/storage-search-counts-before.json');

check('baselines content: empty file fails', bl_content_problem('sniff-baseline.txt', " \n\r\n") === 'empty');
check('baselines content: codecept OK summary accepted', bl_content_problem('codecept-baseline.txt', "OK (12 tests, 30 assertions)\n") === null);
check('baselines content: codecept without a summary fails', bl_content_problem('codecept-baseline.txt', "Service 'testing' is not running\n") !== null);
check('baselines content: phpstan [OK] accepted', bl_content_problem('phpstan-baseline-run.txt', " [OK] No errors\n") === null);
check('baselines content: phpstan without a result line fails', bl_content_problem('phpstan-baseline-run.txt', "Invalid configuration:\nUnexpected item 'parameters › foo'.\n") !== null);
check('baselines content: sniffer command error fails', str_contains((string)bl_content_problem('sniff-baseline.txt', "\n  Command \"code:sniff:style\" is not defined.  \n\n"), 'is not defined'));
check('baselines content: sniffer violations accepted', bl_content_problem('sniff-baseline.txt', "FILE: src/Pyz/X.php\nFOUND 2 ERRORS AFFECTING 2 LINES\n") === null);
check('baselines content: evaluator usage error fails', bl_content_problem('evaluator-baseline.txt', "Could not open input file: vendor/bin/evaluator\n") !== null);
check('baselines content: evaluator Success! accepted', bl_content_problem('evaluator-baseline.txt', "\e[42mSuccess!\e[0m\n") === null);
check('baselines content: base-ref must be a commit hash', bl_content_problem('base-ref', "HEAD\n") !== null);

file_put_contents($baselineState . '/phpstan-post-tooling.txt', "Error response from daemon: container is not running\n");
[$blPostBad, $blPostBadOut] = run_script('check-baselines.php', [], $tooling, $baselineState);
check('baselines: a post-tooling re-take without a result -> exit 1, file named', $blPostBad === 1 && str_contains($blPostBadOut, 'phpstan-post-tooling.txt'));
check('baselines: an existing post-tooling re-take is listed after the required files', array_keys(bl_status($baselineState, true)) === array_merge($allNames, ['phpstan-post-tooling.txt']));
file_put_contents($baselineState . '/phpstan-post-tooling.txt', " [ERROR] Found 3 errors\n");
[$blPostOk] = run_script('check-baselines.php', [], $tooling, $baselineState);
check('baselines: a post-tooling re-take with a result -> exit 0', $blPostOk === 0);
[$blBadArg] = run_script('check-baselines.php', ['--bogus'], $tooling, $baselineState);
check('baselines: unknown argument -> exit 2', $blBadArg === 2);

// --- backoffice-smoke: parsing functions ---
$loginHtml = <<<'HTML'
<html><body>
<form name="auth" method="post" action="/security-gui/login_check">
  <input type="text" id="auth_username" name="auth[username]" required>
  <input type="password" id="auth_password" name="auth[password]">
  <input type="hidden" id="auth__token" name="auth[_token]" value="abc123">
  <button type="submit">Login</button>
</form>
</body></html>
HTML;
$form = bo_parse_login_form($loginHtml, 'http://bo.local/security-gui/login');
check('smoke: CSRF token extracted', bo_extract_csrf($loginHtml) === ['name' => 'auth[_token]', 'value' => 'abc123']);
check('smoke: login form fields and action resolved', ($form['userField'] ?? '') === 'auth[username]' && ($form['passwordField'] ?? '') === 'auth[password]' && ($form['action'] ?? '') === 'http://bo.local/security-gui/login_check');
check('smoke: no CSRF field -> null', bo_extract_csrf('<form><input type="text" name="u"><input type="password" name="p"></form>') === null);

$dashboardHtml = <<<'HTML'
<html><body>
<nav class="navbar-default navbar-static-side"><ul id="side-menu">
  <li><a href="/sales">Sales</a></li>
  <li><a href="#">Catalog</a><ul><li><a href="/product-management">Products</a></li></ul></li>
  <li><a href="/security-gui/logout">Logout</a></li>
  <li><a href="http://other.host/x">External</a></li>
  <li><a href="/customer/delete?id-customer=1">Delete</a></li>
  <li><a href="javascript:void(0)">JS</a></li>
  <li><a href="/sales">Sales again</a></li>
</ul></nav>
<main><a href="/not-in-nav">Content link</a></main>
</body></html>
HTML;
check('smoke: nav links are same-origin, deduplicated, without logout/destructive/js links', bo_extract_nav_links($dashboardHtml, 'http://bo.local/') === ['http://bo.local/sales', 'http://bo.local/product-management']);
check('smoke: no nav container -> all same-origin links', bo_extract_nav_links('<div><a href="/a">A</a><a href="b">B</a><a href="https://x.io/">X</a></div>', 'http://bo.local/dir/page') === ['http://bo.local/a', 'http://bo.local/dir/b']);
check('smoke: destructive paths and queries skipped', bo_is_destructive('http://bo.local/sales/cancel') && bo_is_destructive('http://bo.local/x?action=remove') && bo_is_destructive('http://bo.local/user/deactivate?id=1'));
check('smoke: read-only page is not destructive', !bo_is_destructive('http://bo.local/sales/detail?id-sales-order=1'));

$tablesHtml = '<table class="table gui-table-data" data-ajax="/sales/index/table?x=1&amp;y=2"></table>'
    . '<table data-url="/customer/index/table"></table><table data-ajax="/sales/index/table?x=1&amp;y=2"></table><table></table>';
check('smoke: table data-ajax/data-url endpoints extracted, entities decoded, deduplicated', bo_extract_table_endpoints($tablesHtml, 'http://bo.local/sales') === ['http://bo.local/sales/index/table?x=1&y=2', 'http://bo.local/customer/index/table']);

check('smoke: Whoops page detected', bo_detect_exception('<div class="Whoops container">x</div>') === 'Whoops');
check('smoke: Stack trace / Fatal error / Uncaught detected', bo_detect_exception('<pre>Stack trace: #0</pre>') !== null && bo_detect_exception('Fatal error: x') !== null && bo_detect_exception('Uncaught TypeError') !== null);
check('smoke: Exception in title detected', str_contains((string)bo_detect_exception('<title>Symfony\Component\HttpKernel\Exception\NotFoundHttpException</title>'), 'NotFoundHttpException'));
check('smoke: Exception outside title/h1 is not flagged', bo_detect_exception('<title>Sales</title><p>Exception list</p>') === null);

$ok = ['status' => 200, 'url' => 'http://bo.local/sales/index/table', 'body' => '{"data":[]}', 'contentType' => 'application/json', 'error' => null];
check('smoke: JSON table response is a success', bo_problem($ok, 'table', '/security-gui/login') === null);
check('smoke: HTML table response is a problem', str_starts_with((string)bo_problem(['body' => '<html>Whoops</html>'] + $ok, 'table', '/security-gui/login'), 'not JSON'));
check('smoke: 500 page is a problem', bo_problem(['status' => 500, 'body' => ''] + $ok, 'page', '/security-gui/login') === 'HTTP 500');
check('smoke: redirect back to login is a problem', bo_problem(['url' => 'http://bo.local/security-gui/login', 'body' => '<html></html>'] + $ok, 'page', '/security-gui/login') === 'redirected to login');
check('smoke: relative href resolved against the page directory', bo_resolve_url('index/table', 'http://bo.local/sales/list') === 'http://bo.local/sales/index/table');

[$smokeNoUrl] = run_script('backoffice-smoke.php', [], $tooling, $toolingState);
check('smoke CLI: missing --url -> exit 2', $smokeNoUrl === 2);
[$smokeNoCreds, $smokeNoCredsOut] = run_script('backoffice-smoke.php', ['--url', 'http://bo.local'], $tooling, $toolingState, ['SPRYKER_BACKOFFICE_USER' => '', 'SPRYKER_BACKOFFICE_PASSWORD' => '']);
check('smoke CLI: missing credentials -> exit 2', $smokeNoCreds === 2 && str_contains($smokeNoCredsOut, 'SPRYKER_BACKOFFICE_PASSWORD'));

$smokeDir = $tmp . '/smoke';
$smokeState = $smokeDir . '/.state';
write_file($smokeDir . '/composer.json', "{}\n");
write_file($smokeDir . '/router.php', <<<'PHP'
<?php
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scenario = explode('/', trim($path, '/'))[0] ?? '';
$form = '<form method="post" action="/' . $scenario . '/login_check"><input type="text" name="u"><input type="password" name="p"><input type="hidden" name="_token" value="t"></form>';
if ($scenario === 'noform') {
    echo '<html><body>Maintenance</body></html>';
} elseif ($scenario === 'fail500') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(500);
        echo 'Internal Server Error';
    } else {
        echo $form;
    }
} elseif ($path === "/$scenario/security-gui/login") {
    echo $form;
} elseif ($path === "/$scenario/login_check") {
    if ($scenario === 'ok' && ($_POST['p'] ?? '') === 'secret') {
        setcookie('session', '1', 0, '/');
    }
    header('Location: /' . $scenario . '/');
} elseif (($_COOKIE['session'] ?? '') !== '1') {
    header('Location: /' . $scenario . '/security-gui/login');
} elseif ($path === "/$scenario/sales/table") {
    header('Content-Type: application/json');
    echo '{"data":[]}';
} else {
    echo '<html><body><nav><a href="/' . $scenario . '/sales">Sales</a></nav><table data-ajax="/' . $scenario . '/sales/table"></table></body></html>';
}
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = $probe === false ? 0 : (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
if ($probe !== false) {
    fclose($probe);
}
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $smokeDir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $serverPipes, $smokeDir);
$serverUp = false;
for ($attempt = 0; $port > 0 && $attempt < 50 && !$serverUp; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    $serverUp = $socket !== false;
    $serverUp ? fclose($socket) : usleep(100000);
}
check('smoke CLI: local test server started', $serverUp);
$smokeCreds = ['SPRYKER_BACKOFFICE_USER' => 'admin', 'SPRYKER_BACKOFFICE_PASSWORD' => 'secret'];
$smokeFiles = static fn(): bool => is_file($smokeState . '/backoffice-smoke-report.json') || is_file($smokeState . '/backoffice-smoke-baseline.json');
$base = 'http://127.0.0.1:' . $port;

[$smokeUnreachable, $smokeUnreachableOut] = run_script('backoffice-smoke.php', ['--url', 'http://127.0.0.1:1', '--timeout', '2', '--baseline'], $smokeDir, $smokeState, $smokeCreds);
check('smoke CLI: unreachable URL -> exit 2, no report or baseline', $smokeUnreachable === 2 && !$smokeFiles() && str_contains($smokeUnreachableOut, 'No report or baseline written'));
[$smokeNoForm] = run_script('backoffice-smoke.php', ['--url', $base . '/noform', '--baseline'], $smokeDir, $smokeState, $smokeCreds);
check('smoke CLI: login page without a form -> exit 2, no report or baseline', $smokeNoForm === 2 && !$smokeFiles());
[$smokeFail500] = run_script('backoffice-smoke.php', ['--url', $base . '/fail500'], $smokeDir, $smokeState, $smokeCreds);
check('smoke CLI: login POST answered with 500 -> exit 2, no report', $smokeFail500 === 2 && !$smokeFiles());
[$smokeBadCreds] = run_script('backoffice-smoke.php', ['--url', $base . '/ok', '--baseline'], $smokeDir, $smokeState, ['SPRYKER_BACKOFFICE_PASSWORD' => 'wrong'] + $smokeCreds);
check('smoke CLI: wrong password -> exit 2, no baseline', $smokeBadCreds === 2 && !$smokeFiles());
[$smokeOk, $smokeOkOut] = run_script('backoffice-smoke.php', ['--url', $base . '/ok', '--baseline'], $smokeDir, $smokeState, $smokeCreds);
$smokeBaseline = read_json($smokeState . '/backoffice-smoke-baseline.json');
check('smoke CLI: successful login -> exit 0, baseline written with pages and table endpoints', $smokeOk === 0 && ($smokeBaseline['pages'] ?? 0) === 2 && ($smokeBaseline['tables'] ?? 0) === 1);
check('smoke CLI: a successful baseline passes the baseline content check', bl_content_problem('backoffice-smoke-baseline.json', (string)@file_get_contents($smokeState . '/backoffice-smoke-baseline.json')) === null);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

// --- check-constant-overrides ---
$constants = $tmp . '/constants';
$constantsState = $constants . '/.state';
write_file($constants . '/composer.json', "{}\n");
write_file($constants . '/config/Shared/config_default.php', "<?php\n\$config[KernelConstants::PROJECT_NAMESPACES] = [\n    'Pyz',\n    'Acme',\n    'Missing',\n];\n");
write_file($constants . '/vendor/composer/autoload_psr4.php', "<?php\n\$vendorDir = dirname(__DIR__);\nreturn array('Spryker\\\\' => array(\$vendorDir . '/spryker/spryker/src/Spryker'));\n");
write_file($constants . '/vendor/composer/autoload_classmap.php', "<?php\nreturn array();\n");
$vendorKernel = <<<'PHP'
<?php
namespace Spryker\Zed\Kernel;

abstract class AbstractBundleDependencyProvider
{
    protected const CHAIN_LIMIT = 5;
}
PHP;
$vendorFoo = fixture('constants/VendorFooDependencyProvider.php.fixture');
write_file($constants . '/vendor/spryker/spryker/src/Spryker/Zed/Kernel/AbstractBundleDependencyProvider.php', $vendorKernel);
write_file($constants . '/vendor/spryker/spryker/src/Spryker/Zed/Foo/FooDependencyProvider.php', $vendorFoo);
write_file($constants . '/vendor/spryker/spryker/src/Spryker/Zed/Bar/BarConfig.php', "<?php\nnamespace Spryker\\Zed\\Bar;\n\nclass BarConfig\n{\n    protected const TIMEOUT = 60;\n}\n");
write_file($constants . '/src/Pyz/Zed/Foo/FooDependencyProvider.php', fixture('constants/FooDependencyProvider.php.fixture'));
write_file($constants . '/src/Acme/Zed/Bar/BarConfig.php', "<?php\nnamespace Acme\\Zed\\Bar;\n\nclass BarConfig extends \\Spryker\\Zed\\Bar\\BarConfig\n{\n    protected const TIMEOUT = 30;\n}\n");
write_file($constants . '/src/Pyz/Zed/Foo/FooChildDependencyProvider.php', "<?php\nnamespace Pyz\\Zed\\Foo;\n\nclass FooChildDependencyProvider extends FooDependencyProvider\n{\n    protected const BATCH_SIZE = 700;\n}\n");

check('constants: project namespaces read from PROJECT_NAMESPACES, directories that do not exist dropped', spryker_upgrade_project_namespaces($constants) === ['Pyz', 'Acme']);
$parsedFoo = co_parse_classes($vendorFoo)[0] ?? [];
check('constants: parser reads class, parent and constants', ($parsedFoo['name'] ?? '') === 'Spryker\Zed\Foo\FooDependencyProvider' && ($parsedFoo['parent'] ?? '') === 'Spryker\Zed\Kernel\AbstractBundleDependencyProvider' && count($parsedFoo['constants'] ?? []) === 6);
check('constants: array() and [] with a trailing comma normalise alike', implode('', $parsedFoo['constants']['LIST']['tokens'] ?? []) === '[1,2]');

[$coNoSnapshot] = run_script('check-constant-overrides.php', [], $constants, $constantsState);
check('constants CLI: default run without a snapshot -> exit 2', $coNoSnapshot === 2);
[$coSnapCode, $coSnapOut] = run_script('check-constant-overrides.php', ['--snapshot'], $constants, $constantsState);
$coBaseline = read_json($constantsState . '/constant-overrides-baseline.json');
$coByKey = [];
foreach ($coBaseline['overrides'] ?? [] as $override) {
    $coByKey[$override['class'] . '::' . $override['constant']] = $override;
}
check('constants CLI: --snapshot exits 0 although values differ', $coSnapCode === 0 && str_contains($coSnapOut, '3 with a different value'));
check('constants: different value reported with project and core value and vendor file', ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::BATCH_SIZE']['same'] ?? true) === false
    && ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::BATCH_SIZE']['coreValue'] ?? '') === '100'
    && ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::BATCH_SIZE']['coreFile'] ?? '') === 'vendor/spryker/spryker/src/Spryker/Zed/Foo/FooDependencyProvider.php');
check('constants: same value is not a difference (quote style ignored)', ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::PLUGINS_FOO']['same'] ?? false) && ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::MODE']['same'] ?? false));
check('constants: self:: reference and concatenation resolved before comparing', ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::LABEL']['same'] ?? false) && ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::LABEL']['coreKind'] ?? '') === 'literal');
check('constants: a constant only the project declares is ignored', !isset($coByKey['Pyz\Zed\Foo\FooDependencyProvider::PROJECT_ONLY']));
check('constants: two-level vendor chain resolves the grandparent value', ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::CHAIN_LIMIT']['coreClass'] ?? '') === 'Spryker\Zed\Kernel\AbstractBundleDependencyProvider' && ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::CHAIN_LIMIT']['coreValue'] ?? '') === '5');
check('constants: second project namespace scanned, Config owned by Lane 4', ($coByKey['Acme\Zed\Bar\BarConfig::TIMEOUT']['lane'] ?? '') === 'Lane 4' && ($coByKey['Pyz\Zed\Foo\FooDependencyProvider::BATCH_SIZE']['lane'] ?? '') === 'Lane 3');
check('constants: a constant whose nearest declaration is a project class is not compared to vendor', !isset($coByKey['Pyz\Zed\Foo\FooChildDependencyProvider::BATCH_SIZE']));
[$coSameCode] = run_script('check-constant-overrides.php', [], $constants, $constantsState);
check('constants CLI: unchanged core -> exit 0', $coSameCode === 0);

write_file($constants . '/vendor/spryker/spryker/src/Spryker/Zed/Foo/FooDependencyProvider.php', str_replace(['BATCH_SIZE = 100', "MODE = 'strict'"], ['BATCH_SIZE = 200', "MODE = 'lenient'"], $vendorFoo));
write_file($constants . '/vendor/spryker/spryker/src/Spryker/Zed/Kernel/AbstractBundleDependencyProvider.php', str_replace('    protected const CHAIN_LIMIT = 5;' . "\n", '', $vendorKernel));
[$coChangedCode, $coChangedOut] = run_script('check-constant-overrides.php', [], $constants, $constantsState);
$coReport = read_json($constantsState . '/constant-overrides-report.json');
$coChanges = [];
foreach ($coReport['coreChanges'] ?? [] as $change) {
    $coChanges[$change['constant']] = $change;
}
check('constants CLI: core value changed under an override -> exit 1', $coChangedCode === 1 && str_contains($coChangedOut, 'CORE_VALUE_CHANGED'));
check('constants: changed core value reported with before and now', ($coChanges['BATCH_SIZE']['coreValueBefore'] ?? '') === '100' && ($coChanges['BATCH_SIZE']['coreValueNow'] ?? '') === '200');
check('constants: project value equal to the old core value is reported when core moves', ($coChanges['MODE']['type'] ?? '') === 'CORE_VALUE_CHANGED' && ($coChanges['MODE']['projectValue'] ?? '') === "'strict'");
check('constants: core constant removed under an override reported', ($coChanges['CHAIN_LIMIT']['type'] ?? '') === 'CORE_CONSTANT_REMOVED');
check('constants: exactly three core changes', count($coChanges) === 3);
[$coBadArg] = run_script('check-constant-overrides.php', ['--bogus'], $constants, $constantsState);
check('constants CLI: unknown argument -> exit 2', $coBadArg === 2);

// --- check-constant-overrides: interfaces ---
$ifaces = $tmp . '/constants-interfaces';
$ifacesState = $ifaces . '/.state';
$ifacesVendor = $ifaces . '/vendor/spryker/spryker/src/Spryker';
write_file($ifaces . '/composer.json', "{}\n");
write_file($ifaces . '/vendor/composer/autoload_psr4.php', "<?php\n\$vendorDir = dirname(__DIR__);\nreturn array('Spryker\\\\' => array(\$vendorDir . '/spryker/spryker/src/Spryker'));\n");
write_file($ifaces . '/vendor/composer/autoload_classmap.php', "<?php\nreturn array();\n");
$vendorLimits = <<<'PHP'
<?php
namespace Spryker\Zed\Baz\Dependency;

interface BazLimitsInterface
{
    public const PAGE_SIZE = 20;
    final public const HARD_CAP = 1000;
}
PHP;
write_file($ifacesVendor . '/Zed/Baz/Dependency/BazLimitsInterface.php', $vendorLimits);
write_file($ifacesVendor . '/Zed/Baz/Dependency/BazPagingInterface.php', "<?php\nnamespace Spryker\\Zed\\Baz\\Dependency;\n\ninterface BazPagingInterface extends \\Countable, BazLimitsInterface\n{\n    public const ORDER = 'asc';\n}\n");
write_file($ifacesVendor . '/Zed/Baz/Business/BazReader.php', "<?php\nnamespace Spryker\\Zed\\Baz\\Business;\n\nuse Spryker\\Zed\\Baz\\Dependency\\BazPagingInterface;\n\nclass BazReader implements BazPagingInterface\n{\n    public const PAGE_SIZE = 25;\n\n    public function count(): int\n    {\n        return 0;\n    }\n}\n");
write_file($ifacesVendor . '/Shared/Foo/FooConstants.php', "<?php\nnamespace Spryker\\Shared\\Foo;\n\ninterface FooConstants\n{\n    public const FOO_HOST = 'FOO_HOST';\n}\n");
write_file($ifaces . '/src/Pyz/Zed/Baz/Business/BazWriter.php', "<?php\nnamespace Pyz\\Zed\\Baz\\Business;\n\nuse Spryker\\Zed\\Baz\\Dependency\\BazLimitsInterface;\n\nclass BazWriter implements BazLimitsInterface\n{\n    public const PAGE_SIZE = 40;\n    public const HARD_CAP = 500;\n}\n");
write_file($ifaces . '/src/Pyz/Zed/Baz/Business/BazReader.php', "<?php\nnamespace Pyz\\Zed\\Baz\\Business;\n\nclass BazReader extends \\Spryker\\Zed\\Baz\\Business\\BazReader\n{\n    public const PAGE_SIZE = 50;\n    public const ORDER = 'desc';\n}\n");
write_file($ifaces . '/src/Pyz/Shared/Foo/FooConstants.php', "<?php\nnamespace Pyz\\Shared\\Foo;\n\ninterface FooConstants extends \\Spryker\\Shared\\Foo\\FooConstants\n{\n    public const FOO_HOST = 'PYZ_FOO_HOST';\n    public const PROJECT_KEY = 'PROJECT_KEY';\n}\n");
write_file($ifaces . '/src/Pyz/Zed/Baz/Dependency/BazOptionsInterface.php', "<?php\nnamespace Pyz\\Zed\\Baz\\Dependency;\n\nuse Spryker\\Zed\\Baz\\Dependency\\BazPagingInterface;\n\ninterface BazOptionsInterface extends \\Countable, BazPagingInterface\n{\n    public const PAGE_SIZE = 20;\n}\n");

$parsedLimits = co_parse_classes($vendorLimits)[0] ?? [];
check('constants: parser reads an interface and the final modifier of its constants', ($parsedLimits['kind'] ?? '') === 'interface'
    && ($parsedLimits['constants']['HARD_CAP']['final'] ?? false) === true && ($parsedLimits['constants']['PAGE_SIZE']['final'] ?? true) === false);
$parsedOptions = co_parse_classes((string)file_get_contents($ifaces . '/src/Pyz/Zed/Baz/Dependency/BazOptionsInterface.php'))[0] ?? [];
check('constants: parser reads every interface an interface extends', ($parsedOptions['interfaces'] ?? []) === ['Countable', 'Spryker\Zed\Baz\Dependency\BazPagingInterface'] && array_key_exists('parent', $parsedOptions) && $parsedOptions['parent'] === null);

[$ifSnapCode] = run_script('check-constant-overrides.php', ['--snapshot'], $ifaces, $ifacesState);
$ifByKey = [];
foreach (read_json($ifacesState . '/constant-overrides-baseline.json')['overrides'] ?? [] as $override) {
    $ifByKey[$override['class'] . '::' . $override['constant']] = $override;
}
$writerPage = $ifByKey['Pyz\Zed\Baz\Business\BazWriter::PAGE_SIZE'] ?? [];
check('constants: class implementing a vendor interface with a differing constant reported', $ifSnapCode === 0 && ($writerPage['same'] ?? true) === false
    && ($writerPage['coreClass'] ?? '') === 'Spryker\Zed\Baz\Dependency\BazLimitsInterface' && ($writerPage['coreValue'] ?? '') === '20'
    && ($writerPage['sourceKind'] ?? '') === 'class' && ($writerPage['coreSourceKind'] ?? '') === 'interface' && ($writerPage['lane'] ?? '') === 'Lane 1');
check('constants: final core constant flagged', ($ifByKey['Pyz\Zed\Baz\Business\BazWriter::HARD_CAP']['coreFinal'] ?? false) === true && ($writerPage['coreFinal'] ?? true) === false);
check('constants: nearest declaration wins (vendor parent class before its interface)', ($ifByKey['Pyz\Zed\Baz\Business\BazReader::PAGE_SIZE']['coreValue'] ?? '') === '25'
    && ($ifByKey['Pyz\Zed\Baz\Business\BazReader::PAGE_SIZE']['coreSourceKind'] ?? '') === 'class');
check('constants: interface constant reached through the vendor parent class', ($ifByKey['Pyz\Zed\Baz\Business\BazReader::ORDER']['coreClass'] ?? '') === 'Spryker\Zed\Baz\Dependency\BazPagingInterface'
    && ($ifByKey['Pyz\Zed\Baz\Business\BazReader::ORDER']['same'] ?? true) === false);
$fooHost = $ifByKey['Pyz\Shared\Foo\FooConstants::FOO_HOST'] ?? [];
check('constants: project interface extending a vendor interface reported, Constants owned by Lane 4', ($fooHost['sourceKind'] ?? '') === 'interface'
    && ($fooHost['coreValue'] ?? '') === "'FOO_HOST'" && ($fooHost['same'] ?? true) === false && ($fooHost['lane'] ?? '') === 'Lane 4'
    && !isset($ifByKey['Pyz\Shared\Foo\FooConstants::PROJECT_KEY']));
$optionsPage = $ifByKey['Pyz\Zed\Baz\Dependency\BazOptionsInterface::PAGE_SIZE'] ?? [];
check('constants: interface-of-interface chain resolves the declaring vendor interface', ($optionsPage['coreClass'] ?? '') === 'Spryker\Zed\Baz\Dependency\BazLimitsInterface'
    && ($optionsPage['same'] ?? false) === true && ($optionsPage['sourceKind'] ?? '') === 'interface');
[$ifSameCode] = run_script('check-constant-overrides.php', [], $ifaces, $ifacesState);
check('constants CLI: unchanged core interfaces -> exit 0', $ifSameCode === 0);

write_file($ifacesVendor . '/Zed/Baz/Dependency/BazLimitsInterface.php', str_replace('PAGE_SIZE = 20', 'PAGE_SIZE = 30', $vendorLimits));
[$ifChangedCode, $ifChangedOut] = run_script('check-constant-overrides.php', [], $ifaces, $ifacesState);
$ifChanges = [];
foreach (read_json($ifacesState . '/constant-overrides-report.json')['coreChanges'] ?? [] as $change) {
    $ifChanges[$change['class'] . '::' . $change['constant']] = $change;
}
check('constants CLI: core interface value changed under an override -> exit 1', $ifChangedCode === 1 && str_contains($ifChangedOut, 'CORE_VALUE_CHANGED'));
check('constants: interface value change reported for the implementing class and the extending interface', count($ifChanges) === 2
    && ($ifChanges['Pyz\Zed\Baz\Business\BazWriter::PAGE_SIZE']['coreValueNow'] ?? '') === '30'
    && ($ifChanges['Pyz\Zed\Baz\Dependency\BazOptionsInterface::PAGE_SIZE']['coreValueBefore'] ?? '') === '20'
    && ($ifChanges['Pyz\Zed\Baz\Dependency\BazOptionsInterface::PAGE_SIZE']['sourceKind'] ?? '') === 'interface');

// --- storage-search-counts ---
$compareRows = array_column(ssc_compare(
    ['a_storage' => 10, 'b_search' => 5, 'c_storage' => 3, 'd_storage' => 4, 'f_storage' => 0],
    ['a_storage' => 10, 'b_search' => 2, 'd_storage' => 0, 'e_search' => 7, 'f_storage' => 0]
), null, 'table');
check('counts compare: equal table is same, not failing', ($compareRows['a_storage']['status'] ?? '') === 'same' && !($compareRows['a_storage']['failing'] ?? true));
check('counts compare: drop reported with delta and failing', ($compareRows['b_search']['status'] ?? '') === 'dropped' && ($compareRows['b_search']['delta'] ?? 0) === -3 && ($compareRows['b_search']['failing'] ?? false));
check('counts compare: disappeared table failing', ($compareRows['c_storage']['status'] ?? '') === 'disappeared' && ($compareRows['c_storage']['failing'] ?? false));
check('counts compare: rows before and 0 after is emptied, failing', ($compareRows['d_storage']['status'] ?? '') === 'emptied' && ($compareRows['d_storage']['failing'] ?? false));
check('counts compare: new table is informational', ($compareRows['e_search']['status'] ?? '') === 'appeared' && !($compareRows['e_search']['failing'] ?? true));
check('counts compare: empty before and after is same', ($compareRows['f_storage']['status'] ?? '') === 'same');
$dockerEnv = ['SPRYKER_DB_ENGINE' => 'pgsql', 'SPRYKER_DB_HOST' => 'database', 'SPRYKER_DB_PORT' => '5432', 'SPRYKER_DB_DATABASE' => 'eu-docker', 'SPRYKER_DB_USERNAME' => 'spryker', 'SPRYKER_DB_PASSWORD' => 'pw', 'OTHER_PW' => 'other'];
$pgConnection = ssc_connection([], $dockerEnv);
check('counts connection: Docker SDK variables build a pgsql DSN', $pgConnection['dsn'] === 'pgsql:host=database;port=5432;dbname=eu-docker' && $pgConnection['user'] === 'spryker' && $pgConnection['password'] === 'pw');
$overridden = ssc_connection(['dsn' => 'mysql:host=db;dbname=shop', 'user' => 'root', 'password-env' => 'OTHER_PW'], $dockerEnv);
check('counts connection: --dsn, --user and --password-env override', $overridden['engine'] === 'mysql' && $overridden['database'] === 'shop' && $overridden['user'] === 'root' && $overridden['password'] === 'other');

$counts = $tmp . '/counts';
$countsState = $counts . '/.spryker-upgrade/state';
write_file($counts . '/composer.json', "{}\n");
mkdir($countsState, 0777, true);
copy(__DIR__ . '/storage-search-counts.php', $countsState . '/storage-search-counts.php');
$sqliteFile = $counts . '/db.sqlite';
$countsScript = $countsState . '/storage-search-counts.php';
$runCounts = static function (array $args, array $env = []) use ($countsScript, $counts): array {
    $process = proc_open(array_merge([PHP_BINARY, $countsScript], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $counts, array_merge(getenv(), $env));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string)$output];
};
if (extension_loaded('pdo_sqlite')) {
    $sqlite = new PDO('sqlite:' . $sqliteFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sqlite->exec('CREATE TABLE spy_product_abstract_storage (id INTEGER); CREATE TABLE spy_product_page_search (id INTEGER); CREATE TABLE spy_product (id INTEGER); CREATE TABLE spy_storage_log (id INTEGER)');
    $sqlite->exec('INSERT INTO spy_product_abstract_storage VALUES (1), (2), (3); INSERT INTO spy_product_page_search VALUES (1), (2); INSERT INTO spy_product VALUES (1)');
    check('counts: only *_storage and *_search tables counted', ssc_count_tables($sqlite, 'sqlite') === ['spy_product_abstract_storage' => 3, 'spy_product_page_search' => 2]);
    [$snapBefore] = $runCounts(['--snapshot', 'before', '--dsn', 'sqlite:' . $sqliteFile]);
    $beforeSnapshot = read_json($countsState . '/storage-search-counts-before.json');
    check('counts CLI: --snapshot writes the file next to itself with label, engine, table count and total rows', $snapBefore === 0 && ($beforeSnapshot['label'] ?? '') === 'before' && ($beforeSnapshot['engine'] ?? '') === 'sqlite' && ($beforeSnapshot['tableCount'] ?? 0) === 2 && ($beforeSnapshot['totalRows'] ?? 0) === 5);
    check('counts CLI: snapshot passes the baseline content check', bl_content_problem('storage-search-counts-before.json', (string)file_get_contents($countsState . '/storage-search-counts-before.json')) === null);
    $sqlite->exec('CREATE TABLE spy_cms_block_storage (id INTEGER); INSERT INTO spy_cms_block_storage VALUES (1)');
    $runCounts(['--snapshot', 'grown', '--dsn', 'sqlite:' . $sqliteFile]);
    [$cmpGrown, $cmpGrownOut] = $runCounts(['--compare', 'before', 'grown']);
    check('counts CLI: a new table is informational -> exit 0', $cmpGrown === 0 && str_contains($cmpGrownOut, 'APPEARED'));
    [$cmpSame] = $runCounts(['--compare', 'before', 'before']);
    check('counts CLI: equal snapshots -> exit 0', $cmpSame === 0);
    $sqlite->exec('DELETE FROM spy_product_abstract_storage WHERE id > 1');
    $runCounts(['--snapshot', 'after', '--dsn', 'sqlite:' . $sqliteFile]);
    [$cmpDrop, $cmpDropOut] = $runCounts(['--compare', 'before', 'after']);
    $countsReport = read_json($countsState . '/storage-search-counts-report.json');
    check('counts CLI: a table that lost rows -> exit 1, report written', $cmpDrop === 1 && str_contains($cmpDropOut, 'DROPPED') && ($countsReport['failing'] ?? 0) === 1);
    $sqlite->exec('DROP TABLE spy_product_page_search');
    $runCounts(['--snapshot', 'gone', '--dsn', 'sqlite:' . $sqliteFile]);
    [$cmpGone, $cmpGoneOut] = $runCounts(['--compare', $countsState . '/storage-search-counts-grown.json', 'gone']);
    check('counts CLI: a disappeared table -> exit 1 (path and label accepted)', $cmpGone === 1 && str_contains($cmpGoneOut, 'DISAPPEARED'));
    $sqlite = null;
} else {
    check('counts: pdo_sqlite available for the counting tests', false);
}
[$snapNoLabel] = $runCounts(['--snapshot']);
check('counts CLI: --snapshot without a label -> exit 2', $snapNoLabel === 2);
[$snapNoEnv] = $runCounts(['--snapshot', 'x'], ['SPRYKER_DB_HOST' => '', 'SPRYKER_DB_DATABASE' => '']);
check('counts CLI: no Docker SDK variables and no --dsn -> exit 2', $snapNoEnv === 2);
[$cmpMissing] = $runCounts(['--compare', 'before', 'nope']);
check('counts CLI: a missing snapshot -> exit 2', $cmpMissing === 2);
[$noMode] = $runCounts([]);
check('counts CLI: no mode -> exit 2', $noMode === 2);

// --- check-performance: ProductGroupWidget after shop-ui 1.103.0 ---
$perf = $tmp . '/perf';
$perfState = $perf . '/.state';
$lock = static fn(array $versions): string => json_encode(['packages' => array_map(static fn(string $n, string $v): array => ['name' => $n, 'version' => $v], array_keys($versions), $versions)]);
$lockNow = ['spryker-shop/shop-ui' => '1.102.0', 'spryker-shop/product-group-widget' => '1.12.0'];
write_file($perf . '/composer.json', "{}\n");
write_file($perf . '/composer.lock', $lock($lockNow));
write_file($perf . '/config/Shared/config_default.php', "<?php\n\$config[KernelConstants::PROJECT_NAMESPACES] = ['Pyz'];\n");
mkdir($perf . '/src/Pyz', 0777, true);
[$perfCode, $perfOut] = run_script('check-performance.php', [], $perf, $perfState);
$perfReport = read_json($perfState . '/performance-report.json');
check('performance CLI: no Phase 0 lock -> crossing not checked, exit 0', $perfCode === 0 && array_key_exists('crossed', $perfReport['productGroupWidget'] ?? []) && $perfReport['productGroupWidget']['crossed'] === null && str_contains($perfOut, 'not checked'));

write_file($perfState . '/composer.lock.before', $lock(['spryker-shop/shop-ui' => '1.100.0', 'spryker-shop/product-group-widget' => '1.11.0']));
write_file($perf . '/composer.lock', $lock(['spryker-shop/shop-ui' => '1.103.0'] + $lockNow));
write_file($perf . '/data/import/local/full_EU.yml', "version: 0\n\nactions:\n  - data_entity: product-group\n    source: data/import/common/product_group.csv\n");
write_file($perf . '/data/import/common/product_group.csv', "group_key,abstract_sku,position\ngroup_key_1,001,0\n");
$productItem = $perf . '/src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-item/product-item.twig';
write_file($productItem, "{% extends molecule('product-item', '@SprykerShop:ShopUi') %}\n{% block colors %}{% widget 'ProductGroupColorWidget' args [1] use view('product-item-color-selector', 'ProductGroupWidget') only %}{% endwidget %}{% endblock %}\n");
[$perfLostCode, $perfLostOut] = run_script('check-performance.php', [], $perf, $perfState);
$perfReport = read_json($perfState . '/performance-report.json');
check('performance CLI: shop-ui crossed 1.103.0, groups imported, no template renders the widget -> exit 1', $perfLostCode === 1 && str_contains($perfLostOut, 'FOUND'));
check('performance: product group usage evidence from the import manifest', str_contains(implode(' ', $perfReport['productGroupWidget']['productGroups']['evidence'] ?? []), 'full_EU.yml'));
check('performance: ProductGroupColorWidget and view(..., ProductGroupWidget) do not count as rendering the widget', ($perfReport['productGroupWidget']['templates']['product-item'][0]['rendersWidget'] ?? true) === false);
write_file($perf . '/src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-card/product-card.twig', "{% block groups %}{% widget 'ProductGroupWidget' args [data.abstractId] only %}{% endwidget %}{% endblock %}\n");
[$perfRenderedCode] = run_script('check-performance.php', [], $perf, $perfState);
check('performance CLI: a project template renders ProductGroupWidget -> exit 0', $perfRenderedCode === 0);
unlink($perf . '/src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-card/product-card.twig');
write_file($perf . '/data/import/common/product_group.csv', "group_key,abstract_sku,position\n");
[$perfNoGroupsCode] = run_script('check-performance.php', [], $perf, $perfState);
check('performance CLI: no product groups imported -> exit 0', $perfNoGroupsCode === 0);
write_file($perfState . '/storage-search-counts-before.json', json_encode(['tables' => ['spy_product_abstract_group_storage' => 4], 'totalRows' => 4]));
[$perfRowsCode] = run_script('check-performance.php', [], $perf, $perfState);
check('performance CLI: group storage rows in the before snapshot count as product groups used -> exit 1', $perfRowsCode === 1);
[$perfBadLock] = run_script('check-performance.php', ['--before-lock', $perf . '/nope.lock'], $perf, $perfState);
check('performance CLI: missing --before-lock file -> exit 2', $perfBadLock === 2);

// --- check-performance: code-pattern candidates ---
write_file($perf . '/src/Pyz/Zed/Demo/Business/DemoWriter.php', "<?php\nclass DemoWriter\n{\n    public function write(array \$ids): void\n    {\n        foreach (\$ids as \$id) {\n            \$entity = SpyDemoQuery::create()->filterById(\$id)->findOne();\n            \$data = \$this->storageClient->get('demo:' . \$id);\n        }\n        \$entity->save();\n        \$keys = \$this->storageClient->getKeys('demo:*');\n    }\n}\n");
[$scanCode] = run_script('check-performance.php', ['--scan-code'], $perf, $perfState);
$scan = read_json($perfState . '/performance-code-scan.json')['candidates'] ?? [];
check('performance --scan-code: a query and a storage read inside a loop body are candidates', $scanCode === 0 && count($scan['query-in-loop'] ?? []) === 1 && str_contains($scan['query-in-loop'][0], 'DemoWriter.php:7') && count($scan['storage-read-in-loop'] ?? []) === 1);
check('performance --scan-code: a save after the loop is no candidate; a key scan anywhere is', ($scan['entity-save-in-loop'] ?? null) === [] && count($scan['storage-key-scan'] ?? []) === 1);

// --- check-performance: page list from the guideline index, status file, gate ---
$docs = $tmp . '/perf-docs';
$guideDir = $docs . '/docs/dg/dev/guidelines/performance-guidelines';
$page = static fn(string $title): string => "---\ntitle: $title\ntemplate: concept-topic-template\n---\n\nBody.\n";
write_file($guideDir . '/performance-guidelines.md', $page('Performance guidelines')
    . "- [Alpha](/docs/dg/dev/guidelines/performance-guidelines/alpha.html) for alpha.\n"
    . "- [Beta](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/beta.html#section) for beta.\n"
    . "- [Order page](/docs/pbc/all/order/latest/order-page.html) outside the folder.\n"
    . "- [Alpha again](/docs/dg/dev/guidelines/performance-guidelines/alpha.html) and [elsewhere](https://example.com/x.html).\n");
write_file($guideDir . '/alpha.md', $page('Alpha guidelines'));
write_file($guideDir . '/beta.md', $page('Beta guidelines'));
write_file($docs . '/docs/pbc/all/order/latest/order-page.md', $page('Order page performance'));
write_file($guideDir . '/elastic/ram-batch.md', $page('RAM-aware batches'));
write_file($guideDir . '/unlinked.md', $page('Unlinked guideline'));
$perfStatusFile = $perfState . '/performance-fixes.json';
@unlink($perfStatusFile);

[$verifyNoFile] = run_script('check-performance.php', ['--verify'], $perf, $perfState);
check('performance --verify: no status file -> exit 1', $verifyNoFile === 1);
[$initNoDocs] = run_script('check-performance.php', ['--init', '--docs', $tmp . '/no-docs'], $perf, $perfState);
check('performance --init: no guideline index in the docs copy -> exit 2', $initNoDocs === 2);
[$initCode, $initOut] = run_script('check-performance.php', ['--init', '--docs', $docs], $perf, $perfState);
$status = read_json($perfStatusFile);
check('performance --init: linked pages in index order, then folder pages incl. subfolders -> 5 pages', $initCode === 0 && array_keys($status['pages'] ?? []) === ['alpha', 'beta', 'order-page', 'ram-batch', 'unlinked']);
check('performance --init: each page pending, empty fixes, title from front matter, source recorded', array_unique(array_column($status['pages'], 'status')) === ['pending'] && array_unique(array_map('count', array_column($status['pages'], 'fixes'))) === [0] && ($status['pages']['order-page']['title'] ?? '') === 'Order page performance' && ($status['pages']['unlinked']['source'] ?? '') === 'folder' && ($status['pages']['beta']['source'] ?? '') === 'index');
[$verifyPending, $verifyPendingOut] = run_script('check-performance.php', ['--verify'], $perf, $perfState);
check('performance --verify: a pending page -> exit 1', $verifyPending === 1 && str_contains($verifyPendingOut, 'alpha: no fix recorded and no no-fix reason'));

$sized = ['--size', 'S', '--touches', 'src/Pyz/Zed/Demo/Business/DemoWriter.php', '--gain', 'one storage read per request instead of one per item'];
[$setNew] = run_script('check-performance.php', array_merge(['--set', 'alpha-1', '--status', 'pending', '--title', 'Cache the lookup', '--detected', 'src/Pyz/Zed/Demo/Business/DemoWriter.php:8 reads storage per item'], $sized), $perf, $perfState);
[$setNoDetected] = run_script('check-performance.php', array_merge(['--set', 'alpha-2', '--status', 'pending', '--title', 'x'], $sized), $perf, $perfState);
[$setNoPage] = run_script('check-performance.php', array_merge(['--set', 'gamma-1', '--status', 'pending', '--title', 'x', '--detected', 'y'], $sized), $perf, $perfState);
$status = read_json($perfStatusFile);
check('performance --set: a new fix needs title and detected and an existing page; the page becomes derived', $setNew === 0 && $setNoDetected === 2 && $setNoPage === 2 && ($status['pages']['alpha']['status'] ?? '') === 'derived' && count($status['pages']['alpha']['fixes']) === 1);
check('performance --set: size, touches and gain are recorded', ($status['pages']['alpha']['fixes'][0]['size'] ?? '') === 'S' && ($status['pages']['alpha']['fixes'][0]['touches'] ?? []) === ['src/Pyz/Zed/Demo/Business/DemoWriter.php'] && ($status['pages']['alpha']['fixes'][0]['gain'] ?? '') !== '');
[$setNoSize, $setNoSizeOut] = run_script('check-performance.php', ['--set', 'alpha-2', '--status', 'pending', '--title', 'x', '--detected', 'y', '--touches', 'a.php', '--gain', 'g'], $perf, $perfState);
[$setBadSize] = run_script('check-performance.php', ['--set', 'alpha-2', '--status', 'pending', '--title', 'x', '--detected', 'y', '--size', 'XL', '--touches', 'a.php', '--gain', 'g'], $perf, $perfState);
[$setNoGain] = run_script('check-performance.php', ['--set', 'alpha-2', '--status', 'pending', '--title', 'x', '--detected', 'y', '--size', 'M', '--touches', 'a.php'], $perf, $perfState);
[$setNaNoSize] = run_script('check-performance.php', ['--set', 'alpha-2', '--status', 'not-applicable', '--title', 'x', '--detected', 'already in place', '--reason', 'already in place', '--evidence', 'composer.lock'], $perf, $perfState);
check('performance --set: an applicable fix needs a size, touches and gain; not-applicable needs none', $setNoSize === 2 && str_contains($setNoSizeOut, 'no size') && $setBadSize === 2 && $setNoGain === 2 && $setNaNoSize === 0);
[$setNoFiles] = run_script('check-performance.php', ['--set', 'alpha-1', '--status', 'applied', '--evidence', 'pages identical'], $perf, $perfState);
check('performance --set: applied without files is rejected -> exit 2, nothing written', $setNoFiles === 2 && (read_json($perfStatusFile)['pages']['alpha']['fixes'][0]['status'] ?? '') === 'pending');
[$setNoReason] = run_script('check-performance.php', ['--set', 'alpha-1', '--status', 'not-applied', '--evidence', 'x'], $perf, $perfState);
check('performance --set: not-applied without a reason -> exit 2', $setNoReason === 2);
[$noFixEmpty] = run_script('check-performance.php', ['--no-fix', 'beta', '--reason', 'operator-only page'], $perf, $perfState);
[$noFixOnDerived] = run_script('check-performance.php', ['--no-fix', 'alpha', '--reason', 'r', '--evidence', 'e'], $perf, $perfState);
check('performance --no-fix: needs reason and evidence, and a page without fixes -> exit 2', $noFixEmpty === 2 && $noFixOnDerived === 2);

[$setM] = run_script('check-performance.php', ['--set', 'beta-2', '--status', 'pending', '--title', 'Product lists from search', '--detected', 'src/Pyz/Client/Catalog reads storage per product', '--size', 'M', '--touches', 'src/Pyz/Client/Catalog/CatalogClient.php,src/Pyz/Yves/CatalogPage/Controller/CatalogController.php', '--gain', 'one search request instead of one storage read per product'], $perf, $perfState);
[$setL] = run_script('check-performance.php', ['--set', 'beta-3', '--status', 'pending', '--title', 'Split the import', '--detected', 'one import step for all stores', '--size', 'L', '--touches', 'data/import/local/full_EU.yml', '--gain', 'shorter import'], $perf, $perfState);
[$applyNoDecision, $applyNoDecisionOut] = run_script('check-performance.php', ['--set', 'beta-2', '--status', 'applied', '--files', 'src/Pyz/Client/Catalog/CatalogClient.php', '--evidence', 'pages identical'], $perf, $perfState);
check('performance --set: an M fix is not applied without the developer decision -> exit 2', $setM === 0 && $setL === 0 && $applyNoDecision === 2 && str_contains($applyNoDecisionOut, 'M fix without the developer decision'));
[$setDeferred] = run_script('check-performance.php', ['--set', 'beta-2', '--status', 'deferred', '--reason', 'later', '--evidence', 'x'], $perf, $perfState);
check('performance --set: deferred is recorded with --decide only -> exit 2', $setDeferred === 2);
[$decisionsCode, $decisionsOut] = run_script('check-performance.php', ['--decisions'], $perf, $perfState);
check('performance --decisions: lists the M and L fixes with size, files and gain, not the S fix', $decisionsCode === 0 && str_contains($decisionsOut, 'beta-2 [M]') && str_contains($decisionsOut, 'beta-3 [L]') && str_contains($decisionsOut, 'CatalogController.php') && str_contains($decisionsOut, 'one search request') && !str_contains($decisionsOut, 'alpha-1'));
[$decideS] = run_script('check-performance.php', ['--decide', 'alpha-1', '--decision', 'apply'], $perf, $perfState);
[$decideNoReason] = run_script('check-performance.php', ['--decide', 'beta-2', '--decision', 'defer'], $perf, $perfState);
[$decideBad] = run_script('check-performance.php', ['--decide', 'beta-2', '--decision', 'later'], $perf, $perfState);
[$decisionNoDecide] = run_script('check-performance.php', ['--decision', 'apply'], $perf, $perfState);
check('performance --decide: rejects an S fix, defer without reason, an unknown decision, --decision alone', $decideS === 2 && $decideNoReason === 2 && $decideBad === 2 && $decisionNoDecide === 2);
[$decideDefer] = run_script('check-performance.php', ['--decide', 'beta-2', '--decision', 'defer', '--reason', 'developer: next release'], $perf, $perfState);
[$decideSkip] = run_script('check-performance.php', ['--decide', 'beta-3', '--decision', 'skip', '--reason', 'developer: import time is acceptable'], $perf, $perfState);
$status = read_json($perfStatusFile);
$betaFixes = array_column($status['pages']['beta']['fixes'] ?? [], null, 'id');
check('performance --decide: defer and skip set deferred and skipped with the developer decision and reason', $decideDefer === 0 && $decideSkip === 0 && ($betaFixes['beta-2']['status'] ?? '') === 'deferred' && ($betaFixes['beta-2']['decision'] ?? '') === 'defer' && ($betaFixes['beta-3']['status'] ?? '') === 'skipped' && ($betaFixes['beta-3']['reason'] ?? '') === 'developer: import time is acceptable');
[$backlogCode, $backlogOut] = run_script('check-performance.php', ['--backlog'], $perf, $perfState);
check('performance --backlog: one table row per deferred fix with size, files and gain', $backlogCode === 0 && str_contains($backlogOut, '| ID | Fix | Size | Files | Expected gain | Reason |') && str_contains($backlogOut, '| `beta-2` | Product lists from search | M | `src/Pyz/Client/Catalog/CatalogClient.php`, `src/Pyz/Yves/CatalogPage/Controller/CatalogController.php` | one search request') && !str_contains($backlogOut, 'beta-3'));

run_script('check-performance.php', ['--set', 'unlinked-2', '--status', 'pending', '--title', 'Batch the writer', '--detected', 'DemoWriter.php:7 query per item', '--size', 'L', '--touches', 'src/Pyz/Zed/Demo/Business/DemoWriter.php', '--gain', 'one query per batch'], $perf, $perfState);
[$decideApply] = run_script('check-performance.php', ['--decide', 'unlinked-2', '--decision', 'apply'], $perf, $perfState);
[$appliedL] = run_script('check-performance.php', ['--set', 'unlinked-2', '--status', 'applied', '--files', 'src/Pyz/Zed/Demo/Business/DemoWriter.php', '--evidence', 'suites green; pages identical for guest'], $perf, $perfState);
[$decideApplied] = run_script('check-performance.php', ['--decide', 'unlinked-2', '--decision', 'skip', '--reason', 'r'], $perf, $perfState);
check('performance --decide: apply keeps the L fix pending; it is then applied; no decision after applying', $decideApply === 0 && $appliedL === 0 && $decideApplied === 2 && (read_json($perfStatusFile)['pages']['unlinked']['fixes'][0]['decision'] ?? '') === 'apply');

[$initAgain] = run_script('check-performance.php', ['--init', '--docs', $docs], $perf, $perfState);
$status = read_json($perfStatusFile);
check('performance --init: a re-run keeps recorded fixes', $initAgain === 0 && ($status['pages']['alpha']['fixes'][0]['detected'] ?? '') === 'src/Pyz/Zed/Demo/Business/DemoWriter.php:8 reads storage per item');
write_file($guideDir . '/new-page.md', $page('New guideline'));
run_script('check-performance.php', ['--init', '--docs', $docs], $perf, $perfState);
$status = read_json($perfStatusFile);
check('performance --init: a page that appeared is added pending, recorded data kept', isset($status['pages']['new-page']) && $status['pages']['new-page']['status'] === 'pending' && count($status['pages']['alpha']['fixes']) === 2 && count($status['pages']) === 6);

$complete = [
    'alpha-1' => ['status' => 'applied', 'files' => ['src/Pyz/Zed/Demo/Business/DemoWriter.php'], 'evidence' => 'suites green; pages identical for guest, customer'],
    'beta-1' => ['status' => 'not-applied', 'title' => 'Smaller page size', 'detected' => 'catalog page size above the default', 'size' => 'S', 'touches' => ['config/Shared/config_default.php'], 'gain' => 'smaller search responses', 'reason' => 'proposal: a smaller page size changes what users see', 'evidence' => 'config_default.php:1'],
    'order-page' => ['status' => 'no-fix', 'reason' => 'the page describes a profiling procedure', 'evidence' => 'no project change named on the page'],
    'ram-batch-1' => ['status' => 'not-applicable', 'title' => 'RAM-aware batches', 'detected' => 'no custom publisher', 'reason' => 'operator: memory limit of the workers', 'evidence' => 'no setting in the repository'],
    'unlinked-1' => ['status' => 'reverted', 'title' => 'Index on a filter column', 'detected' => 'filter without index', 'size' => 'S', 'touches' => ['src/Pyz/Zed/Demo/Persistence/Propel/Schema/spy_demo.schema.xml'], 'gain' => 'faster filter query', 'reason' => 'search order changed', 'evidence' => 'positions 3 and 4 swapped'],
    'new-page' => ['status' => 'no-fix', 'reason' => 'already in place', 'evidence' => 'composer.lock: package at the named version'],
];
write_file($perfState . '/import-bad.json', json_encode(['beta-1' => ['status' => 'not-applied', 'title' => 't', 'detected' => 'd', 'size' => 'S', 'touches' => ['a.php'], 'gain' => 'g', 'evidence' => 'x']] + $complete));
[$importBad] = run_script('check-performance.php', ['--import', $perfState . '/import-bad.json'], $perf, $perfState);
check('performance --import: one incomplete entry rejects the whole file -> exit 2', $importBad === 2 && (read_json($perfStatusFile)['pages']['order-page']['status'] ?? '') === 'pending');
write_file($perfState . '/import.json', json_encode($complete));
[$importOk] = run_script('check-performance.php', ['--import', $perfState . '/import.json'], $perf, $perfState);
[$verifyAll, $verifyAllOut] = run_script('check-performance.php', ['--verify'], $perf, $perfState);
check('performance --verify: every page and fix accounted for -> exit 0; S applied with files is complete', $importOk === 0 && $verifyAll === 0 && str_contains($verifyAllOut, 'OK: every page') && str_contains($verifyAllOut, '1 deferred') && str_contains($verifyAllOut, '1 skipped'));

$fixIndex = static function (array $data, string $slug, string $id): int {
    foreach ($data['pages'][$slug]['fixes'] as $i => $fix) {
        if ($fix['id'] === $id) {
            return $i;
        }
    }

    return -1;
};
$verifyEdited = static function (callable $edit) use ($perfStatusFile, $perf, $perfState): array {
    $original = (string)file_get_contents($perfStatusFile);
    $data = json_decode($original, true);
    $edit($data);
    write_file($perfStatusFile, json_encode($data));
    $result = run_script('check-performance.php', ['--verify'], $perf, $perfState);
    write_file($perfStatusFile, $original);

    return $result;
};
$current = read_json($perfStatusFile);
$beta2 = $fixIndex($current, 'beta', 'beta-2');
$beta3 = $fixIndex($current, 'beta', 'beta-3');
[$vNoSize, $vNoSizeOut] = $verifyEdited(static function (array &$d) { $d['pages']['alpha']['fixes'][0]['size'] = ''; });
check('performance --verify: an applied fix without a size -> exit 1', $vNoSize === 1 && str_contains($vNoSizeOut, 'alpha-1: no size'));
[$vNoDecision, $vNoDecisionOut] = $verifyEdited(static function (array &$d) use ($beta2) { $d['pages']['beta']['fixes'][$beta2] = array_merge($d['pages']['beta']['fixes'][$beta2], ['status' => 'pending', 'decision' => '', 'reason' => '']); });
check('performance --verify: an M fix without the developer decision -> exit 1', $vNoDecision === 1 && str_contains($vNoDecisionOut, 'beta-2: M fix without the developer decision'));
[$vAppliedNoDecision] = $verifyEdited(static function (array &$d) use ($beta3) { $d['pages']['beta']['fixes'][$beta3] = array_merge($d['pages']['beta']['fixes'][$beta3], ['status' => 'applied', 'decision' => '', 'files' => ['a.php'], 'evidence' => 'e']); });
check('performance --verify: an L fix applied without the developer decision -> exit 1', $vAppliedNoDecision === 1);
[$vDeferredNoReason, $vDeferredNoReasonOut] = $verifyEdited(static function (array &$d) use ($beta2) { $d['pages']['beta']['fixes'][$beta2]['reason'] = ''; });
[$vSkippedNoReason, $vSkippedNoReasonOut] = $verifyEdited(static function (array &$d) use ($beta3) { $d['pages']['beta']['fixes'][$beta3]['reason'] = ''; });
check('performance --verify: deferred or skipped without a reason -> exit 1', $vDeferredNoReason === 1 && str_contains($vDeferredNoReasonOut, 'beta-2: deferred without a reason') && $vSkippedNoReason === 1 && str_contains($vSkippedNoReasonOut, 'beta-3: skipped without a reason'));
[$vDeferredS] = $verifyEdited(static function (array &$d) { $d['pages']['alpha']['fixes'][0] = array_merge($d['pages']['alpha']['fixes'][0], ['status' => 'deferred', 'reason' => 'later']); });
check('performance --verify: an S fix deferred without a developer decision -> exit 1', $vDeferredS === 1);

$edited = read_json($perfStatusFile);
$edited['pages']['alpha']['fixes'][0]['files'] = [];
write_file($perfStatusFile, json_encode($edited));
[$verifyNoFiles, $verifyNoFilesOut] = run_script('check-performance.php', ['--verify'], $perf, $perfState);
check('performance --verify: an applied fix without files -> exit 1', $verifyNoFiles === 1 && str_contains($verifyNoFilesOut, 'alpha-1: applied without the files it touched'));
$edited['pages']['alpha']['fixes'][0]['files'] = ['src/Pyz/Zed/Demo/Business/DemoWriter.php'];
$edited['pages']['order-page']['reason'] = '';
write_file($perfStatusFile, json_encode($edited));
[$verifyNoReason, $verifyNoReasonOut] = run_script('check-performance.php', ['--verify'], $perf, $perfState);
check('performance --verify: a page with no fixes and no no-fix reason -> exit 1', $verifyNoReason === 1 && str_contains($verifyNoReasonOut, 'order-page: no-fix without a reason and evidence'));

write_file($guideDir . '/performance-guidelines.md', (string)file_get_contents($guideDir . '/performance-guidelines.md') . "- [Gone](/docs/dg/dev/guidelines/performance-guidelines/gone.html)\n");
run_script('check-performance.php', ['--init', '--docs', $docs], $perf, $perfState);
$status = read_json($perfStatusFile);
[$setOnMissing] = run_script('check-performance.php', ['--set', 'gone-1', '--status', 'pending', '--title', 'x', '--detected', 'y'], $perf, $perfState);
[$noFixMissing] = run_script('check-performance.php', ['--no-fix', 'gone', '--reason', 'linked page not published', '--evidence', '404'], $perf, $perfState);
check('performance --init: a linked page missing from the copy is listed; it takes no-fix, not fixes', ($status['pages']['gone']['inDocs'] ?? true) === false && $setOnMissing === 2 && $noFixMissing === 0);

$perfOffline = $tmp . '/perf-offline-state';
[$unreachableNoEvidence] = run_script('check-performance.php', ['--unreachable'], $perf, $perfOffline);
[$unreachable] = run_script('check-performance.php', ['--unreachable', '--evidence', 'git clone: could not resolve host'], $perf, $perfOffline);
[$verifyOffline, $verifyOfflineOut] = run_script('check-performance.php', ['--verify'], $perf, $perfOffline);
check('performance --unreachable: needs evidence; --verify then passes and says the phase did not run', $unreachableNoEvidence === 2 && $unreachable === 0 && $verifyOffline === 0 && str_contains($verifyOfflineOut, 'NOT RUN'));
$edited = read_json($perfStatusFile);
$edited['pages']['new-page'] = array_merge($edited['pages']['new-page'], ['status' => 'pending', 'reason' => '', 'evidence' => '']);
write_file($perfStatusFile, json_encode($edited));
run_script('check-performance.php', ['--unreachable', '--evidence', 'curl: 503'], $perf, $perfState);
$status = read_json($perfStatusFile);
check('performance --unreachable: pending pages become not-checked with the reason, recorded pages stay', ($status['pages']['new-page']['status'] ?? '') === 'not-checked' && $status['pages']['new-page']['reason'] === 'docs unreachable' && ($status['pages']['alpha']['status'] ?? '') === 'derived');
run_script('check-performance.php', ['--init', '--docs', $docs], $perf, $perfState);
check('performance --init: with the docs reachable again, not-checked pages are pending', (read_json($perfStatusFile)['pages']['new-page']['status'] ?? '') === 'pending');
[$twoModes] = run_script('check-performance.php', ['--init', '--verify'], $perf, $perfState);
check('performance: two modes in one run -> exit 2', $twoModes === 2);

// --- run-tools and compare-tool-runs ---
$phpstanTable = static fn (array $errors): string => " ------ ------------------------------------------\n  Line   src/Pyz/Zed/Foo/FooFacade.php\n ------ ------------------------------------------\n"
    . implode('', array_map(static fn (array $e): string => sprintf("  %-6d %s\n         🪪  %s\n", $e[0], $e[1], $e[2]), $errors))
    . " ------ ------------------------------------------\n\n [ERROR] Found " . count($errors) . " error" . (count($errors) === 1 ? '' : 's') . "\n";
$phpstanBase = $phpstanTable([[12, 'Call to an undefined method Foo::bar().', 'method.notFound']]);
$phpstanAfter = $phpstanTable([[14, 'Call to an undefined method Foo::bar().', 'method.notFound'], [30, 'Parameter #1 $x of method Foo::baz() expects int, string given.', 'argument.type']]);
$sniffReport = static fn (array $violations): string => implode('', array_map(static fn (array $v): string => "FILE: /data/{$v[0]}\n----------------------------------------------------------------------\nFOUND 1 ERROR AFFECTING 1 LINE\n----------------------------------------------------------------------\n {$v[1]} | ERROR | [x] {$v[2]}\n    |       |     found\n----------------------------------------------------------------------\n", $violations)) . "Time: 1.2 secs; Memory: 20MB\n";
$sniffBase = $sniffReport([['src/Pyz/Zed/Foo/FooFacade.php', 12, 'Expected 1 space after FUNCTION keyword; 0']]);
$sniffAfter = $sniffReport([['src/Pyz/Zed/Foo/FooFacade.php', 13, 'Expected 1 space after FUNCTION keyword; 0'], ['src/Pyz/Zed/Bar/BarFacade.php', 5, 'Missing doc comment']]);
$evaluatorReport = static fn (array $rows): string => "=====================\nPHP VERSION CHECKER\n=====================\n\n+---+---------------------------+---------------+\n| # | Message                   | Target        |\n+---+---------------------------+---------------+\n"
    . implode('', array_map(static fn (int $i, array $r): string => sprintf("| %d | %-25s | %-13s |\n", $i + 1, $r[0], $r[1]), array_keys($rows), $rows))
    . "+---+---------------------------+---------------+\n\nRead more: https://docs.spryker.com/evaluator\n\n=================\nDEAD CODE CHECKER\n=================\n\nNo violations found\n";
$evaluatorBase = $evaluatorReport([['Composer PHP is 8.1', 'composer.json']]);
$evaluatorAfter = $evaluatorReport([['Composer PHP is 8.1', 'composer.json'], ['Deploy PHP is 8.0', 'deploy.yml']]);
$codeceptRun = static fn (string $barMark, int $failures): string => "Codeception PHP Testing Framework v5.1.2\n[PyzTest\\Zed\\Foo]: tests from /data/tests/PyzTest/Zed/Foo\n\n\e[1mBusiness Tests (3) ------------------------------\e[22m\n"
    . "- FooFacadeTest: Calculates price\r\e[32m✔\e[39m FooFacadeTest: Calculates price (0.01s)\r\n\e[31m✖\e[39m FooFacadeTest: Rounds tax (0.02s)\r\nS FooFacadeTest: Needs search\r\n------------------------------\n"
    . "[PyzTest\\Zed\\Bar]: tests from /data/tests/PyzTest/Zed/Bar\n\nBusiness Tests (1) ------\n$barMark BarFacadeTest: Reads bar (0.01s)\n------\nTime: 00:01.234, Memory: 40.00 MB\n\n"
    . "There " . ($failures === 1 ? 'was 1 failure' : "were $failures failures") . ":\n1) FooFacadeTest: Rounds tax\n Test  tests/PyzTest/Zed/Foo/Business/FooFacadeTest.php:testRoundsTax\n" . ($failures > 1 ? "2) BarFacadeTest: Reads bar\n" : '')
    . "\nFAILURES!\nTests: 4, Assertions: 5, Failures: $failures, Skipped: 1.\n";
$codeceptBase = $codeceptRun('✔', 1);
$codeceptAfter = $codeceptRun('✖', 2);

check('tool output: PHPStan errors by file and message, line number and identifier left out', to_phpstan_errors($phpstanBase) === ['src/Pyz/Zed/Foo/FooFacade.php: Call to an undefined method Foo::bar().']);
check('tool output: sniffer violations with wrapped message, line number left out', to_sniff_violations($sniffBase) === ['/data/src/Pyz/Zed/Foo/FooFacade.php: ERROR Expected 1 space after FUNCTION keyword; 0 found']);
check('tool output: evaluator violation rows per checker; a checker without violations adds none', to_evaluator_findings($evaluatorBase) === ['PHP VERSION CHECKER: Composer PHP is 8.1 (composer.json)']);
$suites = to_codecept_suites(to_plain_text($codeceptBase));
check('tool output: codecept tests per suite from the result lines, include namespace in the suite name', array_keys($suites) === ['PyzTest\\Zed\\Foo Business', 'PyzTest\\Zed\\Bar Business'] && $suites['PyzTest\\Zed\\Foo Business']['failed'] === ['FooFacadeTest: Rounds tax'] && count($suites['PyzTest\\Zed\\Foo Business']['skipped']) === 1 && $suites['PyzTest\\Zed\\Bar Business']['passed'] === ['BarFacadeTest: Reads bar']);
check('tool output: pseudo-terminal line ends (CR LF) keep the evaluator and sniffer tables readable', to_evaluator_findings(str_replace("\n", "\r\n", $evaluatorBase)) === to_evaluator_findings($evaluatorBase) && to_sniff_violations(to_plain_text(str_replace("\n", "\r\n", $sniffBase))) === to_sniff_violations($sniffBase));
check('tool output: codecept counts from the summary line when there are no result lines', to_codecept_counts("Tests: 10, Assertions: 20, Errors: 1, Failures: 2, Skipped: 3.\n") === ['tests' => 10, 'passed' => 4, 'failed' => 3, 'skipped' => 3]);
check('run-tools: macOS and Linux pseudo-terminal wrappers', rt_wrap(['docker/sdk', 'cli', 'x y'], 'Darwin') === ['script', '-q', '/dev/null', 'docker/sdk', 'cli', 'x y'] && rt_wrap(['docker/sdk', 'cli', 'x y'], 'Linux') === ['script', '-q', '-e', '-c', "'docker/sdk' 'cli' 'x y'", '/dev/null']);
check('run-tools: log names keep the names the gates read', rt_log_name('phpstan', 'baseline') === 'phpstan-baseline-run.txt' && rt_log_name('sniffer', 'post-tooling') === 'sniff-post-tooling.txt' && rt_log_name('tests', 'final') === 'codecept-final.txt' && rt_log_name('tests', 'after') === 'codecept-after.txt');
check('run-tools: phpstan command keeps the memory limit and the analysed paths', rt_tool_command('phpstan', ['sniffer' => 'x', 'codeceptOptions' => ''], '') === ['docker/sdk', 'cli', 'php', '-d', 'memory_limit=2048M', 'vendor/bin/phpstan', 'analyze', '-c', 'phpstan.neon', 'src/', '-l', '6']);

$runner = $tmp . '/runner';
$runnerState = $runner . '/.state';
$stub = $tmp . '/stub';
write_file($runner . '/composer.json', "{}\n");
mkdir($runner . '/src/Pyz', 0777, true);
mkdir($runner . '/vendor/spryker/development', 0777, true);
write_file($runner . '/docker/sdk', "#!/bin/sh\necho \"\$* ENV=\${SPRYKER_TESTING_ENABLED:-}\" >> \"\$STUB_DIR/calls.log\"\ncase \"\$*\" in\n  *phpstan*) key=phpstan;;\n  *code:sniff:style*|*phpcs*) key=sniffer;;\n  *evaluator*) key=evaluator;;\n  *'codecept build'*) key=build;;\n  *) key=tests;;\nesac\ncat \"\$STUB_DIR/\$key.out\" 2>/dev/null\nexit \$(cat \"\$STUB_DIR/\$key.exit\" 2>/dev/null || echo 0)\n");
chmod($runner . '/docker/sdk', 0755);
$stubOutput = static function (string $key, string $output, int $exit) use ($stub): void {
    write_file("$stub/$key.out", $output);
    write_file("$stub/$key.exit", (string)$exit);
};
$stubOutput('phpstan', " [OK] No errors\n", 0);
$stubOutput('sniffer', $sniffBase, 1);
$stubOutput('evaluator', "Error response from daemon: container cli is not running\n", 1);
$stubOutput('build', "Building Actor classes for suites: Business\n", 0);
$stubOutput('tests', $codeceptBase, 0);
$stubEnv = ['STUB_DIR' => $stub];
[$rtCode, $rtOut] = run_script('run-tools.php', ['baseline', 'phpstan', 'sniffer', 'evaluator', 'codecept-build', 'tests', '--tests-args=--no-exit', '--env', 'SPRYKER_TESTING_ENABLED=1'], $runner, $runnerState, $stubEnv);
$rtLines = explode("\n", trim($rtOut));
check('run-tools: exit 1 when a tool did not run', $rtCode === 1);
check('run-tools: a passing tool -> one line with exit code, result and log file', str_contains($rtLines[0] ?? '', 'phpstan') && str_contains($rtLines[0], 'exit 0') && str_contains($rtLines[0], '0 error(s)') && str_contains($rtLines[0], '.state/phpstan-baseline-run.txt'));
check('run-tools: a failing tool -> its finding count', str_contains($rtLines[1] ?? '', 'exit 1') && str_contains($rtLines[1], '1 violation(s)') && str_contains($rtLines[1], 'sniff-baseline.txt'));
check('run-tools: a tool that did not start -> "did not run" with the reason, no result', str_contains($rtLines[2] ?? '', 'did not run: Error response from daemon') && !str_contains($rtLines[2], 'finding'));
check('run-tools: red tests -> passed, failed and skipped, failing suite named', str_contains($rtLines[4] ?? '', '4 tests in 2 suite(s): 2 passed, 1 failed, 1 skipped') && str_contains($rtLines[4], 'PyzTest\\Zed\\Foo Business (1)'));
check('run-tools: no raw tool output on stdout', !str_contains($rtOut, 'Expected 1 space') && !str_contains($rtOut, 'Rounds tax'));
check('run-tools: full output in the log, ending with the exit code', str_contains((string)file_get_contents($runnerState . '/sniff-baseline.txt'), 'Expected 1 space') && preg_match('/exit 1\n$/', (string)file_get_contents($runnerState . '/sniff-baseline.txt')) === 1);
check('run-tools: check-baselines accepts the logs of tools that ran and rejects the one that did not', bl_content_problem('phpstan-baseline-run.txt', (string)file_get_contents($runnerState . '/phpstan-baseline-run.txt')) === null && bl_content_problem('codecept-baseline.txt', (string)file_get_contents($runnerState . '/codecept-baseline.txt')) === null && bl_content_problem('evaluator-baseline.txt', (string)file_get_contents($runnerState . '/evaluator-baseline.txt')) !== null);
$pinned = read_json($runnerState . '/tool-invocation.json');
check('run-tools: the baseline pins the sniffer it detected and the environment', ($pinned['sniffer'] ?? '') === 'vendor/bin/console code:sniff:style' && str_starts_with((string)($pinned['snifferSource'] ?? ''), 'detected') && ($pinned['env'] ?? []) === ['SPRYKER_TESTING_ENABLED' => '1']);
check('run-tools: tests run with the environment and the tests arguments', str_contains((string)file_get_contents($stub . '/calls.log'), 'testing codecept run --no-exit ENV=1'));
check('run-tools: tool-runs JSON holds the tests per suite', (read_json($runnerState . '/tool-runs-baseline.json')['tools']['tests']['suites']['PyzTest\\Zed\\Bar Business']['passed'] ?? 0) === 1);
@unlink($stub . '/calls.log');
[$rtFinal, $rtFinalOut] = run_script('run-tools.php', ['final', 'codecept-build', 'tests'], $runner, $runnerState, $stubEnv);
check('run-tools: a later run uses the pinned environment without --env', $rtFinal === 0 && str_contains((string)file_get_contents($stub . '/calls.log'), 'testing codecept run ENV=1') && is_file($runnerState . '/codecept-final.txt'));
[$rtOtherEnv] = run_script('run-tools.php', ['final', 'tests', '--env', 'SPRYKER_TESTING_ENABLED=0'], $runner, $runnerState, $stubEnv);
[$rtOtherSniffer] = run_script('run-tools.php', ['final', 'sniffer', '--sniffer', 'vendor/bin/phpcs'], $runner, $runnerState, $stubEnv);
check('run-tools: an environment or sniffer that differs from the pin -> exit 2', $rtOtherEnv === 2 && $rtOtherSniffer === 2);
[$rtNoPin, $rtNoPinOut] = run_script('run-tools.php', ['after', 'phpstan'], $runner, $tmp . '/runner-after', $stubEnv);
[$rtPinDir] = run_script('run-tools.php', ['after', 'phpstan', '--pin-dir', $runnerState], $runner, $tmp . '/runner-after', $stubEnv);
check('run-tools: no pinned invocation -> exit 2; --pin-dir reads the baseline pin', $rtNoPin === 2 && str_contains($rtNoPinOut, 'No pinned invocation') && $rtPinDir === 0 && is_file($tmp . '/runner-after/phpstan-after.txt'));
[$rtBadTool] = run_script('run-tools.php', ['baseline', 'psalm'], $runner, $runnerState, $stubEnv);
check('run-tools: unknown tool -> exit 2', $rtBadTool === 2);
rename($runner . '/docker/sdk', $runner . '/docker/sdk.off');
[$rtNoSdk, $rtNoSdkOut] = run_script('run-tools.php', ['detect', 'phpstan'], $runner, $runnerState, $stubEnv);
rename($runner . '/docker/sdk.off', $runner . '/docker/sdk');
check('run-tools: docker/sdk missing -> did not run, exit 1', $rtNoSdk === 1 && str_contains($rtNoSdkOut, 'did not run'));
check('run-tools: tool-runs JSON names the failing tests per suite', (read_json($runnerState . '/tool-runs-baseline.json')['tools']['tests']['failedTests'] ?? null) === ['PyzTest\\Zed\\Foo Business' => ['FooFacadeTest: Rounds tax']]);
check('run-tools: tool-runs JSON keeps the tools of an earlier call of the same run', array_keys(read_json($runnerState . '/tool-runs-final.json')['tools'] ?? []) === ['codecept-build', 'tests']);

// Several test configurations: one `codecept run` per --codecept-options, one log.
$multi = $tmp . '/runner-multi';
$multiState = $multi . '/.state';
write_file($multi . '/composer.json', "{}\n");
mkdir($multi . '/src/Pyz', 0777, true);
write_file($multi . '/docker/sdk', "#!/bin/sh\necho \"\$*\" >> \"\$STUB_DIR/multi-calls.log\"\ncase \"\$*\" in\n  *codeception.api.yml*) key=tests-api;;\n  *codeception.broken.yml*) key=tests-broken;;\n  *) key=tests;;\nesac\ncat \"\$STUB_DIR/\$key.out\" 2>/dev/null\nexit \$(cat \"\$STUB_DIR/\$key.exit\" 2>/dev/null || echo 0)\n");
chmod($multi . '/docker/sdk', 0755);
$stubOutput('tests-api', "Codeception PHP Testing Framework v5.1.2\n\nApi Tests (2) ------\n✔ OrdersCest: Reads orders (0.01s)\nE OrdersCest: Creates order (0.01s)\n------\nTime: 00:01.000, Memory: 40.00 MB\n\nERRORS!\nTests: 2, Assertions: 2, Errors: 1.\n", 1);
$stubOutput('tests-broken', "Error response from daemon: container testing is not running\n", 1);
[$rtMulti, $rtMultiOut] = run_script('run-tools.php', ['baseline', 'tests', '--tests-args=--no-exit', '--codecept-options', '-c codeception.functional.yml', '--codecept-options', '-c codeception.api.yml'], $multi, $multiState, $stubEnv);
$multiLog = (string)file_get_contents($multiState . '/codecept-baseline.txt');
$multiCalls = (string)file_get_contents($stub . '/multi-calls.log');
check('run-tools: one codecept run per configuration, in the order given, with the tests arguments', $rtMulti === 0 && strpos($multiCalls, 'codecept run -c codeception.functional.yml --no-exit') !== false && strpos($multiCalls, 'codecept run -c codeception.api.yml --no-exit') > strpos($multiCalls, 'codeception.functional.yml'));
check('run-tools: the configurations share one log that ends with the highest exit code', substr_count($multiLog, '==> docker/sdk testing codecept run') === 2 && str_contains($multiLog, 'Rounds tax') && str_contains($multiLog, 'Creates order') && preg_match('/\nexit 1\n$/', $multiLog) === 1);
check('run-tools: the result adds up the configurations', str_contains($rtMultiOut, '6 tests in 3 suite(s): 3 passed, 2 failed, 1 skipped'));
check('run-tools: a suite of a later configuration does not take the namespace of the one before', array_key_exists('Api', read_json($multiState . '/tool-runs-baseline.json')['tools']['tests']['suites'] ?? []));
check('run-tools: check-baselines accepts the combined log', bl_content_problem('codecept-baseline.txt', $multiLog) === null);
check('run-tools: the pin holds the options of each configuration', (read_json($multiState . '/tool-invocation.json')['codeceptOptions'] ?? null) === ['-c codeception.functional.yml', '-c codeception.api.yml']);
@unlink($stub . '/multi-calls.log');
[$rtMultiLater] = run_script('run-tools.php', ['final', 'tests'], $multi, $multiState, $stubEnv);
check('run-tools: a later run uses every pinned configuration', $rtMultiLater === 0 && substr_count((string)file_get_contents($stub . '/multi-calls.log'), 'codecept run -c ') === 2);
[$rtMultiOther] = run_script('run-tools.php', ['final', 'tests', '--codecept-options', '-c codeception.api.yml'], $multi, $multiState, $stubEnv);
check('run-tools: configurations that differ from the pin -> exit 2', $rtMultiOther === 2);
[$rtMultiBroken, $rtMultiBrokenOut] = run_script('run-tools.php', ['baseline', 'tests', '--codecept-options', '-c codeception.functional.yml', '--codecept-options', '-c codeception.broken.yml'], $multi, $multiState, $stubEnv);
check('run-tools: a configuration that did not start -> tests did not run, named with the reason', $rtMultiBroken === 1 && str_contains($rtMultiBrokenOut, 'did not run: codecept run -c codeception.broken.yml: Error response from daemon'));
$oneFileTwoRuns = to_codecept_suites(to_plain_text($codeceptBase . "Codeception PHP Testing Framework v5.1.2\n\nApi Tests (1) ------\n✔ OrdersCest: Reads orders (0.01s)\n------\nTime: 00:01.000, Memory: 40.00 MB\n\nOK (1 test, 1 assertion)\n"));
check('tool output: a second codecept run in one file starts without an include namespace', array_keys($oneFileTwoRuns) === ['PyzTest\\Zed\\Foo Business', 'PyzTest\\Zed\\Bar Business', 'Api']);

$cmp = $tmp . '/compare';
$cmpState = $cmp . '/.state';
write_file($cmp . '/composer.json', "{}\n");
mkdir($cmp . '/src/Pyz', 0777, true);
$baselines = ['phpstan-baseline-run.txt' => $phpstanBase, 'sniff-baseline.txt' => $sniffBase, 'evaluator-baseline.txt' => $evaluatorBase, 'codecept-baseline.txt' => $codeceptBase];
foreach ($baselines as $name => $contents) {
    write_file("$cmpState/$name", $contents . "exit 0\n");
}
$writeRun = static function (string $run, array $logs) use ($cmpState): void {
    foreach ($logs as $tool => $contents) {
        write_file($cmpState . '/' . rt_log_name($tool, $run), $contents . "exit 1\n");
    }
};
$writeRun('verify', ['phpstan' => $phpstanBase, 'sniffer' => $sniffBase, 'evaluator' => $evaluatorBase, 'tests' => $codeceptBase]);
[$ctSame, $ctSameOut] = run_script('compare-tool-runs.php', ['verify'], $cmp, $cmpState);
check('compare: a baseline with red tests and the same result -> exit 0, every tool ok', $ctSame === 0 && substr_count($ctSameOut, ' ok ') === 4 && !str_contains($ctSameOut, '  + '));
$writeRun('final', ['phpstan' => $phpstanAfter, 'sniffer' => $sniffAfter, 'evaluator' => $evaluatorAfter, 'tests' => $codeceptAfter]);
[$ctReg, $ctRegOut] = run_script('compare-tool-runs.php', ['final'], $cmp, $cmpState);
check('compare: regressions -> exit 1', $ctReg === 1);
check('compare: new PHPStan error only, the moved line of the old one is not new', str_contains($ctRegOut, '+ src/Pyz/Zed/Foo/FooFacade.php: Parameter #1 $x') && !str_contains($ctRegOut, '+ src/Pyz/Zed/Foo/FooFacade.php: Call to an undefined'));
check('compare: new sniffer violation only', str_contains($ctRegOut, '+ /data/src/Pyz/Zed/Bar/BarFacade.php: ERROR Missing doc comment') && substr_count($ctRegOut, 'Expected 1 space') === 0);
check('compare: new evaluator finding only', str_contains($ctRegOut, '+ PHP VERSION CHECKER: Deploy PHP is 8.0 (deploy.yml)') && !str_contains($ctRegOut, 'Composer PHP is 8.1'));
check('compare: a test that passed before and fails now, with its suite; the test red in the baseline is not listed', str_contains($ctRegOut, '+ PyzTest\\Zed\\Bar Business: BarFacadeTest: Reads bar') && !str_contains($ctRegOut, 'Rounds tax'));
check('compare: one verdict line per tool and the report path', preg_match_all('/^(phpstan|sniffer|evaluator|tests)\s+REGRESSION/m', $ctRegOut) === 4 && str_contains($ctRegOut, 'Full report: .state/tool-comparison-final.json'));
check('compare: the report holds the full lists', count(read_json($cmpState . '/tool-comparison-final.json')['tools']['phpstan']['new'] ?? []) === 1);
[$ctLimit, $ctLimitOut] = run_script('compare-tool-runs.php', ['final', 'sniffer', '--limit', '0'], $cmp, $cmpState);
check('compare: --limit caps the listed findings and points to the report', $ctLimit === 1 && str_contains($ctLimitOut, '(1 more in the report)') && !str_contains($ctLimitOut, '  + '));
write_file($cmpState . '/phpstan-post-tooling.txt', $phpstanAfter . "exit 1\n");
[$ctPost, $ctPostOut] = run_script('compare-tool-runs.php', ['final', 'phpstan'], $cmp, $cmpState);
[$ctPhase0] = run_script('compare-tool-runs.php', ['final', 'phpstan', '--baseline', 'phase0'], $cmp, $cmpState);
check('compare: the post-tooling file is the baseline when it exists; --baseline phase0 overrides', $ctPost === 0 && str_contains($ctPostOut, 'phpstan-post-tooling.txt') && $ctPhase0 === 1);
[$ctPostRun, $ctPostRunOut] = run_script('compare-tool-runs.php', ['post-tooling', 'phpstan'], $cmp, $cmpState);
check('compare: the post-tooling run is compared with the Phase 0 file', $ctPostRun === 1 && str_contains($ctPostRunOut, 'phpstan-baseline-run.txt'));
unlink($cmpState . '/evaluator-baseline.txt');
[$ctNoBase, $ctNoBaseOut] = run_script('compare-tool-runs.php', ['final'], $cmp, $cmpState);
check('compare: a missing baseline -> exit 2, named', $ctNoBase === 2 && str_contains($ctNoBaseOut, 'evaluator: no baseline'));
write_file($cmpState . '/evaluator-baseline.txt', "Could not open input file: vendor/bin/evaluator\nexit 1\n");
[$ctBadBase] = run_script('compare-tool-runs.php', ['final', 'evaluator'], $cmp, $cmpState);
check('compare: a baseline without a result -> exit 2', $ctBadBase === 2);
$writeRun('detect', ['phpstan' => "Error response from daemon: container cli is not running\n"]);
[$ctNoResult, $ctNoResultOut] = run_script('compare-tool-runs.php', ['detect'], $cmp, $cmpState);
check('compare: a run without a result -> NO RESULT, exit 1', $ctNoResult === 1 && str_contains($ctNoResultOut, 'NO RESULT'));
[$ctNoRun] = run_script('compare-tool-runs.php', ['nothing'], $cmp, $cmpState);
[$ctMissingLog] = run_script('compare-tool-runs.php', ['detect', 'sniffer'], $cmp, $cmpState);
check('compare: no log of the run, or of a named tool -> exit 2', $ctNoRun === 2 && $ctMissingLog === 2);

// --- detector summaries, Phase 0 results and run-detectors ---
$det = $tmp . '/detectors';
$detState = $det . '/.state';
write_file($det . '/composer.json', "{}\n");
write_file($det . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Spryker\Zed\Old\Communication\Plugin { /** @deprecated Use NewPlugin. */ class OldPlugin {} }
namespace Spryker\Shared\Kept { interface KeptConstants { public const A = 'a'; } }
namespace Spryker\Zed\Base { class BaseConfig { protected const string NAME = 'x'; protected const int SIZE = 1; } }
PHP);
$providerUses = static fn (array $plugins): string => "<?php\nnamespace Pyz\\Zed\\Foo;\n" . implode('', array_map(static fn (string $p): string => "use $p;\n", $plugins)) . "class FooDependencyProvider {}\n";
write_file($det . '/src/Pyz/Zed/Foo/FooDependencyProvider.php', $providerUses(['Spryker\\Zed\\Gone\\Communication\\Plugin\\GonePlugin', 'Spryker\\Zed\\Old\\Communication\\Plugin\\OldPlugin']));
write_file($det . '/src/Pyz/Zed/Foo/FooConfig.php', "<?php\nnamespace Pyz\\Zed\\Foo;\nuse Spryker\\Zed\\Base\\BaseConfig;\nclass FooConfig extends BaseConfig { protected const NAME = 'y'; }\n");
write_file($det . '/config/Shared/config_default.php', "<?php\nuse Spryker\\Shared\\Kept\\KeptConstants;\nuse Spryker\\Shared\\Gone\\GoneConstants;\n\$config[GoneConstants::B] = 2;\n");

[$puBase, $puBaseOut] = run_script('check-plugin-usage.php', ['--baseline'], $det, $detState);
[$ccBase] = run_script('check-config-constants.php', ['--baseline'], $det, $detState);
[$tmBase] = run_script('check-typed-members.php', ['--baseline'], $det, $detState);
check('detectors: --baseline keeps the Phase 0 result in its own file, exit codes unchanged', $puBase === 1 && $ccBase === 1 && $tmBase === 1 && is_file($detState . '/plugin-usage-baseline.json') && is_file($detState . '/config-constants-baseline.json') && is_file($detState . '/typed-members-baseline.json'));
check('detectors: verdict line with the detector, FOUND and a count per category', str_starts_with($puBaseOut, 'check-plugin-usage: FOUND missing 1, deprecated 1, porting 0') && str_contains($puBaseOut, 'Full report: .state/plugin-usage-report.json'));
check('detectors: one line per finding, no Phase 0 marks in the baseline run', str_contains($puBaseOut, "\n  MISSING Spryker\\Zed\\Gone\\Communication\\Plugin\\GonePlugin (used in: src/Pyz/Zed/Foo/FooDependencyProvider.php)\n") && !str_contains($puBaseOut, '[new]'));

write_file($det . '/src/Pyz/Zed/Foo/FooDependencyProvider.php', $providerUses(['Spryker\\Zed\\Gone\\Communication\\Plugin\\GonePlugin', 'Spryker\\Zed\\Gone2\\Communication\\Plugin\\Gone2Plugin', 'Spryker\\Zed\\Old\\Communication\\Plugin\\OldPlugin']));
write_file($det . '/config/Shared/config_default.php', "<?php\nuse Spryker\\Shared\\Kept\\KeptConstants;\nuse Spryker\\Shared\\Gone\\GoneConstants;\n\$config[GoneConstants::B] = 2;\n\$config[KeptConstants::MISSING] = 3;\n");
write_file($det . '/src/Pyz/Zed/Foo/FooConfig.php', "<?php\nnamespace Pyz\\Zed\\Foo;\nuse Spryker\\Zed\\Base\\BaseConfig;\nclass FooConfig extends BaseConfig { protected const NAME = 'y'; protected const SIZE = 2; }\n");
[$pu, $puOut] = run_script('check-plugin-usage.php', [], $det, $detState);
$puLines = explode("\n", trim($puOut));
check('plugin-usage: only the new finding is listed, with the count Phase 0 already had', str_contains($puLines[0], '(1 new, 2 already in Phase 0') && str_starts_with($puLines[1] ?? '', '  [new] MISSING Spryker\\Zed\\Gone2') && str_starts_with($puLines[2] ?? '', 'Full report: '));
$puReport = read_json($detState . '/plugin-usage-report.json');
check('plugin-usage: the report keeps every finding, marked inBaseline', count($puReport['missing'] ?? []) === 2 && ($puReport['missing'][0]['inBaseline'] ?? null) === true && ($puReport['missing'][1]['inBaseline'] ?? null) === false && ($puReport['deprecated'][0]['inBaseline'] ?? null) === true);
[$cc, $ccOut] = run_script('check-config-constants.php', [], $det, $detState);
check('config-constants: the new problem listed, the Phase 0 one counted only', $cc === 1 && str_contains($ccOut, '(1 new, 1 already in Phase 0') && str_contains($ccOut, '[new] CONSTANT_MISSING') && !str_contains($ccOut, 'TYPE_MISSING Spryker'));
[$tm, $tmOut] = run_script('check-typed-members.php', [], $det, $detState);
check('typed-members: the new fatal listed, the Phase 0 one counted only', $tm === 1 && str_contains($tmOut, '(1 new, 1 already in Phase 0') && str_contains($tmOut, '[new] CONSTANT Pyz\\Zed\\Foo\\FooConfig::SIZE') && !str_contains($tmOut, 'FooConfig::NAME'));
write_file($det . '/src/Pyz/Zed/Foo/FooConfig.php', "<?php\nnamespace Pyz\\Zed\\Foo;\nuse Spryker\\Zed\\Base\\BaseConfig;\nclass FooConfig extends BaseConfig { protected const NAME = 'y'; }\n");
[$tmOld, $tmOldOut] = run_script('check-typed-members.php', [], $det, $detState);
check('typed-members: exit 1 also when every finding is from Phase 0', $tmOld === 1 && str_contains($tmOldOut, '(0 new, 1 already in Phase 0'));

[, $puLimitOut] = run_script('check-plugin-usage.php', ['--limit', '1'], $det, $tmp . '/detectors-no-baseline');
check('summary: --limit caps the findings and says how many more are in the report', substr_count($puLimitOut, "\n  MISSING") + substr_count($puLimitOut, "\n  DEPRECATED") === 1 && str_contains($puLimitOut, '  (2 more in the report)'));
[$puZero, $puZeroOut] = run_script('check-plugin-usage.php', ['--limit=0'], $det, $tmp . '/detectors-no-baseline');
check('summary: --limit 0 prints the counts only', $puZero === 1 && !str_contains($puZeroOut, "\n  MISSING") && !str_contains($puZeroOut, "\n  DEPRECATED") && !str_contains($puZeroOut, 'more in the report'));
check('summary: findings without a Phase 0 result -> the verdict, one note that names the missing file, the report path', count(explode("\n", trim($puZeroOut))) === 3 && str_contains($puZeroOut, 'NOTE: no Phase 0 result (plugin-usage-baseline.json)'));
[$puBadLimit] = run_script('check-plugin-usage.php', ['--limit', 'all'], $det, $detState);
check('summary: a --limit that is not a number -> exit 2', $puBadLimit === 2);
ob_start();
spryker_upgrade_print_summary('x', ['a' => 0], [], null, $det, 10);
check('summary: no finding -> OK', trim((string)ob_get_clean()) === 'x: OK a 0');

check('run-detectors: Phase 0 order of the skill text, with --baseline on the detectors without a snapshot', array_map(static fn (array $c): string => $c[0] . ' ' . implode(' ', $c[1]), rd_plan('phase0', [])) === [
    'check-platform-alignment.php ', 'check-dead-overrides.php snapshot', 'twig-shadow-map.php snapshot', 'check-plugin-usage.php --baseline',
    'check-config-constants.php --baseline', 'check-typed-members.php --baseline', 'check-constraint-style.php ', 'check-test-coverage.php ',
    'check-vendor-class-replacement.php ', 'check-constant-overrides.php --snapshot',
]);
check('run-detectors: --typed-members-dir adds to src/Pyz', rd_plan('phase3', ['vendor/x/Bundles'])[0] === ['check-typed-members.php', ['src/Pyz', 'vendor/x/Bundles']]);
[$rd, $rdOut] = run_script('run-detectors.php', ['phase3', '--limit', '1'], $det, $detState);
preg_match_all('/^([a-z-]+): /m', $rdOut, $rdNames);
check('run-detectors: Phase 3 runs check-typed-members first, then the skill order', $rdNames[1] === ['check-typed-members', 'list-major-bumps', 'check-dead-overrides', 'twig-shadow-map', 'check-plugin-usage', 'check-config-constants', 'check-constant-overrides', 'check-performance']);
check('run-detectors: a detector that fails to run is named with its error, the others still run, exit 2', $rd === 2 && str_contains($rdOut, 'list-major-bumps: DID NOT RUN (exit 2): No baseline at') && str_contains($rdOut, 'check-plugin-usage: FOUND') && str_contains($rdOut, 'Did not run: list-major-bumps'));
[, $rdZeroOut] = run_script('run-detectors.php', ['phase3', '--limit', '0'], $det, $detState);
check('run-detectors: --limit reaches each detector', !str_contains($rdZeroOut, '[new]') && str_contains($rdOut, '[new]'));
[$rdBad] = run_script('run-detectors.php', ['phase9'], $det, $detState);
check('run-detectors: unknown phase -> exit 2', $rdBad === 2);

$cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($cleanup as $entry) {
    $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($tmp);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
