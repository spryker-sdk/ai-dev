<?php

declare(strict_types=1);

/**
 * validate.php — general consistency checks the AI/skill drives with parameters.
 *
 * NOT a set of hardcoded rules about specific files. Four concept-free checks;
 * the caller decides which columns/files/references to check by inspecting the
 * data first. New demoshop files/entities need no new code here.
 *
 *   refs      values in column(s) must exist in a reference set
 *             (an explicit list, or the distinct values of another file's column).
 *             → store refs, currency refs, country refs, locale refs, label refs, …
 *   required  cells in given column(s) must be non-empty
 *             → is_searchable.<locale> (blank = silently unsearchable), …
 *   absent    given strings must NOT appear in given text files
 *             → stale DE/AT/de_DE literal sweep across config/src files
 *   paths     every `source:` in an import-config YAML resolves to an existing file
 *   product-refs
 *             recursively scan every CSV under a directory; every product-
 *             referencing column value NOT in the kept set of SKUs is an orphan
 *             (would abort a data import). Auto-discovers sku-bearing columns.
 *
 * This is a helper, not the authority — a green boot is. It only warns earlier
 * and cheaper (one bad row aborts a 30–60 min install). Output: JSON report,
 * exit 2 if any error-level finding.
 *
 * Zero dependencies and fully self-contained — it reads CSVs with PHP's built-in
 * fgetcsv (RFC-4180, escaping disabled). It does NOT depend on the csv skill;
 * the two are independent.
 */

/**
 * Read an RFC-4180 CSV into header + header-keyed rows. Self-contained
 * (fgetcsv with escape '' → pure RFC-4180, multi-line quoted fields handled).
 *
 * @return array{header: list<string>, rows: list<array<string,string>>}
 */
function validate_read_csv(string $path): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("validate: cannot open '{$path}'");
    }
    $header = null;
    $rows = [];
    while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if ($record === [null]) {
            continue;
        }
        if ($header === null) {
            $record[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $record[0]);
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
        throw new RuntimeException("validate: '{$path}' is empty (no header row)");
    }

    return ['header' => $header, 'rows' => $rows];
}

/**
 * refs — for each row, each named column's value(s) must be in $allowed.
 * Empty cells are skipped (empty is "no reference", common and valid, e.g.
 * excluded_store_names). If $split is given, a cell is split into multiple
 * values, each checked (e.g. "US,CA" in included_store_names).
 *
 * A column absent from the header is itself a finding (value 'MISSING COLUMN')
 * — never a silent pass. A wrong/typo'd column name checking nothing and
 * reporting "ok" is the worst failure a validator can have.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param list<string> $columns
 * @param list<string> $allowed
 * @return list<array{row:int|string,column:string,value:string}> offending references
 */
function validate_refs(array $data, array $columns, array $allowed, ?string $split = null): array
{
    $set = array_fill_keys($allowed, true);
    $findings = [];
    foreach ($columns as $col) {
        if (!in_array($col, $data['header'], true)) {
            $findings[] = ['row' => 'header', 'column' => $col, 'value' => 'MISSING COLUMN'];
        }
    }
    foreach ($data['rows'] as $i => $row) {
        foreach ($columns as $col) {
            $cell = $row[$col] ?? '';
            if ($cell === '') {
                continue;
            }
            $values = $split !== null ? explode($split, $cell) : [$cell];
            foreach ($values as $value) {
                $value = trim($value);
                if ($value !== '' && !isset($set[$value])) {
                    $findings[] = ['row' => $i, 'column' => $col, 'value' => $value];
                }
            }
        }
    }

    return $findings;
}

/**
 * refs (composite) — the TUPLE of the named columns must exist as a tuple in the
 * reference set, unlike validate_refs which checks each column independently.
 * For cross-entity integrity like "a merchant assigned to a store": every
 * (merchant, store) in the child file must exist as a (merchant, store) row in
 * merchant_store. Column i is matched positionally against ref-column i.
 * A missing column is a finding; a row whose tuple parts are all empty is skipped.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param list<string> $columns
 * @param array<string,bool> $refKeys reference tuple-keys (built by validate_ref_tuples)
 * @return list<array{row:int|string,column:string,value:string}>
 */
function validate_refs_composite(array $data, array $columns, array $refKeys): array
{
    $findings = [];
    foreach ($columns as $col) {
        if (!in_array($col, $data['header'], true)) {
            $findings[] = ['row' => 'header', 'column' => $col, 'value' => 'MISSING COLUMN'];
        }
    }
    if ($findings !== []) {
        return $findings;
    }
    foreach ($data['rows'] as $i => $row) {
        $parts = [];
        $allEmpty = true;
        foreach ($columns as $col) {
            $value = trim($row[$col] ?? '');
            if ($value !== '') {
                $allEmpty = false;
            }
            $parts[] = $value;
        }
        if ($allEmpty) {
            continue;
        }
        if (!isset($refKeys[implode("\x1f", $parts)])) {
            $findings[] = ['row' => $i, 'column' => implode('+', $columns), 'value' => implode('+', $parts)];
        }
    }

    return $findings;
}

/**
 * Build the set of reference tuple-keys from a ref file's columns (positional).
 *
 * @param list<string> $refColumns
 * @return array<string,bool>
 */
function validate_ref_tuples(string $refFile, array $refColumns): array
{
    $ref = validate_read_csv($refFile);
    foreach ($refColumns as $col) {
        if (!in_array($col, $ref['header'], true)) {
            throw new RuntimeException("validate refs --composite: ref-file has no column '{$col}'");
        }
    }
    $keys = [];
    foreach ($ref['rows'] as $row) {
        $parts = array_map(static fn (string $c): string => trim($row[$c] ?? ''), $refColumns);
        $keys[implode("\x1f", $parts)] = true;
    }

    return $keys;
}

/**
 * required — cells in the given columns must be non-empty.
 * A missing column is itself a finding (value 'MISSING COLUMN').
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param list<string> $columns
 * @return list<array{row:int|string,column:string}> blank/missing cells
 */
function validate_required(array $data, array $columns): array
{
    $findings = [];
    foreach ($columns as $col) {
        if (!in_array($col, $data['header'], true)) {
            $findings[] = ['row' => 'header', 'column' => $col];
            continue;
        }
        foreach ($data['rows'] as $i => $row) {
            if (($row[$col] ?? '') === '') {
                $findings[] = ['row' => $i, 'column' => $col];
            }
        }
    }

    return $findings;
}

/**
 * unique — values in a column must not repeat. Empty cells are ignored (many
 * optional columns are legitimately blank). Concept-free: the caller picks the
 * column (e.g. `url.<locale>` after a prefix rewrite — duplicates fail import).
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @return list<array{value:string,rows:list<int>}> duplicated values with their row indexes
 */
function validate_unique(array $data, string $column): array
{
    if (!in_array($column, $data['header'], true)) {
        return [['value' => 'MISSING COLUMN', 'rows' => []]];
    }
    $seen = [];
    foreach ($data['rows'] as $i => $row) {
        $value = $row[$column] ?? '';
        if ($value === '') {
            continue;
        }
        $seen[$value][] = $i;
    }
    $findings = [];
    foreach ($seen as $value => $rows) {
        if (count($rows) > 1) {
            $findings[] = ['value' => (string) $value, 'rows' => $rows];
        }
    }

    return $findings;
}

/**
 * Expand path arguments to a flat list of regular files: a **directory is
 * recursed** (every regular file under it), a file is kept as-is. A path that
 * is neither a file nor a directory (missing/unreadable) is collected in
 * `unreadable` so the caller reports it — NEVER silently skipped.
 *
 * @param list<string> $paths
 * @return array{files: list<string>, unreadable: list<string>}
 */
function validate_expand_paths(array $paths): array
{
    $files = [];
    $unreadable = [];
    foreach ($paths as $path) {
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry->isFile()) {
                    $files[] = $entry->getPathname();
                }
            }
            continue;
        }
        if (is_file($path)) {
            $files[] = $path;
            continue;
        }
        $unreadable[] = $path;
    }

    return ['files' => $files, 'unreadable' => $unreadable];
}

/**
 * absent — none of $strings may appear in any of the given paths. Literal
 * substring match, line by line. A path may be a file OR a **directory**
 * (recursed to all regular files — the literal-sweep use case: `config/`,
 * `src/Pyz`). A directory arg used to return a false `ok` (fopen on a dir
 * succeeds, fgets fails → zero lines scanned, zero findings) — now it is
 * recursed, and a path that resolves to nothing readable is a `CANNOT READ FILE`
 * finding, never a silent pass.
 *
 * @param list<string> $paths
 * @param list<string> $strings
 * @return list<array{file:string,line:int,string:string}> hits
 */
function validate_absent(array $paths, array $strings): array
{
    $expanded = validate_expand_paths($paths);
    $findings = [];
    foreach ($expanded['unreadable'] as $path) {
        $findings[] = ['file' => $path, 'line' => 0, 'string' => 'CANNOT READ FILE'];
    }
    foreach ($expanded['files'] as $file) {
        $handle = is_file($file) ? @fopen($file, 'rb') : false;
        if ($handle === false) {
            $findings[] = ['file' => $file, 'line' => 0, 'string' => 'CANNOT READ FILE'];
            continue;
        }
        $lineNo = 0;
        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            foreach ($strings as $needle) {
                if ($needle !== '' && str_contains($line, $needle)) {
                    $findings[] = ['file' => $file, 'line' => $lineNo, 'string' => $needle];
                }
            }
        }
        fclose($handle);
    }

    return $findings;
}

/**
 * paths — extract every `source:` value from an import-config YAML and check
 * the referenced file exists (relative to $baseDir). This is the only YAML we
 * read, and only this one key — a targeted extraction, not a YAML parser.
 *
 * @return list<array{source:string}> missing sources
 */
function validate_paths(string $ymlPath, string $baseDir): array
{
    $content = @file_get_contents($ymlPath);
    if ($content === false) {
        return [['source' => "CANNOT READ {$ymlPath}"]];
    }

    $findings = [];
    foreach (explode("\n", $content) as $line) {
        // Match `source: value` or `- source: value`, ignoring leading indent.
        if (preg_match('/^\s*-?\s*source:\s*(.+?)\s*$/', $line, $m) === 1) {
            $source = trim($m[1], "'\" \t");
            if ($source === '') {
                continue;
            }
            $full = $source[0] === '/' ? $source : rtrim($baseDir, '/') . '/' . $source;
            if (!is_file($full)) {
                $findings[] = ['source' => $source];
            }
        }
    }

    return $findings;
}

/**
 * product-refs — for a list of CSV files, scan every discovered product-ref
 * column and collect the orphan tokens (values not in the kept set). Returns
 * both the orphan findings and a per-(file,column) summary so a caller can spot
 * a wholly-orphan column (a mis-classified non-product column) and exclude it.
 *
 * @param list<string> $files
 * @param array<string,bool> $kept union kept-set of valid product tokens
 * @param list<string> $patterns product-ref column header names
 * @param list<string> $excludeColumns column names never treated as product-refs
 * @return array{findings: list<array{file:string,column:string,row:int,value:string}>, columns: list<array{file:string,column:string,list:bool,totalTokens:int,orphanTokens:int}>}
 */
function validate_product_refs(array $files, array $kept, array $patterns, string $listSuffix, array $excludeColumns): array
{
    $patternSet = array_fill_keys($patterns, true);
    $excludeSet = array_fill_keys($excludeColumns, true);
    $findings = [];
    $columns = [];
    foreach ($files as $file) {
        $scan = validate_scan_file($file, $kept, $patternSet, $listSuffix, $excludeSet);
        $findings = array_merge($findings, $scan['findings']);
        $columns = array_merge($columns, $scan['columns']);
    }

    return ['findings' => $findings, 'columns' => $columns];
}

/**
 * Scan one CSV: discover its product-ref columns and check each one.
 *
 * @param array<string,bool> $kept
 * @param array<string,bool> $patternSet
 * @param array<string,bool> $excludeSet
 * @return array{findings: list<array{file:string,column:string,row:int,value:string}>, columns: list<array{file:string,column:string,list:bool,totalTokens:int,orphanTokens:int}>}
 */
function validate_scan_file(string $file, array $kept, array $patternSet, string $listSuffix, array $excludeSet): array
{
    $data = validate_read_csv($file);
    $findings = [];
    $columns = [];
    foreach ($data['header'] as $column) {
        if (!validate_is_product_ref_column($column, $patternSet, $listSuffix, $excludeSet)) {
            continue;
        }
        $scan = validate_scan_column($file, $column, $data['rows'], $kept, $listSuffix);
        $findings = array_merge($findings, $scan['findings']);
        $columns[] = $scan['summary'];
    }

    return ['findings' => $findings, 'columns' => $columns];
}

/**
 * Scan one product-ref column across all rows; count tokens and collect orphans.
 *
 * @param list<array<string,string>> $rows
 * @param array<string,bool> $kept
 * @return array{findings: list<array{file:string,column:string,row:int,value:string}>, summary: array{file:string,column:string,list:bool,totalTokens:int,orphanTokens:int}}
 */
function validate_scan_column(string $file, string $column, array $rows, array $kept, string $listSuffix): array
{
    $isList = $listSuffix !== '' && str_ends_with($column, $listSuffix);
    $findings = [];
    $totalTokens = 0;
    $orphanTokens = 0;
    foreach ($rows as $i => $row) {
        foreach (validate_column_tokens($row[$column] ?? '', $isList) as $token) {
            $totalTokens++;
            if (isset($kept[$token])) {
                continue;
            }
            $orphanTokens++;
            $findings[] = ['file' => $file, 'column' => $column, 'row' => $i, 'value' => $token];
        }
    }

    return [
        'findings' => $findings,
        'summary' => ['file' => $file, 'column' => $column, 'list' => $isList, 'totalTokens' => $totalTokens, 'orphanTokens' => $orphanTokens],
    ];
}

/**
 * A header is a product-ref column when it is in the pattern set, carries
 * `sku` as an underscore-delimited token (covers `sku_concrete`,
 * `abstract_product_sku`, `alternative_product_concrete_sku`, ...), or ends
 * with the list-suffix — unless its exact name is excluded.
 *
 * @param array<string,bool> $patternSet
 * @param array<string,bool> $excludeSet
 */
function validate_is_product_ref_column(string $column, array $patternSet, string $listSuffix, array $excludeSet): bool
{
    if (isset($excludeSet[$column])) {
        return false;
    }
    if (isset($patternSet[$column])) {
        return true;
    }
    if (in_array('sku', explode('_', $column), true)) {
        return true;
    }

    return $listSuffix !== '' && str_ends_with($column, $listSuffix);
}

/**
 * Split a cell into non-empty trimmed tokens. A list column is comma-separated;
 * any other column is a single token.
 *
 * @return list<string>
 */
function validate_column_tokens(string $cell, bool $isList): array
{
    $raw = $isList ? explode(',', $cell) : [$cell];
    $tokens = [];
    foreach ($raw as $value) {
        $value = trim($value);
        if ($value !== '') {
            $tokens[] = $value;
        }
    }

    return $tokens;
}

/**
 * Build the union kept-set from --keep-from CSVs and inline --keep-in tokens.
 * An unreadable file or a missing column throws — never a silent empty keep-set,
 * which would flag every token as an orphan.
 *
 * @param list<string> $keepFrom each "<file>:<column>" (split on the last ':')
 * @param list<string> $keepIn extra inline tokens
 * @return array<string,bool>
 */
function validate_build_kept_set(array $keepFrom, array $keepIn): array
{
    $kept = [];
    foreach ($keepFrom as $spec) {
        $kept = validate_collect_keep_from($spec, $kept);
    }
    foreach ($keepIn as $token) {
        $token = trim($token);
        if ($token !== '') {
            $kept[$token] = true;
        }
    }

    return $kept;
}

/**
 * Add the distinct non-empty values of one "<file>:<column>" spec into $kept.
 *
 * @param array<string,bool> $kept
 * @return array<string,bool>
 */
function validate_collect_keep_from(string $spec, array $kept): array
{
    $pos = strrpos($spec, ':');
    if ($pos === false || $pos === 0 || $pos === strlen($spec) - 1) {
        throw new RuntimeException(sprintf("validate product-refs --keep-from '%s' must be <file>:<column>", $spec));
    }
    $file = substr($spec, 0, $pos);
    $column = substr($spec, $pos + 1);
    $data = validate_read_csv($file);
    if (!in_array($column, $data['header'], true)) {
        throw new RuntimeException(sprintf("validate product-refs --keep-from: '%s' has no column '%s'", $file, $column));
    }
    foreach ($data['rows'] as $row) {
        $value = trim($row[$column] ?? '');
        if ($value !== '') {
            $kept[$value] = true;
        }
    }

    return $kept;
}

/**
 * Recursively find every *.csv under $dir, skipping any whose full path contains
 * an --exclude substring. Sorted for deterministic output.
 *
 * @param list<string> $excludes
 * @return list<string>
 */
function validate_discover_csvs(string $dir, array $excludes): array
{
    if (!is_dir($dir)) {
        throw new RuntimeException(sprintf("validate product-refs: '%s' is not a directory", $dir));
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $files = [];
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'csv') {
            continue;
        }
        $path = $entry->getPathname();
        if (validate_path_excluded($path, $excludes)) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);

    return $files;
}

/**
 * @param list<string> $excludes
 */
function validate_path_excluded(string $path, array $excludes): bool
{
    foreach ($excludes as $needle) {
        if ($needle !== '' && str_contains($path, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Extract the ordered (data_entity, source) entries from an import-config YAML.
 * Targeted line extraction, not a YAML parser (same approach as validate_paths):
 * a `data_entity:` line sets the current entity; the next `source:` line pairs
 * with it. Order is preserved so the dependency-order check can use it.
 *
 * @return list<array{data_entity:string, source:string, file:string, exists:bool}>
 */
function validate_manifest_entries(string $ymlPath, string $baseDir): array
{
    $content = @file_get_contents($ymlPath);
    if ($content === false) {
        throw new RuntimeException(sprintf('validate preflight: cannot read manifest %s', $ymlPath));
    }
    // Buffer per list item and pair data_entity/source WITHIN the item, so a
    // manifest that writes `source:` before `data_entity:` (YAML does not fix key
    // order) is read correctly — the old last-seen-data_entity approach mis-attributed
    // the source to the previous item and produced a silent false green.
    $entries = [];
    $curEntity = '';
    $curSource = '';
    $flush = static function () use (&$entries, &$curEntity, &$curSource, $baseDir): void {
        if ($curSource === '') {
            $curEntity = '';

            return;
        }
        $full = $curSource[0] === '/' ? $curSource : rtrim($baseDir, '/') . '/' . $curSource;
        $entries[] = ['data_entity' => $curEntity, 'source' => $curSource, 'file' => $full, 'exists' => is_file($full)];
        $curEntity = '';
        $curSource = '';
    };
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/^\s*-\s/', $line) === 1) {
            $flush(); // new list item — close the previous one
        }
        if (preg_match('/^\s*-?\s*data_entity\s*:\s*(.+?)\s*$/', $line, $m) === 1) {
            $curEntity = trim($m[1], "'\" \t");
        }
        if (preg_match('/^\s*-?\s*source\s*:\s*(.+?)\s*$/', $line, $m) === 1) {
            $curSource = trim($m[1], "'\" \t");
        }
    }
    $flush();

    return $entries;
}

/**
 * A price cell is "missing" when it is empty OR literal numeric zero. The
 * demoshop mixes empty and `0`; both render as no price (a net-only store
 * booting green with gross=0 sold nothing).
 */
function validate_price_cell_missing(string $cell): bool
{
    $cell = trim($cell);

    return $cell === '' || (is_numeric($cell) && (float) $cell === 0.0);
}

/**
 * Base-before-relation import order. From the ordered first-occurrence of each
 * data_entity: any `<x>-store` must have its base `<x>` (if present) imported
 * before it, and the `store` definition (if present) before every `*-store`.
 * These are the two orderings that caused real boot regressions.
 *
 * @param list<array{data_entity:string, source:string, file:string, exists:bool}> $entries
 * @return list<array{entity:string, detail:string}>
 */
function validate_preflight_order(array $entries): array
{
    $firstIndex = [];
    foreach ($entries as $i => $entry) {
        $name = $entry['data_entity'];
        if ($name !== '' && !isset($firstIndex[$name])) {
            $firstIndex[$name] = $i;
        }
    }
    $violations = [];
    foreach ($firstIndex as $name => $idx) {
        if (!str_ends_with($name, '-store') || $name === 'store') {
            continue;
        }
        $base = substr($name, 0, -strlen('-store'));
        if ($base !== '' && isset($firstIndex[$base]) && $firstIndex[$base] > $idx) {
            $violations[] = ['entity' => $name, 'detail' => sprintf("base '%s' is imported AFTER '%s' — it must come before", $base, $name)];
        }
        if (isset($firstIndex['store']) && $firstIndex['store'] > $idx) {
            $violations[] = ['entity' => $name, 'detail' => sprintf("'store' is imported AFTER '%s' — store definitions must precede every *-store relation", $name)];
        }
    }

    // The store-DEFINITION group must be complete before the catalog begins.
    // Checked on the LAST occurrence of each definition entity: the real
    // regression appended a 3rd store's locale-store entry AFTER the catalog
    // while the entity's first occurrence sat innocently at the top — a
    // first-occurrence check passes it and the store boots silently empty.
    $lastIndex = [];
    foreach ($entries as $i => $entry) {
        if ($entry['data_entity'] !== '') {
            $lastIndex[$entry['data_entity']] = $i;
        }
    }
    $catalogFirst = null;
    foreach ($firstIndex as $name => $idx) {
        if (preg_match('/^(product|category)/', $name) === 1 && ($catalogFirst === null || $idx < $catalogFirst)) {
            $catalogFirst = $idx;
        }
    }
    if ($catalogFirst !== null) {
        foreach (['store', 'locale-store', 'currency-store', 'country-store', 'default-locale-store', 'store-context'] as $def) {
            if (isset($lastIndex[$def]) && $lastIndex[$def] > $catalogFirst) {
                $violations[] = ['entity' => $def, 'detail' => sprintf("a '%s' entry is imported AFTER the catalog begins — every store-definition entry must precede the first product/category entity (a late store's locale-store = a silently empty store, green boot)", $def)];
            }
        }
    }

    return $violations;
}

/**
 * preflight — one driver over an import-config manifest that auto-discovers the
 * boot-critical invariants and checks them, so the caller never enumerates files
 * by hand. Bundles four checks the skills used to narrate as prose:
 *   - url.<locale> global uniqueness (one dup aborts the 30–60 min install)
 *   - is_searchable.<locale> non-blank (blank = silently unsearchable)
 *   - price completeness: value_gross/value_net neither empty NOR literal 0
 *   - base-before-relation import order
 * Discovery is by shape (column prefix / name, the manifest's own source list),
 * so new demoshop files need no new code.
 *
 * @return array{sourcesChecked:int, unreadableSources:list<string>, urlDuplicates:list<array{file:string,column:string,value:string,rows:int}>, searchableBlanks:list<array{file:string,column:string,blanks:int}>, priceMissing:list<array{file:string,column:string,missing:int}>, orderViolations:list<array{entity:string,detail:string}>}
 */
function validate_preflight(string $ymlPath, string $baseDir, array $projectLocales = []): array
{
    $entries = validate_manifest_entries($ymlPath, $baseDir);
    $unreadable = [];
    $urlSeen = [];
    $urlDuplicates = [];
    $searchableBlanks = [];
    $priceMissing = [];
    $priceNetWarnings = [];
    $foreignLocaleColumns = [];
    $foreignLocaleRows = [];
    $localeSet = array_fill_keys($projectLocales, true);
    $shapeWarnings = [];
    $entityNames = [];
    foreach ($entries as $e) {
        if ($e['data_entity'] !== '') {
            $entityNames[$e['data_entity']] = true;
        }
    }
    $taxSetNames = validate_preflight_tax_set($entries);
    foreach ($entries as $entry) {
        if (!$entry['exists']) {
            $unreadable[] = $entry['source'];
            continue;
        }
        if (strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) !== 'csv') {
            continue;
        }
        $data = validate_read_csv($entry['file']);
        // A navigation node's url is a link TARGET, not a spy_url resource —
        // the same category URL legitimately appears in the main AND footer
        // menus (the shipped demo carries ~14 such repeats). Flagging them
        // trains the reader to ignore the URL check, so exempt these sources.
        $isNavigationNode = str_starts_with($entry['data_entity'], 'navigation-node')
            || str_contains(strtolower(basename($entry['file'])), 'navigation_node');
        foreach ($data['header'] as $column) {
            if ($localeSet !== [] && preg_match('/\.([a-z]{2}_[A-Z]{2})$/', $column, $lm) === 1 && !isset($localeSet[$lm[1]])) {
                $foreignLocaleColumns[] = ['file' => $entry['file'], 'column' => $column, 'locale' => $lm[1]];
            }
            if (str_starts_with($column, 'url.')) {
                if ($isNavigationNode) {
                    continue;
                }
                // spy_url is unique ACROSS entities, so accumulate per column
                // family over ALL sources — a product URL colliding with a
                // category or merchant URL is the real constraint a per-file
                // check silently misses.
                foreach ($data['rows'] as $row) {
                    $value = trim($row[$column] ?? '');
                    if ($value === '') {
                        continue;
                    }
                    $urlSeen[$column][$value]['rows'] = ($urlSeen[$column][$value]['rows'] ?? 0) + 1;
                    $urlSeen[$column][$value]['files'][$entry['file']] = true;
                }
                continue;
            }
            if (str_starts_with($column, 'is_searchable.')) {
                $blanks = 0;
                foreach (validate_required($data, [$column]) as $finding) {
                    if ($finding['row'] !== 'header') {
                        $blanks++;
                    }
                }
                if ($blanks > 0) {
                    $searchableBlanks[] = ['file' => $entry['file'], 'column' => $column, 'blanks' => $blanks];
                }
                continue;
            }
        }
        // Price completeness — row-wise and gross-mode aware. Shipment prices are
        // legitimately free (net-empty / gross-0 = free collection), so the
        // empty-or-0 rule is a PRODUCT-price rule. A missing GROSS is the hard
        // failure (the gross=0 priceless-store regression); a missing net beside
        // a present gross is how the shipped gross-mode stores legitimately look
        // (~400 rows boot green), so it is a WARNING, not a gating problem —
        // except in a net-only file, where the net IS the only price.
        $hasGross = in_array('value_gross', $data['header'], true);
        $hasNet = in_array('value_net', $data['header'], true);
        if (($hasGross || $hasNet) && !str_contains(strtolower(basename($entry['file'])), 'shipment')) {
            $grossMissing = 0;
            $netOnlyMissing = 0;
            $netBesideGross = 0;
            foreach ($data['rows'] as $row) {
                $grossMiss = $hasGross && validate_price_cell_missing($row['value_gross'] ?? '');
                $netMiss = $hasNet && validate_price_cell_missing($row['value_net'] ?? '');
                if ($grossMiss) {
                    $grossMissing++;
                } elseif (!$hasGross && $netMiss) {
                    $netOnlyMissing++;
                } elseif ($netMiss) {
                    $netBesideGross++;
                }
            }
            if ($grossMissing > 0) {
                $priceMissing[] = ['file' => $entry['file'], 'column' => 'value_gross', 'missing' => $grossMissing];
            }
            if ($netOnlyMissing > 0) {
                $priceMissing[] = ['file' => $entry['file'], 'column' => 'value_net', 'missing' => $netOnlyMissing];
            }
            if ($netBesideGross > 0) {
                $priceNetWarnings[] = ['file' => $entry['file'], 'column' => 'value_net', 'missing' => $netBesideGross, 'note' => 'net empty/0 beside a present gross — legitimate on a gross-mode store; act only if this store sells net'];
            }
        }
        $shapeWarnings = array_merge($shapeWarnings, validate_preflight_shape($entry, $data, $taxSetNames));
        if ($localeSet !== [] && in_array('locale', $data['header'], true)) {
            $seenLoc = [];
            foreach ($data['rows'] as $row) {
                $loc = trim($row['locale'] ?? '');
                if ($loc !== '' && !isset($localeSet[$loc]) && !isset($seenLoc[$loc])) {
                    $seenLoc[$loc] = true;
                    $foreignLocaleRows[] = ['file' => $entry['file'], 'locale' => $loc];
                }
            }
        }
    }
    $shapeWarnings = array_merge($shapeWarnings, validate_preflight_manifest_shape($entityNames));
    foreach ($urlSeen as $column => $values) {
        foreach ($values as $value => $info) {
            if ($info['rows'] > 1) {
                $urlDuplicates[] = ['files' => implode(' + ', array_keys($info['files'])), 'column' => $column, 'value' => (string) $value, 'rows' => $info['rows']];
            }
        }
    }

    return [
        'sourcesChecked' => count($entries),
        'unreadableSources' => $unreadable,
        'urlDuplicates' => $urlDuplicates,
        'searchableBlanks' => $searchableBlanks,
        'priceMissing' => $priceMissing,
        'priceNetWarnings' => $priceNetWarnings,
        'foreignLocaleColumns' => $foreignLocaleColumns,
        'foreignLocaleRows' => $foreignLocaleRows,
        'shapeWarnings' => $shapeWarnings,
        'orderViolations' => validate_preflight_order($entries),
    ];
}

/**
 * Filter a preflight result against a baseline — a previous preflight JSON
 * report (e.g. captured on the untouched clone before any transformation).
 * Findings already present in the baseline are suppressed so only NEW findings
 * gate; the shipped demoshop's pre-existing quirks stop reading as self-inflicted
 * damage. Matching ignores the volatile count fields (rows/missing/blanks).
 *
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function validate_preflight_apply_baseline(array $result, string $baselinePath): array
{
    $raw = @file_get_contents($baselinePath);
    $baseline = $raw === false ? null : json_decode($raw, true);
    if (!is_array($baseline)) {
        throw new RuntimeException("preflight: cannot read baseline '{$baselinePath}' (expected a previous preflight JSON report)");
    }
    $groups = ['unreadableSources', 'urlDuplicates', 'searchableBlanks', 'priceMissing', 'priceNetWarnings', 'foreignLocaleColumns', 'foreignLocaleRows', 'shapeWarnings', 'orderViolations'];
    foreach ($groups as $group) {
        $known = [];
        foreach ((array) ($baseline[$group] ?? []) as $finding) {
            $known[validate_finding_signature($finding)] = true;
        }
        if ($known === []) {
            continue;
        }
        $result[$group] = array_values(array_filter(
            (array) ($result[$group] ?? []),
            static fn (mixed $f): bool => !isset($known[validate_finding_signature($f)]),
        ));
    }

    return $result;
}

/**
 * Stable identity of one finding for baseline matching: the finding minus its
 * volatile count fields, key-sorted, JSON-encoded.
 */
function validate_finding_signature(mixed $finding): string
{
    if (is_array($finding)) {
        unset($finding['rows'], $finding['missing'], $finding['blanks']);
        ksort($finding);
    }

    return (string) json_encode($finding);
}

/**
 * Build the set of tax_set_name values defined in the manifest's tax source
 * (for F2: product tax_set_name ⊆ tax.csv — the shipped demo has a singular/plural
 * mismatch, "Standard Tax" used by products vs "Standard Taxes" defined).
 *
 * @param list<array{data_entity:string, source:string, file:string, exists:bool}> $entries
 * @return array<string,bool>
 */
function validate_preflight_tax_set(array $entries): array
{
    $names = [];
    foreach ($entries as $entry) {
        if (!$entry['exists'] || ($entry['data_entity'] !== 'tax' && strtolower(basename($entry['file'])) !== 'tax.csv')) {
            continue;
        }
        $data = validate_read_csv($entry['file']);
        if (!in_array('tax_set_name', $data['header'], true)) {
            continue;
        }
        foreach ($data['rows'] as $row) {
            $v = trim($row['tax_set_name'] ?? '');
            if ($v !== '') {
                $names[$v] = true;
            }
        }
    }

    return $names;
}

/**
 * Per-file generate-mode required-shape checks (C1/C4 + F2) — shape/entity-specific
 * assertions the generic column checks can't make. These are the ones that pass every
 * refs/unique/required gate then fail (or silently no-op) at import.
 *
 * @param array{data_entity:string, source:string, file:string, exists:bool} $entry
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @param array<string,bool> $taxSetNames
 * @return list<array{check:string, file:string, detail:string}>
 */
function validate_preflight_shape(array $entry, array $data, array $taxSetNames): array
{
    $warnings = [];
    // C1: product-abstract reads color_code unconditionally → absent = 0 rows imported silently.
    if ($entry['data_entity'] === 'product-abstract' && !in_array('color_code', $data['header'], true)) {
        $warnings[] = ['check' => 'color_code', 'file' => $entry['file'], 'detail' => 'product-abstract source has no color_code column — importer reads it unconditionally; every row fails silently'];
    }
    // C4: visibility is an enum (PDP/PLP/Cart/∅), not a boolean.
    if (in_array('visibility', $data['header'], true)) {
        $allowed = ['PDP' => true, 'PLP' => true, 'Cart' => true, '' => true];
        $bad = 0;
        foreach ($data['rows'] as $row) {
            if (!isset($allowed[trim($row['visibility'] ?? '')])) {
                $bad++;
            }
        }
        if ($bad > 0) {
            $warnings[] = ['check' => 'visibility_enum', 'file' => $entry['file'], 'detail' => "{$bad} rows have `visibility` outside {PDP, PLP, Cart, ∅}"];
        }
    }
    // F2: tax_set_name ⊆ the tax source's set.
    if ($taxSetNames !== [] && in_array('tax_set_name', $data['header'], true)) {
        $seen = [];
        foreach ($data['rows'] as $row) {
            $v = trim($row['tax_set_name'] ?? '');
            if ($v !== '' && !isset($taxSetNames[$v]) && !isset($seen[$v])) {
                $seen[$v] = true;
                $warnings[] = ['check' => 'tax_set_name', 'file' => $entry['file'], 'detail' => "tax_set_name '{$v}' is not defined in the tax source"];
            }
        }
    }

    return $warnings;
}

/**
 * Manifest-level required-shape checks (C5/C6) — presence/absence of whole entities.
 *
 * @param array<string,bool> $entityNames
 * @return list<array{check:string, detail:string}>
 */
function validate_preflight_manifest_shape(array $entityNames): array
{
    $warnings = [];
    // C5 (nastiest): product-abstract with no approval-status entity → 0 search docs, invisibly.
    if (isset($entityNames['product-abstract'])) {
        $hasApproval = false;
        foreach ($entityNames as $name => $_) {
            if (str_contains($name, 'approval')) {
                $hasApproval = true;
                break;
            }
        }
        if (!$hasApproval) {
            $warnings[] = ['check' => 'product-approval-status', 'detail' => 'product-abstract is imported but no *approval-status* entity is — unapproved products publish 0 search docs while every other signal reads green'];
        }
    }
    // C6: merchant-product-offer without merchant-product → PDP shows no seller.
    if (isset($entityNames['merchant-product-offer']) && !isset($entityNames['merchant-product'])) {
        $warnings[] = ['check' => 'merchant-product', 'detail' => 'merchant-product-offer is imported but merchant-product (ownership) is not — the PDP shows no seller'];
    }

    return $warnings;
}

/**
 * manifest-diff — enumerate `data_entity` in two import manifests and report which
 * the OLD (reference/demo) manifest imports that the NEW one does not (`missing`),
 * and which the new adds (`added`). For each missing entity it also counts the rows
 * of its source file (if present) so the caller can RANK: rows ≈ the product
 * population → structural/required; rows ≪ population → opt-in demo garnish. Catches
 * the "generate authored the manifest by intuition and silently dropped behavioural
 * plumbing" gap — one mechanical diff instead of eyeballing 80+ entities.
 *
 * @return array{missing: list<array{data_entity:string, source:string, rows:int|null}>, added: list<array{data_entity:string, source:string}>}
 */
function validate_manifest_diff(string $oldYml, string $newYml, string $baseDir): array
{
    $old = validate_manifest_entries($oldYml, $baseDir);
    $new = validate_manifest_entries($newYml, $baseDir);
    $newEntities = [];
    foreach ($new as $entry) {
        if ($entry['data_entity'] !== '') {
            $newEntities[$entry['data_entity']] = true;
        }
    }
    $oldEntities = [];
    foreach ($old as $entry) {
        if ($entry['data_entity'] !== '') {
            $oldEntities[$entry['data_entity']] = true;
        }
    }
    $missing = [];
    $seenMissing = [];
    foreach ($old as $entry) {
        $name = $entry['data_entity'];
        if ($name === '' || isset($newEntities[$name]) || isset($seenMissing[$name])) {
            continue;
        }
        $seenMissing[$name] = true;
        $rows = null;
        if ($entry['exists'] && strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) === 'csv') {
            $rows = count(validate_read_csv($entry['file'])['rows']);
        }
        $missing[] = ['data_entity' => $name, 'source' => $entry['source'], 'rows' => $rows];
    }
    $added = [];
    $seenAdded = [];
    foreach ($new as $entry) {
        $name = $entry['data_entity'];
        if ($name === '' || isset($oldEntities[$name]) || isset($seenAdded[$name])) {
            continue;
        }
        $seenAdded[$name] = true;
        $added[] = ['data_entity' => $name, 'source' => $entry['source']];
    }

    return ['missing' => $missing, 'added' => $added];
}

/**
 * Parse entity-map.yml into rows. Line-based (no YAML lib): a row opens on
 * `- entity:` and collects source/class/why until the next `- entity:`.
 *
 * @return list<array{entity:string, source:string, class:string, why:string, line:int}>
 */
function validate_parse_entity_map(string $path): array
{
    $content = @file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException(sprintf('known-set: cannot read entity map %s', $path));
    }
    $rows = [];
    $cur = null;
    $lineNo = 0;
    foreach (explode("\n", $content) as $line) {
        $lineNo++;
        if (preg_match('/^\s*-\s*entity\s*:\s*(.+?)\s*$/', $line, $m) === 1) {
            if ($cur !== null) {
                $rows[] = $cur;
            }
            $cur = ['entity' => trim($m[1], "'\" \t"), 'source' => '', 'class' => '', 'why' => '', 'line' => $lineNo];

            continue;
        }
        if ($cur === null) {
            continue;
        }
        if (preg_match('/^\s*source\s*:\s*(.+?)\s*$/', $line, $m) === 1) {
            $cur['source'] = trim($m[1], "'\" \t");
        } elseif (preg_match('/^\s*class\s*:\s*(.+?)\s*$/', $line, $m) === 1) {
            $cur['class'] = trim($m[1], "'\" \t");
        } elseif (preg_match('/^\s*why\s*:\s*(.+?)\s*$/', $line, $m) === 1) {
            $cur['why'] = trim($m[1], "'\" \t");
        }
    }
    if ($cur !== null) {
        $rows[] = $cur;
    }

    return $rows;
}

/**
 * known-set: keep entity-map.yml honest against the shipped manifest, and keep
 * record counts out of the skills. Grounds every check in the manifest or the
 * map — it does NOT scan prose for identifiers/paths (a `<placeholder>`-templated
 * doc false-positives; `paths` and `product-refs` own that against real files).
 *
 * @return array{
 *   missingFromMap: list<string>,
 *   staleMapRows: list<string>,
 *   badSource: list<array{entity:string, source:string, reason:string}>,
 *   unclassified: list<string>,
 *   structuralNoWhy: list<string>,
 *   counts: list<array{file:string, line:int, match:string}>
 * }
 */
function validate_known_set(string $manifestPath, string $mapPath, string $skillsDir, string $baseDir): array
{
    // Coverage is measured against EVERY data_entity declaration, including the
    // source-less ones (e.g. return-reason) that validate_manifest_entries drops
    // because it can only pair entities that have a source.
    $manifestEntities = [];
    $manifestContent = @file_get_contents($manifestPath);
    if ($manifestContent === false) {
        throw new RuntimeException(sprintf('known-set: cannot read manifest %s', $manifestPath));
    }
    foreach (explode("\n", $manifestContent) as $line) {
        if (preg_match('/^\s*-?\s*data_entity\s*:\s*(.+?)\s*$/', $line, $m) === 1) {
            $manifestEntities[trim($m[1], "'\" \t")] = true;
        }
    }

    $entries = validate_manifest_entries($manifestPath, $baseDir);
    $manBasenames = [];
    $manAnyExists = [];
    foreach ($entries as $e) {
        $ent = $e['data_entity'];
        if ($ent === '') {
            continue;
        }
        $manBasenames[$ent][basename($e['source'])] = true;
        $manAnyExists[$ent] = ($manAnyExists[$ent] ?? false) || $e['exists'];
    }

    $map = validate_parse_entity_map($mapPath);
    $mapEntities = [];
    $unclassified = [];
    $structuralNoWhy = [];
    $badSource = [];
    foreach ($map as $row) {
        $mapEntities[$row['entity']] = true;
        if ($row['class'] === '' || $row['class'] === 'unclassified') {
            $unclassified[] = $row['entity'];
        }
        if ($row['class'] === 'structural' && $row['why'] === '') {
            $structuralNoWhy[] = $row['entity'];
        }
        // A source of `~` means the entity legitimately has no file in this
        // manifest (e.g. return-reason) — nothing to verify.
        if ($row['source'] === '' || $row['source'] === '~' || !isset($manBasenames[$row['entity']])) {
            continue;
        }
        if (!isset($manBasenames[$row['entity']][$row['source']])) {
            $badSource[] = ['entity' => $row['entity'], 'source' => $row['source'], 'reason' => 'not the source the manifest imports for this entity'];
        } elseif (($manAnyExists[$row['entity']] ?? false) === false) {
            $badSource[] = ['entity' => $row['entity'], 'source' => $row['source'], 'reason' => 'source file does not exist on disk'];
        }
    }

    $missingFromMap = array_values(array_diff(array_keys($manifestEntities), array_keys($mapEntities)));
    $staleMapRows = array_values(array_diff(array_keys($mapEntities), array_keys($manifestEntities)));

    return [
        'missingFromMap' => $missingFromMap,
        'staleMapRows' => $staleMapRows,
        'badSource' => $badSource,
        'unclassified' => $unclassified,
        'structuralNoWhy' => $structuralNoWhy,
        'counts' => validate_scan_counts($skillsDir),
    ];
}

/**
 * The record-count gate: scan every `.md` under the skills dir for a bare
 * `N rows|files|entities|...` literal. A zero count (`0 products`) is an
 * emptiness assertion, not a record count — exempt. A line carrying a
 * `count-ok` marker is a deliberate, reviewed exception — exempt.
 *
 * @return list<array{file:string, line:int, match:string}>
 */
function validate_scan_counts(string $skillsDir): array
{
    if (!is_dir($skillsDir)) {
        return [];
    }
    $hits = [];
    // The number allows a comma only BETWEEN digits (thousands separator like
    // 1,178) — never a trailing one, so a JSON list comma (`20, categories`)
    // isn't swallowed into a false hit.
    $re = '/~?\d+(?:,\d{3})* (?:rows|files|entities|products|blocks|labels|SKUs|docs|CSVs|nodes|keys|columns|attributes|categories)\b/';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($skillsDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (strtolower($file->getExtension()) !== 'md') {
            continue;
        }
        $lineNo = 0;
        foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $line) {
            $lineNo++;
            if (str_contains($line, 'count-ok')) {
                continue;
            }
            if (preg_match_all($re, $line, $mm) === 0) {
                continue;
            }
            foreach ($mm[0] as $match) {
                if ((int) ltrim($match, '~') === 0) {
                    continue; // 0 / ~0 is an emptiness assertion, not a record count
                }
                $hits[] = ['file' => $file->getPathname(), 'line' => $lineNo, 'match' => trim($match)];
            }
        }
    }

    return $hits;
}

/**
 * --emit-map: regenerate the map SKELETON from the manifest, preserving the
 * class/why of entities that already exist in the current map and marking new
 * ones `unclassified`. A demo-data bump becomes a diff review, not a retype.
 */
function validate_emit_map(string $manifestPath, string $mapPath, string $baseDir): string
{
    $existing = [];
    if (is_file($mapPath)) {
        foreach (validate_parse_entity_map($mapPath) as $row) {
            $existing[$row['entity']] = $row;
        }
    }
    // entity -> source basename, from the source-paired entries (first occurrence).
    $basename = [];
    foreach (validate_manifest_entries($manifestPath, $baseDir) as $e) {
        if ($e['data_entity'] !== '' && !isset($basename[$e['data_entity']])) {
            $basename[$e['data_entity']] = basename($e['source']);
        }
    }
    // Iterate EVERY data_entity declaration in manifest order — including the
    // source-less ones entries drops — so the skeleton is complete.
    $content = (string) @file_get_contents($manifestPath);
    $seen = [];
    $out = '';
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/^\s*-?\s*data_entity\s*:\s*(.+?)\s*$/', $line, $m) !== 1) {
            continue;
        }
        $ent = trim($m[1], "'\" \t");
        if ($ent === '' || isset($seen[$ent])) {
            continue;
        }
        $seen[$ent] = true;
        $prev = $existing[$ent] ?? null;
        $out .= sprintf("- entity: %s\n", $ent);
        $out .= sprintf("  source: %s\n", ($basename[$ent] ?? '') === '' ? '~' : $basename[$ent]);
        $out .= sprintf("  class: %s\n", $prev['class'] ?? 'unclassified');
        $out .= '  why: ' . json_encode($prev['why'] ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    return $out;
}

/**
 * @param array{missingFromMap:list<string>, staleMapRows:list<string>, badSource:list<array<string,string>>, unclassified:list<string>, structuralNoWhy:list<string>, counts:list<array<string,mixed>>} $result
 */
function validate_report_known_set(array $result): int
{
    $problems = count($result['missingFromMap'])
        + count($result['staleMapRows'])
        + count($result['badSource'])
        + count($result['unclassified'])
        + count($result['structuralNoWhy'])
        + count($result['counts']);
    $exit = $problems === 0 ? 0 : 2;
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode([
        'status' => $exit === 0 ? 'ok' : 'error',
        'check' => 'known-set',
        'problemCount' => $problems,
        'missingFromMap' => $result['missingFromMap'],
        'staleMapRows' => $result['staleMapRows'],
        'badSource' => $result['badSource'],
        'unclassified' => $result['unclassified'],
        'structuralNoWhy' => $result['structuralNoWhy'],
        'counts' => $result['counts'],
        'errors' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

/**
 * Built-in referential registry, keyed by the CONSUMER column name (convention,
 * not a per-project schema). Each family lists the producer (data_entity, column)
 * pairs that DEFINE the key; every other occurrence of that column name across the
 * manifest's files is a consumer, checked against the union of its producers. A
 * family whose producer entity is absent from the manifest is left UNCHECKED —
 * never flagged, since without producers every value would be a false orphan.
 *
 * @return array<string, list<array{0: string, 1: string}>>
 */
function validate_manifest_ref_registry(): array
{
    return [
        'abstract_sku' => [['product-abstract', 'abstract_sku']],
        'concrete_sku' => [['product-concrete', 'concrete_sku']],
        'sku' => [['product-abstract', 'abstract_sku'], ['product-concrete', 'concrete_sku']],
        'merchant_reference' => [['merchant', 'merchant_reference']],
        'product_offer_reference' => [['product-offer', 'product_offer_reference'], ['merchant-product-offer', 'product_offer_reference']],
        'category_key' => [['category', 'category_key']],
        'sales_unit_key' => [['product-measurement-sales-unit', 'sales_unit_key']],
    ];
    // NOTE: only families with a DISTINCTIVE column name belong here — a column
    // used by only its producer + consumers. Generic columns (e.g. product-label's
    // `name`, shared by many entities) CANNOT be expressed by this column-name
    // convention without cross-entity false positives; check those with a targeted
    // `refs --ref-file` instead. This registry is a convenience net, not the whole
    // FK graph — see the `spryker-import-tools` doc.
}

/**
 * Sweep the whole FK graph of a manifest by column-name convention: build each
 * key family's producer value set from the entity that defines it, then flag
 * every consumer cell whose value has no producer. Catches orphaned relations a
 * hand-picked `refs` run misses — an offer referencing a missing product or
 * merchant, a `*_store` row referencing a missing parent, and the like.
 *
 * @return array{findings: list<array{family: string, file: string, column: string, row: int, value: string}>, checked: list<string>, unchecked: list<string>}
 */
function validate_manifest_refs(string $ymlPath, string $baseDir): array
{
    $entries = validate_manifest_entries($ymlPath, $baseDir);
    $registry = validate_manifest_ref_registry();

    $entityFiles = [];
    foreach ($entries as $entry) {
        if ($entry['exists'] && strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) === 'csv') {
            $entityFiles[$entry['data_entity']][] = $entry['file'];
        }
    }

    $producers = [];
    $producerCols = [];
    $checked = [];
    $unchecked = [];
    foreach ($registry as $family => $pairs) {
        $values = [];
        $present = false;
        foreach ($pairs as [$entity, $column]) {
            $producerCols[$family][$entity][$column] = true;
            foreach ($entityFiles[$entity] ?? [] as $file) {
                $present = true;
                foreach (validate_read_csv($file)['rows'] as $row) {
                    $value = $row[$column] ?? '';
                    if ($value !== '') {
                        $values[$value] = true;
                    }
                }
            }
        }
        if ($present) {
            $producers[$family] = $values;
            $checked[] = $family;
            continue;
        }
        $unchecked[] = $family;
    }

    $findings = [];
    foreach ($entries as $entry) {
        if (!$entry['exists'] || strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) !== 'csv') {
            continue;
        }
        $data = validate_read_csv($entry['file']);
        foreach ($data['header'] as $column) {
            if (!isset($producers[$column]) || isset($producerCols[$column][$entry['data_entity']][$column])) {
                continue;
            }
            $allowed = $producers[$column];
            foreach ($data['rows'] as $i => $row) {
                $value = $row[$column] ?? '';
                if ($value !== '' && !isset($allowed[$value])) {
                    $findings[] = ['family' => $column, 'file' => $entry['file'], 'column' => $column, 'row' => $i + 2, 'value' => $value];
                }
            }
        }
    }

    return ['findings' => $findings, 'checked' => $checked, 'unchecked' => $unchecked];
}

/**
 * @param array{findings: list<array{family: string, file: string, column: string, row: int, value: string}>, checked: list<string>, unchecked: list<string>} $result
 */
function validate_report_manifest_refs(array $result, int $cap = 200): int
{
    $total = count($result['findings']);
    $exit = $total === 0 ? 0 : 2;
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode([
        'status' => $exit === 0 ? 'ok' : 'error',
        'check' => 'manifest-refs',
        'findingCount' => $total,
        'findings' => array_slice($result['findings'], 0, $cap),
        'checked' => $result['checked'],
        'unchecked' => $result['unchecked'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

/**
 * List CSV files under the given roots that no active manifest source references
 * — dead files that make the import tree lie about what is actually loaded. The
 * complement of `paths` (paths: every source resolves to a file; orphan-files:
 * every file is a source). Roots that are not directories are ignored.
 *
 * @param list<string> $roots
 * @return array{findings: list<array{file: string}>, onDisk: int, referenced: int}
 */
function validate_orphan_files(string $ymlPath, array $roots, string $baseDir): array
{
    $referenced = [];
    foreach (validate_manifest_entries($ymlPath, $baseDir) as $entry) {
        $real = realpath($entry['file']);
        if ($real !== false) {
            $referenced[$real] = true;
        }
    }

    $onDisk = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        foreach (validate_discover_csvs($root, []) as $file) {
            $real = realpath($file);
            if ($real !== false) {
                $onDisk[$real] = true;
            }
        }
    }

    $findings = [];
    foreach (array_keys($onDisk) as $file) {
        if (!isset($referenced[$file])) {
            $findings[] = ['file' => $file];
        }
    }

    return ['findings' => $findings, 'onDisk' => count($onDisk), 'referenced' => count($referenced)];
}

/**
 * @param array{findings: list<array{file: string}>, onDisk: int, referenced: int} $result
 */
function validate_report_orphan_files(array $result): int
{
    $total = count($result['findings']);
    $exit = $total === 0 ? 0 : 2;
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode([
        'status' => $exit === 0 ? 'ok' : 'error',
        'check' => 'orphan-files',
        'findingCount' => $total,
        'findings' => $result['findings'],
        'onDisk' => $result['onDisk'],
        'referenced' => $result['referenced'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

/**
 * threshold-glossary — Sales-Order-Threshold builds its message glossary key at
 * runtime from type+store+currency (`sales-order-threshold.<type>.<store_lc>.<cur_lc>.message`)
 * and looks it up unconditionally; the empty `message_glossary_key` column is NOT
 * an exemption. For every threshold row in the manifest, assert the key resolves in
 * every project locale's glossary. A miss boots green and throws
 * MissingTranslationException only at add-to-cart in the offending store/locale.
 *
 * Files are identified among the manifest's CSV sources by `data_entity` — threshold:
 * `sales-order-threshold`; glossary: `glossary` — so the sweep is scoped to what the
 * active boot imports and does NOT touch `merchant-relationship-sales-order-threshold`
 * (its message keys are auto-generated by its own importer — never glossary-seeded).
 * If a row carries an explicit non-empty `message_glossary_key`, that literal key is
 * checked instead of the derived one (an author override); the empty default derives.
 *
 * @param list<string> $locales project locale iso codes (e.g. nb_NO, pl_PL)
 * @return array{findings: list<array{file:string,row:int,store:string,currency:string,type:string,key:string,locale:string}>, thresholdRows:int, thresholdFiles:int, glossaryFiles:int, locales:list<string>}
 */
function validate_threshold_glossary(string $ymlPath, string $baseDir, array $locales): array
{
    $thresholdFiles = [];
    $glossaryFiles = [];
    foreach (validate_manifest_entries($ymlPath, $baseDir) as $entry) {
        if (!$entry['exists'] || strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) !== 'csv') {
            continue;
        }
        if ($entry['data_entity'] === 'sales-order-threshold') {
            $thresholdFiles[$entry['file']] = true;
        } elseif ($entry['data_entity'] === 'glossary') {
            $glossaryFiles[$entry['file']] = true;
        }
    }

    $glossary = [];
    foreach (array_keys($glossaryFiles) as $file) {
        foreach (validate_read_csv($file)['rows'] as $row) {
            $key = $row['key'] ?? '';
            $locale = $row['locale'] ?? '';
            if ($key !== '' && $locale !== '') {
                $glossary[$key][$locale] = true;
            }
        }
    }

    $findings = [];
    $thresholdRows = 0;
    $skippedRows = 0;
    foreach (array_keys($thresholdFiles) as $file) {
        foreach (validate_read_csv($file)['rows'] as $i => $row) {
            $type = $row['threshold_type_key'] ?? '';
            $store = $row['store'] ?? '';
            $currency = $row['currency'] ?? '';
            // Skip rows the importer itself skips: a blank threshold value writes no
            // record (SalesOrderThresholdWriterStep), and a row missing store/currency/type
            // has no derivable key — counting either would be a false "missing key".
            // Spryker's importer guards on `if ($typeKey && $threshold)`, and '0' is
            // falsy in PHP — so a threshold of 0 (or blank) writes no record and needs
            // no glossary key. Skip both, or the tool demands a key for a row Spryker ignores.
            $thresholdVal = trim($row['threshold'] ?? '');
            if ($type === '' || $store === '' || $currency === '' || $thresholdVal === '' || (float) $thresholdVal === 0.0) {
                $skippedRows++;
                continue;
            }
            $thresholdRows++;
            $explicit = $row['message_glossary_key'] ?? '';
            // The core generator lowercases the WHOLE derived key; a mixed-case authored
            // type would otherwise produce a key that never matches. An explicit
            // message_glossary_key is used verbatim (the author's literal key).
            $key = $explicit !== ''
                ? $explicit
                : strtolower(sprintf('sales-order-threshold.%s.%s.%s.message', $type, $store, $currency));
            foreach ($locales as $locale) {
                if (!isset($glossary[$key][$locale])) {
                    $findings[] = ['file' => $file, 'row' => $i + 2, 'store' => $store, 'currency' => $currency, 'type' => $type, 'key' => $key, 'locale' => $locale];
                }
            }
        }
    }

    $warnings = [];
    if ($thresholdFiles !== [] && $thresholdRows === 0) {
        $warnings[] = 'threshold file(s) found but 0 checkable rows — verify the manifest/CSV parsed as expected before trusting a 0-finding result';
    }

    return [
        'findings' => $findings,
        'thresholdRows' => $thresholdRows,
        'skippedRows' => $skippedRows,
        'thresholdFiles' => count($thresholdFiles),
        'glossaryFiles' => count($glossaryFiles),
        'locales' => array_values($locales),
        'warnings' => $warnings,
    ];
}

/**
 * @param array{findings: list<array{file:string,row:int,store:string,currency:string,type:string,key:string,locale:string}>, thresholdRows:int, skippedRows:int, thresholdFiles:int, glossaryFiles:int, locales:list<string>, warnings:list<string>} $result
 */
function validate_report_threshold_glossary(array $result, int $cap = 200): int
{
    $total = count($result['findings']);
    $exit = $total === 0 ? 0 : 2;
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode([
        'status' => $exit === 0 ? 'ok' : 'error',
        'check' => 'threshold-glossary',
        'findingCount' => $total,
        'findings' => array_slice($result['findings'], 0, $cap),
        'thresholdRows' => $result['thresholdRows'],
        'skippedRows' => $result['skippedRows'],
        'thresholdFiles' => $result['thresholdFiles'],
        'glossaryFiles' => $result['glossaryFiles'],
        'locales' => $result['locales'],
        'warnings' => $result['warnings'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(validate_cli($argv));
}

/**
 * @param list<string> $argv
 */
function validate_cli(array $argv): int
{
    $check = $argv[1] ?? '';
    $opts = validate_parse_opts(array_slice($argv, 2));
    validate_quiet(isset($opts['quiet']));

    try {
        switch ($check) {
            case 'refs':
                $files = $opts['_pos'] ?? [];
                $columns = $opts['column'] ?? [];
                if ($files === [] || $columns === []) {
                    return validate_report(2, 'refs', [], ['usage: validate.php refs <file>... --column C [--column C2] (--in a,b,c | --ref-file F --ref-column K) [--split ,] [--composite]']);
                }
                if (isset($opts['composite'])) {
                    $refFile = $opts['ref-file'] ?? '';
                    $refColumns = $opts['ref-column'] ?? [];
                    if ($refFile === '' || count($refColumns) !== count($columns)) {
                        return validate_report(2, 'refs', [], ['usage: refs <file> --column C1 --column C2 --ref-file F --ref-column K1 --ref-column K2 --composite  (equal # of --column and --ref-column; the tuple must exist in the ref file)']);
                    }
                    $findings = validate_refs_composite(validate_read_csv($files[0]), $columns, validate_ref_tuples($refFile, $refColumns));

                    return validate_report($findings === [] ? 0 : 2, 'refs', $findings);
                }
                $allowed = isset($opts['in']) ? explode(',', $opts['in']) : validate_ref_from_file($opts);
                if ($allowed === null) {
                    return validate_report(2, 'refs', [], ['usage: validate.php refs <file>... --column C [--column C2] (--in a,b,c | --ref-file F --ref-column K) [--split ,] [--composite]']);
                }
                // Multi-file: apply each column only to files that HAVE it, so a heterogeneous
                // batch (a.csv has `key`, b.csv has `attribute_key`) doesn't fail on the cross-product.
                // A column present in NO file is still a finding (typo protection — never a silent pass).
                $findings = [];
                $columnSeen = array_fill_keys($columns, false);
                foreach ($files as $f) {
                    $fdata = validate_read_csv($f);
                    $present = array_values(array_filter($columns, static fn (string $c): bool => in_array($c, $fdata['header'], true)));
                    foreach ($present as $c) {
                        $columnSeen[$c] = true;
                    }
                    foreach (validate_refs($fdata, $present, $allowed, $opts['split'] ?? null) as $fd) {
                        $fd['file'] = $f;
                        $findings[] = $fd;
                    }
                }
                foreach ($columnSeen as $c => $seen) {
                    if (!$seen) {
                        $findings[] = ['row' => 'header', 'column' => $c, 'value' => 'MISSING COLUMN (absent from every file)'];
                    }
                }

                return validate_report($findings === [] ? 0 : 2, 'refs', $findings);

            case 'required':
                $file = $opts['_pos'][0] ?? '';
                $columns = $opts['column'] ?? [];
                if ($file === '' || $columns === []) {
                    return validate_report(2, 'required', [], ['usage: validate.php required <file> --column C [--column C2]']);
                }
                $findings = validate_required(validate_read_csv($file), $columns);

                return validate_report($findings === [] ? 0 : 2, 'required', $findings);

            case 'unique':
                $file = $opts['_pos'][0] ?? '';
                $column = $opts['column'][0] ?? '';
                if ($file === '' || $column === '') {
                    return validate_report(2, 'unique', [], ['usage: validate.php unique <file> --column C']);
                }
                $findings = validate_unique(validate_read_csv($file), $column);

                return validate_report($findings === [] ? 0 : 2, 'unique', $findings);

            case 'absent':
                $files = $opts['_pos'] ?? [];
                $strings = $opts['string'] ?? [];
                if ($files === [] || $strings === []) {
                    return validate_report(2, 'absent', [], ['usage: validate.php absent <file-or-dir> [<path2>...] --string S [--string S2]  (directories are recursed)']);
                }
                $findings = validate_absent($files, $strings);

                return validate_report($findings === [] ? 0 : 2, 'absent', $findings);

            case 'paths':
                $file = $opts['_pos'][0] ?? '';
                $base = $opts['base'] ?? '.';
                if ($file === '') {
                    return validate_report(2, 'paths', [], ['usage: validate.php paths <import-config.yml> [--base dir]']);
                }
                $findings = validate_paths($file, $base);

                return validate_report($findings === [] ? 0 : 2, 'paths', $findings);

            case 'preflight':
                $file = $opts['_pos'][0] ?? '';
                $base = $opts['base'] ?? '.';
                if ($file === '') {
                    return validate_report(2, 'preflight', [], ['usage: validate.php preflight <import-config.yml> [--base dir] [--locales a,b] [--baseline <previous-preflight.json>]']);
                }
                $result = validate_preflight($file, $base, validate_locale_opt($opts));
                if (isset($opts['baseline']) && $opts['baseline'] !== '') {
                    $result = validate_preflight_apply_baseline($result, (string) $opts['baseline']);
                }

                return validate_report_preflight($result);

            case 'manifest-diff':
                $oldYml = $opts['_pos'][0] ?? '';
                $newYml = $opts['_pos'][1] ?? '';
                $base = $opts['base'] ?? '.';
                if ($oldYml === '' || $newYml === '') {
                    return validate_report(2, 'manifest-diff', [], ['usage: validate.php manifest-diff <reference.yml> <new.yml> [--base dir]']);
                }

                return validate_report_manifest_diff(validate_manifest_diff($oldYml, $newYml, $base));

            case 'product-refs':
                $dir = $opts['_pos'][0] ?? '';
                $keepFrom = $opts['keep-from'] ?? [];
                $keepIn = isset($opts['keep-in'])
                    ? array_values(array_filter(array_map('trim', explode(',', $opts['keep-in'])), static fn (string $v): bool => $v !== ''))
                    : [];
                if ($dir === '' || ($keepFrom === [] && $keepIn === [])) {
                    return validate_report(2, 'product-refs', [], ['usage: validate.php product-refs <dir> (--keep-from <file>:<column> | --keep-in a,b,c)... [--pattern n1,n2] [--list-suffix _skus] [--exclude <substr>]... [--exclude-column <col>]...']);
                }
                $patterns = isset($opts['pattern'])
                    ? array_values(array_filter(array_map('trim', explode(',', $opts['pattern'])), static fn (string $v): bool => $v !== ''))
                    : ['sku', 'abstract_sku', 'concrete_sku', 'product_sku', 'product'];
                $kept = validate_build_kept_set($keepFrom, $keepIn);
                $files = validate_discover_csvs($dir, $opts['exclude'] ?? []);
                $result = validate_product_refs($files, $kept, $patterns, $opts['list-suffix'] ?? '_skus', $opts['exclude-column'] ?? []);

                return validate_report_product_refs($result);

            case 'manifest-refs':
                $file = $opts['_pos'][0] ?? '';
                $base = $opts['base'] ?? '.';
                if ($file === '') {
                    return validate_report(2, 'manifest-refs', [], ['usage: validate.php manifest-refs <import-config.yml> [--base dir]  (sweeps the whole FK graph by column-name convention; reports orphaned references)']);
                }

                return validate_report_manifest_refs(validate_manifest_refs($file, $base));

            case 'orphan-files':
                $pos = $opts['_pos'] ?? [];
                $base = $opts['base'] ?? '.';
                if (count($pos) < 2) {
                    return validate_report(2, 'orphan-files', [], ['usage: validate.php orphan-files <import-config.yml> <root-dir> [<root2>...] [--base dir]  (lists CSVs under the roots that no manifest source references)']);
                }

                return validate_report_orphan_files(validate_orphan_files($pos[0], array_slice($pos, 1), $base));

            case 'threshold-glossary':
                $file = $opts['_pos'][0] ?? '';
                $base = $opts['base'] ?? '.';
                $locales = validate_locale_opt($opts);
                if ($file === '' || $locales === []) {
                    return validate_report(2, 'threshold-glossary', [], ['usage: validate.php threshold-glossary <import-config.yml> --locales a,b [--base dir]  (asserts each Sales-Order-Threshold row\'s derived message key resolves in every project locale\'s glossary)']);
                }

                return validate_report_threshold_glossary(validate_threshold_glossary($file, $base, $locales));

            case 'known-set':
                $base = $opts['base'] ?? '.';
                $manifest = $opts['manifest'] ?? ($opts['_pos'][0] ?? 'data/import/local/full_EU.yml');
                // The manifest path is relative to cwd; fall back to base/manifest so
                // `--base <repo-root>` also finds it when cwd is elsewhere.
                if (!is_file($manifest) && $manifest[0] !== '/') {
                    $manifest = rtrim($base, '/') . '/' . $manifest;
                }
                // Defaults resolve relative to THIS script, so known-set works from any cwd.
                $map = $opts['map'] ?? (dirname(__DIR__) . '/data/entity-map.yml');
                $skills = $opts['skills'] ?? dirname(__DIR__, 2);
                if (isset($opts['emit-map'])) {
                    echo validate_emit_map($manifest, $map, $base);

                    return 0;
                }

                return validate_report_known_set(validate_known_set($manifest, $map, $skills, $base));

            default:
                return validate_report(2, $check, [], ['usage: validate.php <refs|required|unique|absent|paths|product-refs|manifest-refs|orphan-files|threshold-glossary|preflight|manifest-diff|known-set> ...']);
        }
    } catch (Throwable $e) {
        return validate_report(2, $check, [], [$e->getMessage()]);
    }
}

/**
 * @param array<string,mixed> $opts
 * @return list<string>|null
 */
function validate_ref_from_file(array $opts): ?array
{
    if (!isset($opts['ref-file'], $opts['ref-column'])) {
        return null;
    }
    $refColumn = is_array($opts['ref-column']) ? ($opts['ref-column'][0] ?? '') : $opts['ref-column'];
    $ref = validate_read_csv($opts['ref-file']);
    $values = [];
    foreach ($ref['rows'] as $row) {
        $v = $row[$refColumn] ?? '';
        if ($v !== '') {
            $values[$v] = true;
        }
    }

    return array_keys($values);
}

/**
 * Parse opts: repeatable flags (--column, --string) collect into lists; other
 * --key value pairs are scalars; bare args collect into $opts['_pos'].
 *
 * @param list<string> $args
 * @return array<string,mixed>
 */
function validate_parse_opts(array $args): array
{
    $repeatable = ['column' => true, 'string' => true, 'ref-column' => true, 'keep-from' => true, 'exclude' => true, 'exclude-column' => true];
    $flags = ['quiet' => true, 'composite' => true, 'emit-map' => true]; // valueless boolean flags
    $opts = ['_pos' => []];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if (str_starts_with($arg, '--')) {
            $key = substr($arg, 2);
            if (isset($flags[$key])) {
                $opts[$key] = true;
            } elseif (isset($repeatable[$key])) {
                $opts[$key][] = $args[++$i] ?? '';
            } else {
                $opts[$key] = $args[++$i] ?? '';
            }
        } else {
            $opts['_pos'][] = $arg;
        }
    }

    return $opts;
}

/**
 * Quiet-mode toggle (--quiet). Set once by the CLI; when on, validate_report
 * prints nothing and the caller relies on the exit code (0 clean / 2 findings).
 * Removes the need to pipe the JSON through another interpreter just to read it.
 */
function validate_quiet(?bool $set = null): bool
{
    static $quiet = false;
    if ($set !== null) {
        $quiet = $set;
    }

    return $quiet;
}

/**
 * @param list<array<string,mixed>> $findings
 * @param list<string> $errors
 */
function validate_report(int $exit, string $check, array $findings, array $errors = []): int
{
    if (validate_quiet()) {
        return $exit;
    }
    $status = $exit === 0 ? 'ok' : ($exit === 1 ? 'warning' : 'error');
    echo json_encode([
        'status' => $status,
        'check' => $check,
        'findingCount' => count($findings),
        'findings' => $findings,
        'errors' => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

/**
 * product-refs report: same shape as validate_report plus a `columns` discovery
 * summary. findingCount is the TRUE orphan total even when the findings list is
 * capped (default 200) to stay token-light. Exit 2 if any orphan, else 0.
 *
 * @param array{findings: list<array{file:string,column:string,row:int,value:string}>, columns: list<array{file:string,column:string,list:bool,totalTokens:int,orphanTokens:int}>} $result
 */
function validate_report_product_refs(array $result, int $cap = 200): int
{
    $total = count($result['findings']);
    $exit = $total === 0 ? 0 : 2;
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode([
        'status' => $exit === 0 ? 'ok' : 'error',
        'check' => 'product-refs',
        'findingCount' => $total,
        'findings' => array_slice($result['findings'], 0, $cap),
        'columns' => $result['columns'],
        'errors' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

/**
 * preflight report: one grouped verdict. Exit 2 if any GATING group has a
 * problem; priceNetWarnings is informational (net-empty beside a present gross
 * is how the shipped gross-mode stores legitimately look) and never gates.
 *
 * @param array{sourcesChecked:int, unreadableSources:list<string>, urlDuplicates:list<array<string,mixed>>, searchableBlanks:list<array<string,mixed>>, priceMissing:list<array<string,mixed>>, priceNetWarnings:list<array<string,mixed>>, orderViolations:list<array<string,mixed>>} $result
 */
function validate_report_preflight(array $result): int
{
    $problems = count($result['unreadableSources'])
        + count($result['urlDuplicates'])
        + count($result['searchableBlanks'])
        + count($result['priceMissing'])
        + count($result['foreignLocaleColumns'])
        + count($result['foreignLocaleRows'])
        + count($result['shapeWarnings'])
        + count($result['orderViolations']);
    $exit = $problems === 0 ? 0 : 2;
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode([
        'status' => $exit === 0 ? 'ok' : 'error',
        'check' => 'preflight',
        'sourcesChecked' => $result['sourcesChecked'],
        'problemCount' => $problems,
        'warningCount' => count($result['priceNetWarnings']),
        'unreadableSources' => $result['unreadableSources'],
        'urlDuplicates' => $result['urlDuplicates'],
        'searchableBlanks' => $result['searchableBlanks'],
        'priceMissing' => $result['priceMissing'],
        'priceNetWarnings' => $result['priceNetWarnings'],
        'foreignLocaleColumns' => $result['foreignLocaleColumns'],
        'foreignLocaleRows' => $result['foreignLocaleRows'],
        'shapeWarnings' => $result['shapeWarnings'],
        'orderViolations' => $result['orderViolations'],
        'errors' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

/**
 * @param array<string,mixed> $opts
 * @return list<string>
 */
function validate_locale_opt(array $opts): array
{
    if (!isset($opts['locales']) || !is_string($opts['locales'])) {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $opts['locales'])), static fn (string $v): bool => $v !== ''));
}

/**
 * manifest-diff report: entities the reference imports but the new manifest does not
 * (`missing`, with source row counts to rank), and entities the new one adds. Exit 2
 * if anything is missing (the caller must classify each as intentional-drop vs must-keep).
 *
 * @param array{missing: list<array<string,mixed>>, added: list<array<string,mixed>>} $result
 */
function validate_report_manifest_diff(array $result): int
{
    $exit = $result['missing'] === [] ? 0 : 2;
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode([
        'status' => $exit === 0 ? 'ok' : 'error',
        'check' => 'manifest-diff',
        'missingCount' => count($result['missing']),
        'addedCount' => count($result['added']),
        'missing' => $result['missing'],
        'added' => $result['added'],
        'errors' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}
