<?php

/**
 * Compares the PHPStan, sniffer, evaluator and test results of a run of run-tools.php with the
 * baselines, and prints only what is new.
 *
 * The baseline of a tool is its Phase 1.2 re-take (`<tool>-post-tooling.txt`) when it exists, else
 * its Phase 0 file (phpstan-baseline-run.txt, sniff-baseline.txt, evaluator-baseline.txt,
 * codecept-baseline.txt). `--baseline phase0` always takes the Phase 0 file; the post-tooling run is
 * always compared with the Phase 0 files. `--baseline-dir <dir>` reads the baseline files from <dir>
 * instead of the state directory; the logs of the run stay in the state directory.
 *
 * Usage (from the project root):
 *   php compare-tool-runs.php <run> [<tool>...] [--baseline phase0] [--baseline-dir <dir>] [--limit N]
 *
 *   <run>   the run name given to run-tools.php (detect, verify, final, post-tooling, ...).
 *   <tool>  phpstan, sniffer, evaluator, tests; default: every tool with a log of the run.
 *
 * New findings: PHPStan errors and sniffer violations by file and message (line numbers ignored in
 * the match), evaluator violations by checker, message and target, and tests that fail now and did
 * not fail in the baseline, per suite. A test that passed in the baseline and is skipped or absent
 * now, and a suite of the baseline that has no result now, count as new too. A new PHPStan error or
 * sniffer violation is printed as `<file>:<line>: <message> [<identifier>]` from the run.
 * Standard output: one verdict line per tool, then at most --limit (default 10) new findings per
 * tool. The full lists go to tool-comparison-<run>.json in the state directory.
 *
 * A log of the run whose last line is not `exit <code>` (a run that was stopped) is NO RESULT.
 *
 * Exit codes: 0 no regression; 1 a regression, or a tool of the run without a result; 2 usage error,
 * a missing log of the run, or a missing baseline or a baseline without a result.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/tool-output.php';
require_once __DIR__ . '/run-tools.php';

const CT_TOOLS = ['phpstan', 'sniffer', 'evaluator', 'tests'];
const CT_USAGE = "Usage: php compare-tool-runs.php <run> [phpstan|sniffer|evaluator|tests]... [--baseline phase0] [--baseline-dir <dir>] [--limit N]\n";

/**
 * Entries of $after that are not in $before, counted: an error that occurs twice now and once in the
 * baseline is new once.
 *
 * @param list<string> $before
 * @param list<string> $after
 *
 * @return list<string>
 */
function ct_new_entries(array $before, array $after): array
{
    $remaining = array_count_values($before);
    $new = [];
    foreach ($after as $entry) {
        if (($remaining[$entry] ?? 0) > 0) {
            $remaining[$entry]--;
            continue;
        }
        $new[] = $entry;
    }

    return $new;
}

/**
 * Findings of $after whose key is not in $before, counted as in ct_new_entries().
 *
 * @param list<array{key: string, text: string, line: ?int, identifier: ?string}> $before
 * @param list<array{key: string, text: string, line: ?int, identifier: ?string}> $after
 *
 * @return list<array{key: string, text: string, line: ?int, identifier: ?string}>
 */
function ct_new_findings(array $before, array $after): array
{
    $remaining = array_count_values(array_column($before, 'key'));
    $new = [];
    foreach ($after as $finding) {
        if (($remaining[$finding['key']] ?? 0) > 0) {
            $remaining[$finding['key']]--;
            continue;
        }
        $new[] = $finding;
    }

    return $new;
}

/**
 * The baseline file of a tool, or null when neither the post-tooling nor the Phase 0 file exists.
 */
function ct_baseline_file(string $stateDir, string $tool, string $run, bool $phase0Only): ?string
{
    $phase0 = rt_log_name($tool, 'baseline');
    $postTooling = rt_log_name($tool, 'post-tooling');
    if (!$phase0Only && $run !== 'post-tooling' && is_file($stateDir . '/' . $postTooling)) {
        return $postTooling;
    }

    return is_file($stateDir . '/' . $phase0) ? $phase0 : null;
}

/**
 * Count and findings of one tool output (see to_finding()); findings are null when the output gives a
 * count only.
 *
 * @return array{count: ?int, findings: ?list<array{key: string, text: string, line: ?int, identifier: ?string}>}
 */
function ct_measure(string $tool, string $text): array
{
    return match ($tool) {
        'phpstan' => ['count' => to_phpstan_count($text), 'findings' => to_phpstan_findings($text)],
        'sniffer' => ['count' => to_sniff_count($text), 'findings' => to_sniff_findings($text)],
        'evaluator' => (static function (?array $findings): array {
            return ['count' => $findings === null ? null : count($findings), 'findings' => $findings === null ? null : array_map(
                static fn (string $finding): array => ['key' => $finding, 'text' => $finding, 'line' => null, 'identifier' => null],
                $findings
            )];
        })(to_evaluator_findings($text)),
        default => ['count' => to_codecept_counts($text)['failed'], 'findings' => null],
    };
}

/**
 * The failing tests per suite, as "<suite>: <test>"; the failure list when the output has no result
 * lines.
 *
 * @return array{failing: list<string>, suites: list<string>}
 */
function ct_failing_tests(string $text): array
{
    $suites = to_codecept_suites($text);
    if ($suites === []) {
        return ['failing' => to_codecept_summary($text)['failing'], 'suites' => []];
    }
    $failing = [];
    foreach ($suites as $name => $suite) {
        foreach ($suite['failed'] as $test) {
            $failing[] = $name . ': ' . $test;
        }
    }

    return ['failing' => $failing, 'suites' => array_keys($suites)];
}

/**
 * The comparison of one tool. "new" holds the text of each new finding; "newFindings" holds the new
 * PHPStan, sniffer and evaluator findings with line number and identifier.
 *
 * @return array{verdict: string, line: string, new: list<string>, newFindings?: list<array<string, mixed>>, baseline: string, log: string}
 */
function ct_compare_tool(string $tool, string $baselineFile, string $baselineContents, string $logFile, string $logContents): array
{
    $unit = ['phpstan' => 'error(s)', 'sniffer' => 'violation(s)', 'evaluator' => 'finding(s)', 'tests' => 'failed test(s)'][$tool];
    $before = to_plain_text(to_strip_exit($baselineContents));
    $after = to_plain_text(to_strip_exit($logContents));
    $resultTool = ['sniffer' => 'sniff', 'tests' => 'codecept'][$tool] ?? $tool;
    $base = ['baseline' => $baselineFile, 'log' => $logFile, 'new' => []];
    $exitCode = to_log_exit_code($logContents);
    if ($exitCode === null) {
        return $base + ['verdict' => 'NO RESULT', 'line' => "$logFile: the last line is not `exit <code>`: the run did not end; run the tool again"];
    }
    $afterProblem = to_result_problem($resultTool, $after, $exitCode);
    if ($afterProblem !== null) {
        return $base + ['verdict' => 'NO RESULT', 'line' => "$logFile: $afterProblem"];
    }

    if ($tool === 'tests') {
        $old = ct_failing_tests($before);
        $now = ct_failing_tests($after);
        $new = ct_new_entries($old['failing'], $now['failing']);
        $missingSuites = $now['suites'] === [] ? [] : array_values(array_diff($old['suites'], $now['suites']));
        foreach ($missingSuites as $suite) {
            $new[] = "$suite: suite has no result in this run";
        }
        $nowSuites = to_codecept_suites($after);
        foreach (to_codecept_suites($before) as $suite => $tests) {
            if (!isset($nowSuites[$suite])) {
                continue;
            }
            foreach (ct_new_entries($nowSuites[$suite]['passed'], $tests['passed']) as $test) {
                if (in_array($test, $nowSuites[$suite]['failed'], true)) {
                    continue;
                }
                $new[] = "$suite: $test: " . (in_array($test, $nowSuites[$suite]['skipped'], true) ? 'skipped now' : 'not in this run') . ', passed in the baseline';
            }
        }
        $oldSummary = to_codecept_summary($before);
        $nowSummary = to_codecept_summary($after);
        $oldCounts = to_codecept_counts($before);
        $counts = to_codecept_counts($after);
        $line = sprintf(
            '%s tests, %s failed, %s skipped; baseline %s tests, %s failed, %s skipped (%s)',
            $counts['tests'] ?? '?',
            $counts['failed'] ?? '?',
            $counts['skipped'] ?? '?',
            $oldCounts['tests'] ?? '?',
            $oldCounts['failed'] ?? '?',
            $oldCounts['skipped'] ?? '?',
            $baselineFile
        );
        if ($new === [] && $now['failing'] === [] && $oldSummary['total'] !== null && (int)$nowSummary['total'] > $oldSummary['total']) {
            $new[] = sprintf('failures grew from %d to %d (no test names in the output)', $oldSummary['total'], $nowSummary['total']);
        }
        if ($new === [] && $nowSuites === [] && $oldCounts['passed'] !== null && $counts['passed'] !== null && $counts['passed'] < $oldCounts['passed']) {
            $new[] = sprintf('passed tests dropped from %d to %d (no test names in the output)', $oldCounts['passed'], $counts['passed']);
        }

        return ['verdict' => $new === [] ? 'ok' : 'REGRESSION', 'line' => $line . ($new === [] ? '' : sprintf('; %d new', count($new))), 'new' => $new] + $base;
    }

    $old = ct_measure($tool, $before);
    $now = ct_measure($tool, $after);
    $newFindings = ct_new_findings($old['findings'] ?? [], $now['findings'] ?? []);
    $new = array_column($newFindings, 'text');
    if ($new === [] && $old['count'] !== null && $now['count'] !== null && $now['count'] > $old['count']) {
        $new[] = sprintf('%d %s more than the baseline (no readable list in the output)', $now['count'] - $old['count'], $unit);
    }
    $line = sprintf('%s %s; baseline %s (%s)', $now['count'] ?? '?', $unit, $old['count'] ?? '?', $baselineFile);
    $details = array_map(static fn (array $finding): array => ['text' => $finding['text'], 'line' => $finding['line'], 'identifier' => $finding['identifier']], $newFindings);

    return ['verdict' => $new === [] ? 'ok' : 'REGRESSION', 'line' => $line . ($new === [] ? '' : sprintf('; %d new', count($new))), 'new' => $new, 'newFindings' => $details] + $base;
}

function ct_main(array $argv): int
{
    $positional = [];
    $phase0Only = false;
    $baselineDir = null;
    $limit = 10;
    $args = array_slice($argv, 1);
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if ($arg === '--baseline-dir' || str_starts_with($arg, '--baseline-dir=')) {
            $baselineDir = rtrim($arg === '--baseline-dir' ? ($args[++$i] ?? '') : substr($arg, 15), '/');
            if ($baselineDir === '' || !is_dir($baselineDir)) {
                fwrite(STDERR, "--baseline-dir takes an existing directory\n" . CT_USAGE);

                return 2;
            }
        } elseif ($arg === '--baseline' || str_starts_with($arg, '--baseline=')) {
            $value = $arg === '--baseline' ? ($args[++$i] ?? '') : substr($arg, 11);
            if ($value !== 'phase0') {
                fwrite(STDERR, "--baseline takes phase0\n" . CT_USAGE);

                return 2;
            }
            $phase0Only = true;
        } elseif ($arg === '--limit' || str_starts_with($arg, '--limit=')) {
            $value = $arg === '--limit' ? ($args[++$i] ?? '') : substr($arg, 8);
            if (!ctype_digit($value)) {
                fwrite(STDERR, "--limit takes a number\n" . CT_USAGE);

                return 2;
            }
            $limit = (int)$value;
        } elseif (str_starts_with($arg, '--')) {
            fwrite(STDERR, "Unknown option: $arg\n" . CT_USAGE);

            return 2;
        } else {
            $positional[] = $arg;
        }
    }
    $run = array_shift($positional);
    if ($run === null || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $run) || $run === 'baseline') {
        fwrite(STDERR, "Name the run to compare (not the baseline).\n" . CT_USAGE);

        return 2;
    }
    foreach ($positional as $tool) {
        if (!in_array($tool, CT_TOOLS, true)) {
            fwrite(STDERR, "Unknown tool: $tool\n" . CT_USAGE);

            return 2;
        }
    }

    $root = spryker_upgrade_project_root();
    $stateDir = spryker_upgrade_state_dir($root);
    $baselineDir ??= $stateDir;
    $tools = $positional !== []
        ? array_values(array_unique($positional))
        : array_values(array_filter(CT_TOOLS, static fn (string $tool): bool => is_file($stateDir . '/' . rt_log_name($tool, $run))));
    if ($tools === []) {
        fwrite(STDERR, "No log of the run \"$run\" in " . spryker_upgrade_rel($stateDir, $root) . ": run run-tools.php $run first.\n");

        return 2;
    }

    $results = [];
    $usage = [];
    foreach ($tools as $tool) {
        $logName = rt_log_name($tool, $run);
        if (!is_file($stateDir . '/' . $logName)) {
            $usage[] = "$tool: no $logName (run run-tools.php $run $tool first)";
            continue;
        }
        $baselineName = ct_baseline_file($baselineDir, $tool, $run, $phase0Only);
        if ($baselineName === null) {
            $usage[] = "$tool: no baseline (" . rt_log_name($tool, 'baseline') . ')';
            continue;
        }
        $baselineContents = (string)file_get_contents($baselineDir . '/' . $baselineName);
        if ($baselineDir !== $stateDir) {
            $baselineName = spryker_upgrade_rel(realpath($baselineDir . '/' . $baselineName) ?: $baselineDir . '/' . $baselineName, $root);
        }
        $problem = to_result_problem(['sniffer' => 'sniff', 'tests' => 'codecept'][$tool] ?? $tool, to_strip_exit($baselineContents), to_log_exit_code($baselineContents));
        if ($problem !== null) {
            $usage[] = "$tool: the baseline $baselineName holds no result ($problem)";
            continue;
        }
        $results[$tool] = ct_compare_tool($tool, $baselineName, $baselineContents, $logName, (string)file_get_contents($stateDir . '/' . $logName));
    }
    if ($usage !== []) {
        fwrite(STDERR, implode("\n", $usage) . "\n");

        return 2;
    }

    $reportFile = $stateDir . '/tool-comparison-' . $run . '.json';
    file_put_contents($reportFile, json_encode(['run' => $run, 'createdAt' => date('c'), 'tools' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

    $failing = 0;
    foreach ($results as $tool => $result) {
        $failing += $result['verdict'] === 'ok' ? 0 : 1;
        fwrite(STDOUT, sprintf("%-10s %-10s %s\n", $tool, $result['verdict'], $result['line']));
        foreach (array_slice($result['new'], 0, $limit) as $entry) {
            fwrite(STDOUT, "  + $entry\n");
        }
        if (count($result['new']) > $limit) {
            fwrite(STDOUT, sprintf(
                "  (%d more: compare-tool-runs.php %s %s --limit %d%s)\n",
                count($result['new']) - $limit,
                $run,
                $tool,
                count($result['new']),
                $baselineDir !== $stateDir ? ' --baseline-dir ' . spryker_upgrade_rel($baselineDir, $root) : ''
            ));
        }
    }
    fwrite(STDOUT, 'Full report: ' . spryker_upgrade_rel($reportFile, $root) . "\n");

    return $failing === 0 ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(ct_main($argv));
}
