<?php

/**
 * Definition of Done: is the feature integration finished — DONE or NOT DONE, item by item.
 *
 * A run can install a feature, see most checks pass and still not be finished: a blocked
 * verification, a baseline that never ran, a module that moved without a reason, no test, release
 * steps nobody proved. This script decides the status from evidence, so the report cannot claim
 * more than the evidence shows. Its first output line is the report's first line, word for word.
 *
 * Usage (from the project root, or set SPRYKER_PROJECT_ROOT):
 *   php check-dod.php <state dir>        e.g. .ai-dev/feature-install/recurring-orders
 *
 * Evidence it reads from the state dir:
 *   checklist.json        rows, verdicts, `test` rows, `tests_waived` (items 4, 5, 7, 8, 9)
 *   dod.json              scope, baselines, accepted module moves, after-install checks, authored
 *                         content, accepted unproven release steps (items 1, 2, 3, 6, 9, 10)
 *   composer.lock.before  with the project's composer.lock and composer.json (item 3)
 *   release-steps.md      the release-steps log (item 10): a row is met when its status starts
 *                         with `verified`, `not needed` or `covered`
 *
 * dod.json:
 *   {
 *     "scope": {"feature": "...", "install_release": "...", "update_mode": "-w", "branch": "...",
 *               "confirmed": "<the developer's answer>"},
 *     "baselines": {"phpstan": "valid", "sniffer": "valid", "tests": "valid",
 *                   "backoffice-crawl": "valid", "storage-counts": "valid"},
 *     "accepted_moves": {"spryker/<module>": "<why the move stays>"},
 *     "checks": {"phpstan": "pass", "sniffer": "pass", "tests": "pass", "backoffice-crawl": "pass",
 *                "storage-counts": "pass", "frontend-build": "pass"},
 *     "authored_content": [{"file": "...", "what": "...", "approved": "<who approved, when>"}],
 *     "release_steps_accepted": "<the developer's reason to ship with unproven steps>"
 *   }
 *   A baseline is `valid`; a check is `pass`. Anything else is unmet, except `waived: <the
 *   developer's reason>`. `blocked: <why>` records a blocker, and a blocker is unmet.
 *
 * Exit codes: 0 DONE; 1 NOT DONE (each unmet item listed); 2 usage or unreadable state.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/check-checklist.php';
require_once __DIR__ . '/check-lock-diff.php';
require_once __DIR__ . '/check-feature-installed.php';

const DOD_BASELINES = ['phpstan', 'sniffer', 'tests', 'backoffice-crawl', 'storage-counts'];
const DOD_CHECKS = ['phpstan', 'sniffer', 'tests', 'backoffice-crawl', 'storage-counts', 'frontend-build'];

const DOD_ITEMS = [
    1 => 'the developer confirmed the scope',
    2 => 'every baseline ran and is valid',
    3 => 'only modules the feature needs moved; every other move has a reason',
    4 => 'every checklist row is decided, and done or skipped with a reason',
    5 => 'the decided wiring is in place',
    6 => 'no regression against the baselines, and the frontend builds pass',
    7 => 'every verification passed',
    8 => 'passing tests cover the feature',
    9 => 'every text the agent wrote is approved',
    10 => 'the release steps are proven',
];

function dod_waived(string $value): bool
{
    return (bool)preg_match('/^waived:\s*\S/', $value);
}

/**
 * @return list<string> unmet entries of a baselines or checks map
 */
function dod_unmet_map(array $map, array $required, string $passValue): array
{
    $unmet = [];
    foreach ($required as $name) {
        $value = trim((string)($map[$name] ?? ''));
        if ($value === '') {
            $unmet[] = "$name: not recorded";
        } elseif ($value !== $passValue && !dod_waived($value)) {
            $unmet[] = "$name: $value";
        }
    }

    return $unmet;
}

/**
 * @return list<string> release-steps log rows whose status is not verified or not needed
 */
function dod_unproven_release_steps(string $file): array
{
    if (!is_file($file)) {
        return ['release-steps.md is missing'];
    }
    $unproven = [];
    $inTable = false;
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!preg_match('/^\s*\|/', $line)) {
            $inTable = false;
            continue;
        }
        $isHeader = !$inTable;
        $inTable = true;
        if ($isHeader || preg_match('/^\s*\|?[\s:\-|]+\|?\s*$/', $line)) {
            continue;
        }
        $cells = array_map('trim', explode('|', trim(trim($line), '|')));
        $status = strtolower((string)end($cells));
        if (!preg_match('/^(verified|not needed|covered)\b/', $status)) {
            $unproven[] = $cells[0] . ': ' . ($status !== '' ? $status : 'no status');
        }
    }

    return $unproven;
}

/**
 * @return array<int, list<string>> item number => unmet reasons (empty list = met)
 */
function dod_evaluate(string $root, string $stateDir): array
{
    $checklist = fi_load_checklist($stateDir . '/checklist.json');
    $dod = json_decode((string)@file_get_contents($stateDir . '/dod.json'), true);
    $dod = is_array($dod) ? $dod : [];
    $result = array_fill_keys(array_keys(DOD_ITEMS), []);

    $scope = $dod['scope'] ?? [];
    foreach (['feature', 'install_release', 'update_mode', 'branch', 'confirmed'] as $field) {
        if (trim((string)($scope[$field] ?? '')) === '') {
            $result[1][] = "scope.$field is not recorded";
        }
    }

    $result[2] = dod_unmet_map($dod['baselines'] ?? [], DOD_BASELINES, 'valid');

    $before = $stateDir . '/composer.lock.before';
    if ($checklist === null || !is_file($before) || !is_file($root . '/composer.lock')) {
        $result[3][] = 'cannot compare: checklist.json, composer.lock.before or composer.lock is missing';
    } else {
        $after = fi_lock_packages($root . '/composer.lock');
        $features = cld_features_from_checklist($checklist);
        $outside = array_diff_key(cld_moved(fi_lock_packages($before), $after), cld_tree($after, $features));
        foreach ($outside as $package => $versions) {
            if (trim((string)($dod['accepted_moves'][$package] ?? '')) === '') {
                $result[3][] = sprintf('%s %s -> %s moved outside the feature tree with no reason', $package, $versions['before'] ?? '(new)', $versions['after'] ?? '(removed)');
            }
        }
        $composerJson = (array)json_decode((string)@file_get_contents($root . '/composer.json'), true);
        foreach (cld_dev_constraints($composerJson) as $package => $constraint) {
            if (in_array(strtolower($package), array_map('strtolower', $features), true)) {
                $result[3][] = "$package has the dev constraint $constraint";
            }
        }
    }

    if ($checklist === null) {
        $result[4][] = 'checklist.json is missing or unreadable';
        $result[7][] = 'checklist.json is missing or unreadable';
        $result[8][] = 'checklist.json is missing or unreadable';
    } else {
        $byItem = cc_problems_by_item($checklist, 'report');
        $result[4] = $byItem['rows'];
        $result[7] = $byItem['verification'];
        $result[8] = $byItem['tests'];

        $namespaces = cfi_project_namespaces($root);
        $providers = cfi_dependency_providers($root, $namespaces);
        foreach ($checklist['rows'] as $row) {
            $decision = $row['decision'] ?? null;
            if (!in_array($row['kind'] ?? '', CFI_WIRING_KINDS, true) || $decision === null || fi_is_skip($decision)) {
                continue;
            }
            $state = cfi_wiring_state($root, $row, $namespaces, $providers);
            if (!in_array($state['state'], ['present', 'file-present'], true)) {
                $result[5][] = sprintf('%s %s: %s', $row['id'] ?? '?', $row['target'] ?? '', $state['state']);
            }
        }
    }

    $result[6] = dod_unmet_map($dod['checks'] ?? [], DOD_CHECKS, 'pass');

    foreach ($dod['authored_content'] ?? [] as $content) {
        if (trim((string)($content['approved'] ?? '')) === '') {
            $result[9][] = sprintf('%s (%s) is not approved', $content['file'] ?? '?', $content['what'] ?? 'text the agent wrote');
        }
    }

    if (trim((string)($dod['release_steps_accepted'] ?? '')) === '') {
        $result[10] = dod_unproven_release_steps($stateDir . '/release-steps.md');
    }

    return $result;
}

function dod_main(array $argv): int
{
    $stateDir = $argv[1] ?? null;
    if ($stateDir === null || !is_dir($stateDir)) {
        fwrite(STDERR, "usage: php check-dod.php <state dir>   (for example .ai-dev/feature-install/<feature-slug>)\n");

        return 2;
    }
    $result = dod_evaluate(fi_project_root(), rtrim($stateDir, '/'));
    $unmet = array_filter($result, static fn (array $reasons): bool => $reasons !== []);

    fwrite(STDOUT, $unmet === []
        ? "DONE — all 10 Definition of Done items are met.\n"
        : sprintf("NOT DONE — %d of 10 Definition of Done items are not met: %s.\n", count($unmet), implode(', ', array_keys($unmet))));
    foreach (DOD_ITEMS as $number => $label) {
        $reasons = $result[$number];
        fwrite(STDOUT, sprintf("  [%s] %2d %s\n", $reasons === [] ? 'x' : ' ', $number, $label));
        foreach (array_slice($reasons, 0, 8) as $reason) {
            fwrite(STDOUT, '         - ' . (mb_strlen($reason) > 160 ? mb_substr($reason, 0, 120) . ' … ' . mb_substr($reason, -36) : $reason) . "\n");
        }
        if (count($reasons) > 8) {
            fwrite(STDOUT, sprintf("         - and %d more\n", count($reasons) - 8));
        }
    }

    return $unmet === [] ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(dod_main($argv));
}
