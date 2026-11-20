<?php

/**
 * Definition of Done: is the feature integration finished — DONE or NOT DONE, item by item.
 *
 * A run can install a feature, see most checks pass and still not be finished: a blocked
 * verification, a baseline that never ran, a module that moved without being needed, no test, a data
 * file no environment imports, release steps nobody proved. This script decides the status from
 * evidence — tool output, lock files, the checklist, the project files — so a status cannot claim
 * more than the evidence shows. Its first output line is the run's status.
 *
 * Usage (from the project root, or set SPRYKER_PROJECT_ROOT):
 *   php check-dod.php <state dir>        e.g. .ai-dev/feature-install/recurring-orders
 *
 * Evidence it reads:
 *   baseline/, after/     the tool outputs compare-baselines.php reads (items 2, 6)
 *   composer.lock.before  with composer.lock and composer.json: check-needed-moves.php (item 3)
 *   checklist.json        rows, wiring, verdicts, `test` rows and their files, `tests_waived`
 *                         (items 4, 5, 7, 8)
 *   release-steps.md      the release-steps log (item 10): a row is met when its status starts
 *                         with `verified`, `not needed` or `covered`
 *   dod.json              what no file shows (items 1, 3, 9, 10, and waivers for 2 and 6):
 *   {
 *     "scope": {"feature": "...", "install_release": "...", "update_mode": "-w", "branch": "...",
 *               "confirmed": "<the developer's answer>"},
 *     "accepted_moves": {"spryker/<module>": "<the developer's reason to keep the move>"},
 *     "data_files": ["data/import/..."],
 *     "authored_content": [{"file": "...", "what": "<the texts the agent wrote, for example fr_FR translations>"}],
 *     "release_steps_accepted": "<the developer's reason to ship with unproven steps>",
 *     "waived": {"baseline:backoffice-crawl": "<the developer's reason>", "check:frontend-build": "..."}
 *   }
 *
 * Exit codes: 0 DONE; 1 NOT DONE (each unmet item listed); 2 usage or unreadable state.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/check-checklist.php';
require_once __DIR__ . '/check-lock-diff.php';
require_once __DIR__ . '/check-feature-installed.php';
require_once __DIR__ . '/check-needed-moves.php';
require_once __DIR__ . '/compare-baselines.php';
require_once __DIR__ . '/check-data-manifests.php';

const DOD_ITEMS = [
    1 => 'the developer confirmed the scope',
    2 => 'every baseline ran and is valid',
    3 => 'only modules the feature needs moved; every other move has a reason',
    4 => 'every checklist row is decided, and done or skipped with a reason',
    5 => 'the decided wiring is in place',
    6 => 'no regression against the baselines, and the frontend builds pass',
    7 => 'every verification passed',
    8 => 'passing tests cover the feature',
    9 => 'every data file reaches an environment, and every text the agent wrote is recorded for review',
    10 => 'the release steps are proven',
];

/**
 * @return list<string> unmet reasons of a measured group, minus the ones the developer waived
 */
function dod_unwaived(array $measured, array $waived, string $prefix): array
{
    $unmet = [];
    foreach ($measured as $name => $problem) {
        if ($problem !== null && trim((string)($waived["$prefix:$name"] ?? '')) === '') {
            $unmet[] = "$name: $problem";
        }
    }

    return $unmet;
}

/**
 * @return list<string> release-steps log rows whose status is not verified, not needed or covered
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
    $waived = (array)($dod['waived'] ?? []);
    $result = array_fill_keys(array_keys(DOD_ITEMS), []);

    $scope = $dod['scope'] ?? [];
    foreach (['feature', 'install_release', 'update_mode', 'branch', 'confirmed'] as $field) {
        if (trim((string)($scope[$field] ?? '')) === '') {
            $result[1][] = "scope.$field is not recorded";
        }
    }

    $measured = cb_evaluate($stateDir);
    $result[2] = dod_unwaived($measured['baselines'], $waived, 'baseline');
    $result[6] = dod_unwaived($measured['checks'], $waived, 'check');

    $before = $stateDir . '/composer.lock.before';
    if (!is_file($before) || !is_file($root . '/composer.lock')) {
        $result[3][] = 'cannot compare: composer.lock.before or composer.lock is missing';
    } else {
        $composerJson = (array)json_decode((string)@file_get_contents($root . '/composer.json'), true);
        $classified = cnm_classify(fi_lock_packages($before), fi_lock_packages($root . '/composer.lock'), $composerJson);
        foreach (cnm_open($classified, (array)($dod['accepted_moves'] ?? [])) as $package => $move) {
            $result[3][] = sprintf('%s %s -> %s: %s, and no reason recorded', $package, $move['before'], $move['after'], $move['verdict']);
        }
        $features = $checklist !== null ? array_map('strtolower', cld_features_from_checklist($checklist)) : [];
        foreach (cld_dev_constraints($composerJson) as $package => $constraint) {
            if (in_array(strtolower($package), $features, true)) {
                $result[3][] = "$package has the dev constraint $constraint";
            }
        }
    }

    if ($checklist === null) {
        foreach ([4, 5, 7, 8] as $item) {
            $result[$item][] = 'checklist.json is missing or unreadable';
        }
    } else {
        $byItem = cc_problems_by_item($checklist, 'report');
        $result[4] = $byItem['rows'];
        $result[7] = $byItem['verification'];
        $result[8] = $byItem['tests'];
        foreach ($checklist['rows'] as $row) {
            if (($row['kind'] ?? '') === 'test' && ($row['status'] ?? '') === 'done' && !is_file($root . '/' . ltrim((string)($row['target'] ?? ''), '/'))) {
                $result[8][] = sprintf('%s: the test file %s does not exist', $row['id'] ?? '?', $row['target'] ?? '');
            }
        }

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

        $dataRowsDone = array_filter($checklist['rows'], static fn (array $row): bool => in_array($row['kind'] ?? '', FI_DATA_KINDS, true) && ($row['status'] ?? '') === 'done');
        $dataFiles = array_values(array_filter((array)($dod['data_files'] ?? []), 'is_string'));
        if ($dataRowsDone !== [] && $dataFiles === []) {
            $result[9][] = 'data rows are done, but dod.json data_files does not list the files the hand-off wrote';
        }
        if ($dataFiles !== []) {
            foreach (cdm_evaluate($root, $dataFiles) as $file => $importedBy) {
                if ($importedBy === []) {
                    $result[9][] = "$file is imported by no manifest a recipe runs";
                }
            }
        }
    }

    foreach ($dod['authored_content'] ?? [] as $index => $content) {
        $file = trim((string)($content['file'] ?? ''));
        if ($file === '' || trim((string)($content['what'] ?? '')) === '') {
            $result[9][] = sprintf('authored_content entry %d needs both `file` and `what`, so the report can list it for review', $index + 1);
        } elseif (!is_file($root . '/' . ltrim($file, '/'))) {
            $result[9][] = "authored_content names $file, which does not exist";
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
