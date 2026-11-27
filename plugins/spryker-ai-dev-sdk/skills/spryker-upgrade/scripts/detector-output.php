<?php

/**
 * Standard output of the spryker-upgrade detectors: one verdict line with the count of each
 * category, at most --limit findings of one line each (with the field to act on), then the path of
 * the report file. The report file keeps the full list. `--category <name>` prints only the findings
 * of that category, so `--category X --limit N` gives a part of the list without a read of the report.
 *
 * Detectors without a snapshot of their own keep their Phase 0 result: `--baseline` writes a copy of
 * the report as `<name>-baseline.json`; a later run marks each finding `inBaseline` in its report,
 * prints only the new findings and counts the ones Phase 0 already had.
 */

declare(strict_types=1);

const SPRYKER_UPGRADE_DEFAULT_LIMIT = 10;
const SPRYKER_UPGRADE_FIELD_LENGTH = 120;

/**
 * Remove `--limit N` (`--limit=N`), `--category <name>` (`--category=a,b`, repeatable) and, when the
 * detector takes them, `--baseline` and `--force` from $argv.
 *
 * @param list<string> $argv
 *
 * @return array{limit: int, baseline: bool, force: bool, categories: list<string>}
 */
function spryker_upgrade_output_options(array &$argv, bool $takesBaseline = false): array
{
    $options = ['limit' => SPRYKER_UPGRADE_DEFAULT_LIMIT, 'baseline' => false, 'force' => false, 'categories' => []];
    $kept = [];
    for ($i = 0; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--limit' || str_starts_with($arg, '--limit=')) {
            $value = $arg === '--limit' ? (string)($argv[++$i] ?? '') : substr($arg, 8);
            if (!ctype_digit($value)) {
                fwrite(STDERR, "--limit takes a number (0 prints the counts only)\n");
                exit(2);
            }
            $options['limit'] = (int)$value;
            continue;
        }
        if ($arg === '--category' || str_starts_with($arg, '--category=')) {
            $value = $arg === '--category' ? (string)($argv[++$i] ?? '') : substr($arg, 11);
            $names = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $n): bool => $n !== ''));
            if ($names === []) {
                fwrite(STDERR, "--category takes a category name of the verdict line (comma list or repeated)\n");
                exit(2);
            }
            array_push($options['categories'], ...$names);
            continue;
        }
        if ($takesBaseline && $arg === '--baseline') {
            $options['baseline'] = true;
            continue;
        }
        if ($takesBaseline && $arg === '--force') {
            $options['force'] = true;
            continue;
        }
        $kept[] = $arg;
    }
    $argv = $kept;
    spryker_upgrade_category_filter($options['categories']);

    return $options;
}

/**
 * The categories `--category` selects for this process; an empty list prints every category.
 *
 * @param list<string>|null $set the new filter, or null to read it
 *
 * @return list<string>
 */
function spryker_upgrade_category_filter(?array $set = null): array
{
    static $categories = [];
    if ($set !== null) {
        $categories = array_values(array_unique(array_map(static fn (string $c): string => strtolower(trim($c, '[] ')), $set)));
    }

    return $categories;
}

/**
 * One field of a finding line: whitespace collapsed, cut to about $max characters.
 */
function spryker_upgrade_short(string $text, int $max = SPRYKER_UPGRADE_FIELD_LENGTH): string
{
    $text = trim((string)preg_replace('/\s+/', ' ', $text));

    return strlen($text) > $max ? rtrim(substr($text, 0, $max - 3)) . '...' : $text;
}

/**
 * Stop a `--baseline` run that would replace the Phase 0 result after the update: the result file
 * exists and composer.lock differs from composer.lock.before in the state directory. `--force`
 * records it again.
 */
function spryker_upgrade_guard_baseline(string $root, string $stateDir, string $name, bool $force): void
{
    $file = $stateDir . '/' . $name . '-baseline.json';
    $before = $stateDir . '/composer.lock.before';
    $lock = $root . '/composer.lock';
    if ($force || !is_file($file) || !is_file($before) || !is_file($lock) || md5_file($before) === md5_file($lock)) {
        return;
    }
    fwrite(STDERR, sprintf(
        "%s-baseline.json exists and composer.lock differs from composer.lock.before: the Phase 0 result stays. Run without --baseline to compare with it; add --force to record it again.\n",
        $name
    ));
    exit(2);
}

/**
 * Keep or compare the Phase 0 result of a detector without a snapshot of its own.
 *
 * With $record the report payload is written to `<name>-baseline.json` and null is returned. Without
 * it, each list in $lists gets `inBaseline` on every entry whose key (from $key) is in the baseline
 * copy; null when there is no baseline copy.
 *
 * @param array<string, mixed> $payload the report payload
 * @param list<string> $lists the payload keys that hold finding lists
 * @param callable(string, array<string, mixed>): string $key list name and entry → finding key
 *
 * @return array<string, mixed>|null the payload with `inBaseline` marks, or null
 */
function spryker_upgrade_compare_with_baseline(string $stateDir, string $name, array $payload, array $lists, callable $key, bool $record): ?array
{
    $file = $stateDir . '/' . $name . '-baseline.json';
    if ($record) {
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return null;
    }
    $baseline = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($baseline)) {
        return null;
    }
    foreach ($lists as $list) {
        $known = [];
        foreach ((array)($baseline[$list] ?? []) as $entry) {
            $known[$key($list, (array)$entry)] = true;
        }
        foreach ((array)($payload[$list] ?? []) as $index => $entry) {
            $payload[$list][$index]['inBaseline'] = isset($known[$key($list, (array)$entry)]);
        }
    }
    $payload['baseline'] = basename($file);

    return $payload;
}

/**
 * The line that says a detector has findings but no Phase 0 result to compare them with, or that the
 * Phase 0 result is not valid JSON; null when this run records the Phase 0 result, when the result
 * exists, or when there is nothing to compare.
 */
function spryker_upgrade_baseline_note(string $stateDir, string $name, bool $record, bool $hasFindings): ?string
{
    $file = $stateDir . '/' . $name . '-baseline.json';
    if ($record) {
        return null;
    }
    if (is_file($file) && !is_array(json_decode((string)file_get_contents($file), true))) {
        return sprintf('NOTE: %s-baseline.json is not valid JSON: the list does not tell new findings from the ones the project already had. Restore the file, or record it again with --baseline --force before the update.', $name);
    }
    if (!$hasFindings || is_file($file)) {
        return null;
    }

    return sprintf('NOTE: no Phase 0 result (%s-baseline.json): the list does not tell new findings from the ones the project already had. Phase 0 runs this detector with --baseline.', $name);
}

/**
 * At most $limit findings for the summary: the first finding of each category, then the others in
 * their order, so a category with few findings is not hidden behind one with many.
 *
 * @param list<array{category: string, text: string, inBaseline: ?bool}> $findings
 *
 * @return list<array{category: string, text: string, inBaseline: ?bool}>
 */
function spryker_upgrade_pick_findings(array $findings, int $limit): array
{
    $picked = [];
    $seen = [];
    foreach ($findings as $index => $finding) {
        if (count($picked) < $limit && !isset($seen[$finding['category']])) {
            $seen[$finding['category']] = true;
            $picked[$index] = $finding;
        }
    }
    foreach ($findings as $index => $finding) {
        if (count($picked) >= $limit) {
            break;
        }
        $picked[$index] ??= $finding;
    }
    ksort($picked);

    return array_values($picked);
}

/**
 * One finding for the summary: its category, its one-line text, and whether Phase 0 had it (null
 * when there is no Phase 0 result to compare with).
 *
 * @param array<string, mixed> $entry
 *
 * @return array{category: string, text: string, inBaseline: ?bool}
 */
function spryker_upgrade_finding(string $category, string $text, array $entry = []): array
{
    return [
        'category' => $category,
        'text' => trim((string)preg_replace('/\s+/', ' ', $text)),
        'inBaseline' => array_key_exists('inBaseline', $entry) ? (bool)$entry['inBaseline'] : null,
    ];
}

/**
 * Print the verdict line, at most $limit findings (only the new ones when Phase 0 is compared; at
 * least one of each category while the limit allows), the notes and the report path. With
 * `--category` only the findings of those categories are printed, the Phase 0 ones marked too.
 *
 * @param bool $found the detector's exit-1 condition: FOUND, else OK
 * @param array<string, int> $counts category label → count, printed as is
 * @param list<array{category: string, text: string, inBaseline: ?bool}> $findings
 * @param list<string> $notes extra lines the agent must act on
 */
function spryker_upgrade_print_summary(string $detector, bool $found, array $counts, array $findings, ?string $reportFile, string $root, int $limit, array $notes = []): void
{
    $parts = [];
    foreach ($counts as $label => $count) {
        $parts[] = "$label $count";
    }
    $compared = array_filter($findings, static fn (array $f): bool => $f['inBaseline'] !== null);
    $old = count(array_filter($compared, static fn (array $f): bool => $f['inBaseline'] === true));
    $line = rtrim(sprintf('%s: %s %s', $detector, $found ? 'FOUND' : 'OK', implode(', ', $parts)));
    $categories = spryker_upgrade_category_filter();
    if ($compared !== []) {
        $line .= sprintf(' (%d new, %d already in Phase 0: in the report, still to process where the lane says so)', count($findings) - $old, $old);
        if ($categories === []) {
            $findings = array_values(array_filter($findings, static fn (array $f): bool => $f['inBaseline'] !== true));
        }
    }
    echo $line . "\n";

    if ($categories !== []) {
        $findings = array_values(array_filter(
            $findings,
            static fn (array $f): bool => in_array(strtolower(trim($f['category'], '[] ')), $categories, true)
                || in_array(strtolower(strtok(trim($f['category'], '[] '), ' ') ?: ''), $categories, true)
        ));
        if ($findings === []) {
            printf("  (no finding in --category %s)\n", implode(',', $categories));
        }
    }
    foreach (spryker_upgrade_pick_findings(array_values($findings), $limit) as $finding) {
        $mark = match ($finding['inBaseline']) {
            false => '[new] ',
            true => '[Phase 0] ',
            null => '',
        };
        echo '  ' . $mark . $finding['category'] . ' ' . $finding['text'] . "\n";
    }
    if ($limit > 0 && count($findings) > $limit) {
        printf(
            "  (%d more: rerun with --category %s --limit %d)\n",
            count($findings) - $limit,
            $categories === [] ? '<category>' : implode(',', $categories),
            count($findings)
        );
    }
    foreach ($notes as $note) {
        echo $note . "\n";
    }
    if ($reportFile !== null) {
        echo 'Full report: ' . str_replace($root . '/', '', $reportFile) . "\n";
    }
}
