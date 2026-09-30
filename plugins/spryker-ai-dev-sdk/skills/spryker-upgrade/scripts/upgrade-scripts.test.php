<?php

declare(strict_types=1);

/** Zero-dependency tests for the spryker-upgrade scripts. Run: php scripts/upgrade-scripts.test.php */

require __DIR__ . '/check-added-comments.php';
require __DIR__ . '/backoffice-smoke.php';
require __DIR__ . '/check-tooling-alignment.php';
require __DIR__ . '/check-baselines.php';

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
write_file($coverage . '/src/Pyz/Zed/Foo/Business/FooBusinessFactory.php', <<<'PHP'
<?php
namespace Pyz\Zed\Foo\Business;

use Spryker\Zed\Foo\Business\FooBusinessFactory as SprykerFooBusinessFactory;

class FooBusinessFactory extends SprykerFooBusinessFactory
{
    public function createA() {} public function createB() {} public function createC() {}
    public function createD() {} public function createE() {} public function createF() {}
}
PHP);
write_file($coverage . '/src/Pyz/Zed/Foo/FooDependencyProvider.php', <<<'PHP'
<?php
namespace Pyz\Zed\Foo;

use Spryker\Zed\Foo\FooDependencyProvider as SprykerFooDependencyProvider;
use Spryker\Zed\Foo\Communication\Plugin\FooPlugin;

class FooDependencyProvider extends SprykerFooDependencyProvider
{
    public function getPlugins() {}
}
PHP);
write_file($coverage . '/src/Pyz/Client/Bar/BarFactory.php', <<<'PHP'
<?php
namespace Pyz\Client\Bar;

class BarFactory extends \Spryker\Client\Bar\BarFactory
{
    public function createReader() {}
}
PHP);
write_file($coverage . '/src/Pyz/Zed/Baz/Business/Model/Calculator.php', <<<'PHP'
<?php
namespace Pyz\Zed\Baz\Business\Model;

use Spryker\Zed\Foo\Business\Model\Calculator as SprykerCalculator;

class Calculator extends SprykerCalculator
{
    public function calculate() {}
}
PHP);
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
$allNames = ['base-ref', 'codecept-baseline.txt', 'phpstan-baseline-run.txt', 'sniff-baseline.txt', 'evaluator-baseline.txt', 'backoffice-smoke-baseline.json'];
check('baselines: exit 1 with an empty state dir', $blCode === 1);
check('baselines: every missing file named', array_filter($allNames, static fn(string $n): bool => !str_contains($blOut, $n)) === []);
$realResults = [
    'base-ref' => "0123456789abcdef0123456789abcdef01234567\n",
    'codecept-baseline.txt' => "Codeception PHP Testing Framework\n\e[32mFAILURES!\e[0m\r\nTests: 630, Assertions: 2100, Failures: 12.\r\n",
    'phpstan-baseline-run.txt' => " 312/312 [============================] 100%\n\n [ERROR] Found 312 errors\n",
    'sniff-baseline.txt' => "Run Code Style Sniffer for PROJECT\n",
    'evaluator-baseline.txt' => "====================\nPHP_VERSION_CHECKER\n====================\nRead more: https://docs.spryker.com\n",
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
check('baselines: bl_status lists the six files', array_keys(bl_status($baselineState, true)) === $allNames);

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
check('baselines: an existing post-tooling re-take is listed after the six files', array_keys(bl_status($baselineState, true)) === array_merge($allNames, ['phpstan-post-tooling.txt']));
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

$cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($cleanup as $entry) {
    $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($tmp);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
