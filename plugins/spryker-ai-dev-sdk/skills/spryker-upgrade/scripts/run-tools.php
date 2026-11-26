<?php

/**
 * Runs PHPStan, the project's sniffer, the evaluator, `codecept build` and `codecept run` in the
 * container, writes each tool's full output into the state directory, and prints one line per tool.
 *
 * Usage (from the project root):
 *   php run-tools.php <run> <tool>... [--tests-args "<args>"]
 *                     [--sniffer "<command>"] [--env NAME=VALUE]... [--codecept-options "<options>"]...
 *                     [--pin-dir <dir>]
 *
 *   <run>   baseline, post-tooling, detect, verify, final, after, or another lower-case name.
 *   <tool>  phpstan, sniffer, evaluator, codecept-build, tests.
 *
 * Commands (each wrapped in `script` for a pseudo-terminal: `script -q /dev/null <command>` on macOS
 * and BSD, `script -q -e -c "<command>" /dev/null` on Linux):
 *   phpstan         docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/ -l 6
 *   sniffer         docker/sdk cli <sniffer entry point>
 *   evaluator       docker/sdk cli vendor/bin/evaluator evaluate
 *   codecept-build  docker/sdk testing codecept build
 *   tests           docker/sdk testing codecept run <codecept options> <tests args>
 *
 * A project that runs several test configurations names each with its own --codecept-options
 * (`--codecept-options "-c codeception.functional.yml" --codecept-options "-c codeception.api.yml"`).
 * The tests tool then runs `codecept run` once per configuration, in that order, with the same tests
 * arguments, into one log. A configuration that did not start makes the tests tool "did not run".
 *
 * Output files in the state directory (SPRYKER_UPGRADE_STATE_DIR, default .spryker-upgrade/state):
 *   phpstan-<run>.txt (phpstan-baseline-run.txt for the baseline), sniff-<run>.txt,
 *   evaluator-<run>.txt, codecept-build-<run>.txt, codecept-<run>.txt. Each file ends with
 *   `exit <code>`. tool-runs-<run>.json holds the commands, exit codes, results, the tests per suite
 *   and the names of the failing tests of the latest run of each tool; it is written after each tool.
 *
 * The pinned invocation: the baseline run writes tool-invocation.json (sniffer entry point and how it
 * was chosen, environment variables from --env, codecept options per configuration); a repeated
 * baseline run keeps each value it is not given. Every other run reads it from
 * --pin-dir (default: the state directory) and uses it; --sniffer, --env or --codecept-options that
 * differ from it are a usage error. Without --sniffer the baseline detects the entry point:
 * `vendor/bin/console code:sniff:style` when spryker/development is installed, else `vendor/bin/phpcs`
 * with a phpcs.xml(.dist), else `composer cs-check` when composer.json has that script.
 *
 * Standard output: one line per tool with its exit code, its result and its log file; a tool that did
 * not start is reported as "did not run". Exit codes: 0 every tool ran, 1 a tool did not run, 2 usage
 * error or no pinned invocation.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/tool-output.php';

const RT_TOOLS = ['phpstan', 'sniffer', 'evaluator', 'codecept-build', 'tests'];
const RT_PIN_FILE = 'tool-invocation.json';
const RT_USAGE = "Usage: php run-tools.php <run> <tool>... [--tests-args \"<args>\"] [--sniffer \"<command>\"] [--env NAME=VALUE]... [--codecept-options \"<options>\"]... [--pin-dir <dir>]\n"
    . "  <tool>: phpstan, sniffer, evaluator, codecept-build, tests\n";

/**
 * The log file of a tool in a run.
 */
function rt_log_name(string $tool, string $run): string
{
    return match ($tool) {
        'phpstan' => $run === 'baseline' ? 'phpstan-baseline-run.txt' : "phpstan-$run.txt",
        'sniffer' => "sniff-$run.txt",
        'evaluator' => "evaluator-$run.txt",
        'codecept-build' => "codecept-build-$run.txt",
        default => "codecept-$run.txt",
    };
}

/**
 * Split a command line into words: single and double quotes group, no shell expansion.
 *
 * @return list<string>
 */
function rt_words(string $line): array
{
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|(\S+)/', $line, $m, PREG_SET_ORDER);

    return array_map(static fn (array $hit): string => match (true) {
        ($hit[3] ?? '') !== '' => $hit[3],
        ($hit[2] ?? '') !== '' => $hit[2],
        default => stripcslashes($hit[1]),
    }, $m);
}

/**
 * The sniffer entry point and how it was chosen.
 *
 * @return array{0: string, 1: string}
 */
function rt_detect_sniffer(string $root): array
{
    if (is_dir($root . '/vendor/spryker/development')) {
        return ['vendor/bin/console code:sniff:style', 'detected: spryker/development provides code:sniff:style'];
    }
    foreach (['phpcs.xml', 'phpcs.xml.dist'] as $config) {
        if (is_file($root . '/' . $config)) {
            return ['vendor/bin/phpcs', "detected: $config"];
        }
    }
    $composer = json_decode((string)@file_get_contents($root . '/composer.json'), true);
    if (isset($composer['scripts']['cs-check'])) {
        return ['composer cs-check', 'detected: composer.json script cs-check'];
    }

    return ['vendor/bin/console code:sniff:style', 'default: no sniffer setup found'];
}

/**
 * The codecept options of each test configuration: one entry per `codecept run`. A project with one
 * configuration has one entry, which is empty when it needs no options.
 *
 * @return list<string>
 */
function rt_option_sets(mixed $value): array
{
    $sets = is_array($value) ? array_values(array_map('strval', $value)) : (is_string($value) ? [$value] : []);

    return $sets === [] ? [''] : $sets;
}

/**
 * The command of a tool, without the pseudo-terminal wrapper. $codeceptOptions are the options of
 * one test configuration.
 *
 * @param array{sniffer: string} $pin
 *
 * @return list<string>
 */
function rt_tool_command(string $tool, array $pin, string $testsArgs, string $codeceptOptions = ''): array
{
    return match ($tool) {
        'phpstan' => ['docker/sdk', 'cli', 'php', '-d', 'memory_limit=2048M', 'vendor/bin/phpstan', 'analyze', '-c', 'phpstan.neon', 'src/', '-l', '6'],
        'sniffer' => array_merge(['docker/sdk', 'cli'], rt_words($pin['sniffer'])),
        'evaluator' => ['docker/sdk', 'cli', 'vendor/bin/evaluator', 'evaluate'],
        'codecept-build' => ['docker/sdk', 'testing', 'codecept', 'build'],
        default => array_merge(['docker/sdk', 'testing', 'codecept', 'run'], rt_words($codeceptOptions), rt_words($testsArgs)),
    };
}

/**
 * The command inside the pseudo-terminal wrapper of the operating system.
 *
 * @param list<string> $command
 *
 * @return list<string>
 */
function rt_wrap(array $command, string $osFamily): array
{
    if ($osFamily === 'Linux') {
        return ['script', '-q', '-e', '-c', implode(' ', array_map('escapeshellarg', $command)), '/dev/null'];
    }

    return array_merge(['script', '-q', '/dev/null'], $command);
}

/**
 * Run a command with its output into $logFile; append `exit <code>`.
 *
 * @param list<string> $command
 * @param array<string, string> $env
 */
function rt_execute(array $command, string $root, array $env, string $logFile): int
{
    $log = fopen($logFile, 'w');
    if ($log === false) {
        return 127;
    }
    $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, $root, array_merge(getenv(), $env));
    if (!is_resource($process)) {
        fwrite($log, "could not start: " . $command[0] . "\n");
        $code = 127;
    } else {
        $code = proc_close($process);
    }
    fwrite($log, "exit $code\n");
    fclose($log);

    return $code;
}

/**
 * Run `codecept run` once per test configuration, all into $logFile. With more than one
 * configuration each part starts with a `==> <command>` line and ends with `==> exit <code>`; the
 * file ends with `exit <highest code>`.
 *
 * @param list<list<string>> $commands one command per configuration, without the wrapper
 * @param array<string, string> $env
 *
 * @return array{0: int, 1: list<string>} the highest exit code, and one "<command>: <reason>" entry
 *         per configuration that did not start or gave no result
 */
function rt_execute_tests(array $commands, string $root, array $env, string $logFile): array
{
    $highest = 0;
    $notRun = [];
    $combined = '';
    foreach ($commands as $command) {
        $partFile = $logFile . '.part';
        $code = rt_execute(rt_wrap($command, PHP_OS_FAMILY), $root, $env, $partFile);
        $part = (string)preg_replace('/\nexit \d+\s*$/', "\n", (string)file_get_contents($partFile));
        @unlink($partFile);
        $highest = max($highest, $code);
        $problem = $code === 126 || $code === 127 ? "exit $code" : to_result_problem('codecept', $part);
        if ($problem !== null) {
            $notRun[] = implode(' ', array_slice($command, 2)) . ': ' . (to_start_failure(to_plain_text($part)) ?? $problem);
        }
        $combined .= count($commands) > 1
            ? '==> ' . implode(' ', $command) . "\n" . $part . "==> exit $code\n"
            : $part;
    }
    file_put_contents($logFile, $combined . "exit $highest\n");

    return [$highest, $notRun];
}

/**
 * The result of a tool from its log: did it run, and what did it find.
 *
 * @return array{ran: bool, result: string, counts?: array<string, ?int>, suites?: array<string, array{passed: int, failed: int, skipped: int}>, failedTests?: array<string, list<string>>}
 */
function rt_result(string $tool, string $contents, int $exitCode): array
{
    $text = to_plain_text($contents);
    $startFailure = to_start_failure($text);
    if ($exitCode === 126 || $exitCode === 127) {
        return ['ran' => false, 'result' => 'did not run: ' . ($startFailure ?? "exit $exitCode")];
    }
    if ($tool === 'codecept-build') {
        if ($exitCode === 0) {
            return ['ran' => true, 'result' => 'built'];
        }

        return $startFailure !== null
            ? ['ran' => false, 'result' => 'did not run: ' . $startFailure]
            : ['ran' => true, 'result' => 'failed'];
    }
    $problem = to_result_problem(match ($tool) {
        'sniffer' => 'sniff',
        'tests' => 'codecept',
        default => $tool,
    }, (string)preg_replace('/\nexit \d+\s*$/', "\n", $contents));
    if ($problem !== null) {
        return ['ran' => false, 'result' => 'did not run: ' . ($startFailure ?? $problem)];
    }
    if ($tool === 'phpstan') {
        $count = to_phpstan_count($text);

        return ['ran' => true, 'result' => sprintf('%d error(s)', (int)$count), 'counts' => ['errors' => $count]];
    }
    if ($tool === 'sniffer') {
        $count = to_sniff_count($text);

        return ['ran' => true, 'result' => sprintf('%d violation(s)', (int)$count), 'counts' => ['violations' => $count]];
    }
    if ($tool === 'evaluator') {
        $findings = to_evaluator_findings($text) ?? [];

        return ['ran' => true, 'result' => sprintf('%d finding(s)', count($findings)), 'counts' => ['findings' => count($findings)]];
    }
    $counts = to_codecept_counts($text);
    $suites = [];
    $failedTests = [];
    foreach (to_codecept_suites($text) as $name => $suite) {
        $suites[$name] = ['passed' => count($suite['passed']), 'failed' => count($suite['failed']), 'skipped' => count($suite['skipped'])];
        if ($suite['failed'] !== []) {
            $failedTests[$name] = $suite['failed'];
        }
    }
    $failingSuites = array_filter($suites, static fn (array $suite): bool => $suite['failed'] > 0);
    $named = array_map(static fn (string $name, array $suite): string => sprintf('%s (%d)', $name, $suite['failed']), array_keys($failingSuites), $failingSuites);
    $result = sprintf(
        '%s tests in %d suite(s): %s passed, %s failed, %s skipped',
        $counts['tests'] ?? '?',
        count($suites),
        $counts['passed'] ?? '?',
        $counts['failed'] ?? '?',
        $counts['skipped'] ?? '?'
    );
    if ($named !== []) {
        $result .= '; failing suites: ' . implode(', ', array_slice($named, 0, 5)) . (count($named) > 5 ? sprintf(', +%d more', count($named) - 5) : '');
    }

    return ['ran' => true, 'result' => $result, 'counts' => $counts, 'suites' => $suites, 'failedTests' => $failedTests];
}

/**
 * @return array{run: string, tools: list<string>, options: array<string, mixed>}|string the parsed
 *         arguments, or the usage error
 */
function rt_parse_args(array $args): array|string
{
    $positional = [];
    $options = ['env' => [], 'codecept-options' => []];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if (!str_starts_with($arg, '--')) {
            $positional[] = $arg;
            continue;
        }
        [$name, $value] = str_contains($arg, '=') ? explode('=', substr($arg, 2), 2) : [substr($arg, 2), null];
        if (!in_array($name, ['tests-args', 'sniffer', 'env', 'codecept-options', 'pin-dir'], true)) {
            return "Unknown option: --$name";
        }
        if ($value === null) {
            if (!isset($args[$i + 1])) {
                return "--$name needs a value";
            }
            $value = $args[++$i];
        }
        if ($name === 'env') {
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $value, $m)) {
                return "--env takes NAME=VALUE: $value";
            }
            $options['env'][$m[1]] = $m[2];
            continue;
        }
        if ($name === 'codecept-options') {
            $options['codecept-options'][] = $value;
            continue;
        }
        $options[$name] = $value;
    }
    $run = array_shift($positional);
    if ($run === null || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $run)) {
        return 'The first argument is the run name (lower-case letters, digits and dashes).';
    }
    if ($positional === []) {
        return 'Name at least one tool.';
    }
    foreach ($positional as $tool) {
        if (!in_array($tool, RT_TOOLS, true)) {
            return "Unknown tool: $tool";
        }
    }

    return ['run' => $run, 'tools' => array_values(array_unique($positional)), 'options' => $options];
}

/**
 * The pinned invocation: written by the baseline run, read by every other run.
 *
 * @param array<string, mixed> $options
 *
 * @return array{sniffer: string, snifferSource: string, env: array<string, string>, codeceptOptions: list<string>}|string
 */
function rt_pin(string $run, array $options, string $root, string $pinFile): array|string
{
    $existing = json_decode((string)@file_get_contents($pinFile), true);
    if ($run === 'baseline') {
        $existing = is_array($existing) && isset($existing['sniffer']) ? $existing : null;
        [$sniffer, $source] = match (true) {
            isset($options['sniffer']) => [$options['sniffer'], 'argument --sniffer'],
            $existing !== null => [$existing['sniffer'], (string)($existing['snifferSource'] ?? '')],
            default => rt_detect_sniffer($root),
        };
        $pin = [
            'sniffer' => $sniffer,
            'snifferSource' => $source,
            'env' => $options['env'] !== [] ? $options['env'] : (array)($existing['env'] ?? []),
            'codeceptOptions' => $options['codecept-options'] !== [] ? $options['codecept-options'] : rt_option_sets($existing['codeceptOptions'] ?? null),
        ];
        if (!is_dir(dirname($pinFile))) {
            mkdir(dirname($pinFile), 0775, true);
        }
        file_put_contents($pinFile, json_encode(['env' => (object)$pin['env']] + $pin + ['recordedAt' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $pin;
    }
    $pin = $existing;
    if (!is_array($pin) || !isset($pin['sniffer'])) {
        return "No pinned invocation in $pinFile: run the baseline first, or name its directory with --pin-dir.";
    }
    $pin['env'] = (array)($pin['env'] ?? []);
    $pin['codeceptOptions'] = rt_option_sets($pin['codeceptOptions'] ?? null);
    if (isset($options['sniffer']) && $options['sniffer'] !== $pin['sniffer']) {
        return "--sniffer differs from the pinned invocation in $pinFile ({$pin['sniffer']}).";
    }
    if ($options['codecept-options'] !== [] && $options['codecept-options'] !== $pin['codeceptOptions']) {
        return "--codecept-options differs from the pinned invocation in $pinFile (" . implode(' | ', $pin['codeceptOptions']) . ').';
    }
    if ($options['env'] !== [] && $options['env'] != $pin['env']) {
        return "--env differs from the pinned invocation in $pinFile.";
    }

    return $pin;
}

function rt_main(array $argv): int
{
    $parsed = rt_parse_args(array_slice($argv, 1));
    if (is_string($parsed)) {
        fwrite(STDERR, $parsed . "\n" . RT_USAGE);

        return 2;
    }
    $root = spryker_upgrade_project_root();
    $stateDir = spryker_upgrade_state_dir($root);
    $pinDir = isset($parsed['options']['pin-dir']) ? rtrim((string)$parsed['options']['pin-dir'], '/') : $stateDir;
    $pin = rt_pin($parsed['run'], $parsed['options'], $root, $pinDir . '/' . RT_PIN_FILE);
    if (is_string($pin)) {
        fwrite(STDERR, $pin . "\n");

        return 2;
    }

    $testsArgs = (string)($parsed['options']['tests-args'] ?? '');
    $runsFile = $stateDir . '/tool-runs-' . $parsed['run'] . '.json';
    $records = (array)(json_decode((string)@file_get_contents($runsFile), true)['tools'] ?? []);
    $notRun = 0;
    foreach ($parsed['tools'] as $tool) {
        $logFile = $stateDir . '/' . rt_log_name($tool, $parsed['run']);
        if ($tool === 'tests') {
            $commands = array_map(static fn (string $set): array => rt_tool_command($tool, $pin, $testsArgs, $set), $pin['codeceptOptions']);
            [$exitCode, $partsNotRun] = rt_execute_tests($commands, $root, $pin['env'], $logFile);
            $result = rt_result($tool, (string)file_get_contents($logFile), $exitCode);
            if ($partsNotRun !== [] && $result['ran']) {
                $result['ran'] = false;
                $result['result'] .= '; did not run: ' . implode('; ', $partsNotRun);
            }
        } else {
            $commands = [rt_tool_command($tool, $pin, $testsArgs)];
            $exitCode = rt_execute(rt_wrap($commands[0], PHP_OS_FAMILY), $root, $pin['env'], $logFile);
            $result = rt_result($tool, (string)file_get_contents($logFile), $exitCode);
        }
        $notRun += $result['ran'] ? 0 : 1;
        $log = spryker_upgrade_rel($logFile, $root);
        $records[$tool] = ['command' => implode(' && ', array_map(static fn (array $command): string => implode(' ', $command), $commands)), 'exitCode' => $exitCode, 'log' => $log] + $result;
        fwrite(STDOUT, sprintf("%-15s exit %-3d %s  %s\n", $tool, $exitCode, $result['result'], $log));
        // Written after each tool, so a run that is stopped keeps the tools that finished.
        file_put_contents($runsFile, json_encode([
            'run' => $parsed['run'],
            'createdAt' => date('c'),
            'invocation' => $pin,
            'tools' => $records,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    if ($parsed['run'] === 'baseline') {
        fwrite(STDOUT, sprintf("Pinned invocation: %s (sniffer %s)\n", spryker_upgrade_rel($pinDir . '/' . RT_PIN_FILE, $root), $pin['snifferSource']));
    }

    return $notRun === 0 ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(rt_main($argv));
}
