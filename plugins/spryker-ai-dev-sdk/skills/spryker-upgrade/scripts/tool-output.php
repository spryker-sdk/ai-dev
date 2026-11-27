<?php

/**
 * Parsing of the PHPStan, code sniffer, evaluator and Codeception output files.
 *
 * Used by check-baselines.php (does a file hold a result), run-tools.php (one result line per tool),
 * compare-tool-runs.php (new findings against the baseline) and spryker-feature-install's
 * compare-baselines.php. Every function takes the raw file contents or the plain text and has no
 * side effects.
 */

declare(strict_types=1);

const TO_TOOL_FAILURE = '/is not defined|There are no commands defined|Could not open input file|command not found'
    . '|No such file or directory|Error response from daemon|is not running|Fatal error|Uncaught|Usage:|Unknown option'
    . '|option does not exist|Permission denied/i';

/**
 * The output of a tool that did not start: no container, no binary, no command.
 */
const TO_START_FAILURE = '/Error response from daemon|is not running|command not found|No such file or directory'
    . '|Could not open input file|There are no commands defined|Command "[^"]+" is not defined|Permission denied/i';

/**
 * The file's text without ANSI escape sequences, the end-of-input echo of `script` (`^D` and two
 * backspaces) and other control characters. A pseudo-terminal line end (`\r\n`) is one line break;
 * a lone `\r` (a line the tool wrote over) starts a new line.
 */
function to_plain_text(string $contents): string
{
    $text = (string)preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e[()][A-Z0-9]|\e[=>]|\^D\x08\x08/', '', $contents);
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    return (string)preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
}

/**
 * The exit code of the `exit <code>` last line that run-tools.php appends to a log, or null when the
 * last line is not `exit <code>`.
 */
function to_log_exit_code(string $contents): ?int
{
    return preg_match('/(?:^|\n)exit (\d+)\s*$/', $contents, $m) === 1 ? (int)$m[1] : null;
}

/**
 * The log without its `exit <code>` last line.
 */
function to_strip_exit(string $contents): string
{
    return (string)preg_replace('/(^|\n)exit \d+\s*$/', '$1', $contents);
}

/**
 * The reason a tool output holds no real result of its tool, or null when it does.
 * $tool: phpstan, sniff, codecept; any other name is checked as evaluator output.
 * $exitCode: the exit code of the tool, when known. A sniffer or evaluator output without a result
 * marker holds a result only when the exit code is 0 or unknown.
 */
function to_result_problem(string $tool, string $contents, ?int $exitCode = null): ?string
{
    $text = to_plain_text($contents);
    if (trim($text) === '') {
        return 'empty';
    }
    if ($tool === 'codecept') {
        return preg_match('/\bOK \(\d+ tests?|\bTests: \d+|FAILURES!|ERRORS!/', $text) === 1
            ? null
            : 'no codecept summary line (`OK (`, `Tests:`, `FAILURES!`)';
    }
    if ($tool === 'phpstan') {
        return preg_match('/\[OK\]|Found \d+ (?:\S+ )?errors?/', $text) === 1
            ? null
            : 'no PHPStan result line (`[OK] No errors` or `Found N errors`)';
    }
    $resultPattern = $tool === 'sniff'
        ? '/FOUND \d+ ERRORS?|^FILE: |Time: |\d+ \/ \d+ \(\d+%\)/m'
        : '/Success!|Read more: |^={5,}\s*$|^\s*\{"/m';
    if (preg_match($resultPattern, $text) === 1) {
        return null;
    }
    $rows = preg_split('/\n/', $text) ?: [];
    foreach ($rows as $row) {
        if (preg_match(TO_TOOL_FAILURE, $row) === 1) {
            return 'tool error instead of a result: ' . trim($row);
        }
    }
    if ($exitCode !== null && $exitCode !== 0) {
        $last = '';
        foreach (array_reverse($rows) as $row) {
            if (trim($row) !== '') {
                $last = trim($row);
                break;
            }
        }

        return "no result line and exit $exitCode: $last";
    }

    return null;
}

/**
 * The first line of the output that says the tool did not start, or null.
 */
function to_start_failure(string $text): ?string
{
    foreach (preg_split('/\n/', $text) ?: [] as $row) {
        if (preg_match(TO_START_FAILURE, $row) === 1) {
            return trim($row);
        }
    }

    return null;
}

function to_squash(string $text): string
{
    return trim((string)preg_replace('/\s+/', ' ', $text));
}

function to_phpstan_count(string $text): ?int
{
    if (preg_match('/Found (\d+) (?:\S+ )?errors?/', $text, $m)) {
        return (int)$m[1];
    }

    return preg_match('/\[OK\] No errors/', $text) ? 0 : null;
}

/**
 * A finding of a tool: "key" is the text the comparison matches (no line number: lines move between
 * runs), "text" the text to print (`<file>:<line>: <message> [<identifier>]`).
 *
 * @return array{key: string, text: string, line: ?int, identifier: ?string}
 */
function to_finding(string $file, ?int $line, string $message, ?string $identifier = null): array
{
    return [
        'key' => $file . ': ' . $message,
        'text' => $file . ($line === null ? '' : ':' . $line) . ': ' . $message . ($identifier === null ? '' : " [$identifier]"),
        'line' => $line,
        'identifier' => $identifier,
    ];
}

/**
 * The PHPStan errors of the table output, each as "<file>: <message>", without line numbers and
 * without the identifier, tip and link lines. Repeated errors stay repeated.
 *
 * @return list<string>
 */
function to_phpstan_errors(string $text): array
{
    return array_column(to_phpstan_findings($text), 'key');
}

/**
 * The PHPStan errors of the table output with line number and identifier (see to_finding()).
 *
 * @return list<array{key: string, text: string, line: ?int, identifier: ?string}>
 */
function to_phpstan_findings(string $text): array
{
    $errors = [];
    $file = null;
    $current = null;
    $open = false;
    $flush = static function () use (&$errors, &$current, &$file): void {
        if ($current !== null) {
            $errors[] = to_finding((string)$file, $current['line'], to_squash($current['message']), $current['identifier']);
        }
        $current = null;
    };
    foreach (explode("\n", $text) as $row) {
        if (preg_match('/^\s*-{3,}[\s-]*$/', $row)) {
            $flush();
            $open = false;
            continue;
        }
        if (preg_match('/^\s*Line\s{2,}(\S.*?)\s*$/', $row, $m)) {
            $flush();
            $file = $m[1];
            continue;
        }
        if (preg_match('/^\s*Error\s*$/', $row)) {
            $flush();
            $file = '(no file)';
            continue;
        }
        if ($file === null) {
            continue;
        }
        if ($current !== null && preg_match('/^\s*🪪\s*(\S+)/u', $row, $m)) {
            $current['identifier'] = $m[1];
            $open = false;
            continue;
        }
        if (preg_match('/^\s*(?:[^\x00-\x7F]|See:|Tip:|https?:\/\/)/u', $row)) {
            $open = false;
            continue;
        }
        if ($file !== '(no file)' && preg_match('/^\s+:?(\d+)\s{2,}(\S.*?)\s*$/', $row, $m)) {
            $flush();
            $current = ['line' => (int)$m[1], 'message' => $m[2], 'identifier' => null];
            $open = true;
            continue;
        }
        if ($file === '(no file)' && preg_match('/^\s+(\S.*?)\s*$/', $row, $m)) {
            $flush();
            $current = ['line' => null, 'message' => $m[1], 'identifier' => null];
            $open = true;
            continue;
        }
        if ($open && $current !== null && preg_match('/^\s{4,}(\S.*?)\s*$/', $row, $m)) {
            $current['message'] .= ' ' . $m[1];
        }
    }
    $flush();

    return $errors;
}

/**
 * Errors plus warnings of the sniffer output: the TOTAL line, else the sum of the FOUND lines, else 0
 * when the output holds a sniffer result without findings; null when it holds no result.
 */
function to_sniff_count(string $text): ?int
{
    if (preg_match('/A TOTAL OF (\d+) ERRORS? AND (\d+) WARNINGS?/', $text, $m)) {
        return (int)$m[1] + (int)$m[2];
    }
    if (preg_match_all('/FOUND (\d+) ERRORS?(?: AND (\d+) WARNINGS?)?/', $text, $m, PREG_SET_ORDER)) {
        return array_sum(array_map(static fn (array $hit): int => (int)$hit[1] + (int)($hit[2] ?? 0), $m));
    }

    return to_result_problem('sniff', to_strip_exit($text), to_log_exit_code($text)) === null ? 0 : null;
}

/**
 * The sniffer violations of the full report, each as "<file>: <ERROR|WARNING> <message>", without
 * line numbers.
 *
 * @return list<string>
 */
function to_sniff_violations(string $text): array
{
    return array_column(to_sniff_findings($text), 'key');
}

/**
 * The sniffer violations of the full report with line number (see to_finding()).
 *
 * @return list<array{key: string, text: string, line: ?int, identifier: ?string}>
 */
function to_sniff_findings(string $text): array
{
    $violations = [];
    $file = null;
    $current = null;
    $flush = static function () use (&$violations, &$current, &$file): void {
        if ($current !== null) {
            $violations[] = to_finding((string)$file, $current['line'], to_squash($current['message']));
        }
        $current = null;
    };
    foreach (explode("\n", $text) as $row) {
        if (preg_match('/^FILE: (.+?)\s*$/', $row, $m)) {
            $flush();
            $file = $m[1];
            continue;
        }
        if ($file !== null && preg_match('/^\s*(\d+)\s*\|\s*(ERROR|WARNING)\s*\|\s*(?:\[[ x]\]\s*)?(.*?)\s*$/', $row, $m)) {
            $flush();
            $current = ['line' => (int)$m[1], 'message' => $m[2] . ' ' . $m[3]];
            continue;
        }
        if ($current !== null && preg_match('/^\s*\|\s*\|\s*(.*?)\s*$/', $row, $m)) {
            $current['message'] .= ' ' . $m[1];
            continue;
        }
        $flush();
    }
    $flush();

    return $violations;
}

/**
 * The evaluator findings: one entry per violation row, "<checker>: <message> (<target>)"; a checker
 * with violations but no readable row counts as one finding "<checker>". Null when the output holds
 * no evaluator result.
 *
 * @return list<string>|null
 */
function to_evaluator_findings(string $text): ?array
{
    if (to_result_problem('evaluator', to_strip_exit($text), to_log_exit_code($text)) !== null) {
        return null;
    }
    $rows = explode("\n", $text);
    $findings = [];
    $checker = null;
    $checkerRows = 0;
    $checkerFailed = false;
    $current = null;
    $closeChecker = static function () use (&$findings, &$checker, &$checkerRows, &$checkerFailed, &$current): void {
        if ($current !== null) {
            $findings[] = $checker . ': ' . to_squash($current[0]) . ' (' . to_squash($current[1]) . ')';
            $current = null;
        }
        if ($checker !== null && $checkerFailed && $checkerRows === 0) {
            $findings[] = $checker;
        }
    };
    foreach ($rows as $i => $row) {
        if (preg_match('/^={3,}\s*$/', $row) && isset($rows[$i + 2]) && preg_match('/^={3,}\s*$/', $rows[$i + 2]) && trim($rows[$i + 1]) !== '') {
            $closeChecker();
            $checker = trim($rows[$i + 1]);
            $checkerRows = 0;
            $checkerFailed = false;
            continue;
        }
        if ($checker === null) {
            continue;
        }
        if (str_starts_with(trim($row), 'Read more: ')) {
            $checkerFailed = true;
        }
        if (preg_match('/^\|\s*(\d+)\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*$/', $row, $m)) {
            if ($current !== null) {
                $findings[] = $checker . ': ' . to_squash($current[0]) . ' (' . to_squash($current[1]) . ')';
            }
            $current = [$m[2], $m[3]];
            $checkerRows++;
            $checkerFailed = true;
            continue;
        }
        if ($current !== null && preg_match('/^\|\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*$/', $row, $m)) {
            $current[0] .= ' ' . $m[1];
            $current[1] .= ' ' . $m[2];
            continue;
        }
        if ($current !== null && preg_match('/^\+[-+]+\+\s*$/', $row)) {
            $findings[] = $checker . ': ' . to_squash($current[0]) . ' (' . to_squash($current[1]) . ')';
            $current = null;
        }
    }
    $closeChecker();

    return $findings;
}

/**
 * The Codeception summary: the tests of the failure list ("1) FooTest: bar") and the error plus
 * failure count of the `Tests:` line (0 for `OK (`, null without a summary line).
 *
 * @return array{total: ?int, failing: list<string>}
 */
function to_codecept_summary(string $text): array
{
    $failing = [];
    if (preg_match_all('/^\s*\d+\) (\S.*?)\s*$/m', $text, $m)) {
        $failing = array_values(array_unique(array_map('trim', $m[1])));
    }
    $total = null;
    if (preg_match_all('/Tests: \d+, Assertions: \d+(?:, Errors: (\d+))?(?:, Failures: (\d+))?/', $text, $m, PREG_SET_ORDER)) {
        $total = array_sum(array_map(static fn (array $hit): int => (int)($hit[1] ?? 0) + (int)($hit[2] ?? 0), $m));
    } elseif (preg_match('/\bOK \(\d+ tests?/', $text)) {
        $total = 0;
    }

    return ['total' => $total, 'failing' => $failing];
}

/**
 * The tests of each suite by their result line: `✔`/`+` passed, `✖`/`x`/`E` failed, `S`/`I`/`U`/`W`
 * skipped. A suite is "<include namespace> <suite>" when the run includes several configurations.
 *
 * @return array<string, array{passed: list<string>, failed: list<string>, skipped: list<string>}>
 */
function to_codecept_suites(string $text): array
{
    $suites = [];
    $namespace = '';
    $suite = null;
    foreach (explode("\n", $text) as $row) {
        if (str_starts_with($row, 'Codeception PHP Testing Framework')) {
            // A new `codecept run` starts: its suites do not belong to the last include namespace.
            $namespace = '';
            $suite = null;
            continue;
        }
        if (preg_match('/^\[(.+?)\]: tests from /', $row, $m)) {
            $namespace = $m[1];
            continue;
        }
        if (preg_match('/^(\S.*?) Tests \(\d+\) -*\s*$/', $row, $m)) {
            $suite = $namespace === '' ? $m[1] : $namespace . ' ' . $m[1];
            $suites[$suite] ??= ['passed' => [], 'failed' => [], 'skipped' => []];
            continue;
        }
        if ($suite === null) {
            continue;
        }
        if (preg_match('/^(Time: |There (?:was|were) \d+ |FAILURES!|ERRORS!|OK \()/', $row)) {
            $suite = null;
            continue;
        }
        if (preg_match('/^(✔|✖|\+|x|E|S|I|U|W) (\S.*?)(?:\s+\(\d+(?:\.\d+)?m?s\))?\s*$/u', $row, $m)) {
            $kind = match ($m[1]) {
                '✔', '+' => 'passed',
                '✖', 'x', 'E' => 'failed',
                default => 'skipped',
            };
            $suites[$suite][$kind][] = to_squash((string)preg_replace('/\s+(?:\d+x)?\[F\]\s*$/', '', $m[2]));
        }
    }

    return $suites;
}

/**
 * Counts of a test run: from the result lines per suite, or from the summary line when the output
 * has no result lines.
 *
 * @return array{tests: ?int, passed: ?int, failed: ?int, skipped: ?int}
 */
function to_codecept_counts(string $text): array
{
    $suites = to_codecept_suites($text);
    $counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
    foreach ($suites as $suite) {
        foreach ($counts as $kind => $count) {
            $counts[$kind] = $count + count($suite[$kind]);
        }
    }
    if (array_sum($counts) > 0) {
        return ['tests' => array_sum($counts)] + $counts;
    }
    if (preg_match_all('/Tests: (\d+), Assertions: \d+(?:, Errors: (\d+))?(?:, Failures: (\d+))?(?:, Warnings: \d+)?(?:, Skipped: (\d+))?(?:, Incomplete: (\d+))?/', $text, $m, PREG_SET_ORDER)) {
        $tests = $failed = $skipped = 0;
        foreach ($m as $hit) {
            $tests += (int)$hit[1];
            $failed += (int)($hit[2] ?? 0) + (int)($hit[3] ?? 0);
            $skipped += (int)($hit[4] ?? 0) + (int)($hit[5] ?? 0);
        }

        return ['tests' => $tests, 'passed' => $tests - $failed - $skipped, 'failed' => $failed, 'skipped' => $skipped];
    }
    if (preg_match_all('/\bOK \((\d+) tests?/', $text, $m)) {
        $tests = array_sum(array_map('intval', $m[1]));

        return ['tests' => $tests, 'passed' => $tests, 'failed' => 0, 'skipped' => 0];
    }

    return ['tests' => null, 'passed' => null, 'failed' => null, 'skipped' => null];
}
