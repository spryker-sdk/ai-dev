<?php

declare(strict_types=1);

/**
 * csv.php — RFC-4180 CSV primitives for the project-starter plugin.
 *
 * Zero dependencies. Two ways to use it:
 *   1. require it and call the csv_* functions (sibling PHP scripts).
 *   2. run it as a CLI for universal read/filter/delete/columns operations.
 *
 * Why this exists: Spryker import CSVs contain multi-line quoted fields
 * (e.g. cms_page.csv), so shell tools (cut/awk/sed) MUST NOT touch them.
 * PHP's fgetcsv with escape disabled ('') is RFC-4180 compliant and handles
 * multi-line quoted fields correctly. All access is by HEADER NAME, never by
 * column index (the index approach caused a real mis-read in the playbook run).
 */

/**
 * Read an RFC-4180 CSV file.
 *
 * @return array{header: list<string>, rows: list<array<string,string>>}
 *   header preserves source column order; each row is an ordered map
 *   keyed by header name (missing trailing cells become '').
 */
function csv_read(string $path): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("csv_read: cannot open '{$path}'");
    }

    $header = null;
    $rows = [];
    // escape='' disables PHP's proprietary backslash escaping → pure RFC-4180.
    while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        // A truly blank physical line yields [null]; skip it only when no header
        // has been read yet or the whole record is a single null (blank line).
        if ($record === [null]) {
            continue;
        }

        if ($header === null) {
            // Strip a UTF-8 BOM from the very first cell if present.
            if (isset($record[0])) {
                $record[0] = preg_replace('/^\xEF\xBB\xBF/', '', $record[0]);
            }
            $header = array_map('strval', $record);
            continue;
        }

        $row = [];
        foreach ($header as $i => $name) {
            $row[$name] = array_key_exists($i, $record) ? (string) $record[$i] : '';
        }
        $rows[] = $row;
    }
    fclose($handle);

    if ($header === null) {
        throw new RuntimeException("csv_read: '{$path}' is empty (no header row)");
    }

    return ['header' => $header, 'rows' => $rows];
}

/**
 * Write an RFC-4180 CSV file. Deterministic output: a field is quoted iff it
 * contains a comma, double-quote, CR or LF; embedded quotes are doubled.
 * Line terminator is "\n". Rows are read by header name; unknown keys ignored,
 * missing keys written as ''.
 *
 * @param list<string> $header
 * @param list<array<string,string>> $rows
 */
function csv_write(string $path, array $header, array $rows): void
{
    $out = csv_encode_row($header);
    foreach ($rows as $row) {
        $ordered = [];
        foreach ($header as $name) {
            $ordered[] = array_key_exists($name, $row) ? (string) $row[$name] : '';
        }
        $out .= csv_encode_row($ordered);
    }

    // Atomic write: a crash mid-write must never leave a truncated, header-corrupt
    // CSV behind (freshly-authored --out files have no git undo). Write to a temp
    // file in the same directory, then rename over the target.
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $out) === false) {
        throw new RuntimeException("csv_write: cannot write '{$tmp}'");
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("csv_write: cannot move '{$tmp}' onto '{$path}'");
    }
}

/**
 * Encode one record to an RFC-4180 line (with trailing "\n").
 *
 * @param list<string> $fields
 */
function csv_encode_row(array $fields): string
{
    $encoded = [];
    foreach ($fields as $field) {
        $field = (string) $field;
        if (preg_match('/[",\r\n]/', $field) === 1) {
            $field = '"' . str_replace('"', '""', $field) . '"';
        }
        $encoded[] = $field;
    }

    return implode(',', $encoded) . "\n";
}

/**
 * Keep rows where every (column => value) in $where matches exactly.
 * Unknown columns in $where match nothing (returns []), which is intentional:
 * a typo'd column should not silently pass everything.
 *
 * @param list<array<string,string>> $rows
 * @param array<string,string> $where
 * @return list<array<string,string>>
 */
function csv_filter(array $rows, array $where, string $mode = 'exact'): array
{
    return array_values(array_filter($rows, static fn (array $row): bool => csv_row_matches($row, $where, $mode)));
}

/**
 * Row matches when every (column => value) in $where matches per $mode:
 * 'exact' (default), 'prefix' (cell starts with value), 'contains' (substring).
 * The caller (AI/skill) picks the mode — the function knows nothing of what the
 * columns mean (e.g. prefix `cms-block-email--` to select the email blocks).
 *
 * @param array<string,string> $row
 * @param array<string,string> $where
 */
function csv_row_matches(array $row, array $where, string $mode = 'exact'): bool
{
    foreach ($where as $col => $val) {
        if (!array_key_exists($col, $row)) {
            return false;
        }
        $cell = $row[$col];
        $ok = match ($mode) {
            'prefix' => str_starts_with($cell, $val),
            'contains' => str_contains($cell, $val),
            default => $cell === $val,
        };
        if (!$ok) {
            return false;
        }
    }

    return true;
}

/**
 * GENERAL MECHANIC — duplicate column families by suffix.
 * For every column ending in ".$from", add a sibling ".$to" copying its value,
 * inserted immediately after the last column sharing that base (preserving
 * Spryker's grouped-by-base layout). Concept-free: the CALLER decides $from/$to
 * (locale copy, variant copy, …) and which bases to exclude via $skipBases.
 * Idempotent: existing target columns are left untouched.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param list<string> $skipBases  bases to leave alone (e.g. 'url' — needs transform, not copy)
 * @return array{header: list<string>, rows: list<array<string,string>>, added: list<string>, skipped: list<string>}
 */
function csv_duplicate_columns(array $data, string $from, string $to, array $skipBases = []): array
{
    $suffix = '.' . $from;
    $sourceCols = [];
    foreach ($data['header'] as $col) {
        if (str_ends_with($col, $suffix)) {
            $sourceCols[substr($col, 0, -strlen($suffix))] = $col;
        }
    }

    $header = $data['header'];
    $added = [];
    $skipped = [];
    foreach ($sourceCols as $base => $sourceCol) {
        if (in_array($base, $skipBases, true)) {
            $skipped[] = $base;
            continue;
        }
        $newCol = $base . '.' . $to;
        if (in_array($newCol, $header, true)) {
            continue;
        }
        $insertAt = null;
        $prefix = $base . '.';
        foreach ($header as $i => $col) {
            if (str_starts_with($col, $prefix)) {
                $insertAt = $i;
            }
        }
        array_splice($header, $insertAt + 1, 0, [$newCol]);
        $added[] = $newCol;
    }

    $rows = [];
    foreach ($data['rows'] as $row) {
        foreach ($added as $newCol) {
            $base = substr($newCol, 0, -strlen('.' . $to));
            $row[$newCol] = $row[$sourceCols[$base]] ?? '';
        }
        $rows[] = $row;
    }

    return ['header' => $header, 'rows' => $rows, 'added' => $added, 'skipped' => $skipped];
}

/**
 * GENERAL MECHANIC — clone rows by a column value.
 * For each row where $column === $from, append a clone with that column set to
 * $to (unless an identical row already exists). Concept-free: caller decides the
 * column and values (glossary locale rows, store relations, currency rows, …).
 * Idempotent.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @return array{header: list<string>, rows: list<array<string,string>>, added: int}
 */
function csv_duplicate_rows(array $data, string $column, string $from, string $to): array
{
    if (!in_array($column, $data['header'], true)) {
        throw new RuntimeException("csv_duplicate_rows: no '{$column}' column");
    }

    $seen = [];
    foreach ($data['rows'] as $row) {
        $seen[csv_row_key($row)] = true;
    }

    $rows = $data['rows'];
    $added = 0;
    foreach ($data['rows'] as $row) {
        if (($row[$column] ?? '') !== $from) {
            continue;
        }
        $clone = $row;
        $clone[$column] = $to;
        $k = csv_row_key($clone);
        if (isset($seen[$k])) {
            continue;
        }
        $rows[] = $clone;
        $seen[$k] = true;
        $added++;
    }

    return ['header' => $data['header'], 'rows' => $rows, 'added' => $added];
}

/**
 * GENERAL MECHANIC — set a column's value on matching rows.
 * On every row matching $where, set $column to $value. Empty $where = all rows.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param array<string,string> $where
 * @return array{header: list<string>, rows: list<array<string,string>>, changed: int}
 */
function csv_set(array $data, string $column, string $value, array $where = []): array
{
    if (!in_array($column, $data['header'], true)) {
        $data['header'][] = $column;
    }
    $changed = 0;
    foreach ($data['rows'] as &$row) {
        if ($where === [] || csv_row_matches($row, $where)) {
            $row[$column] = $value;
            $changed++;
        }
    }
    unset($row);

    return ['header' => $data['header'], 'rows' => $data['rows'], 'changed' => $changed];
}

/**
 * GENERAL MECHANIC — string/regex replace within a column's values.
 * On rows matching $where (empty = all), replace $search with $replace in the
 * column. $regex treats $search as a PCRE pattern (incl. delimiters). Concept-
 * free: the caller supplies the pattern (e.g. rewrite a URL language prefix).
 * The column must exist (replacing a missing column is an error, not a no-op).
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param array<string,string> $where
 * @return array{header: list<string>, rows: list<array<string,string>>, changed: int}
 */
function csv_replace(array $data, string $column, string $search, string $replace, bool $regex = false, array $where = [], string $mode = 'exact'): array
{
    if (!in_array($column, $data['header'], true)) {
        throw new RuntimeException("csv_replace: no '{$column}' column");
    }
    $changed = 0;
    foreach ($data['rows'] as &$row) {
        if ($where !== [] && !csv_row_matches($row, $where, $mode)) {
            continue;
        }
        $before = $row[$column] ?? '';
        $after = $regex ? (string) preg_replace($search, $replace, $before) : str_replace($search, $replace, $before);
        if ($after !== $before) {
            $row[$column] = $after;
            $changed++;
        }
    }
    unset($row);

    return ['header' => $data['header'], 'rows' => $data['rows'], 'changed' => $changed];
}

/**
 * GENERAL MECHANIC — multiply numeric values in a column by a factor (e.g.
 * currency rate conversion). Two modes:
 *   - flat (default): the whole cell is one number; non-numeric cells are
 *     skipped.
 *   - JSON ($jsonKeys non-empty): the cell holds JSON (object or array of
 *     objects, nested); every numeric value found under a key in $jsonKeys is
 *     scaled, and the cell is re-serialized. Covers embedded price tiers like
 *     `price_data.volume_prices` = [{"quantity":10,"net_price":900,...}].
 * $round rounds to an integer (Spryker prices are integer minor units).
 * Concept-free: the caller supplies the column, factor, and (for JSON) the keys.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param array<string,string> $where
 * @param list<string> $jsonKeys
 * @return array{header: list<string>, rows: list<array<string,string>>, changed: int, skipped: int}
 */
function csv_scale(array $data, string $column, float $factor, bool $round = true, array $where = [], string $mode = 'exact', array $jsonKeys = []): array
{
    if (!in_array($column, $data['header'], true)) {
        throw new RuntimeException("csv_scale: no '{$column}' column");
    }
    $changed = 0;
    $skipped = 0;
    foreach ($data['rows'] as &$row) {
        if ($where !== [] && !csv_row_matches($row, $where, $mode)) {
            continue;
        }
        $cell = $row[$column] ?? '';
        if ($cell === '') {
            $skipped++;
            continue;
        }

        if ($jsonKeys !== []) {
            // Scale numbers by string pattern, NOT by json_decode: the demoshop's
            // embedded price JSON is often invalid (empty values like
            // `"gross_price":`) and varies by file. A `"key":<number>` regex
            // scales the ones that have a value and leaves empty ones untouched.
            $after = csv_scale_json_numbers($cell, $jsonKeys, $factor, $round);
            if ($after !== $cell) {
                $row[$column] = $after;
                $changed++;
            } else {
                $skipped++;
            }
            continue;
        }

        if (!is_numeric($cell)) {
            $skipped++;
            continue;
        }
        $value = (float) $cell * $factor;
        $row[$column] = $round ? (string) (int) round($value) : (string) $value;
        $changed++;
    }
    unset($row);

    return ['header' => $data['header'], 'rows' => $data['rows'], 'changed' => $changed, 'skipped' => $skipped];
}

/**
 * Scale numbers in a JSON-ish string by pattern: for each key, find
 * `"key": <number>` and multiply the number. Values with no number
 * (e.g. `"gross_price":` — invalid JSON the demoshop ships) don't match and are
 * left untouched. Robust to malformed/partial JSON; does not parse the cell.
 *
 * @param list<string> $keys
 */
function csv_scale_json_numbers(string $cell, array $keys, float $factor, bool $round): string
{
    foreach ($keys as $key) {
        $pattern = '/("' . preg_quote($key, '/') . '"\s*:\s*)(-?\d+(?:\.\d+)?)/';
        $cell = (string) preg_replace_callback($pattern, static function (array $m) use ($factor, $round): string {
            $scaled = (float) $m[2] * $factor;

            return $m[1] . ($round ? (string) (int) round($scaled) : (string) $scaled);
        }, $cell);
    }

    return $cell;
}

/**
 * GENERAL MECHANIC — project a subset of columns (in the given order).
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param list<string> $columns
 * @return array{header: list<string>, rows: list<array<string,string>>}
 */
/**
 * Cross-column arithmetic scale can't do: write a target column as source ×
 * factor (e.g. value_gross = value_net × 1.19). Creates the target column if
 * absent. A row whose source is empty or non-numeric is skipped. `--only-empty`
 * fills only rows whose target is currently blank (gap-fill, no overwrite).
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param array<string,string> $where
 * @return array{header: list<string>, rows: list<array<string,string>>, changed: int, skipped: int}
 */
function csv_derive(array $data, string $target, string $source, float $factor, bool $round = true, array $where = [], string $mode = 'exact', bool $onlyEmpty = false): array
{
    if (!in_array($source, $data['header'], true)) {
        throw new RuntimeException("csv_derive: no source column '{$source}'");
    }
    $header = in_array($target, $data['header'], true) ? $data['header'] : array_merge($data['header'], [$target]);
    $changed = 0;
    $skipped = 0;
    foreach ($data['rows'] as &$row) {
        if (!array_key_exists($target, $row)) {
            $row[$target] = '';
        }
        if ($where !== [] && !csv_row_matches($row, $where, $mode)) {
            continue;
        }
        if ($onlyEmpty && ($row[$target] ?? '') !== '') {
            $skipped++;
            continue;
        }
        $cell = $row[$source] ?? '';
        if ($cell === '' || !is_numeric($cell)) {
            $skipped++;
            continue;
        }
        $value = (float) $cell * $factor;
        $row[$target] = $round ? (string) (int) round($value) : (string) $value;
        $changed++;
    }
    unset($row);

    return ['header' => $header, 'rows' => $data['rows'], 'changed' => $changed, 'skipped' => $skipped];
}

function csv_select(array $data, array $columns): array
{
    $rows = [];
    foreach ($data['rows'] as $row) {
        $out = [];
        foreach ($columns as $c) {
            $out[$c] = $row[$c] ?? '';
        }
        $rows[] = $out;
    }

    return ['header' => $columns, 'rows' => $rows];
}

/**
 * GENERAL MECHANIC — remove columns from the header and every row (the inverse
 * of csv_select: name what to REMOVE, not what to keep). This is the ergonomic
 * path for wide files — you'd otherwise enumerate everything to keep. $columns
 * drops exact names; $suffixes drops every column whose name ends with the given
 * string (e.g. '.de_DE' strips a whole leftover locale family). Idempotent:
 * dropping an already-absent column is a no-op (reported in skippedColumns).
 * Concept-free: the caller decides which names/suffixes to strip.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param list<string> $columns
 * @param list<string> $suffixes
 * @return array{header: list<string>, rows: list<array<string,string>>, dropped: list<string>, skippedColumns: list<string>}
 */
function csv_drop_columns(array $data, array $columns, array $suffixes = []): array
{
    $skippedColumns = [];
    foreach ($columns as $column) {
        if (!in_array($column, $data['header'], true)) {
            $skippedColumns[] = $column;
        }
    }

    $dropped = [];
    $header = [];
    foreach ($data['header'] as $col) {
        if (csv_column_matches_drop($col, $columns, $suffixes)) {
            $dropped[] = $col;
            continue;
        }
        $header[] = $col;
    }

    $rows = [];
    foreach ($data['rows'] as $row) {
        foreach ($dropped as $col) {
            unset($row[$col]);
        }
        $rows[] = $row;
    }

    return ['header' => $header, 'rows' => $rows, 'dropped' => $dropped, 'skippedColumns' => $skippedColumns];
}

/**
 * A column is dropped when it matches an exact name in $columns or ends with any
 * (non-empty) suffix in $suffixes.
 *
 * @param list<string> $columns
 * @param list<string> $suffixes
 */
function csv_column_matches_drop(string $column, array $columns, array $suffixes): bool
{
    if (in_array($column, $columns, true)) {
        return true;
    }
    foreach ($suffixes as $suffix) {
        if ($suffix !== '' && str_ends_with($column, $suffix)) {
            return true;
        }
    }

    return false;
}

/**
 * Rename header columns (and the matching key in every row) by old:new pairs.
 * A pair whose `old` isn't present is reported in skippedColumns, not fatal (a
 * batch survives files that lack it). A pair whose `new` already exists as a
 * different column is skipped too — renaming onto an existing column would
 * silently merge data. Idempotent: re-running finds nothing to rename.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param list<array{0: string, 1: string}> $pairs
 * @return array{header: list<string>, rows: list<array<string,string>>, renamed: list<array{0: string, 1: string}>, skippedColumns: list<string>}
 */
function csv_rename_columns(array $data, array $pairs): array
{
    $map = [];
    $renamed = [];
    $skippedColumns = [];
    foreach ($pairs as [$old, $new]) {
        if (!in_array($old, $data['header'], true) || ($new !== $old && in_array($new, $data['header'], true))) {
            $skippedColumns[] = $old;
            continue;
        }
        $map[$old] = $new;
        $renamed[] = [$old, $new];
    }

    $header = [];
    foreach ($data['header'] as $col) {
        $header[] = $map[$col] ?? $col;
    }

    $rows = [];
    foreach ($data['rows'] as $row) {
        $newRow = [];
        foreach ($row as $col => $value) {
            $newRow[$map[$col] ?? $col] = $value;
        }
        $rows[] = $newRow;
    }

    return ['header' => $header, 'rows' => $rows, 'renamed' => $renamed, 'skippedColumns' => $skippedColumns];
}

/**
 * GENERAL MECHANIC — distinct values of a column with their row counts.
 * Replaces shelling out to `awk|sort|uniq -c` for inspection (which mis-reads
 * multi-line quoted fields). Sorted by count desc, then value asc. Empty cells
 * count under the '' key. Missing column throws (a typo must not read as empty).
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @return list<array{value: string, count: int}>
 */
function csv_distinct(array $data, string $column): array
{
    if (!in_array($column, $data['header'], true)) {
        throw new RuntimeException("csv_distinct: no '{$column}' column");
    }
    $counts = [];
    foreach ($data['rows'] as $row) {
        $value = $row[$column] ?? '';
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }
    uksort($counts, static function (string $a, string $b) use ($counts): int {
        return ($counts[$b] <=> $counts[$a]) ?: ($a <=> $b);
    });

    $out = [];
    foreach ($counts as $value => $count) {
        $out[] = ['value' => (string) $value, 'count' => $count];
    }

    return $out;
}

/**
 * GENERAL MECHANIC — apply a source→target value map to one column (translation).
 * For each row, look up the value in $sourceCol; if it's a key in $map, write the
 * mapped value into $targetCol. **Only $targetCol is ever modified** — every other
 * column is byte-identical, so this is safe by construction and replaces risky
 * whole-file rewrites for localization. Exact match; multi-line quoted fields are
 * handled by csv_read/csv_write. Rows whose source value isn't in the map are left
 * untouched. $sourceCol may equal $targetCol (translate matching current values).
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param array<string,string> $map
 * @return array{header: list<string>, rows: list<array<string,string>>, changed: int}
 */
function csv_translate(array $data, string $sourceCol, string $targetCol, array $map): array
{
    foreach ([$sourceCol, $targetCol] as $col) {
        if (!in_array($col, $data['header'], true)) {
            throw new RuntimeException("csv_translate: no '{$col}' column");
        }
    }
    $changed = 0;
    foreach ($data['rows'] as &$row) {
        $src = $row[$sourceCol] ?? '';
        if ($src === '' || !array_key_exists($src, $map)) {
            continue;
        }
        if (($row[$targetCol] ?? '') !== $map[$src]) {
            $row[$targetCol] = $map[$src];
            $changed++;
        }
    }
    unset($row);

    return ['header' => $data['header'], 'rows' => $data['rows'], 'changed' => $changed];
}

/**
 * Read a translation map CSV (must have `source` and `target` columns) into a
 * source→target array. Empty-source rows are ignored.
 *
 * @return array<string,string>
 */
function csv_translation_map(string $file): array
{
    $data = csv_read($file);
    foreach (['source', 'target'] as $col) {
        if (!in_array($col, $data['header'], true)) {
            throw new RuntimeException("apply-translations: map file must have 'source' and 'target' columns");
        }
    }
    $map = [];
    foreach ($data['rows'] as $row) {
        if (($row['source'] ?? '') !== '') {
            $map[$row['source']] = $row['target'] ?? '';
        }
    }

    return $map;
}

/**
 * Parse set-membership options for filter/delete: `--in col=v1,v2,…` (inline)
 * and `--in-file col=path` (one value per line, for large sets). Returns
 * col => {value: true}. A spec without `=` is an error (never silently ignored).
 *
 * @param array<string,mixed> $opts
 * @return array<string,array<string,bool>>
 */
function csv_parse_in(array $opts): array
{
    $sets = [];
    foreach (['in' => false, 'in-file' => true] as $key => $fromFile) {
        if (!isset($opts[$key])) {
            continue;
        }
        $spec = (string) $opts[$key];
        $eq = strpos($spec, '=');
        if ($eq === false) {
            throw new RuntimeException("--{$key} '{$spec}' is malformed (expected col=values)");
        }
        $col = substr($spec, 0, $eq);
        $rhs = substr($spec, $eq + 1);
        $values = $fromFile ? csv_read_value_file($rhs) : csv_split_list($rhs);
        foreach ($values as $v) {
            $sets[$col][$v] = true;
        }
    }

    return $sets;
}

/**
 * Read a value-set file: one value per line, trimmed, blanks skipped.
 *
 * @return list<string>
 */
function csv_read_value_file(string $path): array
{
    $raw = @file($path, FILE_IGNORE_NEW_LINES);
    if ($raw === false) {
        throw new RuntimeException("--in-file: cannot read '{$path}'");
    }

    return array_values(array_filter(array_map('trim', $raw), static fn (string $v): bool => $v !== ''));
}

/**
 * Row satisfies every set-membership condition (its cell value is in the set).
 * A missing column is a non-match (consistent with csv_row_matches).
 *
 * @param array<string,string> $row
 * @param array<string,array<string,bool>> $sets
 */
function csv_row_in(array $row, array $sets): bool
{
    foreach ($sets as $col => $set) {
        if (!array_key_exists($col, $row) || !isset($set[$row[$col]])) {
            return false;
        }
    }

    return true;
}

/**
 * Split a comma list, trimming blanks — for list-valued options (e.g. `--to
 * pl_PL,uk_UA` fans out over several locales/stores in one call, no shell loop).
 *
 * @return list<string>
 */
function csv_split_list(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
}

/**
 * Parse a `key=val,key=val` map — e.g. `--rates PLN=4.3,UAH=45` (per-currency
 * conversion factors) so `scale` handles every currency in one call.
 *
 * @return array<string,string>
 */
function csv_parse_map(string $value): array
{
    $map = [];
    foreach (csv_split_list($value) as $pair) {
        $eq = strpos($pair, '=');
        if ($eq !== false) {
            $map[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }
    }

    return $map;
}

/**
 * Read a repeatable `--key value` option as a list. csv_parse_opts stores a key
 * given once as a scalar and a repeated key as a list, so normalize both to a
 * list<string> (missing → []).
 *
 * @param array<string,mixed> $opts
 * @return list<string>
 */
function csv_opt_list(array $opts, string $key): array
{
    if (!isset($opts[$key])) {
        return [];
    }

    return is_array($opts[$key]) ? array_values(array_map('strval', $opts[$key])) : [(string) $opts[$key]];
}

/**
 * Stable identity of a row for dedup (order-independent on keys).
 *
 * @param array<string,string> $row
 */
function csv_row_key(array $row): string
{
    ksort($row);

    return (string) json_encode($row);
}

// ---------------------------------------------------------------------------
// CLI — only runs when this file is executed directly, not when required.
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(csv_cli($argv));
}

/**
 * @param list<string> $argv
 */
function csv_cli(array $argv): int
{
    $command = $argv[1] ?? '';
    $rest = array_slice($argv, 2);

    // Leading positional args (before the first --flag) are input files. This lets
    // mutation commands take MANY files in one call (with --in-place), replacing
    // the shell for-loops that trip the operator-approval gate.
    $files = [];
    $k = 0;
    while ($k < count($rest) && !str_starts_with($rest[$k], '--')) {
        $files[] = $rest[$k];
        $k++;
    }
    $opts = csv_parse_opts(array_slice($rest, $k));
    $where = $opts['where'] ?? [];

    if ($command === '' || $files === []) {
        return csv_usage();
    }
    if (isset($opts['_errors'])) { // e.g. a malformed --where — fail loudly, never silently narrow/broaden
        return csv_report(2, [], $opts['_errors']);
    }

    // Inspection commands own their output (plain/JSON) and never write.
    if ($command === 'columns') {
        return csv_cli_columns($files, isset($opts['plain']));
    }
    if ($command === 'distinct') {
        return csv_cli_distinct($files, $opts);
    }
    if ($command === 'read') {
        if (count($files) > 1) {
            return csv_report(2, [], ['read takes ONE file (' . count($files) . ' given). Silent truncation is worse than an error — read files one at a time, or use `columns`/`count` for many-file inspection.']);
        }
        return csv_cli_read($files[0], $opts);
    }
    if ($command === 'count') {
        return csv_cli_count($files, isset($opts['plain']));
    }

    // Mutation commands. Output target: --in-place (write each file back to itself)
    // or --out <file> (single file only). Multiple files therefore require --in-place.
    $inPlace = isset($opts['in-place']);
    if (count($files) > 1 && !$inPlace) {
        return csv_report(2, [], ['multiple files require --in-place (--out targets a single file)']);
    }

    $results = [];
    $exit = 0;
    foreach ($files as $f) {
        try {
            $data = csv_read($f);
            $target = $inPlace ? $f : ($opts['out'] ?? null);
            $r = csv_apply($command, $data, $opts, $where, $target);
            $results[] = array_merge(['file' => $f], $r['summary']);
            $exit = max($exit, $r['exit']);
        } catch (Throwable $e) {
            $results[] = ['file' => $f, 'error' => $e->getMessage()];
            $exit = 2;
        }
    }

    // Single file → flat report (back-compatible). Multiple → a files[] array.
    if (count($results) === 1) {
        $only = $results[0];
        if (isset($only['error'])) {
            return csv_report(2, [], [$only['error']]);
        }
        $written = array_values((array) ($only['written'] ?? []));
        unset($only['file'], $only['written']);

        return csv_report($exit, $written, [], $only);
    }

    return csv_report($exit, [], [], ['files' => $results]);
}

function csv_usage(): int
{
    return csv_report(2, [], [
        'usage: csv.php <command> <file> [<file2>...] [options]',
        'Inspection (own output; --plain = line output; columns/distinct take many files):',
        '  read <file> [--limit N]',
        '  columns <file> [<file2>...] [--plain]',
        '  count <file> [<file2>...] [--plain]   (data-row count per file, many files in one call — no for-loop/grep)',
        '  distinct <file> [<file2>...] --column C [--plain]',
        'Mutation (write with --out <file> for ONE file, or --in-place for one-or-many):',
        '  filter <file>... (--where col=val ... [--match exact|prefix|contains] | --in col=v1,v2 | --in-file col=path) [--out f | --in-place]',
        '  delete <file>... (--where col=val ... [--match ...] | --in col=v1,v2 | --in-file col=path) [--out f | --in-place]',
        '  duplicate-columns <file>... --from en_US --to fr_CA[,de_DE,...] [--skip-base url,...] (--out f | --in-place)',
        '  duplicate-rows <file>... --column locale --from en_US --to fr_CA[,uk_UA,...] (--out f | --in-place)',
        '  set <file>... --column store --value US [--where col=val ...] (--out f | --in-place)',
        '  select <file> --columns a,b,c --out f',
        '  drop-columns <file>... (--column name [--column name2 ...] | --suffix .de_DE [--suffix ...]) (--out f | --in-place)   (removes named/suffix-matched columns; inverse of select)',
        '  rename-columns <file>... --rename old:new [--rename old2:new2 ...] (--out f | --in-place)   (renames header + row keys; missing/colliding pairs skipped, not fatal)',
        '  apply-translations <file>... --target-column name.uk_UA [--source-column name.en_US] --map map.csv (--out f | --in-place)   (map has source,target cols; only target-column changes)',
        '  replace <file>... --column url.fr_CA --search /en/ --with /fr/ [--regex] [--where col=val] (--out f | --in-place)',
        '  scale <file>... --column value_gross (--by 1.08 | --rates PLN=4.3,UAH=45 [--currency-column currency]) [--no-round] [--json-keys a,b] [--where col=val] (--out f | --in-place)',
        '  derive <file>... --target value_gross --source value_net --factor 1.19 [--no-round] [--only-empty] [--where col=val] (--out f | --in-place)   (target = source × factor; creates target column; skips empty/non-numeric source)',
    ]);
}

/**
 * distinct across one or more files. Single file → flat report (back-compat);
 * multiple → a files[] array. --plain prints `count⇥value` lines, delimited by
 * `== <file> ==` when more than one file.
 *
 * @param list<string> $files
 * @param array<string,mixed> $opts
 */
function csv_cli_distinct(array $files, array $opts): int
{
    $col = $opts['column'] ?? '';
    if ($col === '') {
        return csv_report(2, [], ['distinct: --column is required']);
    }
    $plain = isset($opts['plain']);
    $multi = count($files) > 1;
    $lines = [];
    $results = [];
    foreach ($files as $f) {
        try {
            $distinct = csv_distinct(csv_read($f), $col);
        } catch (Throwable $e) {
            if ($plain) {
                if ($multi) {
                    $lines[] = '== ' . $f . ' ==';
                }
                $lines[] = 'ERROR: ' . $e->getMessage();
                continue;
            }
            $results[] = ['file' => $f, 'error' => $e->getMessage()];
            continue;
        }
        if ($plain) {
            if ($multi) {
                $lines[] = '== ' . $f . ' ==';
            }
            foreach ($distinct as $d) {
                $lines[] = $d['count'] . "\t" . $d['value'];
            }
            continue;
        }
        $results[] = ['file' => $f, 'column' => $col, 'distinctCount' => count($distinct), 'distinct' => $distinct];
    }

    if ($plain) {
        return csv_plain($lines);
    }
    if (!$multi && count($results) === 1) {
        if (isset($results[0]['error'])) {
            return csv_report(2, [], [$results[0]['error']]);
        }

        return csv_report(0, [], [], ['column' => $col, 'distinctCount' => $results[0]['distinctCount'], 'distinct' => $results[0]['distinct']]);
    }
    $exit = array_filter($results, static fn (array $r): bool => isset($r['error'])) === [] ? 0 : 2;

    return csv_report($exit, [], [], ['files' => $results]);
}

/**
 * @param array<string,mixed> $opts
 */
function csv_cli_read(string $file, array $opts): int
{
    try {
        $data = csv_read($file);
    } catch (Throwable $e) {
        return csv_report(2, [], [$e->getMessage()]);
    }
    $rows = $data['rows'];
    if (isset($opts['limit'])) {
        $rows = array_slice($rows, 0, (int) $opts['limit']);
    }

    return csv_report(0, [], [], ['header' => $data['header'], 'rowCount' => count($data['rows']), 'rows' => $rows]);
}

/**
 * Apply one mutation command to one file's data, writing to $target (in-place =
 * the file itself, or --out). Returns {exit, summary}; the caller renders one
 * report (single file) or aggregates (batch). Any csv_* throw propagates to the
 * caller and becomes a clean JSON error, never an uncaught stack trace.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param array<string,mixed> $opts
 * @param array<string,string> $where
 * @return array{exit: int, summary: array<string,mixed>}
 */
function csv_apply(string $command, array $data, array $opts, array $where, ?string $target): array
{
    switch ($command) {
        case 'filter':
        case 'delete':
            $inSets = csv_parse_in($opts);
            if ($where === [] && $inSets === []) {
                return ['exit' => 2, 'summary' => ['error' => "{$command}: at least one --where col=val or --in col=v1,v2 (or --in-file col=path) is required"]];
            }
            $mode = $opts['match'] ?? 'exact';
            // A row "matches" when it satisfies BOTH the --where conditions and the
            // set-membership (--in) conditions (AND). Set-membership expresses
            // "col ∈ {…}" — e.g. keep only the 44 kept SKUs across every catalog file.
            $pred = static fn (array $r): bool => csv_row_matches($r, $where, $mode) && csv_row_in($r, $inSets);
            $matched = array_values(array_filter($data['rows'], $pred));
            $kept = $command === 'filter'
                ? $matched
                : array_values(array_filter($data['rows'], static fn (array $r): bool => !$pred($r)));
            $written = [];
            if ($target !== null) {
                csv_write($target, $data['header'], $kept);
                $written[] = $target;
            }

            // Preview (no --out/--in-place) is the destructive-op gate's evidence:
            // the COUNTS are the verdict, so cap the echoed rows (a full glossary
            // preview once printed 626 KB). --limit raises the cap when needed.
            $previewLimit = max(0, (int) ($opts['limit'] ?? 20));
            $previewRows = $target === null ? array_slice($kept, 0, $previewLimit) : null;

            return ['exit' => 0, 'summary' => [
                'written' => $written,
                'header' => $data['header'],
                'inputRows' => count($data['rows']),
                'matchedRows' => count($matched),
                'resultRows' => count($kept),
                'rowsShown' => $previewRows === null ? null : count($previewRows),
                'rows' => $previewRows,
            ]];

        case 'duplicate-columns':
            $from = $opts['from'] ?? '';
            $to = $opts['to'] ?? '';
            if ($from === '' || $to === '' || $target === null) {
                return ['exit' => 2, 'summary' => ['error' => 'duplicate-columns: --from, --to and (--out|--in-place) are required']];
            }
            $skip = isset($opts['skip-base']) ? explode(',', $opts['skip-base']) : [];
            $added = [];
            $skipped = [];
            foreach (csv_split_list($to) as $t) { // list-valued --to fans out over locales/stores
                $r = csv_duplicate_columns($data, $from, $t, $skip);
                $data = ['header' => $r['header'], 'rows' => $r['rows']];
                $added = array_merge($added, $r['added']);
                $skipped = $r['skipped'];
            }
            csv_write($target, $data['header'], $data['rows']);
            $warnings = $skipped === [] ? [] : ['skipped bases (not copied): ' . implode(', ', $skipped)];

            return ['exit' => $warnings === [] ? 0 : 1, 'summary' => ['written' => [$target], 'warnings' => $warnings, 'addedColumns' => $added]];

        case 'duplicate-rows':
            $col = $opts['column'] ?? '';
            $from = $opts['from'] ?? '';
            $to = $opts['to'] ?? '';
            if ($col === '' || $from === '' || $to === '' || $target === null) {
                return ['exit' => 2, 'summary' => ['error' => 'duplicate-rows: --column, --from, --to and (--out|--in-place) are required']];
            }
            $addedRows = 0;
            foreach (csv_split_list($to) as $t) { // list-valued --to fans out (e.g. one store row-set per project store)
                $r = csv_duplicate_rows($data, $col, $from, $t);
                $data = ['header' => $r['header'], 'rows' => $r['rows']];
                $addedRows += $r['added'];
            }
            csv_write($target, $data['header'], $data['rows']);

            return ['exit' => 0, 'summary' => ['written' => [$target], 'addedRows' => $addedRows]];

        case 'set':
            $col = $opts['column'] ?? '';
            if ($col === '' || !isset($opts['value']) || $target === null) {
                return ['exit' => 2, 'summary' => ['error' => 'set: --column, --value and (--out|--in-place) are required']];
            }
            $r = csv_set($data, $col, (string) $opts['value'], $where);
            csv_write($target, $r['header'], $r['rows']);

            return ['exit' => 0, 'summary' => ['written' => [$target], 'changedRows' => $r['changed']]];

        case 'select':
            if (!isset($opts['columns']) || $target === null) {
                return ['exit' => 2, 'summary' => ['error' => 'select: --columns and --out are required']];
            }
            $r = csv_select($data, explode(',', $opts['columns']));
            csv_write($target, $r['header'], $r['rows']);

            return ['exit' => 0, 'summary' => ['written' => [$target], 'columns' => $r['header']]];

        case 'drop-columns':
            $columns = csv_opt_list($opts, 'column');
            $suffixes = csv_opt_list($opts, 'suffix');
            if (($columns === [] && $suffixes === []) || $target === null) {
                return ['exit' => 2, 'summary' => ['error' => 'drop-columns: at least one --column <name> or --suffix <str>, and (--out|--in-place) are required']];
            }
            $r = csv_drop_columns($data, $columns, $suffixes);
            csv_write($target, $r['header'], $r['rows']);

            // A named column absent from THIS file is reported, not fatal (a batch
            // survives files that lack it) — dropping is idempotent, so status stays ok.
            return ['exit' => 0, 'summary' => ['written' => [$target], 'droppedColumns' => $r['dropped'], 'skippedColumns' => $r['skippedColumns']]];

        case 'rename-columns':
            $specs = csv_opt_list($opts, 'rename');
            if ($specs === [] || $target === null) {
                return ['exit' => 2, 'summary' => ['error' => 'rename-columns: at least one --rename old:new, and (--out|--in-place) are required']];
            }
            $pairs = [];
            foreach ($specs as $spec) {
                $pos = strpos($spec, ':');
                if ($pos === false || $pos === 0 || $pos === strlen($spec) - 1) {
                    return ['exit' => 2, 'summary' => ['error' => "rename-columns: malformed --rename '{$spec}' (expected old:new)"]];
                }
                $pairs[] = [substr($spec, 0, $pos), substr($spec, $pos + 1)];
            }
            $r = csv_rename_columns($data, $pairs);
            csv_write($target, $r['header'], $r['rows']);

            return ['exit' => 0, 'summary' => ['written' => [$target], 'renamedColumns' => $r['renamed'], 'skippedColumns' => $r['skippedColumns']]];

        case 'replace':
            $col = $opts['column'] ?? '';
            if ($col === '' || !isset($opts['search']) || !isset($opts['with']) || $target === null) {
                return ['exit' => 2, 'summary' => ['error' => 'replace: --column, --search, --with and (--out|--in-place) are required ([--regex] [--where col=val --match mode])']];
            }
            $r = csv_replace($data, $col, (string) $opts['search'], (string) $opts['with'], isset($opts['regex']), $where, $opts['match'] ?? 'exact');
            csv_write($target, $r['header'], $r['rows']);

            return ['exit' => 0, 'summary' => ['written' => [$target], 'changedRows' => $r['changed']]];

        case 'scale':
            $columns = csv_opt_list($opts, 'column'); // repeatable — net + gross scale together in one call
            if ($columns === [] || $target === null || (!isset($opts['by']) && !isset($opts['rates']))) {
                return ['exit' => 2, 'summary' => ['error' => 'scale: --column (repeatable), (--by <factor> | --rates CUR=rate,CUR=rate) and (--out|--in-place) are required ([--no-round] [--json-keys a,b] [--currency-column c] [--where col=val --match mode])']];
            }
            $jsonKeys = isset($opts['json-keys']) ? csv_split_list($opts['json-keys']) : [];
            $round = !isset($opts['no-round']);
            $mode = $opts['match'] ?? 'exact';
            $changed = 0;
            $skippedN = 0;
            $rates = [];
            $curCol = $opts['currency-column'] ?? 'currency';
            if (isset($opts['rates'])) { // per-currency factors in one call — no loop over currencies
                $rates = csv_parse_map($opts['rates']);
                if ($rates === []) {
                    return ['exit' => 2, 'summary' => ['error' => "scale: --rates parsed no CUR=rate pairs from '{$opts['rates']}'"]];
                }
                foreach ($rates as $cur => $rate) {
                    if (!is_numeric($rate) || (float) $rate <= 0) { // a bad/empty rate would silently zero every matching price
                        return ['exit' => 2, 'summary' => ['error' => "scale: rate for '{$cur}' must be a positive number, got '{$rate}'"]];
                    }
                }
            } elseif (!is_numeric($opts['by']) || (float) $opts['by'] <= 0) { // non-numeric → (float) 0.0 → every value silently zeroed
                return ['exit' => 2, 'summary' => ['error' => "scale: --by must be a positive number, got '{$opts['by']}'"]];
            }
            foreach ($columns as $col) {
                if ($rates !== []) {
                    foreach ($rates as $cur => $rate) {
                        $r = csv_scale($data, $col, (float) $rate, $round, array_merge($where, [$curCol => $cur]), $mode, $jsonKeys);
                        $data = ['header' => $r['header'], 'rows' => $r['rows']];
                        $changed += $r['changed'];
                        $skippedN += $r['skipped'];
                    }
                    continue;
                }
                $r = csv_scale($data, $col, (float) $opts['by'], $round, $where, $mode, $jsonKeys);
                $data = ['header' => $r['header'], 'rows' => $r['rows']];
                $changed += $r['changed'];
                $skippedN += $r['skipped'];
            }
            csv_write($target, $data['header'], $data['rows']);

            return ['exit' => 0, 'summary' => ['written' => [$target], 'changedRows' => $changed, 'skippedNonNumeric' => $skippedN]];

        case 'derive':
            $targetCol = $opts['target'] ?? '';
            $sourceCol = $opts['source'] ?? '';
            $factorRaw = $opts['factor'] ?? '';
            if ($targetCol === '' || $sourceCol === '' || $factorRaw === '' || $target === null) {
                return ['exit' => 2, 'summary' => ['error' => 'derive: --target, --source, --factor and (--out|--in-place) are required ([--no-round] [--only-empty] [--where col=val --match mode])']];
            }
            if (!is_numeric($factorRaw) || (float) $factorRaw === 0.0) {
                return ['exit' => 2, 'summary' => ['error' => "derive: --factor must be a non-zero number (got '{$factorRaw}')"]];
            }
            $r = csv_derive($data, $targetCol, $sourceCol, (float) $factorRaw, !isset($opts['no-round']), $where, $opts['match'] ?? 'exact', isset($opts['only-empty']));
            csv_write($target, $r['header'], $r['rows']);

            return ['exit' => 0, 'summary' => ['written' => [$target], 'changed' => $r['changed'], 'skipped' => $r['skipped']]];

        case 'apply-translations':
            $targetCol = $opts['target-column'] ?? '';
            $sourceCol = $opts['source-column'] ?? $targetCol;
            $mapFile = $opts['map'] ?? '';
            if ($targetCol === '' || $mapFile === '' || $target === null) {
                return ['exit' => 2, 'summary' => ['error' => 'apply-translations: --target-column, --map <file> and (--out|--in-place) are required ([--source-column] defaults to target)']];
            }
            $r = csv_translate($data, $sourceCol, $targetCol, csv_translation_map($mapFile));
            csv_write($target, $r['header'], $r['rows']);

            return ['exit' => 0, 'summary' => ['written' => [$target], 'translatedCells' => $r['changed']]];

        default:
            return ['exit' => 2, 'summary' => ['error' => "unknown command '{$command}'"]];
    }
}

/**
 * Parse options. `--where col=val` is repeatable and collected into a map;
 * every other `--key value` becomes $opts['key']. `--value` may legitimately be
 * an empty string, which is preserved.
 *
 * @param list<string> $args
 * @return array<string,mixed>
 */
function csv_parse_opts(array $args): array
{
    $flags = ['regex' => true, 'no-round' => true, 'plain' => true, 'in-place' => true, 'only-empty' => true]; // valueless boolean flags
    $opts = [];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $key = substr($arg, 2);
        if (isset($flags[$key])) {
            $opts[$key] = true;
        } elseif ($key === 'where') {
            $pair = $args[++$i] ?? '';
            $eq = strpos($pair, '=');
            if ($eq === false) { // a malformed --where must NOT be silently dropped — that broadens a destructive delete/filter
                $opts['_errors'][] = "malformed --where '{$pair}' (expected col=val)";
            } else {
                $opts['where'][substr($pair, 0, $eq)] = substr($pair, $eq + 1);
            }
        } else {
            $value = $args[++$i] ?? '';
            if (!array_key_exists($key, $opts)) {
                $opts[$key] = $value;
                continue;
            }
            // A repeated `--key value` collects into a list (e.g. `--column a --column b`
            // for drop-columns). A key given once stays a scalar, so every other
            // command reading `$opts['key']` as a string is unaffected.
            $opts[$key] = is_array($opts[$key]) ? $opts[$key] : [$opts[$key]];
            $opts[$key][] = $value;
        }
    }

    return $opts;
}

/**
 * Emit the standard JSON report and return the process exit code.
 *
 * @param list<string> $written
 * @param list<string> $errors
 * @param array<string,mixed> $extra
 */
function csv_report(int $exit, array $written, array $errors, array $extra = []): int
{
    $status = $exit === 0 ? 'ok' : ($exit === 1 ? 'warning' : 'error');
    $report = array_merge(['status' => $status, 'written' => $written, 'warnings' => [], 'errors' => $errors], $extra);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

/**
 * Inspect the columns of one or more files in a single invocation. A per-file
 * read error is reported inline (that file gets an `error`), never aborting the
 * batch. Single-file output stays backward-compatible (`{header, rowCount}`);
 * multiple files return a `files` array. --plain prints columns per file, with
 * a `== <file> ==` delimiter only when more than one file is given.
 *
 * @param list<string> $files
 */
function csv_cli_columns(array $files, bool $plain): int
{
    $results = [];
    foreach ($files as $f) {
        try {
            $data = csv_read($f);
            $results[] = ['file' => $f, 'header' => $data['header'], 'rowCount' => count($data['rows'])];
        } catch (Throwable $e) {
            $results[] = ['file' => $f, 'error' => $e->getMessage()];
        }
    }

    if ($plain) {
        $multi = count($results) > 1;
        $lines = [];
        foreach ($results as $r) {
            if ($multi) {
                $lines[] = '== ' . $r['file'] . ' ==';
            }
            if (isset($r['error'])) {
                $lines[] = 'ERROR: ' . $r['error'];
                continue;
            }
            foreach ($r['header'] as $col) {
                $lines[] = $col;
            }
        }

        return csv_plain($lines);
    }

    if (count($results) === 1 && !isset($results[0]['error'])) {
        return csv_report(0, [], [], ['header' => $results[0]['header'], 'rowCount' => $results[0]['rowCount']]);
    }

    return csv_report(0, [], [], ['files' => $results]);
}

/**
 * count — data-row count (header excluded) for one or many files in ONE call.
 * Compact by design: `--plain` prints `rowCount<TAB>file` per line, so "how many
 * rows in these N files" never needs a shell `for` loop or a `| grep rowCount`.
 * A missing/broken file is reported inline; the batch survives.
 *
 * @param list<string> $files
 */
function csv_cli_count(array $files, bool $plain): int
{
    $results = [];
    foreach ($files as $f) {
        try {
            $data = csv_read($f);
            $results[] = ['file' => $f, 'rowCount' => count($data['rows'])];
        } catch (Throwable $e) {
            $results[] = ['file' => $f, 'error' => $e->getMessage()];
        }
    }

    if ($plain) {
        $lines = [];
        foreach ($results as $r) {
            $lines[] = (isset($r['error']) ? 'ERROR' : (string) $r['rowCount']) . "\t" . $r['file'];
        }

        return csv_plain($lines);
    }

    if (count($results) === 1 && !isset($results[0]['error'])) {
        return csv_report(0, [], [], ['rowCount' => $results[0]['rowCount']]);
    }

    return csv_report(0, [], [], ['files' => $results]);
}

/**
 * Plain line output for inspection commands (--plain) — one entry per line, no
 * JSON to parse. Lets the caller read results directly instead of piping the
 * report through another interpreter.
 *
 * @param list<string> $lines
 */
function csv_plain(array $lines): int
{
    echo implode("\n", $lines) . (count($lines) > 0 ? "\n" : '');

    return 0;
}
