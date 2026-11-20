<?php

/**
 * Checklist gate: a feature integration is not finished while a guide step is undecided, open,
 * failed or unverified.
 *
 * Usage:
 *   php check-checklist.php <checklist.json> [--phase decide|report]
 *
 *   --phase decide   after reconcile, before the install: every row has a valid decision, no row
 *                    is `unknown`, every data row that is not skipped has a `data_class`
 *   --phase report   (default) before the report: the decide checks, plus every row is `done` or
 *                    `skipped`, every verified row has a passing verdict, and at least one `test`
 *                    row is `done`
 *
 * A row's `decision` is one of apply-guide, apply-reference, merge, already-present or
 * skip:<reason>. A `verification` row that is not skipped needs `verdict` PASS, or ACCEPTED with a
 * `verdict_note` that names who accepted it and why. A feature without a passing test is only
 * accepted when the checklist's `tests_waived` holds the developer's reason: the next upgrade has
 * nothing else that notices the feature stopped working.
 *
 * Exit codes: 0 clean; 1 problems (listed by row); 2 usage or unreadable checklist.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/**
 * @return list<string> one line per problem
 */
function cc_problems(array $checklist, string $phase): array
{
    return array_merge(...array_values(cc_problems_by_item($checklist, $phase)));
}

/**
 * The problems grouped as the Definition of Done counts them: rows (decisions and status),
 * verification (verdicts) and tests (coverage).
 *
 * @return array{rows: list<string>, verification: list<string>, tests: list<string>}
 */
function cc_problems_by_item(array $checklist, string $phase): array
{
    $byItem = ['rows' => [], 'verification' => [], 'tests' => []];
    foreach ($checklist['rows'] as $row) {
        $problems = [];
        $id = (string)($row['id'] ?? '?');
        $where = sprintf('%s %s %s', $id, $row['kind'] ?? '?', $row['target'] ?? '');
        $decision = $row['decision'] ?? null;
        $status = $row['status'] ?? null;

        if (($row['kind'] ?? '') === 'unknown') {
            $problems[] = "$where: kind is unknown — read the guide at lines " . implode('-', $row['lines'] ?? []) . ' and set the kind';
        }
        if (in_array($row['kind'] ?? '', FI_DATA_KINDS, true) && !fi_is_skip($decision) && !in_array($row['data_class'] ?? null, FI_DATA_CLASSES, true)) {
            $problems[] = "$where: data row without a data class — set data_class to feature-data, project-content or demo-data";
        }
        if ($decision === null || $decision === '') {
            $problems[] = "$where: no decision";
        } elseif (fi_is_skip($decision)) {
            if (trim(substr($decision, 5)) === '') {
                $problems[] = "$where: skip without a reason — write skip:<reason>";
            }
        } elseif (!in_array($decision, FI_DECISIONS, true)) {
            $problems[] = "$where: unknown decision '$decision'";
        } elseif (in_array($decision, ['apply-reference', 'merge'], true) && empty($row['reference'])) {
            $problems[] = "$where: decision $decision without the reference it is based on";
        }
        if ($status !== null && !in_array($status, FI_STATUSES, true)) {
            $problems[] = "$where: unknown status '$status'";
        }

        if ($phase !== 'report') {
            $byItem['rows'] = array_merge($byItem['rows'], $problems);
            continue;
        }
        if ($status === 'failed') {
            $problems[] = "$where: failed";
        } elseif (!in_array($status, ['done', 'skipped'], true)) {
            $problems[] = "$where: still $status";
        } elseif ($status === 'skipped' && !fi_is_skip($decision)) {
            $problems[] = "$where: skipped, but the decision is not skip:<reason>";
        }
        if (($row['kind'] ?? '') === 'verification' && !fi_is_skip($decision)) {
            $verdict = $row['verdict'] ?? null;
            if (!in_array($verdict, FI_VERDICTS_PASSING, true)) {
                $problems[] = "$where: verdict " . ($verdict ?? 'missing') . ' — a verification row needs PASS, or ACCEPTED with a verdict_note';
            } elseif ($verdict === 'ACCEPTED' && trim((string)($row['verdict_note'] ?? '')) === '') {
                $problems[] = "$where: ACCEPTED without a verdict_note";
            }
        }
        $item = match ($row['kind'] ?? '') {
            'verification' => 'verification',
            'test' => 'tests',
            default => 'rows',
        };
        $byItem[$item] = array_merge($byItem[$item], $problems);
    }

    if ($phase === 'report') {
        $coveringTests = array_filter($checklist['rows'], static fn (array $row): bool => ($row['kind'] ?? '') === 'test' && ($row['status'] ?? '') === 'done');
        if ($coveringTests === [] && trim((string)($checklist['tests_waived'] ?? '')) === '') {
            $byItem['tests'][] = 'no test covers the feature: add `test` rows and make them pass (Phase 6b), or set `tests_waived` to the developer\'s reason';
        }
    }

    return $byItem;
}

function cc_main(array $argv): int
{
    $path = null;
    $phase = 'report';
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--phase') {
            $phase = (string)($argv[++$i] ?? '');
        } else {
            $path = $argv[$i];
        }
    }
    if ($path === null || !in_array($phase, ['decide', 'report'], true)) {
        fwrite(STDERR, "usage: php check-checklist.php <checklist.json> [--phase decide|report]\n");

        return 2;
    }
    $checklist = fi_load_checklist($path);
    if ($checklist === null) {
        fwrite(STDERR, "$path is not a readable checklist\n");

        return 2;
    }
    $problems = cc_problems($checklist, $phase);
    if ($problems === []) {
        fwrite(STDOUT, sprintf("OK: %d rows, phase %s.\n", count($checklist['rows']), $phase));

        return 0;
    }
    fwrite(STDOUT, sprintf("%d problem(s), phase %s:\n", count($problems), $phase));
    foreach ($problems as $problem) {
        fwrite(STDOUT, "  $problem\n");
    }

    return 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(cc_main($argv));
}
