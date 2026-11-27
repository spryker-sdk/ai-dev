<?php

/**
 * Baselines and regressions, measured from the tool output files, not from what anyone wrote down.
 *
 * Phase 0 writes each tool's output into `$STATE/baseline/`, Phase 6 the same tools' output after the
 * install into `$STATE/after/`. This script checks that every baseline holds a real result (a run
 * that failed to start, or a crawl whose login failed, is not a baseline), then compares each tool
 * after the install with its baseline. Pre-existing failures are allowed; new ones are not.
 *
 * Files (the baseline names are the ones spryker-upgrade's check-baselines.php validates):
 *
 *   baseline/phpstan-baseline-run.txt      after/phpstan-after.txt     error count must not grow
 *   baseline/sniff-baseline.txt            after/sniff-after.txt       error + warning count must not grow
 *   baseline/codecept-baseline.txt         after/codecept-after.txt    no test fails that passed before
 *   baseline/backoffice-smoke-baseline.json  baseline/backoffice-smoke-report.json
 *                                          (backoffice-smoke.php run again with the same state dir)
 *                                          no failing page or endpoint that is not in the baseline
 *   baseline/storage-search-counts-before.json  baseline/storage-search-counts-report.json
 *                                          (storage-search-counts.php --compare before after)
 *                                          no table lost rows
 *   after/yves-build.txt, after/zed-build.txt, after/mp-build.txt
 *                                          each build output ends with `exit <code>`; code 0 passes.
 *                                          A build the project does not have: no file.
 *
 * Usage (from the project root):
 *   php compare-baselines.php <state dir>
 *
 * Exit codes: 0 every baseline is valid and nothing regressed; 1 a baseline is not valid or a check
 * regressed (each named); 2 usage.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../../spryker-upgrade/scripts/check-baselines.php';

const CB_BASELINES = [
    'phpstan' => 'phpstan-baseline-run.txt',
    'sniffer' => 'sniff-baseline.txt',
    'tests' => 'codecept-baseline.txt',
    'backoffice-crawl' => 'backoffice-smoke-baseline.json',
    'storage-counts' => 'storage-search-counts-before.json',
];

const CB_BUILDS = ['yves-build.txt', 'zed-build.txt', 'mp-build.txt'];

function cb_text(string $file): ?string
{
    return is_file($file) ? to_plain_text((string)file_get_contents($file)) : null;
}

/**
 * @return array{baselines: array<string, ?string>, checks: array<string, ?string>} each name → null
 *         when met, else the reason
 */
function cb_evaluate(string $stateDir): array
{
    $baselineDir = $stateDir . '/baseline';
    $afterDir = $stateDir . '/after';
    $baselines = [];
    foreach (CB_BASELINES as $name => $file) {
        $contents = @file_get_contents($baselineDir . '/' . $file);
        $baselines[$name] = $contents === false ? "missing baseline/$file" : bl_content_problem($file, $contents);
    }

    $checks = [];
    $before = cb_text($baselineDir . '/phpstan-baseline-run.txt');
    $after = cb_text($afterDir . '/phpstan-after.txt');
    $checks['phpstan'] = match (true) {
        $after === null => 'missing after/phpstan-after.txt',
        to_phpstan_count($after) === null => 'after/phpstan-after.txt has no PHPStan result line',
        $before === null || to_phpstan_count($before) === null => 'no valid baseline to compare with',
        to_phpstan_count($after) > to_phpstan_count($before) => sprintf('errors grew from %d to %d', to_phpstan_count($before), to_phpstan_count($after)),
        default => null,
    };

    $before = cb_text($baselineDir . '/sniff-baseline.txt');
    $after = cb_text($afterDir . '/sniff-after.txt');
    $checks['sniffer'] = match (true) {
        $after === null => 'missing after/sniff-after.txt',
        to_sniff_count($after) === null => 'after/sniff-after.txt has no sniffer result',
        $before === null || to_sniff_count($before) === null => 'no valid baseline to compare with',
        to_sniff_count($after) > to_sniff_count($before) => sprintf('findings grew from %d to %d', to_sniff_count($before), to_sniff_count($after)),
        default => null,
    };

    $before = cb_text($baselineDir . '/codecept-baseline.txt');
    $after = cb_text($afterDir . '/codecept-after.txt');
    if ($after === null) {
        $checks['tests'] = 'missing after/codecept-after.txt';
    } elseif ($before === null) {
        $checks['tests'] = 'no valid baseline to compare with';
    } else {
        $old = to_codecept_summary($before);
        $new = to_codecept_summary($after);
        $newFailures = array_values(array_diff($new['failing'], $old['failing']));
        $checks['tests'] = match (true) {
            $new['total'] === null => 'after/codecept-after.txt has no summary line',
            $newFailures !== [] => sprintf('%d test(s) fail that passed before: %s', count($newFailures), implode('; ', array_slice($newFailures, 0, 3))),
            $new['failing'] === [] && $old['total'] !== null && $new['total'] > $old['total'] => sprintf('failures grew from %d to %d', $old['total'], $new['total']),
            default => null,
        };
    }

    $report = json_decode((string)@file_get_contents($baselineDir . '/backoffice-smoke-report.json'), true);
    if (!is_array($report) || !is_array($report['results'] ?? null)) {
        $checks['backoffice-crawl'] = 'missing baseline/backoffice-smoke-report.json (run backoffice-smoke.php again after the install)';
    } else {
        $new = array_filter($report['results'], static fn (array $r): bool => ($r['problem'] ?? null) !== null && empty($r['inBaseline']));
        $checks['backoffice-crawl'] = $new === [] ? null : sprintf('%d page(s) or endpoint(s) fail that did not before: %s', count($new), implode('; ', array_slice(array_column($new, 'url'), 0, 3)));
    }

    $report = json_decode((string)@file_get_contents($baselineDir . '/storage-search-counts-report.json'), true);
    if (!is_array($report) || !isset($report['rows'])) {
        $checks['storage-counts'] = 'missing baseline/storage-search-counts-report.json (snapshot after, then --compare before after)';
    } else {
        $failing = array_filter((array)$report['rows'], static fn (array $r): bool => !empty($r['failing']));
        $checks['storage-counts'] = $failing === [] ? null : sprintf('%d table(s) lost rows: %s', count($failing), implode(', ', array_slice(array_column($failing, 'table'), 0, 3)));
    }

    $builds = [];
    foreach (CB_BUILDS as $file) {
        $text = cb_text($afterDir . '/' . $file);
        if ($text === null) {
            continue;
        }
        if (!preg_match_all('/^exit (\d+)\s*$/m', $text, $m)) {
            $builds[] = "after/$file does not end with `exit <code>`";
        } elseif ((int)end($m[1]) !== 0) {
            $builds[] = sprintf('%s failed (exit %d)', basename($file, '.txt'), (int)end($m[1]));
        }
    }
    $hasBuild = array_filter(CB_BUILDS, static fn (string $file): bool => is_file($afterDir . '/' . $file)) !== [];
    $checks['frontend-build'] = match (true) {
        !$hasBuild => 'no build output in after/ (yves-build.txt, zed-build.txt, mp-build.txt)',
        $builds !== [] => implode('; ', $builds),
        default => null,
    };

    return ['baselines' => $baselines, 'checks' => $checks];
}

function cb_main(array $argv): int
{
    $stateDir = $argv[1] ?? null;
    if ($stateDir === null || !is_dir($stateDir)) {
        fwrite(STDERR, "usage: php compare-baselines.php <state dir>\n");

        return 2;
    }
    $result = cb_evaluate(rtrim($stateDir, '/'));
    $problems = 0;
    foreach (['baselines' => 'Baselines', 'checks' => 'After the install'] as $group => $title) {
        fwrite(STDOUT, "$title:\n");
        foreach ($result[$group] as $name => $problem) {
            $problems += $problem === null ? 0 : 1;
            fwrite(STDOUT, sprintf("  %-5s %-17s %s\n", $problem === null ? 'ok' : 'FAIL', $name, $problem ?? ''));
        }
    }

    return $problems === 0 ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(cb_main($argv));
}
