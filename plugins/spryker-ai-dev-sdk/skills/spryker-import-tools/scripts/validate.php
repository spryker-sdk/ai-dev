<?php

declare(strict_types=1);

/** validate.php — general consistency checks the AI/skill drives with parameters. */

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
 * — never a silent pass. A wrong/typo'd column name would otherwise check
 * nothing and report "ok".
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
 * `unreadable` so the caller reports it — never silently skipped.
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
 * absent — none of $strings may appear in any of the given paths.
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
            $source = validate_yaml_scalar($m[1]);
            if ($source === '') {
                continue;
            }
            $full = $source[0] === '/' ? $source : rtrim($baseDir, '/') . '/' . $source;
            if (!is_file($full) && !(str_starts_with($source, 'vendor/') && !is_dir(rtrim($baseDir, '/') . '/vendor'))) {
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

/** A plain YAML scalar value with quotes and a trailing ` # comment` removed. */
function validate_yaml_scalar(string $raw): string
{
    $value = trim($raw);
    if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
        $q = $value[0];
        $end = strpos($value, $q, 1);

        return $end === false ? trim($value, "'\" \t") : substr($value, 1, $end - 1);
    }
    $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;

    return trim($value, "'\" \t");
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
    // order) is read correctly — pairing on the last-seen data_entity would attribute
    // the source to the previous item and report a false green.
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
            $curEntity = validate_yaml_scalar($m[1]);
        }
        if (preg_match('/^\s*-?\s*source\s*:\s*(.+?)\s*$/', $line, $m) === 1) {
            $curSource = validate_yaml_scalar($m[1]);
        }
    }
    $flush();

    return $entries;
}

/**
 * A price cell is "missing" when it is empty OR literal numeric zero. The
 * demoshop mixes empty and `0`; both render as no price (a net-only store
 * with gross=0 boots green and shows no price).
 */
function validate_price_cell_missing(string $cell): bool
{
    $cell = trim($cell);

    return $cell === '' || (is_numeric($cell) && (float) $cell === 0.0);
}

/**
 * Cross-entity import dependencies: entity → the entities whose rows its importer RESOLVES BY
 * REFERENCE and that must therefore already be in the database.
 *
 * @return array<string, list<string>>
 */
function validate_import_order_table(): array
{
    return [
        'merchant-relationship' => ['merchant', 'company-business-unit'],
        'merchant-relation-to-product-list' => ['merchant-relationship', 'product-list'],
        'merchant-relationship-product-list' => ['merchant-relationship', 'product-list'],
        'product-price-merchant-relationship' => ['merchant-relationship', 'product-price'],
        'merchant-relationship-sales-order-threshold' => ['merchant-relationship'],
        'product-list-to-category' => ['product-list', 'category'],
        'product-list-category' => ['product-list', 'category'],
        'product-list-to-concrete-product' => ['product-list', 'product-concrete'],
        'product-list-product-concrete' => ['product-list', 'product-concrete'],
        'cms-slot-block' => ['cms-block', 'cms-slot'],
        'customer-address' => ['customer'],
        'merchant-product' => ['merchant', 'product-abstract'],
        'product-offer' => ['merchant'],
        'product-concrete' => ['product-abstract'],
        'product-image' => ['product-abstract'],
    ];
}

/**
 * Base-before-relation import order.
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
    // Checked on the last occurrence of each definition entity: a 3rd store's
    // locale-store entry appended after the catalog still has the entity's first
    // occurrence at the top, so a first-occurrence check passes it and the store
    // boots silently empty.
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

    foreach (validate_import_order_table() as $entity => $dependsOn) {
        if (!isset($firstIndex[$entity])) {
            continue;
        }
        foreach ($dependsOn as $dependency) {
            if (!isset($lastIndex[$dependency]) || $lastIndex[$dependency] < $firstIndex[$entity]) {
                continue;
            }
            $violations[] = ['entity' => $entity, 'detail' => sprintf(
                "'%s' (action #%d) depends on '%s' but that is imported at action #%d — the importer resolves the reference against the database and aborts (\"Could not find … by reference\"), costing a full reset",
                $entity,
                $firstIndex[$entity] + 1,
                $dependency,
                $lastIndex[$dependency] + 1,
            )];
        }
    }

    return $violations;
}

/**
 * preflight — one driver over an import-config manifest that auto-discovers the boot-critical
 * invariants and checks them, so the caller never enumerates files by hand.
 *
 * @return array{sourcesChecked:int, unreadableSources:list<string>, urlDuplicates:list<array{file:string,column:string,value:string,rows:int}>, searchableBlanks:list<array{file:string,column:string,blanks:int}>, priceMissing:list<array{file:string,column:string,missing:int}>, orderViolations:list<array{entity:string,detail:string}>}
 */
function validate_preflight(string $ymlPath, string $baseDir, array $projectLocales = [], array $knownStores = []): array
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
    $loaded = [];
    foreach ($entries as $entry) {
        if (!$entry['exists']) {
            if (!validate_is_vendor_source($entry['file'], $baseDir)) {
                $unreadable[] = $entry['source'];
            }
            continue;
        }
        if (strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) !== 'csv') {
            continue;
        }
        $data = validate_read_csv($entry['file']);
        $loaded[$entry['file']] = $data;
        if (validate_is_vendor_source($entry['file'], $baseDir)) {
            continue;
        }
        // A navigation node's url is a link target, not a spy_url resource —
        // the same category URL legitimately appears in the main and footer
        // menus (the shipped demo carries such repeats). Flagging them would be
        // false positives, so exempt these sources.
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
                // spy_url is unique across entities, so accumulate per column
                // family over all sources — a product URL colliding with a
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
        // empty-or-0 rule is a product-price rule. A missing gross is the hard
        // failure (gross=0 leaves a gross-mode store without a price); a missing net
        // beside a present gross is how the shipped gross-mode stores legitimately
        // look (those rows boot green), so it is a warning, not a gating problem —
        // except in a net-only file, where the net is the only price.
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
            // The en_US glossary is the Zed-side fallback layer (TRANSLATION_ZED_FALLBACK_LOCALES
            // maps every project locale to en_US), imported whether or not a store serves en_US —
            // its rows are not a foreign locale. Other files' en_US rows still are.
            $isGlossary = $entry['data_entity'] === 'glossary' || str_starts_with(strtolower(basename($entry['file'])), 'glossary');
            $seenLoc = [];
            foreach ($data['rows'] as $row) {
                $loc = trim($row['locale'] ?? '');
                if ($isGlossary && $loc === validate_glossary_fallback_locale()) {
                    continue;
                }
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

    return array_merge([
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
    ], validate_preflight_invariants($entries, $baseDir, $loaded, $knownStores));
}

/** The glossary locale every project imports as the translator fallback, store locale or not. */
function validate_glossary_fallback_locale(): string
{
    return 'en_US';
}

/**
 * Discount clauses on `shipment-method` / `shipment-carrier` whose value is not a numeric id.
 * Both decision rules (and their collector twins) compare the id (`queryStringCompare(...,
 * (string) $idShipmentMethod)`), so `shipment-method = 'DHL Standard'` imports, passes every
 * other check and never matches.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @return list<array<string,string>>
 */
function validate_discount_shipment_clauses(string $file, array $data): array
{
    $out = [];
    $keyCol = in_array('discount_key', $data['header'], true) ? 'discount_key' : null;
    foreach (['decision_rule_query_string', 'collector_query_string'] as $col) {
        if (!in_array($col, $data['header'], true)) {
            continue;
        }
        foreach ($data['rows'] as $i => $row) {
            $query = (string) ($row[$col] ?? '');
            if (preg_match_all('~\b(shipment-method|shipment-carrier)\s*(?:!=|=|is\s+not\s+in|is\s+in)\s*([\'"])(.*?)\2~i', $query, $m, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($m as $clause) {
                foreach (explode(';', $clause[3]) as $value) {
                    $value = trim($value);
                    if ($value !== '' && preg_match('/^\d+$/', $value) !== 1) {
                        $out[] = ['file' => $file, 'discount' => $keyCol !== null ? trim($row[$keyCol] ?? '') : 'row ' . ($i + 1), 'column' => $col, 'clause' => $clause[0], 'value' => $value,
                            'hint' => strtolower($clause[1]) . ' compares the numeric id (spy_shipment_' . (strtolower($clause[1]) === 'shipment-method' ? 'method.id_shipment_method' : 'carrier.id_shipment_carrier') . '), never the name — the rule imports and never applies'];
                    }
                }
            }
        }
    }

    return $out;
}

/**
 * Invariants the skills state that no other check covers.
 *
 * @param list<array{data_entity:string, source:string, file:string, exists:bool}> $entries
 * @param array<string, array{header: list<string>, rows: list<array<string,string>>}> $loaded CSV data keyed by file
 * @param list<string> $knownStores stores declared by OTHER manifests of the same boot (the gate passes the union)
 * @return array<string, list<array<string,mixed>>>
 */
function validate_preflight_invariants(array $entries, string $baseDir, array $loaded, array $knownStores = []): array
{
    $headerOnly = [];
    $glossaryMulti = [];
    $duplicateEntity = [];
    $storeDirMismatch = [];
    $searchOverlap = [];
    $templateUnknown = [];
    $rootNotInMenu = [];
    $approvalMissing = [];
    $priceNonInteger = [];
    $embeddedMissing = [];
    $undeclaredStore = [];
    $lineEndings = [];
    $discountShipmentByName = [];
    $categoryParents = [];
    $categoryStoreRows = [];
    $categoryStoreFiles = [];

    $byEntity = [];
    $stores = array_fill_keys($knownStores, true);
    $templates = [];
    $abstractSkus = [];
    $approvalSkus = [];
    $approvalFiles = [];
    $searchMapKeys = [];
    $searchAttrKeys = [];
    foreach ($entries as $entry) {
        if (!$entry['exists'] || !isset($loaded[$entry['file']])) {
            continue;
        }
        $data = $loaded[$entry['file']];
        $entity = $entry['data_entity'];
        $base = strtolower(basename($entry['file']));
        if (!validate_is_vendor_source($entry['file'], $baseDir)) {
            $byEntity[$entity][] = $entry['file'];
        }
        if (($entity === 'store' || $base === 'store.csv') && in_array('name', $data['header'], true)) {
            foreach ($data['rows'] as $row) {
                $v = trim($row['name'] ?? '');
                if ($v !== '') {
                    $stores[$v] = true;
                }
            }
        }
        if (($entity === 'category-template' || $base === 'category_template.csv') && in_array('template_name', $data['header'], true)) {
            foreach ($data['rows'] as $row) {
                $v = trim($row['template_name'] ?? '');
                if ($v !== '') {
                    $templates[$v] = true;
                }
            }
        }
        if ($entity === 'product-abstract' && in_array('abstract_sku', $data['header'], true)) {
            foreach ($data['rows'] as $row) {
                $v = trim($row['abstract_sku'] ?? '');
                if ($v !== '') {
                    $abstractSkus[$v] = true;
                }
            }
        }
        if (str_contains($entity, 'approval') || str_contains($base, 'approval_status')) {
            $col = in_array('sku', $data['header'], true) ? 'sku' : (in_array('abstract_sku', $data['header'], true) ? 'abstract_sku' : null);
            if ($col !== null) {
                $approvalFiles[] = $entry['file'];
                foreach ($data['rows'] as $row) {
                    $v = trim($row[$col] ?? '');
                    if ($v !== '') {
                        $approvalSkus[$v] = true;
                    }
                }
            }
        }
        if (($entity === 'product-search-attribute-map' || $base === 'product_search_attribute_map.csv') && in_array('attribute_key', $data['header'], true)) {
            foreach ($data['rows'] as $row) {
                $v = trim($row['attribute_key'] ?? '');
                if ($v !== '') {
                    $searchMapKeys[$v] = $entry['file'];
                }
            }
        }
        if (($entity === 'product-search-attribute' || $base === 'product_search_attribute.csv') && in_array('key', $data['header'], true)) {
            foreach ($data['rows'] as $row) {
                $v = trim($row['key'] ?? '');
                if ($v !== '') {
                    $searchAttrKeys[$v] = $entry['file'];
                }
            }
        }
    }

    $storeCols = ['store' => false, 'store_name' => false, 'included_store_names' => true];
    $moneyCols = ['value_gross', 'value_net', 'value_cost', 'amount', 'threshold'];
    foreach ($entries as $entry) {
        if (!$entry['exists'] || !isset($loaded[$entry['file']])) {
            continue;
        }
        $data = $loaded[$entry['file']];
        $entity = $entry['data_entity'];
        $base = strtolower(basename($entry['file']));
        $isVendor = validate_is_vendor_source($entry['file'], $baseDir);
        if (!$isVendor) {
            $raw = @file_get_contents($entry['file'], false, null, 0, 1 << 20);
            $crlf = $raw === false ? 0 : substr_count($raw, "\r\n");
            if ($crlf > 0) {
                $lineEndings[] = ['file' => $entry['file'], 'crlfLines' => $crlf];
            }
        }
        if (!$isVendor && $data['rows'] === []) {
            $headerOnly[] = ['file' => $entry['file'], 'entity' => $entity];
        }
        if (!$isVendor && $entity === 'glossary' && in_array('locale', $data['header'], true)) {
            $locs = [];
            foreach ($data['rows'] as $row) {
                $v = trim($row['locale'] ?? '');
                if ($v !== '') {
                    $locs[$v] = true;
                }
            }
            if (count($locs) > 1) {
                $l = array_keys($locs);
                sort($l);
                $glossaryMulti[] = ['file' => $entry['file'], 'locales' => $l];
            }
        }
        $rel = validate_relative_path($entry['file'], $baseDir);
        $expectedStore = null;
        if ($isVendor) {
            $rel = '';
        }
        foreach (explode('/', dirname($rel)) as $segment) {
            if (isset($stores[$segment])) {
                $expectedStore = $segment;
            }
        }
        if ($expectedStore !== null) {
            foreach ($storeCols as $col => $isList) {
                if (!in_array($col, $data['header'], true)) {
                    continue;
                }
                $found = [];
                $bad = 0;
                foreach ($data['rows'] as $row) {
                    $cell = trim($row[$col] ?? '');
                    if ($cell === '') {
                        continue;
                    }
                    $values = $isList ? array_map('trim', explode(',', $cell)) : [$cell];
                    $rowBad = false;
                    foreach ($values as $v) {
                        if ($v !== '' && $v !== $expectedStore) {
                            $found[$v] = true;
                            $rowBad = true;
                        }
                    }
                    if ($rowBad) {
                        $bad++;
                    }
                }
                if ($bad > 0) {
                    $f = array_keys($found);
                    sort($f);
                    $storeDirMismatch[] = ['file' => $entry['file'], 'column' => $col, 'expected' => $expectedStore, 'found' => $f, 'rows' => $bad];
                }
            }
        }
        if ($stores !== []) {
            foreach (['store' => false, 'store_name' => false, 'included_store_names' => true, 'excluded_store_names' => true] as $col => $isList) {
                if (!in_array($col, $data['header'], true) || ($entity === 'store' || $base === 'store.csv')) {
                    continue;
                }
                $unknown = [];
                foreach ($data['rows'] as $row) {
                    $cell = trim($row[$col] ?? '');
                    if ($cell === '') {
                        continue;
                    }
                    foreach ($isList ? array_map('trim', explode(',', $cell)) : [$cell] as $v) {
                        if ($v !== '' && !isset($stores[$v])) {
                            $unknown[$v] = ($unknown[$v] ?? 0) + 1;
                        }
                    }
                }
                if ($unknown !== []) {
                    ksort($unknown);
                    $undeclaredStore[] = ['file' => $entry['file'], 'column' => $col, 'values' => array_keys($unknown), 'rows' => array_sum($unknown)];
                }
            }
        }
        if (!$isVendor && $templates !== [] && ($entity === 'category' || $base === 'category.csv') && in_array('template_name', $data['header'], true)) {
            $seen = [];
            foreach ($data['rows'] as $row) {
                $v = trim($row['template_name'] ?? '');
                if ($v !== '' && !isset($templates[$v])) {
                    $seen[$v] = ($seen[$v] ?? 0) + 1;
                }
            }
            foreach ($seen as $tpl => $n) {
                $templateUnknown[] = ['file' => $entry['file'], 'template' => (string) $tpl, 'rows' => $n];
            }
        }
        if (!$isVendor) {
            $discountShipmentByName = array_merge($discountShipmentByName, validate_discount_shipment_clauses($entry['file'], $data));
        }
        if (!$isVendor && ($entity === 'category' || $base === 'category.csv') && in_array('category_key', $data['header'], true)) {
            foreach ($data['rows'] as $row) {
                $k = trim($row['category_key'] ?? '');
                if ($k !== '') {
                    $categoryParents[$k] = trim($row['parent_category_key'] ?? '');
                }
            }
        }
        if (!$isVendor && ($entity === 'category-store' || $base === 'category_store.csv') && in_array('category_key', $data['header'], true)) {
            $categoryStoreFiles[] = $entry['file'];
            foreach ($data['rows'] as $row) {
                $k = trim($row['category_key'] ?? '');
                if ($k === '') {
                    continue;
                }
                // Both lists empty = "take the parent's stores" (CategoryStoreWriteStep falls back to
                // the parent relation) — an explicit row for every store the parent has.
                if (trim((string) ($row['included_store_names'] ?? '')) === '' && trim((string) ($row['excluded_store_names'] ?? '')) === '') {
                    $categoryStoreRows[$k]['inherit'] = true;
                }
                foreach (['included_store_names' => 'in', 'excluded_store_names' => 'out'] as $col => $side) {
                    foreach (array_map('trim', explode(',', (string) ($row[$col] ?? ''))) as $st) {
                        if ($st !== '') {
                            $categoryStoreRows[$k][$side][$st] = true;
                        }
                    }
                }
            }
        }
        if (!$isVendor && ($entity === 'category' || $base === 'category.csv') && in_array('is_in_menu', $data['header'], true)) {
            foreach ($data['rows'] as $row) {
                $isRoot = trim($row['parent_category_key'] ?? '') === '' || trim($row['is_root'] ?? '') === '1';
                if ($isRoot && trim($row['is_in_menu'] ?? '') !== '1') {
                    $rootNotInMenu[] = ['file' => $entry['file'], 'key' => trim($row['category_key'] ?? ''), 'is_in_menu' => trim($row['is_in_menu'] ?? '')];
                }
            }
        }
        foreach ($isVendor ? [] : $moneyCols as $col) {
            if (!in_array($col, $data['header'], true)) {
                continue;
            }
            $bad = 0;
            $sample = [];
            foreach ($data['rows'] as $row) {
                $cell = trim($row[$col] ?? '');
                if ($cell !== '' && preg_match('/^-?\d+$/', $cell) !== 1) {
                    $bad++;
                    if (count($sample) < 3) {
                        $sample[] = $cell;
                    }
                }
            }
            if ($bad > 0) {
                $priceNonInteger[] = ['file' => $entry['file'], 'column' => $col, 'rows' => $bad, 'sample' => $sample];
            }
        }
        foreach ($isVendor ? [] : validate_embedded_paths($data) as $column => $paths) {
            foreach ($paths as $path) {
                if (validate_resolve_embedded($path, $baseDir, dirname($entry['file'])) === null) {
                    $embeddedMissing[] = ['file' => $entry['file'], 'column' => $column, 'path' => $path];
                }
            }
        }
    }

    foreach ($byEntity as $entity => $files) {
        if (count($files) < 2) {
            continue;
        }
        $byDir = [];
        foreach ($files as $file) {
            $stem = pathinfo($file, PATHINFO_FILENAME);
            $tokens = explode('_', str_replace('-', '_', $stem));
            $scoped = false;
            foreach ($tokens as $i => $tok) {
                if (isset($stores[$tok]) || (isset($tokens[$i + 1]) && preg_match('/^[a-z]{2}$/', $tok) === 1 && preg_match('/^[A-Z]{2}$/', $tokens[$i + 1]) === 1)) {
                    $scoped = true;
                    break;
                }
            }
            if (!$scoped) {
                $byDir[dirname($file)][] = basename($file);
            }
        }
        foreach ($byDir as $dir => $names) {
            if (count($names) > 1) {
                sort($names);
                $duplicateEntity[] = ['entity' => (string) $entity, 'directory' => $dir, 'sources' => $names];
            }
        }
    }
    // category-store propagates the root's stores to its children only when the root relation is
    // FIRST created (CategoryStoreUpdater: no store diff → return before the children update), so
    // a category added on a later import gets no spy_category_store row unless it carries its own.
    // Root-only rows (the shipped shape) are fine on a fresh import; once a store's rows name any
    // non-root category, every category must be stated for that store (included or excluded).
    $categoryStoreIncomplete = [];
    $explicitStores = [];
    foreach ($categoryStoreRows as $k => $sides) {
        if (($categoryParents[$k] ?? '') !== '') {
            foreach (array_keys($sides['in'] ?? []) as $st) {
                $explicitStores[$st] = true;
            }
        }
    }
    foreach (array_keys($explicitStores) as $st) {
        $missing = [];
        foreach ($categoryParents as $k => $parent) {
            if ($parent !== '' && !isset($categoryStoreRows[$k]['inherit']) && !isset($categoryStoreRows[$k]['in'][$st]) && !isset($categoryStoreRows[$k]['out'][$st])) {
                $missing[] = (string) $k;
            }
        }
        if ($missing !== []) {
            sort($missing);
            $categoryStoreIncomplete[] = ['store' => (string) $st, 'files' => implode(' + ', $categoryStoreFiles), 'missing' => count($missing), 'sample' => array_slice($missing, 0, 8),
                'hint' => 'these categories rely on the root\'s first-import propagation; one added after the first import gets no store row and its page shows 0 products — add a category_store row per category for this store'];
        }
    }
    foreach (array_intersect_key($searchMapKeys, $searchAttrKeys) as $key => $mapFile) {
        $searchOverlap[] = ['key' => (string) $key, 'files' => [$mapFile, $searchAttrKeys[$key]]];
    }
    if ($approvalFiles !== [] && $abstractSkus !== []) {
        $missing = array_keys(array_diff_key($abstractSkus, $approvalSkus));
        if ($missing !== []) {
            sort($missing);
            $approvalMissing[] = ['file' => implode(' + ', $approvalFiles), 'missing' => count($missing), 'sample' => array_slice($missing, 0, 5)];
        }
    }

    return [
        'headerOnlySources' => $headerOnly,
        'glossaryMultiLocale' => $glossaryMulti,
        'duplicateEntitySources' => $duplicateEntity,
        'storeDirMismatch' => $storeDirMismatch,
        'searchAttributeOverlap' => $searchOverlap,
        'categoryTemplateUnknown' => $templateUnknown,
        'rootCategoryNotInMenu' => $rootNotInMenu,
        'approvalStatusMissing' => $approvalMissing,
        'priceNonInteger' => $priceNonInteger,
        'embeddedPathsMissing' => $embeddedMissing,
        'undeclaredStore' => $undeclaredStore,
        'lineEndings' => $lineEndings,
        'discountShipmentByName' => $discountShipmentByName,
        'categoryStoreIncomplete' => $categoryStoreIncomplete,
    ];
}

/** The gating groups a preflight report carries (order = report order). */
function validate_preflight_groups(): array
{
    return ['unreadableSources', 'urlDuplicates', 'searchableBlanks', 'priceMissing', 'foreignLocaleColumns', 'foreignLocaleRows', 'shapeWarnings', 'orderViolations',
        'headerOnlySources', 'glossaryMultiLocale', 'duplicateEntitySources', 'storeDirMismatch', 'searchAttributeOverlap', 'categoryTemplateUnknown', 'rootCategoryNotInMenu', 'approvalStatusMissing', 'priceNonInteger', 'embeddedPathsMissing', 'undeclaredStore', 'lineEndings', 'discountShipmentByName', 'categoryStoreIncomplete'];
}

/** A source under `vendor/` is package-owned data: checked for store references only. */
function validate_is_vendor_source(string $file, string $baseDir): bool
{
    $rel = validate_relative_path($file, $baseDir);

    return str_starts_with($rel, 'vendor/') || str_contains($file, '/vendor/');
}

/** Path relative to $baseDir when it is under it, else unchanged. */
function validate_relative_path(string $file, string $baseDir): string
{
    $base = rtrim($baseDir, '/') . '/';
    $real = realpath($file);
    $realBase = realpath($baseDir);
    if ($real !== false && $realBase !== false && str_starts_with($real, rtrim($realBase, '/') . '/')) {
        return substr($real, strlen(rtrim($realBase, '/')) + 1);
    }

    return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
}

/**
 * `data/import/...` paths embedded in CSV cell values (workflow.csv `definition`, SSP file
 * assets).
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @return array<string, list<string>> column → unique paths
 */
function validate_embedded_paths(array $data): array
{
    $out = [];
    foreach ($data['rows'] as $row) {
        foreach ($row as $column => $cell) {
            if ($cell === '') {
                continue;
            }
            if (str_contains($cell, 'data/import/') && preg_match_all('~(?<![\w/])data/import/[^\s"\',;|<>]+~', $cell, $m) > 0) {
                foreach ($m[0] as $p) {
                    $p = rtrim($p, '.)');
                    $out[$column][$p] = true;
                }
                continue;
            }
            $trim = trim($cell);
            if (preg_match('~^[A-Za-z0-9_][A-Za-z0-9_./ -]*\.(pdf|xml|json|csv|docx?|xlsx?|zip)$~i', $trim) === 1 && !str_starts_with($trim, 'http')) {
                $out[$column][$trim] = true;
            }
        }
    }

    return array_map(static fn (array $set): array => array_keys($set), $out);
}

/**
 * Resolve an embedded path against every root it may be relative to: absolute, the project base,
 * the import root (`data/import/`), and the CSV's own directory.
 */
function validate_resolve_embedded(string $path, string $baseDir, string $csvDir): ?string
{
    $base = rtrim($baseDir, '/');
    $candidates = $path[0] === '/' ? [$path] : [$base . '/' . $path, $base . '/data/import/' . $path, rtrim($csvDir, '/') . '/' . $path];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return realpath($c) ?: $c;
        }
    }

    return null;
}

/**
 * Filter a preflight result against a baseline — a previous preflight or gate JSON report.
 *
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function validate_preflight_apply_baseline(array $result, string $baselinePath, string $baseDir = ''): array
{
    $raw = @file_get_contents($baselinePath);
    $baseline = $raw === false ? null : json_decode($raw, true);
    if (!is_array($baseline)) {
        throw new RuntimeException("preflight: cannot read baseline '{$baselinePath}' (expected a previous preflight JSON report)");
    }
    $groups = array_merge(validate_preflight_groups(), ['priceNetWarnings']);
    foreach ($groups as $group) {
        $known = [];
        foreach ((array) ($baseline[$group] ?? []) as $finding) {
            $known[validate_finding_signature($finding, $baseDir)] = true;
        }
        if ($known === []) {
            continue;
        }
        $result[$group] = array_values(array_filter(
            (array) ($result[$group] ?? []),
            static fn (mixed $f): bool => !isset($known[validate_finding_signature($f, $baseDir)]),
        ));
    }

    return $result;
}

/**
 * One path field of a finding, reduced to the form both sides can agree on: project-relative, no
 * `./` prefix.
 */
function validate_normalise_finding_path(string $value, string $baseDir): string
{
    $value = preg_replace('~^(\./)+~', '', $value) ?? $value;
    if ($value === '') {
        return $value;
    }
    $base = $baseDir === '' ? '' : (realpath($baseDir) ?: rtrim($baseDir, '/'));
    $candidates = $value[0] === '/' ? [$value] : [$value, ($base === '' ? '' : $base . '/' . $value)];
    foreach ($candidates as $candidate) {
        if ($candidate === '' || !file_exists($candidate)) {
            continue;
        }
        $real = realpath($candidate);
        if ($real === false) {
            continue;
        }
        if ($base !== '' && str_starts_with($real, rtrim($base, '/') . '/')) {
            return substr($real, strlen(rtrim($base, '/')) + 1);
        }

        return $real;
    }
    if ($base !== '' && str_starts_with($value, rtrim($base, '/') . '/')) {
        return substr($value, strlen(rtrim($base, '/')) + 1);
    }

    return $value;
}

/**
 * Stable identity of one finding for baseline matching: the finding minus its volatile count
 * fields, its path fields normalised against $baseDir (relative, `./` stripped — otherwise an
 * absolute finding never matches a relative baseline entry and the baseline suppresses nothing),
 * key-sorted, JSON-encoded.
 */
function validate_finding_signature(mixed $finding, string $baseDir = ''): string
{
    if (is_array($finding)) {
        unset($finding['rows'], $finding['missing'], $finding['blanks']);
        foreach (['file', 'path', 'source', 'manifest'] as $key) {
            if (isset($finding[$key]) && is_string($finding[$key])) {
                $finding[$key] = validate_normalise_finding_path($finding[$key], $baseDir);
            }
        }
        ksort($finding);
    }

    return (string) json_encode($finding);
}

/**
 * Build the set of tax_set_name values defined in the manifest's tax source
 * (for the tax-set check: product tax_set_name ⊆ tax.csv — the shipped demo has a singular/plural
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
 * Per-file generate-mode required-shape checks (C1/C4 + the tax-set check) — shape/entity-specific
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
    // tax-set check: tax_set_name ⊆ the tax source's set.
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
    // C5: product-abstract with no approval-status entity → 0 search docs, invisibly.
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
 * behavioural plumbing silently dropped from a hand-assembled manifest with one
 * mechanical diff instead of a manual comparison.
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
 * Families whose consumer cell is a comma-separated LIST by importer contract —
 * `ProductAbstractWriterStep::getCategoryKeys()` explodes `category_key` on `,`, and a comma list
 * is the only shipped way to put an abstract in a second category.
 *
 * @return array<string,bool>
 */
function validate_manifest_ref_list_families(): array
{
    return ['category_key' => true];
}

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
            $isList = isset(validate_manifest_ref_list_families()[$column]);
            foreach ($data['rows'] as $i => $row) {
                $cell = $row[$column] ?? '';
                if ($cell === '') {
                    continue;
                }
                foreach ($isList ? array_map('trim', explode(',', $cell)) : [$cell] as $value) {
                    if ($value !== '' && !isset($allowed[$value])) {
                        $findings[] = ['family' => $column, 'file' => $entry['file'], 'column' => $column, 'row' => $i + 2, 'value' => $value];
                    }
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
        if ($real === false) {
            continue;
        }
        $referenced[$real] = true;
        if (strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) !== 'csv') {
            continue;
        }
        foreach (validate_embedded_paths(validate_read_csv($entry['file'])) as $paths) {
            foreach ($paths as $path) {
                $realRef = validate_resolve_embedded($path, $baseDir, dirname($entry['file']));
                if ($realRef !== null) {
                    $referenced[$realRef] = true;
                }
            }
        }
    }

    $onDisk = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        foreach (validate_discover_import_files($root) as $file) {
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
 * Every import ASSET under a root — all file types, not only CSV.
 *
 * @return list<string>
 */
function validate_discover_import_files(string $dir): array
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $files = [];
    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $name = $entry->getFilename();
        $ext = strtolower($entry->getExtension());
        if ($name[0] === '.' || $ext === 'yml' || $ext === 'yaml' || preg_match('/^readme/i', $name) === 1) {
            continue;
        }
        $files[] = $entry->getPathname();
    }
    sort($files);

    return $files;
}

/**
 * The active catalogue manifests of a clone: `data/import/local/full_*.yml` under $baseDir (the
 * wizard's `full_<REGION>.yml` naming).
 *
 * @return list<string>
 */
function validate_discover_manifests(string $baseDir, string $subdir = 'data/import/local'): array
{
    $base = rtrim($baseDir, '/');
    $found = glob($base . '/' . trim($subdir, '/') . '/full_*.yml') ?: [];
    $regions = validate_deploy_regions($base . '/deploy.dev.yml');
    if ($regions !== []) {
        $found = array_values(array_filter($found, static function (string $f) use ($regions): bool {
            return preg_match('/full_(.+)\.yml$/', basename($f), $m) === 1 && in_array($m[1], $regions, true);
        }));
    }
    foreach (glob($base . '/config/install/docker.yml') ?: [] as $recipe) {
        $content = @file_get_contents($recipe);
        if ($content === false) {
            continue;
        }
        if (preg_match_all('/data:import\s+--config=([^\s\'"]+)/', $content, $m) > 0) {
            foreach ($m[1] as $path) {
                if (str_contains($path, '$')) {
                    continue;
                }
                $full = $path[0] === '/' ? $path : $base . '/' . $path;
                if (is_file($full)) {
                    $found[] = $full;
                }
            }
        }
    }
    $found = array_values(array_unique(array_map(static fn (string $f): string => realpath($f) ?: $f, $found)));
    sort($found);

    return $found;
}

/**
 * Region keys of a deploy file's top-level `regions:` block (targeted line scan, not a YAML parser
 * — the same approach the manifest reader takes).
 *
 * @return list<string>
 */
function validate_deploy_regions(string $deployFile): array
{
    $content = @file_get_contents($deployFile);
    if ($content === false) {
        return [];
    }
    $regions = [];
    $inBlock = false;
    $indent = null;
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/^regions:\s*$/', $line) === 1) {
            $inBlock = true;
            continue;
        }
        if ($inBlock) {
            if (preg_match('/^\S/', $line) === 1) {
                break;
            }
            if (preg_match('/^( +)([A-Za-z0-9_-]+):\s*$/', $line, $m) === 1) {
                $indent ??= strlen($m[1]);
                if (strlen($m[1]) === $indent) {
                    $regions[] = $m[2];
                }
            }
        }
    }

    return $regions;
}

/**
 * Project locales from the manifest's locale-store sources (`locale_name`, else `locale`).
 *
 * @param list<array{data_entity:string, source:string, file:string, exists:bool}> $entries
 * @return list<string>
 */
function validate_discover_locales(array $entries): array
{
    $locales = [];
    foreach ($entries as $entry) {
        $base = strtolower(basename($entry['file']));
        if (!$entry['exists'] || ($entry['data_entity'] !== 'locale-store' && !str_contains($base, 'locale_store'))) {
            continue;
        }
        $data = validate_read_csv($entry['file']);
        $col = in_array('locale_name', $data['header'], true) ? 'locale_name' : (in_array('locale', $data['header'], true) ? 'locale' : null);
        if ($col === null) {
            continue;
        }
        foreach ($data['rows'] as $row) {
            $v = trim($row[$col] ?? '');
            if ($v !== '') {
                $locales[$v] = true;
            }
        }
    }
    $out = array_keys($locales);
    sort($out);

    return $out;
}

/**
 * Declared stores from the manifests' `store` sources (column `name`).
 *
 * @param list<array{data_entity:string, source:string, file:string, exists:bool}> $entries
 * @return list<string>
 */
function validate_discover_stores(array $entries): array
{
    $stores = [];
    foreach ($entries as $entry) {
        if (!$entry['exists'] || ($entry['data_entity'] !== 'store' && strtolower(basename($entry['file'])) !== 'store.csv')) {
            continue;
        }
        $data = validate_read_csv($entry['file']);
        if (!in_array('name', $data['header'], true)) {
            continue;
        }
        foreach ($data['rows'] as $row) {
            $v = trim($row['name'] ?? '');
            if ($v !== '') {
                $stores[$v] = true;
            }
        }
    }
    $out = array_keys($stores);
    sort($out);

    return $out;
}

/**
 * inventory — every manifest source with its entity, row count and a sample of its free-text
 * values.
 *
 * @return list<array{entity:string, source:string, rows:int|null, columns:int|null, sample:list<string>}>
 */
function validate_inventory(string $ymlPath, string $baseDir): array
{
    $out = [];
    foreach (validate_manifest_entries($ymlPath, $baseDir) as $entry) {
        $row = ['entity' => $entry['data_entity'], 'source' => $entry['source'], 'rows' => null, 'columns' => null, 'sample' => []];
        if ($entry['exists'] && strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) === 'csv') {
            try {
                $data = validate_read_csv($entry['file']);
                $row['rows'] = count($data['rows']);
                $row['columns'] = count($data['header']);
                $row['sample'] = validate_inventory_sample($data);
            } catch (Throwable $e) {
                $row['sample'] = ['UNREADABLE: ' . $e->getMessage()];
            }
        } elseif (!$entry['exists']) {
            $row['sample'] = ['MISSING FILE'];
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * Up to four distinctive text cells from the first rows — prose columns first
 * (name/title/description/translation/value/label/text/content), then anything that is neither
 * numeric, a key, nor a URL.
 *
 * @param array{header: list<string>, rows: list<array<string,string>>} $data
 * @return list<string>
 */
function validate_inventory_sample(array $data, int $max = 4): array
{
    $sample = [];
    $prose = [];
    $other = [];
    foreach ($data['header'] as $col) {
        if (preg_match('/(name|title|description|translation|value|label|text|content|heading)/i', $col) === 1 && preg_match('/(_key|sku|code|url|path|iso)/i', $col) !== 1) {
            $prose[] = $col;
        } else {
            $other[] = $col;
        }
    }
    foreach ([$prose, $other] as $cols) {
        foreach (array_slice($data['rows'], 0, 3) as $row) {
            foreach ($cols as $col) {
                $v = trim((string) ($row[$col] ?? ''));
                if ($v === '' || is_numeric($v) || str_starts_with($v, 'http') || mb_strlen($v) < 4 || preg_match('/^[\w.\-]+$/', $v) === 1) {
                    continue;
                }
                $v = preg_replace('/\s+/', ' ', strip_tags($v)) ?? $v;
                $v = mb_substr($v, 0, 60);
                if (!in_array($v, $sample, true)) {
                    $sample[] = $v;
                }
                if (count($sample) >= $max) {
                    return $sample;
                }
            }
        }
    }

    return $sample;
}

/** @param list<array<string,mixed>> $rows */
function validate_report_inventory(array $rows, bool $plain): int
{
    if (validate_quiet()) {
        return 0;
    }
    if ($plain) {
        echo "entity\trows\tsource\tsample\n";
        foreach ($rows as $r) {
            echo $r['entity'] . "\t" . ($r['rows'] ?? '?') . "\t" . $r['source'] . "\t" . implode(' | ', $r['sample']) . "\n";
        }

        return 0;
    }
    echo json_encode(['status' => 'ok', 'check' => 'inventory', 'sourceCount' => count($rows), 'sources' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return 0;
}

/**
 * sku-coverage — the complement of `refs`: not "does this reference resolve" but "is this SKU
 * present in every file its peers are in".
 *
 * @return array{findings: list<array{family:string, sku:string, missingFrom:list<string>, presentIn:int}>, families: array<string, array{skus:int, coreFiles:list<string>}>}
 */
function validate_sku_coverage(string $ymlPath, string $baseDir, float $coreRatio = 0.8): array
{
    $entries = validate_manifest_entries($ymlPath, $baseDir);
    $abstract = [];
    $concrete = [];
    $perFile = [];
    foreach ($entries as $entry) {
        if (!$entry['exists'] || strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) !== 'csv' || validate_is_vendor_source($entry['file'], $baseDir)) {
            continue;
        }
        $data = validate_read_csv($entry['file']);
        foreach ($data['header'] as $col) {
            if (preg_match('/^(abstract_sku|concrete_sku|sku|product_sku|product_abstract_sku|product_concrete_sku)$/', $col) !== 1) {
                continue;
            }
            foreach ($data['rows'] as $row) {
                $v = trim($row[$col] ?? '');
                if ($v === '') {
                    continue;
                }
                $perFile[$entry['file']][$col][$v] = true;
                if ($entry['data_entity'] === 'product-abstract' && $col === 'abstract_sku') {
                    $abstract[$v] = true;
                }
                if ($entry['data_entity'] === 'product-concrete' && $col === 'concrete_sku') {
                    $concrete[$v] = true;
                }
            }
        }
    }
    $families = ['abstract' => $abstract, 'concrete' => $concrete];
    $result = ['findings' => [], 'families' => []];
    foreach ($families as $family => $skus) {
        if ($skus === []) {
            continue;
        }
        $membership = [];
        foreach ($perFile as $file => $cols) {
            foreach ($cols as $col => $values) {
                foreach ($values as $v => $_) {
                    if (isset($skus[$v])) {
                        $membership[$file][$v] = true;
                    }
                }
            }
        }
        $total = count($skus);
        $core = [];
        foreach ($membership as $file => $present) {
            if (count($present) >= $coreRatio * $total && count($present) < $total) {
                $core[] = $file;
            } elseif (count($present) === $total) {
                $core[] = $file;
            }
        }
        sort($core);
        $result['families'][$family] = ['skus' => $total, 'coreFiles' => array_map('basename', $core)];
        foreach (array_keys($skus) as $sku) {
            $missing = [];
            $present = 0;
            foreach ($core as $file) {
                if (isset($membership[$file][$sku])) {
                    $present++;
                } else {
                    $missing[] = basename($file);
                }
            }
            if ($missing !== []) {
                $result['findings'][] = ['family' => $family, 'sku' => (string) $sku, 'missingFrom' => $missing, 'presentIn' => $present];
            }
        }
    }

    return $result;
}

/** @param array{findings: list<array<string,mixed>>, families: array<string,mixed>} $result */
function validate_report_sku_coverage(array $result, int $cap = 200): int
{
    $total = count($result['findings']);
    $exit = $total === 0 ? 0 : 1;
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode([
        'status' => $exit === 0 ? 'ok' : 'warning',
        'check' => 'sku-coverage',
        'findingCount' => $total,
        'findings' => array_slice($result['findings'], 0, $cap),
        'families' => $result['families'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

/**
 * Gate checks whose findings WARN (exit 1) instead of gating (exit 2).
 *
 * @return array<string,bool>
 */
function validate_gate_warning_checks(): array
{
    return ['store-coverage' => true, 'locale-coverage' => true, 'attribute-glossary' => true, 'bundle-stock' => true, 'tree-scope' => true];
}

/**
 * gate — one command, one verdict over the active manifests.
 *
 * @param list<string> $manifests
 * @param list<string> $locales empty → discovered from the locale-store sources
 * @param list<string> $roots   empty → `<base>/data/import`
 * @return array<string,mixed>
 */
function validate_gate(array $manifests, string $baseDir, array $locales, ?string $baselinePath, array $roots, bool $strict): array
{
    if ($manifests === []) {
        $manifests = validate_discover_manifests($baseDir);
    }
    if ($roots === []) {
        $roots = [rtrim($baseDir, '/') . '/data/import'];
    }
    $checks = [
        'paths' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'preflight' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'manifest-refs' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'threshold-glossary' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'orphan-files' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'sku-coverage' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'duplicate-keys' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'cms-block-store' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'store-coverage' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'locale-coverage' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'attribute-glossary' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'bundle-stock' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
        'tree-scope' => ['status' => 'ok', 'findingCount' => 0, 'findings' => []],
    ];
    $errors = [];
    if ($manifests === []) {
        $errors[] = 'gate: no manifest given and none found under data/import/local/full_*.yml';
    }
    $allEntries = [];
    foreach ($manifests as $manifest) {
        try {
            $allEntries = array_merge($allEntries, validate_manifest_entries($manifest, $baseDir));
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    if ($locales === []) {
        $locales = validate_discover_locales($allEntries);
    }
    $knownStores = validate_discover_stores($allEntries);
    $gateBaseline = null;
    if ($baselinePath !== null) {
        $raw = @file_get_contents($baselinePath);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            $errors[] = "gate: cannot read baseline '{$baselinePath}' (expected a saved gate or preflight JSON report) — recapture it with `gate --save {$baselinePath}` on a clone whose data you trust";
        } elseif (($decoded['check'] ?? '') === 'gate') {
            if (($decoded['errors'] ?? []) !== [] || ($decoded['baselineSafe'] ?? true) === false) {
                $errors[] = "gate: baseline '{$baselinePath}' is CORRUPT — it recorded errors instead of findings (" . implode('; ', array_map('strval', (array) ($decoded['errors'] ?? ['baselineSafe=false']))) . "). Recapture with `gate --save {$baselinePath}`; never `>`.";
            } else {
                $gateBaseline = $decoded;
            }
        }
    }
    $cap = 500;
    foreach ($manifests as $manifest) {
        try {
            foreach (validate_paths($manifest, $baseDir) as $f) {
                $checks['paths']['findings'][] = ['manifest' => $manifest] + $f;
            }
            $pf = validate_preflight($manifest, $baseDir, $locales, $knownStores);
            if ($baselinePath !== null && !$gateBaseline) {
                $pf = validate_preflight_apply_baseline($pf, $baselinePath, $baseDir);
            }
            foreach (validate_preflight_groups() as $group) {
                foreach ((array) ($pf[$group] ?? []) as $f) {
                    $checks['preflight']['findings'][] = ['group' => $group] + (is_array($f) ? $f : ['value' => $f]);
                }
            }
            $checks['preflight']['warningCount'] = ($checks['preflight']['warningCount'] ?? 0) + count($pf['priceNetWarnings']);
            foreach (validate_manifest_refs($manifest, $baseDir)['findings'] as $f) {
                $checks['manifest-refs']['findings'][] = $f;
            }
            foreach (validate_sku_coverage($manifest, $baseDir)['findings'] as $f) {
                $checks['sku-coverage']['findings'][] = $f;
            }
            $dup = validate_duplicate_keys($manifest, $baseDir);
            foreach ($dup['findings'] as $f) {
                $checks['duplicate-keys']['findings'][] = $f;
            }
            foreach ($dup['identical'] as $f) {
                $checks['duplicate-keys']['identicalDuplicates'][] = $f;
            }
            foreach ($dup['additive'] as $f) {
                $checks['duplicate-keys']['additiveDuplicates'][] = $f;
            }
            foreach (validate_cms_block_store($manifest, $baseDir, $knownStores)['findings'] as $f) {
                $checks['cms-block-store']['findings'][] = $f;
            }
            foreach (validate_store_coverage($manifest, $baseDir, $knownStores)['findings'] as $f) {
                $checks['store-coverage']['findings'][] = $f;
            }
            foreach (validate_locale_coverage($manifest, $baseDir, $locales)['findings'] as $f) {
                $checks['locale-coverage']['findings'][] = $f;
            }
            foreach (validate_attribute_glossary($manifest, $baseDir, $locales)['findings'] as $f) {
                $checks['attribute-glossary']['findings'][] = $f;
            }
            foreach (validate_bundle_stock($manifest, $baseDir)['findings'] as $f) {
                $checks['bundle-stock']['findings'][] = $f;
            }
            if ($locales !== []) {
                $tg = validate_threshold_glossary($manifest, $baseDir, $locales);
                foreach ($tg['findings'] as $f) {
                    $checks['threshold-glossary']['findings'][] = $f;
                }
                $checks['threshold-glossary']['thresholdRows'] = ($checks['threshold-glossary']['thresholdRows'] ?? 0) + $tg['thresholdRows'];
            } else {
                $checks['threshold-glossary']['skipped'] = 'no project locales given or discoverable (locale-store source)';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    try {
        $referencedAll = [];
        $onDiskAll = [];
        foreach ($manifests as $manifest) {
            $of = validate_orphan_files($manifest, $roots, $baseDir);
            $onDiskAll = $of['onDisk'];
            $orphans = [];
            foreach ($of['findings'] as $f) {
                $orphans[$f['file']] = true;
            }
            $referencedAll[] = $orphans;
        }
        $orphanEverywhere = $referencedAll === [] ? [] : array_intersect_key(...$referencedAll);
        foreach (array_keys($orphanEverywhere) as $file) {
            $checks['orphan-files']['findings'][] = ['file' => $file];
        }
        $checks['orphan-files']['onDisk'] = $onDiskAll;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    try {
        $ts = validate_tree_scope($manifests, $baseDir);
        foreach ($ts['findings'] as $f) {
            $checks['tree-scope']['findings'][] = $f;
        }
        $checks['tree-scope']['dirs'] = $ts['dirs'];
        $checks['tree-scope']['expected'] = $ts['expected'];
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    if ($gateBaseline !== null) {
        foreach ($checks as $name => &$check) {
            $known = [];
            foreach ((array) ($gateBaseline['checks'][$name]['findings'] ?? []) as $f) {
                $known[validate_finding_signature($f, $baseDir)] = true;
            }
            if ($known === []) {
                continue;
            }
            $before = count($check['findings']);
            $check['findings'] = array_values(array_filter($check['findings'], static fn (mixed $f): bool => !isset($known[validate_finding_signature($f, $baseDir)])));
            $check['baselined'] = $before - count($check['findings']);
        }
        unset($check);
    }

    $gating = 0;
    $warnings = 0;
    $summary = [];
    foreach ($checks as $name => &$check) {
        $n = count($check['findings']);
        $check['findingCount'] = $n;
        $check['findings'] = array_slice($check['findings'], 0, $cap);
        if ($n === 0) {
            $check['status'] = 'ok';
            $summary[] = "{$name}: ok";
            continue;
        }
        if ($name === 'orphan-files' && !$strict) {
            $check['status'] = 'warning';
            $warnings += $n;
            $summary[] = "{$name}: {$n} unreferenced file(s) — warning (gates with --strict)";
            continue;
        }
        if ($name === 'sku-coverage') {
            $check['status'] = 'warning';
            $warnings += $n;
            $summary[] = "{$name}: {$n} SKU(s) missing from a file their peers are in — warning";
            continue;
        }
        if (isset(validate_gate_warning_checks()[$name])) {
            $check['status'] = 'warning';
            $warnings += $n;
            $summary[] = "{$name}: {$n} finding(s) — warning";
            continue;
        }
        $check['status'] = 'error';
        $gating += $n;
        $summary[] = "{$name}: {$n} finding(s)";
    }
    unset($check);
    if ($errors !== []) {
        $gating += count($errors);
    }

    return [
        'status' => $gating > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'ok'),
        'check' => 'gate',
        'baselineSafe' => $errors === [],
        'gatingCount' => $gating,
        'warningCount' => $warnings,
        'manifests' => $manifests,
        'locales' => $locales,
        'stores' => $knownStores,
        'baseline' => $baselinePath,
        'strict' => $strict,
        'roots' => $roots,
        'summary' => $summary,
        'checks' => $checks,
        'errors' => $errors,
    ];
}

/**
 * @param array<string,mixed> $result
 * @param string|null $savePath `--save`: write the report here after the run — the safe way to
 *                              capture a baseline (a shell redirect truncates the target first)
 */
function validate_report_gate(array $result, ?string $savePath = null): int
{
    $exit = $result['status'] === 'error' ? 2 : ($result['status'] === 'warning' ? 1 : 0);
    if ($savePath !== null) {
        if (($result['baselineSafe'] ?? false) !== true) {
            $result['errors'][] = "gate --save refused: this report could not run its checks (see errors) and must not become a baseline";
            $exit = 2;
        } else {
            $dir = dirname($savePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            if (@file_put_contents($savePath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) {
                $result['errors'][] = "gate --save: cannot write {$savePath}";
                $exit = 2;
            } else {
                $result['saved'] = $savePath;
            }
        }
    }
    if (validate_quiet()) {
        return $exit;
    }
    if ($savePath !== null && isset($result['saved'])) {
        echo json_encode(['status' => $result['status'], 'check' => 'gate', 'saved' => $result['saved'] ?? null, 'gatingCount' => $result['gatingCount'], 'warningCount' => $result['warningCount'], 'summary' => $result['summary'], 'manifests' => $result['manifests'], 'locales' => $result['locales'], 'stores' => $result['stores']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return $exit;
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

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

/**
 * The manifest's own CSV sources, read once, with the entity that imports them and the GROUP they
 * belong to.
 *
 * @return list<array{entity:string, group:string, file:string, data:array{header:list<string>, rows:list<array<string,string>>}}>
 */
function validate_project_sources(string $ymlPath, string $baseDir): array
{
    $out = [];
    foreach (validate_manifest_entries($ymlPath, $baseDir) as $entry) {
        if (!$entry['exists'] || strtolower(pathinfo($entry['file'], PATHINFO_EXTENSION)) !== 'csv') {
            continue;
        }
        if (validate_is_vendor_source($entry['file'], $baseDir)) {
            continue;
        }
        $out[] = [
            'entity' => $entry['data_entity'],
            'group' => $entry['data_entity'] !== '' ? $entry['data_entity'] : strtolower(basename($entry['file'])),
            'file' => $entry['file'],
            'data' => validate_read_csv($entry['file']),
        ];
    }

    return $out;
}

/**
 * Shared engine for store-coverage and locale-coverage: per entity group, count the rows each
 * known value owns across the group's columns, and report a value that has ZERO rows where another
 * value has some.
 *
 * @param list<array{entity:string, group:string, file:string, data:array{header:list<string>, rows:list<array<string,string>>}}> $sources
 * @param array<string,bool> $columns column name → is it a comma-separated list
 * @param list<string> $known
 * @return list<array{group:string, value:string, files:list<string>, present:array<string,int>}>
 */
function validate_value_coverage(array $sources, array $columns, array $known): array
{
    if ($known === []) {
        return [];
    }
    $groups = [];
    foreach ($sources as $source) {
        $cols = array_values(array_filter(array_keys($columns), static fn (string $c): bool => in_array($c, $source['data']['header'], true)));
        if ($cols === []) {
            continue;
        }
        $groups[$source['group']]['files'][] = $source['file'];
        $groups[$source['group']]['counts'] ??= array_fill_keys($known, 0);
        foreach ($source['data']['rows'] as $row) {
            foreach ($cols as $col) {
                $cell = trim($row[$col] ?? '');
                if ($cell === '') {
                    continue;
                }
                foreach ($columns[$col] ? array_map('trim', explode(',', $cell)) : [$cell] as $value) {
                    if ($value !== '' && isset($groups[$source['group']]['counts'][$value])) {
                        $groups[$source['group']]['counts'][$value]++;
                    }
                }
            }
        }
    }
    $findings = [];
    foreach ($groups as $group => $info) {
        $counts = $info['counts'];
        if (array_sum($counts) === 0) {
            continue;
        }
        foreach ($counts as $value => $n) {
            if ($n === 0) {
                $findings[] = [
                    'group' => (string) $group,
                    'value' => (string) $value,
                    'files' => array_map('basename', $info['files']),
                    'present' => array_filter($counts, static fn (int $c): bool => $c > 0),
                ];
            }
        }
    }

    return $findings;
}

/**
 * store-coverage — a declared store with zero rows in an entity other stores have rows in.
 *
 * @param list<string> $stores empty → nothing to check (no store definition in reach)
 * @return array{findings: list<array{group:string, value:string, files:list<string>, present:array<string,int>}>, stores:list<string>, sources:int}
 */
function validate_store_coverage(string $ymlPath, string $baseDir, array $stores): array
{
    $sources = validate_project_sources($ymlPath, $baseDir);
    $columns = ['store' => false, 'store_name' => false, 'included_store_names' => true];

    return [
        'findings' => validate_value_coverage($sources, $columns, $stores),
        'stores' => array_values($stores),
        'sources' => count($sources),
    ];
}

/**
 * locale-coverage — the same asymmetry for locales.
 *
 * @param list<string> $locales
 * @return array{findings: list<array{group:string, value:string, files:list<string>, present:array<string,int>}>, locales:list<string>, sources:int}
 */
function validate_locale_coverage(string $ymlPath, string $baseDir, array $locales): array
{
    $sources = validate_project_sources($ymlPath, $baseDir);
    $columns = ['locale' => false, 'locale_name' => false];

    return [
        'findings' => validate_value_coverage($sources, $columns, $locales),
        'locales' => array_values($locales),
        'sources' => count($sources),
    ];
}

/**
 * Column bases whose two locale cells are IDENTICAL BY DESIGN — a URL slug, an image path, a
 * SKU, an attribute KEY (`attribute_key_1.de_DE` is a key, not prose).
 */
function validate_translation_skip_base(string $base): bool
{
    $skip = [
        'url', 'urls', 'image', 'images', 'imageurl', 'image_url', 'click_url', 'sku', 'skus',
        'key', 'keys', 'code', 'codes', 'reference', 'references', 'price', 'prices',
        'css', 'class', 'path', 'template', 'slug', 'id', 'uuid', 'type',
    ];
    foreach (preg_split('/[._-]+/', strtolower($base)) ?: [] as $token) {
        if (in_array($token, $skip, true) || in_array(rtrim($token, '0123456789'), $skip, true)) {
            return true;
        }
    }

    return false;
}

/**
 * The part of a cell a translator would actually change: Twig expressions and HTML tags removed.
 */
function validate_translatable_text(string $cell): string
{
    $stripped = preg_replace('~<(style|script)\b[^>]*>.*?</\1>~su', ' ', $cell) ?? $cell;
    $stripped = preg_replace('/\{[{%].*?[%}]\}/su', ' ', $stripped) ?? $stripped;

    return trim(strip_tags($stripped));
}

/**
 * translation-coverage — for every `<base>.<from>` column that has a `<base>.<to>` twin, the
 * rows whose two cells are non-empty, identical and carry at least three letters.
 *
 * @param list<string> $files
 * @return array{findings: list<array{file:string, base:string, identical:int, compared:int, sample:list<string>}>, filesChecked:int, columnsCompared:int}
 */
function validate_translation_coverage(array $files, string $from, string $to): array
{
    $findings = [];
    $columnsCompared = 0;
    foreach ($files as $file) {
        $data = validate_read_csv($file);
        foreach ($data['header'] as $column) {
            if (!str_ends_with($column, '.' . $from)) {
                continue;
            }
            $base = substr($column, 0, -strlen('.' . $from));
            $twin = $base . '.' . $to;
            if ($base === '' || !in_array($twin, $data['header'], true) || validate_translation_skip_base($base)) {
                continue;
            }
            $columnsCompared++;
            $identical = 0;
            $compared = 0;
            $sample = [];
            foreach ($data['rows'] as $row) {
                $a = trim($row[$column] ?? '');
                $b = trim($row[$twin] ?? '');
                if ($a === '' || $b === '') {
                    continue;
                }
                $compared++;
                $text = validate_translatable_text($a);
                if ($a !== $b || preg_match_all('/\p{L}/u', $text) < 3) {
                    continue;
                }
                $identical++;
                if (count($sample) < 3) {
                    $sample[] = mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 60);
                }
            }
            if ($identical > 0) {
                $findings[] = ['file' => $file, 'base' => $base, 'identical' => $identical, 'compared' => $compared, 'sample' => $sample];
            }
        }
    }

    return ['findings' => $findings, 'filesChecked' => count($files), 'columnsCompared' => $columnsCompared];
}

/**
 * Primary key per file, keyed by basename (the dotted variants of one file — `glossary.csv`,
 * `glossary.de_DE.csv` — share the stem, so the lookup uses the stem).
 *
 * @return array<string, list<string>>
 */
function validate_duplicate_key_table(): array
{
    return [
        'company' => ['key'],
        'company_role' => ['company_role_key'],
        'company_business_unit' => ['business_unit_key'],
        'company_user' => ['company_user_key'],
        'product_abstract' => ['abstract_sku'],
        'product_concrete' => ['concrete_sku'],
        'product_stock' => ['concrete_sku', 'name'],
        'cms_block' => ['block_key'],
        'cms_slot' => ['slot_key'],
        'cms_page' => ['page_key'],
        'category' => ['category_key'],
        'glossary' => ['key', 'locale'],
        'customer' => ['customer_reference'],
        'merchant' => ['merchant_reference'],
        'store' => ['name'],
    ];
}

/**
 * The key columns of one file: the table, else `<stem>_key` when the file HAS that column
 * (`product_list.csv` → `product_list_key`).
 *
 * @param list<string> $header
 * @return list<string> empty → this file has no derivable primary key; skip it
 */
function validate_duplicate_key_columns(string $file, array $header): array
{
    $stem = strtolower(explode('.', basename($file))[0]);
    $columns = validate_duplicate_key_table()[$stem] ?? [$stem . '_key'];
    foreach ($columns as $column) {
        if (!in_array($column, $header, true)) {
            return [];
        }
    }

    return $columns;
}

/**
 * duplicate-keys — the same primary key twice in one file.
 *
 * @return array{findings: list<array{file:string, key:string, value:string, rows:int, differing:list<string>}>, additive: list<array{file:string, key:string, duplicates:int, sample:list<string>}>, identical: list<array{file:string, key:string, duplicates:int, sample:list<string>}>, filesChecked:int}
 */
function validate_duplicate_keys(string $ymlPath, string $baseDir): array
{
    $findings = [];
    $additive = [];
    $identical = [];
    $checked = 0;
    foreach (validate_project_sources($ymlPath, $baseDir) as $source) {
        $columns = validate_duplicate_key_columns($source['file'], $source['data']['header']);
        if ($columns === []) {
            continue;
        }
        $checked++;
        $seen = [];
        foreach ($source['data']['rows'] as $row) {
            $key = implode('|', array_map(static fn (string $c): string => trim($row[$c] ?? ''), $columns));
            if (trim($key, '|') === '') {
                continue;
            }
            $seen[$key][] = $row;
        }
        $identicalKeys = [];
        $additiveKeys = [];
        foreach ($seen as $value => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $differing = [];
            $contradicting = [];
            foreach ($source['data']['header'] as $column) {
                $values = array_unique(array_map(static fn (array $r): string => (string) ($r[$column] ?? ''), $rows));
                if (count($values) < 2) {
                    continue;
                }
                $differing[] = $column;
                if (count(array_filter($values, static fn (string $v): bool => trim($v) !== '')) > 1) {
                    $contradicting[] = $column;
                }
            }
            if ($differing === []) {
                $identicalKeys[] = (string) $value;
                continue;
            }
            if ($contradicting === []) {
                $additiveKeys[] = (string) $value;
                continue;
            }
            $findings[] = [
                'file' => $source['file'],
                'key' => implode('+', $columns),
                'value' => (string) $value,
                'rows' => count($rows),
                'differing' => $contradicting,
            ];
        }
        if ($additiveKeys !== []) {
            $additive[] = ['file' => $source['file'], 'key' => implode('+', $columns), 'duplicates' => count($additiveKeys), 'sample' => array_slice($additiveKeys, 0, 3)];
        }
        if ($identicalKeys !== []) {
            $identical[] = ['file' => $source['file'], 'key' => implode('+', $columns), 'duplicates' => count($identicalKeys), 'sample' => array_slice($identicalKeys, 0, 3)];
        }
    }

    return ['findings' => $findings, 'additive' => $additive, 'identical' => $identical, 'filesChecked' => $checked];
}

/** Is $twin the per-store sibling of $key — `blck-9` and `blck-9-de`? */
function validate_cms_block_twin(string $key, string $twin): bool
{
    foreach ([[$key, $twin], [$twin, $key]] as [$short, $long]) {
        if ($short !== $long && str_starts_with($long, $short) && in_array($long[strlen($short)] ?? '', ['-', '_'], true)) {
            return true;
        }
    }

    return false;
}

/**
 * cms-block-store — an active `block_key` with no `cms_block_store` row is imported, is valid,
 * and renders nothing: block visibility is per store and the default is nowhere.
 *
 * @param list<string> $stores
 * @return array{findings: list<array{kind:string, block:string, store:string, file:string}>, twins: list<array{block:string, store:string, coveredBy:string}>, blocks:int, activeBlocks:int}
 */
function validate_cms_block_store(string $ymlPath, string $baseDir, array $stores): array
{
    $blocks = [];
    $assigned = [];
    $slotRefs = [];
    $blockFile = '';
    $storeFile = '';
    foreach (validate_project_sources($ymlPath, $baseDir) as $source) {
        $stem = strtolower(explode('.', basename($source['file']))[0]);
        $header = $source['data']['header'];
        if (($source['entity'] === 'cms-block' || $stem === 'cms_block') && in_array('block_key', $header, true)) {
            $blockFile = $source['file'];
            foreach ($source['data']['rows'] as $row) {
                $key = trim($row['block_key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $active = strtolower(trim($row['active'] ?? '1'));
                $blocks[$key] = ($blocks[$key] ?? false) || !in_array($active, ['', '0', 'false', 'no'], true);
            }
        } elseif (($source['entity'] === 'cms-block-store' || $stem === 'cms_block_store') && in_array('block_key', $header, true)) {
            $storeFile = $source['file'];
            $col = in_array('store_name', $header, true) ? 'store_name' : 'store';
            foreach ($source['data']['rows'] as $row) {
                $key = trim($row['block_key'] ?? '');
                $store = trim($row[$col] ?? '');
                if ($key !== '' && $store !== '') {
                    $assigned[$key][$store] = true;
                }
            }
        } elseif (($source['entity'] === 'cms-slot-block' || $stem === 'cms_slot_block') && in_array('block_key', $header, true)) {
            foreach ($source['data']['rows'] as $row) {
                $key = trim($row['block_key'] ?? '');
                if ($key !== '') {
                    $slotRefs[$key] = $source['file'];
                }
            }
        }
    }
    $findings = [];
    $twins = [];
    if ($blocks === []) {
        return ['findings' => [], 'twins' => [], 'blocks' => 0, 'activeBlocks' => 0];
    }
    $active = 0;
    foreach ($blocks as $key => $isActive) {
        if (!$isActive) {
            continue;
        }
        $active++;
        if (!isset($assigned[$key])) {
            $findings[] = ['kind' => 'noStoreRow', 'block' => (string) $key, 'store' => '', 'file' => $storeFile !== '' ? $storeFile : $blockFile];
            continue;
        }
        foreach ($stores as $store) {
            if (isset($assigned[$key][$store])) {
                continue;
            }
            $coveredBy = '';
            foreach (array_keys($assigned) as $other) {
                if (isset($assigned[$other][$store]) && validate_cms_block_twin((string) $key, (string) $other)) {
                    $coveredBy = (string) $other;
                    break;
                }
            }
            if ($coveredBy !== '') {
                $twins[] = ['block' => (string) $key, 'store' => $store, 'coveredBy' => $coveredBy];
                continue;
            }
            $findings[] = ['kind' => 'storeMissing', 'block' => (string) $key, 'store' => $store, 'file' => $storeFile];
        }
    }
    foreach ($slotRefs as $key => $file) {
        if (!isset($blocks[$key])) {
            $findings[] = ['kind' => 'unknownBlock', 'block' => (string) $key, 'store' => '', 'file' => $file];
        }
    }

    return ['findings' => $findings, 'twins' => $twins, 'blocks' => count($blocks), 'activeBlocks' => $active];
}

/**
 * attribute-glossary — every project attribute key needs a `product.attribute.<key>` glossary
 * row in every locale, or the PDP prints the raw key.
 *
 * @param list<string> $locales
 * @return array{findings: list<array{key:string, locale:string, glossaryKey:string}>, attributeKeys:int, glossaryRows:int}
 */
function validate_attribute_glossary(string $ymlPath, string $baseDir, array $locales): array
{
    $keys = [];
    $glossary = [];
    $glossaryRows = 0;
    foreach (validate_project_sources($ymlPath, $baseDir) as $source) {
        $stem = strtolower(explode('.', basename($source['file']))[0]);
        $header = $source['data']['header'];
        if ($source['entity'] === 'glossary' || $stem === 'glossary') {
            foreach ($source['data']['rows'] as $row) {
                $glossaryRows++;
                $key = trim($row['key'] ?? '');
                $locale = trim($row['locale'] ?? '');
                if ($key !== '') {
                    $glossary[$key][$locale] = true;
                }
            }
            continue;
        }
        $column = null;
        if ($source['entity'] === 'product-attribute-key' || $stem === 'product_attribute_key') {
            $column = in_array('attribute_key', $header, true) ? 'attribute_key' : 'key';
        } elseif ($source['entity'] === 'product-management-attribute' || $stem === 'product_management_attribute') {
            $column = in_array('key', $header, true) ? 'key' : 'attribute_key';
        }
        if ($column === null || !in_array($column, $header, true)) {
            continue;
        }
        foreach ($source['data']['rows'] as $row) {
            $key = trim($row[$column] ?? '');
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
    }
    $findings = [];
    if ($glossary === [] || $locales === []) {
        return ['findings' => [], 'attributeKeys' => count($keys), 'glossaryRows' => $glossaryRows];
    }
    foreach (array_keys($keys) as $key) {
        $glossaryKey = 'product.attribute.' . $key;
        foreach ($locales as $locale) {
            if (!isset($glossary[$glossaryKey][$locale])) {
                $findings[] = ['key' => (string) $key, 'locale' => $locale, 'glossaryKey' => $glossaryKey];
            }
        }
    }

    return ['findings' => $findings, 'attributeKeys' => count($keys), 'glossaryRows' => $glossaryRows];
}

/**
 * bundle-stock — a configurable bundle slot offers the concretes of its product list; one with
 * zero stock is offered and cannot be added.
 *
 * @return array{findings: list<array{list:string, sku:string, reason:string}>, slots:int, concretes:int}
 */
function validate_bundle_stock(string $ymlPath, string $baseDir): array
{
    $slotLists = [];
    $listToConcrete = [];
    $stock = [];
    $stockFiles = 0;
    foreach (validate_project_sources($ymlPath, $baseDir) as $source) {
        $stem = strtolower(explode('.', basename($source['file']))[0]);
        $header = $source['data']['header'];
        if (($source['entity'] === 'configurable-bundle-template-slot' || $stem === 'configurable_bundle_template_slot') && in_array('product_list_key', $header, true)) {
            foreach ($source['data']['rows'] as $row) {
                $key = trim($row['product_list_key'] ?? '');
                if ($key !== '') {
                    $slotLists[$key] = true;
                }
            }
        } elseif ($stem === 'product_list_to_concrete_product' || $source['entity'] === 'product-list-product-concrete' || $source['entity'] === 'product-list-to-concrete-product') {
            foreach ($source['data']['rows'] as $row) {
                $key = trim($row['product_list_key'] ?? '');
                $sku = trim($row['concrete_sku'] ?? '');
                if ($key !== '' && $sku !== '') {
                    $listToConcrete[$key][$sku] = true;
                }
            }
        } elseif (($source['entity'] === 'product-stock' || $stem === 'product_stock') && in_array('concrete_sku', $header, true)) {
            $stockFiles++;
            foreach ($source['data']['rows'] as $row) {
                $sku = trim($row['concrete_sku'] ?? '');
                if ($sku === '') {
                    continue;
                }
                $never = strtolower(trim($row['is_never_out_of_stock'] ?? ''));
                $ok = (float) trim($row['quantity'] ?? '0') > 0 || in_array($never, ['1', 'true', 'yes'], true);
                $stock[$sku] = ($stock[$sku] ?? false) || $ok;
            }
        }
    }
    if ($slotLists === [] || $stockFiles === 0) {
        return ['findings' => [], 'slots' => count($slotLists), 'concretes' => 0];
    }
    $findings = [];
    $concretes = 0;
    foreach (array_keys($slotLists) as $list) {
        foreach (array_keys($listToConcrete[$list] ?? []) as $sku) {
            $concretes++;
            if (!isset($stock[$sku])) {
                $findings[] = ['list' => (string) $list, 'sku' => (string) $sku, 'reason' => 'no product_stock row'];
            } elseif (!$stock[$sku]) {
                $findings[] = ['list' => (string) $list, 'sku' => (string) $sku, 'reason' => 'quantity 0 and not is_never_out_of_stock'];
            }
        }
    }

    return ['findings' => $findings, 'slots' => count($slotLists), 'concretes' => $concretes];
}

/**
 * tree-scope — every project `source:` must live under ONE top-level directory below
 * `data/import/`.
 *
 * @param list<string> $manifests
 * @return array{findings: list<array{manifest:string, source:string, dir:string}>, dirs: array<string,int>, expected:string}
 */
function validate_tree_scope(array $manifests, string $baseDir): array
{
    $seen = [];
    $where = [];
    foreach ($manifests as $manifest) {
        foreach (validate_manifest_entries($manifest, $baseDir) as $entry) {
            if (validate_is_vendor_source($entry['file'], $baseDir)) {
                continue;
            }
            $rel = ltrim(validate_relative_path($entry['file'], $baseDir), '/');
            if (!str_starts_with($rel, 'data/import/')) {
                $dir = 'OUTSIDE data/import/';
            } else {
                $rest = substr($rel, strlen('data/import/'));
                $dir = str_contains($rest, '/') ? explode('/', $rest)[0] : '(data/import root)';
            }
            $seen[$dir] = ($seen[$dir] ?? 0) + 1;
            $where[$dir][] = ['manifest' => basename($manifest), 'source' => $entry['source'], 'dir' => $dir];
        }
    }
    if (count($seen) < 2) {
        return ['findings' => [], 'dirs' => $seen, 'expected' => (string) (array_key_first($seen) ?? '')];
    }
    arsort($seen);
    $expected = (string) array_key_first($seen);
    $findings = [];
    foreach ($where as $dir => $rows) {
        if ($dir === $expected) {
            continue;
        }
        foreach ($rows as $row) {
            $findings[] = $row;
        }
    }

    return ['findings' => $findings, 'dirs' => $seen, 'expected' => $expected];
}

/**
 * Split a needs-list value into items: commas separate items only OUTSIDE brackets and never
 * between two digits (`109,99`), a trailing ` # comment` and one outer `[ ]` are dropped, and
 * parenthesised text inside an item is removed (`jacket (kit 109,99 €)` → `jacket`).
 *
 * @return list<string>
 */
function validate_needs_split(string $value): array
{
    $value = trim((string) preg_replace('~\s+#.*$~u', '', $value));
    if (preg_match('~^\[(.*)\]$~su', $value, $m) === 1) {
        $value = trim($m[1]);
    }
    $items = [];
    $buf = '';
    $depth = 0;
    $chars = preg_split('~~u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($chars as $i => $ch) {
        if ($ch === '(' || $ch === '[' || $ch === '{') {
            $depth++;
        } elseif (($ch === ')' || $ch === ']' || $ch === '}') && $depth > 0) {
            $depth--;
        }
        $betweenDigits = ctype_digit($chars[$i - 1] ?? '') && ctype_digit($chars[$i + 1] ?? '');
        if ($ch === ',' && $depth === 0 && !$betweenDigits) {
            $items[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $items[] = $buf;
    $out = [];
    foreach ($items as $item) {
        do {
            $item = (string) preg_replace('~\([^()]*\)|\[[^\[\]]*\]~u', '', $item, -1, $n);
        } while ($n > 0);
        $item = trim($item, " \t`'\"");
        if ($item !== '') {
            $out[] = $item;
        }
    }

    return $out;
}

/**
 * A needs-list product/category/facet/block value is checked only when it reads as an
 * identifier (a SKU or key: no whitespace, no currency or prose punctuation). A prose value is
 * reported as `skipped`, never as a finding.
 */
function validate_needs_is_identifier(string $item): bool
{
    return preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/+\-]*$~', $item) === 1;
}

/**
 * Parse a demo needs list: `## <ID>` sections with `products:` / `categories:` / `facets:` /
 * `blocks:` / `persona:` / `wording:` lines (synonyms accepted). Each beat also carries
 * `skipped` — values that are not identifiers, so the check cannot look them up.
 *
 * @return array<string, array<string, mixed>>
 */
function validate_parse_needs(string $path): array
{
    $out = [];
    if (!is_file($path)) {
        return $out;
    }
    $id = null;
    $keys = ['persona', 'personas', 'actor', 'actors', 'products', 'skus', 'categories', 'facets', 'attributes', 'blocks', 'wording', 'strings'];
    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        if (preg_match('~^##+\s+([A-Za-z0-9][A-Za-z0-9._-]*)\s*(?:[—:-]\s*(.*))?$~u', trim($line), $m) === 1) {
            $id = $m[1];
            $out[$id] = ['title' => trim($m[2] ?? ''), 'persona' => [], 'products' => [], 'categories' => [], 'facets' => [], 'blocks' => [], 'wording' => [], 'skipped' => []];
            continue;
        }
        if ($id === null || preg_match('~^\s*[-*]?\s*([A-Za-z_]+)\s*:\s*(.+)$~', $line, $m) !== 1) {
            continue;
        }
        $key = strtolower($m[1]);
        if (!in_array($key, $keys, true)) {
            continue;
        }
        $value = trim($m[2]);
        if ($key === 'wording' || $key === 'strings') {
            if (preg_match_all('~["“]([^"”]+)["”]~u', $value, $q) > 0) {
                $out[$id]['wording'] = array_merge($out[$id]['wording'], $q[1]);
            } elseif ($value !== '') {
                $out[$id]['wording'][] = $value;
            }
            continue;
        }
        $target = match ($key) {
            'personas', 'actor', 'actors' => 'persona',
            'skus' => 'products',
            'attributes' => 'facets',
            default => $key,
        };
        if ($target === 'persona') {
            // Only an address is checked; take every address on the line, prose around it or not.
            if (preg_match_all('~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~', $value, $em) > 0) {
                $out[$id]['persona'] = array_merge($out[$id]['persona'], $em[0]);
            } else {
                $out[$id]['persona'] = array_merge($out[$id]['persona'], validate_needs_split($value));
            }
            continue;
        }
        foreach (validate_needs_split($value) as $item) {
            if (validate_needs_is_identifier($item)) {
                $out[$id][$target][] = $item;
            } else {
                $out[$id]['skipped'][] = ['key' => $target, 'value' => $item, 'reason' => 'not an identifier — rewrite the line to SKUs / keys so the check can look it up'];
            }
        }
    }

    return $out;
}

/** Does the data support the demo? */
function validate_demo_needs(string $needsPath, array $manifests, string $baseDir): array
{
    $beats = validate_parse_needs($needsPath);
    if ($beats === []) {
        return ['findings' => [], 'beats' => [], 'errors' => ["no needs found in {$needsPath} — expected `## <ID>` sections with `products:` / `categories:` / `wording:` lines"]];
    }
    $abstract = [];
    $concreteOf = [];
    $concreteSeen = [];
    $stock = [];
    $categories = [];
    $attributeKeys = [];
    $blocks = [];
    $customers = [];
    $text = [];
    foreach ($manifests as $manifest) {
        foreach (validate_project_sources($manifest, $baseDir) as $source) {
            $stem = strtolower(explode('.', basename($source['file']))[0]);
            $header = $source['data']['header'];
            $rows = $source['data']['rows'];
            if ($stem === 'product_abstract') {
                foreach ($rows as $row) {
                    $sku = trim($row['abstract_sku'] ?? '');
                    if ($sku === '') {
                        continue;
                    }
                    $attrs = $abstract[$sku] ?? [];
                    foreach ($header as $col) {
                        if (preg_match('~^attribute_key_(\d+)$~', $col, $m) === 1) {
                            $k = trim($row[$col] ?? '');
                            $v = trim($row['value_' . $m[1]] ?? '');
                            if ($k !== '') {
                                $attrs[$k][$v] = true;
                            }
                        }
                    }
                    $abstract[$sku] = $attrs;
                }
            } elseif ($stem === 'product_concrete') {
                foreach ($rows as $row) {
                    $c = trim($row['concrete_sku'] ?? '');
                    $a = trim($row['abstract_sku'] ?? '');
                    if ($c === '') {
                        continue;
                    }
                    $concreteSeen[$c] = true;
                    if ($a !== '') {
                        $concreteOf[$a][] = $c;
                    }
                    foreach ($header as $col) {
                        if (preg_match('~^attribute_key_(\d+)$~', $col, $m) === 1 && $a !== '') {
                            $k = trim($row[$col] ?? '');
                            $v = trim($row['value_' . $m[1]] ?? '');
                            if ($k !== '') {
                                $abstract[$a][$k][$v] = true;
                            }
                        }
                    }
                }
            } elseif ($stem === 'product_stock') {
                foreach ($rows as $row) {
                    $c = trim($row['concrete_sku'] ?? '');
                    if ($c === '') {
                        continue;
                    }
                    $qty = (int) trim((string) ($row['quantity'] ?? '0'));
                    $never = in_array(strtolower(trim((string) ($row['is_never_out_of_stock'] ?? ''))), ['1', 'true', 'yes'], true);
                    $stock[$c] = ($stock[$c] ?? false) || $qty > 0 || $never;
                }
            } elseif ($stem === 'category') {
                foreach ($rows as $row) {
                    $k = trim($row['category_key'] ?? '');
                    if ($k !== '') {
                        $categories[$k] = true;
                    }
                }
            } elseif ($stem === 'product_attribute_key' || $stem === 'product_management_attribute') {
                foreach ($rows as $row) {
                    $k = trim($row['key'] ?? ($row['attribute_key'] ?? ''));
                    if ($k !== '') {
                        $attributeKeys[$k] = true;
                    }
                }
            } elseif ($stem === 'cms_block') {
                foreach ($rows as $row) {
                    $k = trim($row['block_key'] ?? '');
                    if ($k !== '') {
                        $blocks[$k] = in_array(strtolower(trim((string) ($row['active'] ?? '1'))), ['1', 'true', 'yes'], true);
                    }
                }
            } elseif ($stem === 'customer') {
                foreach ($rows as $row) {
                    $e = strtolower(trim($row['email'] ?? ''));
                    if ($e !== '') {
                        $customers[$e] = true;
                    }
                }
            }
            if (in_array($stem, ['cms_block', 'content_banner', 'glossary', 'category', 'navigation_node', 'product_abstract'], true)) {
                foreach ($rows as $row) {
                    foreach ($row as $cell) {
                        $cell = trim((string) $cell);
                        if ($cell !== '' && strlen($cell) < 400) {
                            $text[] = $cell;
                        }
                    }
                }
            }
            // Checkout wording lives in the delivery/payment entities' own name columns, not in
            // content rows: a brief quoting "DHL Standard" is satisfied by shipment.csv.
            foreach (['shipment' => ['name', 'carrier'], 'shipment_type' => ['name'], 'payment_method' => ['payment_method_name']][$stem] ?? [] as $col) {
                foreach ($rows as $row) {
                    $cell = trim((string) ($row[$col] ?? ''));
                    if ($cell !== '') {
                        $text[] = $cell;
                    }
                }
            }
        }
    }
    $haystack = "\n" . implode("\n", $text) . "\n";
    $findings = [];
    $add = static function (string $id, string $kind, string $what, string $detail) use (&$findings): void {
        $findings[] = ['beat' => $id, 'group' => $kind, 'subject' => $what, 'detail' => $detail];
    };
    foreach ($beats as $id => $sc) {
        foreach ($sc['products'] as $sku) {
            $isAbstract = isset($abstract[$sku]);
            if (!$isAbstract && !isset($concreteSeen[$sku])) {
                $add($id, 'productMissing', $sku, 'the needs list names it, no product row carries this SKU');
                continue;
            }
            $concretes = $isAbstract ? ($concreteOf[$sku] ?? []) : [$sku];
            $buyable = false;
            foreach ($concretes as $c) {
                if ($stock[$c] ?? false) {
                    $buyable = true;
                    break;
                }
            }
            if ($concretes !== [] && !$buyable) {
                $add($id, 'productOutOfStock', $sku, 'every variant has zero stock — the story dead-ends at add-to-basket');
            }
        }
        foreach ($sc['categories'] as $key) {
            if (!isset($categories[$key])) {
                $add($id, 'categoryMissing', $key, 'the demo browses it, no category row carries this key');
            }
        }
        foreach ($sc['facets'] as $facet) {
            if (!isset($attributeKeys[$facet])) {
                $add($id, 'facetMissing', $facet, 'the demo filters on it, no attribute key is declared');
                continue;
            }
            if ($sc['products'] === []) {
                continue;
            }
            $values = [];
            foreach ($sc['products'] as $sku) {
                foreach (array_keys($abstract[$sku][$facet] ?? []) as $v) {
                    if ($v !== '') {
                        $values[$v] = true;
                    }
                }
            }
            if (count($values) < 2) {
                $add($id, 'facetDoesNotNarrow', $facet, 'the beat\'s own products carry ' . (count($values) === 1 ? 'one value (' . implode('', array_keys($values)) . ')' : 'no value') . ' — the filter shows nothing to choose between');
            }
        }
        foreach ($sc['blocks'] as $key) {
            if (!isset($blocks[$key])) {
                $add($id, 'blockMissing', $key, 'the demo shows it, no cms_block row carries this key');
            } elseif ($blocks[$key] === false) {
                $add($id, 'blockInactive', $key, 'the block exists but is not active — it renders nothing');
            }
        }
        foreach ($sc['persona'] as $who) {
            $email = strtolower(trim($who));
            if (str_contains($email, '@') && !isset($customers[$email])) {
                $add($id, 'personaMissing', $who, 'the beat is told as this actor, no customer row carries the address');
            }
        }
        foreach ($sc['wording'] as $phrase) {
            if ($phrase !== '' && !str_contains($haystack, $phrase)) {
                $add($id, 'wordingMissing', $phrase, 'quoted in the brief, not present verbatim in any content row (CMS, glossary, category, navigation, product, shipment/payment method names) — a paraphrase of a named string counts as a finding');
            }
        }
    }
    $skipped = [];
    foreach ($beats as $id => $sc) {
        foreach ($sc['skipped'] ?? [] as $s) {
            $skipped[] = ['beat' => $id] + $s;
        }
    }

    return ['findings' => $findings, 'beats' => array_keys($beats), 'skipped' => $skipped, 'errors' => []];
}

/**
 * Generic report for the coverage and consistency checks: same envelope as validate_report, plus
 * the check's own discovery counters so a clean verdict says what it looked at (a check that read
 * nothing then shows zero counters instead of a plain clean result).
 *
 * @param list<array<string,mixed>> $findings
 * @param array<string,mixed> $context
 */
function validate_report_check(string $check, array $findings, array $context, bool $gating, int $cap = 200): int
{
    $total = count($findings);
    $exit = $total === 0 ? 0 : ($gating ? 2 : 1);
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode([
        'status' => $exit === 0 ? 'ok' : ($gating ? 'error' : 'warning'),
        'check' => $check,
        'findingCount' => $total,
        'findings' => array_slice($findings, 0, $cap),
    ] + $context + ['errors' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

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

            case 'gate':
                $base = $opts['base'] ?? '.';
                $manifests = $opts['_pos'] ?? [];
                $baseline = $opts['baseline'] ?? null;
                if ($baseline === null) {
                    foreach (['.ai-dev/gate-baseline.json', '.ai-dev/preflight-baseline.json'] as $auto) {
                        if (is_file(rtrim($base, '/') . '/' . $auto)) {
                            $baseline = rtrim($base, '/') . '/' . $auto;
                            break;
                        }
                    }
                }
                $roots = isset($opts['root']) ? (array) $opts['root'] : [];
                $save = isset($opts['save']) && is_string($opts['save']) && $opts['save'] !== '' ? $opts['save'] : null;
                if ($save !== null && $baseline !== null && realpath($save) !== false && realpath($save) === realpath($baseline)) {
                    $baseline = null;
                }

                return validate_report_gate(validate_gate($manifests, $base, validate_locale_opt($opts), $baseline, $roots, isset($opts['strict'])), $save);

            case 'inventory':
                $file = $opts['_pos'][0] ?? '';
                $base = $opts['base'] ?? '.';
                if ($file === '') {
                    $found = validate_discover_manifests($base);
                    $file = $found[0] ?? '';
                }
                if ($file === '') {
                    return validate_report(2, 'inventory', [], ['usage: validate.php inventory [<import-config.yml>] [--base dir] [--plain]  (every manifest source with entity, row count and a text sample — classify each project / generic-plumbing / demo-leftover before `done`)']);
                }

                return validate_report_inventory(validate_inventory($file, $base), isset($opts['plain']));

            case 'sku-coverage':
                $file = $opts['_pos'][0] ?? '';
                $base = $opts['base'] ?? '.';
                if ($file === '') {
                    $found = validate_discover_manifests($base);
                    $file = $found[0] ?? '';
                }
                if ($file === '') {
                    return validate_report(2, 'sku-coverage', [], ['usage: validate.php sku-coverage [<import-config.yml>] [--base dir]  (SKUs missing from a catalogue file their peers are in)']);
                }

                return validate_report_sku_coverage(validate_sku_coverage($file, $base));

            case 'import-order':
                [$manifests, , ] = validate_cli_manifest_context($opts, $opts['base'] ?? '.');
                if ($manifests === []) {
                    return validate_report(2, 'import-order', [], ['usage: validate.php import-order [<import-config.yml>...] [--base dir]  (an action must not precede an action it depends on — merchant-relationship after merchant, cms-slot-block after cms-block, …)']);
                }
                $findings = [];
                foreach ($manifests as $manifest) {
                    foreach (validate_preflight_order(validate_manifest_entries($manifest, $opts['base'] ?? '.')) as $f) {
                        $findings[] = ['manifest' => $manifest] + $f;
                    }
                }

                return validate_report_check('import-order', $findings, ['manifests' => $manifests], true);

            case 'store-coverage':
                [$manifests, , $stores] = validate_cli_manifest_context($opts, $opts['base'] ?? '.');
                if ($manifests === []) {
                    return validate_report(2, 'store-coverage', [], ['usage: validate.php store-coverage [<import-config.yml>...] [--base dir] [--stores a,b]  (a declared store with zero rows where other stores have rows)']);
                }
                $findings = [];
                $sources = 0;
                foreach ($manifests as $manifest) {
                    $r = validate_store_coverage($manifest, $opts['base'] ?? '.', $stores);
                    $findings = array_merge($findings, $r['findings']);
                    $sources += $r['sources'];
                }

                return validate_report_check('store-coverage', $findings, ['stores' => $stores, 'sourcesChecked' => $sources], false);

            case 'locale-coverage':
                [$manifests, $locales, ] = validate_cli_manifest_context($opts, $opts['base'] ?? '.');
                if ($manifests === []) {
                    return validate_report(2, 'locale-coverage', [], ['usage: validate.php locale-coverage [<import-config.yml>...] [--base dir] [--locales a,b]  (a project locale with zero rows where other locales have rows)']);
                }
                $findings = [];
                $sources = 0;
                foreach ($manifests as $manifest) {
                    $r = validate_locale_coverage($manifest, $opts['base'] ?? '.', $locales);
                    $findings = array_merge($findings, $r['findings']);
                    $sources += $r['sources'];
                }

                return validate_report_check('locale-coverage', $findings, ['locales' => $locales, 'sourcesChecked' => $sources], false);

            case 'translation-coverage':
                $base = $opts['base'] ?? '.';
                $from = $opts['from'] ?? '';
                $to = $opts['to'] ?? '';
                if ($from === '' || $to === '' || $from === $to) {
                    return validate_report(2, 'translation-coverage', [], ['usage: validate.php translation-coverage --from <locale> --to <locale> [<import-config.yml>|<dir>...] [--base dir]  (rows whose <base>.<to> cell is identical to its <base>.<from> cell = untranslated text in a translated column)']);
                }
                $files = [];
                foreach (($opts['_pos'] ?? []) as $pos) {
                    if (is_dir($pos)) {
                        $files = array_merge($files, validate_discover_csvs($pos, $opts['exclude'] ?? ['/vendor/']));
                    } elseif (strtolower(pathinfo($pos, PATHINFO_EXTENSION)) === 'csv') {
                        $files[] = $pos;
                    } else {
                        $files = array_merge($files, array_column(validate_project_sources($pos, $base), 'file'));
                    }
                }
                if ($files === []) {
                    foreach (validate_discover_manifests($base) as $manifest) {
                        $files = array_merge($files, array_column(validate_project_sources($manifest, $base), 'file'));
                    }
                }
                $files = array_values(array_unique($files));
                sort($files);
                if ($files === []) {
                    return validate_report(2, 'translation-coverage', [], ['translation-coverage: no CSV found (give a manifest, a directory or CSV paths)']);
                }
                $tc = validate_translation_coverage($files, (string) $from, (string) $to);

                return validate_report_check('translation-coverage', $tc['findings'], ['from' => $from, 'to' => $to, 'filesChecked' => $tc['filesChecked'], 'columnsCompared' => $tc['columnsCompared']], false);

            case 'duplicate-keys':
                [$manifests, , ] = validate_cli_manifest_context($opts, $opts['base'] ?? '.');
                if ($manifests === []) {
                    return validate_report(2, 'duplicate-keys', [], ['usage: validate.php duplicate-keys [<import-config.yml>...] [--base dir]  (the same primary key twice in one file; conflicting rows gate, byte-identical repeats are reported only)']);
                }
                $findings = [];
                $identical = [];
                $additive = [];
                $filesChecked = 0;
                foreach ($manifests as $manifest) {
                    $r = validate_duplicate_keys($manifest, $opts['base'] ?? '.');
                    $findings = array_merge($findings, $r['findings']);
                    $identical = array_merge($identical, $r['identical']);
                    $additive = array_merge($additive, $r['additive']);
                    $filesChecked += $r['filesChecked'];
                }

                return validate_report_check('duplicate-keys', $findings, ['additiveDuplicates' => $additive, 'identicalDuplicates' => $identical, 'filesChecked' => $filesChecked], true);

            case 'cms-block-store':
                [$manifests, , $stores] = validate_cli_manifest_context($opts, $opts['base'] ?? '.');
                if ($manifests === []) {
                    return validate_report(2, 'cms-block-store', [], ['usage: validate.php cms-block-store [<import-config.yml>...] [--base dir] [--stores a,b]  (an active block with no cms_block_store row renders nowhere; a slot bound to an unknown block aborts the import)']);
                }
                $findings = [];
                $twins = [];
                foreach ($manifests as $manifest) {
                    $r = validate_cms_block_store($manifest, $opts['base'] ?? '.', $stores);
                    $findings = array_merge($findings, $r['findings']);
                    $twins = array_merge($twins, $r['twins']);
                }

                return validate_report_check('cms-block-store', $findings, ['stores' => $stores, 'perStoreTwins' => $twins], true);

            case 'attribute-glossary':
                [$manifests, $locales, ] = validate_cli_manifest_context($opts, $opts['base'] ?? '.');
                if ($manifests === []) {
                    return validate_report(2, 'attribute-glossary', [], ['usage: validate.php attribute-glossary [<import-config.yml>...] [--base dir] [--locales a,b]  (every attribute_key needs a product.attribute.<key> glossary row per locale, else the PDP prints the raw key)']);
                }
                $findings = [];
                $keys = 0;
                foreach ($manifests as $manifest) {
                    $r = validate_attribute_glossary($manifest, $opts['base'] ?? '.', $locales);
                    $findings = array_merge($findings, $r['findings']);
                    $keys += $r['attributeKeys'];
                }

                return validate_report_check('attribute-glossary', $findings, ['locales' => $locales, 'attributeKeys' => $keys], false);

            case 'bundle-stock':
                [$manifests, , ] = validate_cli_manifest_context($opts, $opts['base'] ?? '.');
                if ($manifests === []) {
                    return validate_report(2, 'bundle-stock', [], ['usage: validate.php bundle-stock [<import-config.yml>...] [--base dir]  (a concrete a configurable-bundle slot offers must have stock, or the pick dead-ends)']);
                }
                $findings = [];
                $concretes = 0;
                foreach ($manifests as $manifest) {
                    $r = validate_bundle_stock($manifest, $opts['base'] ?? '.');
                    $findings = array_merge($findings, $r['findings']);
                    $concretes += $r['concretes'];
                }

                return validate_report_check('bundle-stock', $findings, ['concretesReachable' => $concretes], false);

            case 'tree-scope':
                [$manifests, , ] = validate_cli_manifest_context($opts, $opts['base'] ?? '.');
                if ($manifests === []) {
                    return validate_report(2, 'tree-scope', [], ['usage: validate.php tree-scope [<import-config.yml>...] [--base dir]  (every project source under ONE top-level directory below data/import/)']);
                }
                $ts = validate_tree_scope($manifests, $opts['base'] ?? '.');

                return validate_report_check('tree-scope', $ts['findings'], ['expected' => $ts['expected'], 'dirs' => $ts['dirs']], false);

            case 'demo-needs':
                $needsPath = $opts['_pos'][0] ?? ($opts['needs'] ?? '.ai-dev/demo-prep.md');
                $sBase = $opts['base'] ?? '.';
                if (!is_file($needsPath) && $needsPath[0] !== '/') {
                    $needsPath = rtrim($sBase, '/') . '/' . ltrim($needsPath, './');
                }
                [$manifests, , ] = validate_cli_manifest_context($opts, $sBase);
                if ($manifests === [] || !is_file($needsPath)) {
                    return validate_report(2, 'demo-needs', [], ['usage: validate.php demo-needs [<demo-prep.md>] [<import-config.yml>...] [--base dir]  (does the data support the demo: products exist and are buyable, categories and blocks exist, a named facet has something to narrow, the persona exists, quoted wording is present verbatim)']);
                }
                $sc = validate_demo_needs($needsPath, $manifests, $sBase);
                if ($sc['errors'] !== []) {
                    return validate_report(2, 'demo-needs', [], $sc['errors']);
                }

                return validate_report_check('demo-needs', $sc['findings'], ['needsFile' => $needsPath, 'beats' => $sc['beats'], 'skippedCount' => count($sc['skipped']), 'skipped' => $sc['skipped']], true);

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
                    $skeleton = validate_emit_map($manifest, $map, $base);
                    // --out writes the skeleton itself, so a diff review never needs a shell redirect.
                    $out = isset($opts['out']) && is_string($opts['out']) && $opts['out'] !== '' ? $opts['out'] : null;
                    if ($out === null) {
                        echo $skeleton;

                        return 0;
                    }
                    if (@file_put_contents($out, $skeleton) === false) {
                        return validate_report(2, 'known-set', [], ["known-set --emit-map: cannot write {$out}"]);
                    }
                    echo json_encode(['status' => 'ok', 'check' => 'known-set', 'emitMap' => $out, 'entities' => substr_count($skeleton, '- entity:')], JSON_UNESCAPED_SLASHES) . "\n";

                    return 0;
                }

                return validate_report_known_set(validate_known_set($manifest, $map, $skills, $base));

            default:
                return validate_report(2, $check, [], [
                    'usage: validate.php <gate|inventory|sku-coverage|preflight|paths|manifest-refs|threshold-glossary|orphan-files|refs|required|unique|absent|product-refs|manifest-diff|known-set|import-order|store-coverage|locale-coverage|translation-coverage|duplicate-keys|cms-block-store|attribute-glossary|bundle-stock|tree-scope|demo-needs> ...',
                    'import-order [<yml>...] [--base dir]  — an action must not precede an action it depends on (GATE, gating)',
                    'store-coverage [<yml>...] [--base dir] [--stores a,b]  — a declared store with zero rows where other stores have rows (GATE, warning)',
                    'locale-coverage [<yml>...] [--base dir] [--locales a,b]  — a project locale with zero rows where other locales have rows (GATE, warning)',
                    'translation-coverage --from <locale> --to <locale> [<yml>|<dir>|<csv>...]  — <base>.<to> cells identical to <base>.<from> (not in gate: it needs the two locales named)',
                    'duplicate-keys [<yml>...] [--base dir]  — the same primary key twice in one file; conflicting rows gate, identical repeats are reported only (GATE, gating)',
                    'cms-block-store [<yml>...] [--stores a,b]  — an active block with no cms_block_store row renders nowhere (GATE, gating)',
                    'attribute-glossary [<yml>...] [--locales a,b]  — every attribute_key needs product.attribute.<key> per locale (GATE, warning)',
                    'bundle-stock [<yml>...]  — every concrete a bundle slot offers must have stock (GATE, warning)',
                    'tree-scope [<yml>...]  — every project source under ONE top-level directory below data/import/ (GATE, warning)',
                    'demo-needs [<demo-prep.md>] [<yml>...] [--base dir]  — does the data support the demo: each named product exists and has a variant in stock, categories and blocks exist and are active, a named facet has more than one value among that beat\'s own products, the persona exists, and quoted wording is present verbatim in content rows or shipment/payment method names; a value that is not a SKU/key (prose) is listed under `skipped`, never a finding (not in gate: it needs a demo needs list)',
                    'gate [<import-config.yml> ...] [--locales a,b] [--base dir] [--baseline <gate-or-preflight.json>] [--root dir]... [--strict] [--save <path>] [--quiet]  — one verdict over paths+preflight+manifest-refs+threshold-glossary+duplicate-keys+cms-block-store (gating), orphan-files (gating only with --strict) and sku-coverage+store-coverage+locale-coverage+attribute-glossary+bundle-stock+tree-scope (warnings); --save writes the report AFTER the run (the only safe way to capture a baseline — never `>`); auto-discovers the booted regions\' data/import/local/full_*.yml + the install recipe\'s --config manifests, the project locales, and .ai-dev/gate-baseline.json (else preflight-baseline.json)']);
        }
    } catch (Throwable $e) {
        return validate_report(2, $check, [], [$e->getMessage()]);
    }
}

/**
 * The manifest/locale/store context each of these subcommands needs, resolved the same way the gate
 * resolves it: positional manifests else the booted `full_*.yml`, and the locales/stores
 * discovered from those manifests unless `--locales` / `--stores` names them.
 *
 * @param array<string,mixed> $opts
 * @return array{0: list<string>, 1: list<string>, 2: list<string>}
 */
function validate_cli_manifest_context(array $opts, string $base): array
{
    $manifests = $opts['_pos'] ?? [];
    if ($manifests === []) {
        $manifests = validate_discover_manifests($base);
    }
    $entries = [];
    foreach ($manifests as $manifest) {
        $entries = array_merge($entries, validate_manifest_entries($manifest, $base));
    }
    $locales = validate_locale_opt($opts);
    if ($locales === []) {
        $locales = validate_discover_locales($entries);
    }
    $stores = isset($opts['stores']) && is_string($opts['stores'])
        ? array_values(array_filter(array_map('trim', explode(',', $opts['stores'])), static fn (string $v): bool => $v !== ''))
        : validate_discover_stores($entries);

    return [$manifests, $locales, $stores];
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
    $repeatable = ['column' => true, 'string' => true, 'ref-column' => true, 'keep-from' => true, 'exclude' => true, 'exclude-column' => true, 'root' => true];
    $flags = ['quiet' => true, 'composite' => true, 'emit-map' => true, 'strict' => true, 'plain' => true]; // valueless boolean flags
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
    $problems = validate_preflight_problem_count($result);
    $exit = $problems === 0 ? 0 : 2;
    if (validate_quiet()) {
        return $exit;
    }
    echo json_encode(validate_preflight_payload($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $exit;
}

/** @param array<string,mixed> $result */
function validate_preflight_problem_count(array $result): int
{
    $problems = 0;
    foreach (validate_preflight_groups() as $group) {
        $problems += count((array) ($result[$group] ?? []));
    }

    return $problems;
}

/**
 * The preflight JSON body (shared by the CLI report and the gate).
 *
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function validate_preflight_payload(array $result): array
{
    $problems = validate_preflight_problem_count($result);
    $payload = [
        'status' => $problems === 0 ? 'ok' : 'error',
        'check' => 'preflight',
        'sourcesChecked' => $result['sourcesChecked'],
        'problemCount' => $problems,
        'warningCount' => count($result['priceNetWarnings']),
    ];
    foreach (validate_preflight_groups() as $group) {
        $payload[$group] = $result[$group] ?? [];
        if ($group === 'priceMissing') {
            $payload['priceNetWarnings'] = $result['priceNetWarnings'];
        }
    }
    $payload['errors'] = [];

    return $payload;
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
