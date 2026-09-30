<?php

/**
 * Baseline gate: checks that every Phase 0 baseline file exists in the state directory and holds a
 * real result of its tool.
 *
 * Every "after" check of the upgrade is compared against a "before" run. Without the baseline a new
 * failure cannot be told apart from a pre-existing one, so the upgrade does not start until all of
 * them exist. `2>&1 | tee` creates the file even when the command failed, so each file is also
 * checked for its tool's result line. The Phase 1.2 re-takes (`*-post-tooling.txt`) are checked the
 * same way when they exist.
 *
 * Usage:
 *   php $UP/check-baselines.php                   # all baselines required
 *   php $UP/check-baselines.php --no-backoffice   # Back Office smoke baseline not required
 *
 * Exit 0 when all required baselines hold a result, 1 when any is missing or holds none, 2 on usage
 * error.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

const BL_REQUIRED = [
    'base-ref' => 'php $UP/check-added-comments.php --record-base',
    'codecept-baseline.txt' => 'full codecept run output before the upgrade',
    'phpstan-baseline-run.txt' => 'phpstan analyse output before the upgrade',
    'sniff-baseline.txt' => 'code sniffer output before the upgrade',
    'evaluator-baseline.txt' => 'spryker-sdk/evaluator output before the upgrade',
    'backoffice-smoke-baseline.json' => 'php $UP/backoffice-smoke.php --url <zed url> --baseline',
];

const BL_POST_TOOLING = [
    'codecept-post-tooling.txt' => 'codecept run output after the tooling step',
    'phpstan-post-tooling.txt' => 'phpstan analyse output after the tooling step',
    'sniff-post-tooling.txt' => 'code sniffer output after the tooling step',
    'evaluator-post-tooling.txt' => 'spryker-sdk/evaluator output after the tooling step',
];

const BL_TOOL_FAILURE = '/is not defined|There are no commands defined|Could not open input file|command not found'
    . '|No such file or directory|Error response from daemon|is not running|Fatal error|Uncaught|Usage:|Unknown option'
    . '|option does not exist|Permission denied/i';

/**
 * The file's text without ANSI escape sequences, carriage returns and other control characters.
 */
function bl_plain_text(string $contents): string
{
    $text = (string)preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e[()][A-Z0-9]|\e[=>]/', '', $contents);
    $text = str_replace("\r", "\n", $text);

    return (string)preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
}

/**
 * The reason a baseline file holds no real result of its tool, or null when it does.
 */
function bl_content_problem(string $file, string $contents): ?string
{
    $text = bl_plain_text($contents);
    if (trim($text) === '') {
        return 'empty';
    }
    if ($file === 'base-ref') {
        return preg_match('/^[0-9a-f]{7,64}$/', trim($text)) === 1 ? null : 'not a commit hash';
    }
    if ($file === 'backoffice-smoke-baseline.json') {
        $decoded = json_decode($contents, true);

        return is_array($decoded) && is_array($decoded['results'] ?? null) ? null : 'not a backoffice-smoke report (no "results" list)';
    }
    $tool = explode('-', $file)[0];
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
    foreach (preg_split('/\n/', $text) ?: [] as $row) {
        if (preg_match(BL_TOOL_FAILURE, $row) === 1) {
            return 'tool error instead of a result: ' . trim($row);
        }
    }

    return null;
}

/**
 * Required baseline files, plus the post-tooling re-takes that exist, mapped to null when the file
 * holds a real result, or to the reason it does not ('missing' when absent).
 *
 * @return array<string, ?string>
 */
function bl_status(string $stateDir, bool $requireBackoffice): array
{
    $status = [];
    foreach (array_keys(BL_REQUIRED) as $file) {
        if ($file === 'backoffice-smoke-baseline.json' && !$requireBackoffice) {
            continue;
        }
        $path = $stateDir . '/' . $file;
        $status[$file] = is_file($path) ? bl_content_problem($file, (string)file_get_contents($path)) : 'missing';
    }
    foreach (array_keys(BL_POST_TOOLING) as $file) {
        $path = $stateDir . '/' . $file;
        if (is_file($path)) {
            $status[$file] = bl_content_problem($file, (string)file_get_contents($path));
        }
    }

    return $status;
}

function bl_main(array $argv): int
{
    require_once __DIR__ . '/bootstrap.php';

    foreach (array_slice($argv, 1) as $arg) {
        if (!in_array($arg, ['--no-backoffice', '--help'], true)) {
            fwrite(STDERR, "Unknown argument: $arg\nUsage: php check-baselines.php [--no-backoffice]\n");

            return 2;
        }
    }
    if (in_array('--help', $argv, true)) {
        fwrite(STDOUT, "Usage: php check-baselines.php [--no-backoffice]\n");

        return 0;
    }

    $root = spryker_upgrade_project_root();
    $stateDir = spryker_upgrade_state_dir($root);
    $status = bl_status($stateDir, !in_array('--no-backoffice', $argv, true));
    $missing = array_keys(array_filter($status, static fn(?string $problem): bool => $problem === 'missing'));
    $noResult = array_filter($status, static fn(?string $problem): bool => $problem !== null && $problem !== 'missing');

    file_put_contents($stateDir . '/baselines-report.json', json_encode([
        'createdAt' => date('c'),
        'stateDir' => spryker_upgrade_rel($stateDir, $root),
        'checked' => array_keys($status),
        'missing' => $missing,
        'noResult' => $noResult,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    fwrite(STDOUT, sprintf("Baselines in %s\n\n", spryker_upgrade_rel($stateDir, $root)));
    foreach ($status as $file => $problem) {
        $label = match (true) {
            $problem === null => 'ok',
            $problem === 'missing' => 'MISSING',
            default => 'NO RESULT',
        };
        $detail = match (true) {
            $problem === null => '',
            $problem === 'missing' => BL_REQUIRED[$file] ?? BL_POST_TOOLING[$file],
            default => $problem . ' — re-run: ' . (BL_REQUIRED[$file] ?? BL_POST_TOOLING[$file]),
        };
        fwrite(STDOUT, sprintf("  %-10s %-32s %s\n", $label, $file, $detail));
    }
    $failing = count($missing) + count($noResult);
    fwrite(STDOUT, "\n" . ($failing === 0
        ? "OK: all baselines recorded with a result.\n"
        : sprintf("%d baseline(s) missing or without a result: %s\n", $failing, implode(' ', array_merge($missing, array_keys($noResult))))));

    return $failing === 0 ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(bl_main($argv));
}
