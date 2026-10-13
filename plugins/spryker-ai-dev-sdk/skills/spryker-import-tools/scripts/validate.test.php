<?php

declare(strict_types=1);

/** Zero-dependency test for scripts/validate.php. Run: php scripts/validate.test.php */

require __DIR__ . '/validate.php';

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

// --- refs: store references ⊆ declared stores (concept-free; AI passes column+allowed) ---
$storeData = ['header' => ['sku', 'store'], 'rows' => [
    ['sku' => 'A', 'store' => 'US'],
    ['sku' => 'B', 'store' => 'DE'],   // offending: DE not declared
    ['sku' => 'C', 'store' => ''],     // empty skipped
]];
$f = validate_refs($storeData, ['store'], ['US', 'CA']);
check('refs: 1 offending value', count($f) === 1);
check('refs: identifies DE at row 1', $f[0]['value'] === 'DE' && $f[0]['row'] === 1);
check('refs: empty cell skipped', validate_refs(['header' => ['store'], 'rows' => [['store' => '']]], ['store'], ['US']) === []);
check('refs: all valid → none', validate_refs(['header' => ['store'], 'rows' => [['store' => 'US']]], ['store'], ['US', 'CA']) === []);

// A wrong/missing column must not silently pass.
// currency_store.csv uses 'store_name', not 'store' — checking 'store' must be a finding, not ok.
$wrongCol = validate_refs(['header' => ['currency_code', 'store_name'], 'rows' => [['currency_code' => 'USD', 'store_name' => 'US']]], ['store'], ['US', 'CA']);
check('refs: missing column flagged (no false pass)', count($wrongCol) === 1 && $wrongCol[0]['value'] === 'MISSING COLUMN');
check('refs: correct column name passes', validate_refs(['header' => ['currency_code', 'store_name'], 'rows' => [['currency_code' => 'USD', 'store_name' => 'US']]], ['store_name'], ['US', 'CA']) === []);

// refs with --split (multi-value cell like included_store_names "US,CA")
$multi = ['header' => ['cat', 'included'], 'rows' => [['cat' => 'root', 'included' => 'US,CA'], ['cat' => 'x', 'included' => 'US,MX']]];
$fm = validate_refs($multi, ['included'], ['US', 'CA'], ',');
check('refs split: MX flagged, US/CA ok', count($fm) === 1 && $fm[0]['value'] === 'MX');

// --- required: no blank cells (is_searchable) ---
$data = ['header' => ['sku', 'is_searchable.en_US'], 'rows' => [
    ['sku' => 'A', 'is_searchable.en_US' => 'yes'],
    ['sku' => 'B', 'is_searchable.en_US' => ''],   // blank → silent-unsearchable
]];
$fr = validate_required($data, ['is_searchable.en_US']);
check('required: 1 blank found', count($fr) === 1 && $fr[0]['row'] === 1);
check('required: missing column flagged', validate_required($data, ['nope'])[0]['row'] === 'header');
check('required: all filled → none', validate_required(['header' => ['a'], 'rows' => [['a' => 'x']]], ['a']) === []);

// --- unique: no repeated values (URL uniqueness after prefix rewrite) ---
$uniq = ['header' => ['sku', 'url'], 'rows' => [
    ['sku' => 'A', 'url' => '/fr/widget'],
    ['sku' => 'B', 'url' => '/fr/widget'],   // collision
    ['sku' => 'C', 'url' => '/fr/gadget'],
    ['sku' => 'D', 'url' => ''],             // empty ignored
    ['sku' => 'E', 'url' => ''],
]];
$u = validate_unique($uniq, 'url');
check('unique: 1 duplicated value found', count($u) === 1 && $u[0]['value'] === '/fr/widget');
check('unique: reports colliding rows', $u[0]['rows'] === [0, 1]);
check('unique: empty cells ignored', validate_unique(['header' => ['url'], 'rows' => [['url' => ''], ['url' => '']]], 'url') === []);
check('unique: all distinct → none', validate_unique(['header' => ['u'], 'rows' => [['u' => 'a'], ['u' => 'b']]], 'u') === []);
check('unique: missing column flagged', validate_unique($uniq, 'nope')[0]['value'] === 'MISSING COLUMN');

// --- absent: literal sweep across text files ---
$tmp = sys_get_temp_dir() . '/validate_' . getmypid();
@mkdir($tmp, 0777, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn ['store' => 'DE', 'locale' => 'de_DE'];\n");
file_put_contents($tmp . '/clean.php', "<?php\nreturn ['store' => 'US'];\n");
$fa = validate_absent([$tmp . '/config.php', $tmp . '/clean.php'], ['DE', 'de_DE']);
check('absent: 2 hits in config.php', count($fa) === 2);
check('absent: clean file no hits', count(array_filter($fa, fn ($x) => str_contains($x['file'], 'clean'))) === 0);
check('absent: reports line numbers', $fa[0]['line'] === 2);
check('absent: no strings present → none', validate_absent([$tmp . '/clean.php'], ['DE', 'de_DE']) === []);
check('absent: unreadable file flagged', validate_absent(['/no/such/file'], ['x'])[0]['string'] === 'CANNOT READ FILE');
// directory args must be recursed, not silently passed (fopen on a dir succeeds and fgets fails → a false ok)
@mkdir($tmp . '/sub', 0777, true);
file_put_contents($tmp . '/sub/nested.php', "<?php\n// store DE here\n");
$faDir = validate_absent([$tmp], ['DE']);
check('absent: directory arg is recursed (finds hit in top-level file)', count(array_filter($faDir, fn ($x) => str_contains($x['file'], 'config.php'))) === 1);
check('absent: directory arg recurses into subdirs', count(array_filter($faDir, fn ($x) => str_contains($x['file'], 'nested.php'))) === 1);
check('absent: directory with no match → clean (not a silent skip of a real scan)', validate_absent([$tmp . '/sub'], ['ZZZ_absent_token']) === []);
check('absent: empty needle set on a dir → no hits', validate_absent([$tmp], []) === []);

// --- paths: source: extraction + existence ---
file_put_contents($tmp . '/exists.csv', "a\n1\n");
file_put_contents($tmp . '/import.yml', implode("\n", [
    'version: 0',
    'actions:',
    '  - data_entity: store',
    '    source: exists.csv',
    '  - data_entity: currency',
    "    source: 'missing.csv'",
]) . "\n");
$fp = validate_paths($tmp . '/import.yml', $tmp);
check('paths: 1 missing source', count($fp) === 1);
check('paths: identifies missing.csv (quotes stripped)', $fp[0]['source'] === 'missing.csv');

// --- CLI round-trip ---
$php = PHP_BINARY;
$lib = escapeshellarg(__DIR__ . '/validate.php');
$csvFile = $tmp . '/rows.csv';
// self-contained fixture (no csv skill dependency)
file_put_contents($csvFile, "sku,store\nA,US\nB,DE\nC,\n");
$json = shell_exec("{$php} {$lib} refs " . escapeshellarg($csvFile) . " --column store --in US,CA 2>&1");
$rep = json_decode((string) $json, true);
check('CLI refs: status error (DE offends)', ($rep['status'] ?? '') === 'error');
check('CLI refs: findingCount 1', ($rep['findingCount'] ?? null) === 1);

$okJson = shell_exec("{$php} {$lib} refs " . escapeshellarg($csvFile) . " --column store --in US,CA,DE 2>&1");
$okRep = json_decode((string) $okJson, true);
check('CLI refs: status ok when all allowed', ($okRep['status'] ?? '') === 'ok');

// --quiet: no output, exit code carries the result (0 clean / 2 findings).
exec("{$php} {$lib} refs " . escapeshellarg($csvFile) . ' --column store --in US,CA --quiet 2>&1', $qOut, $qCode);
check('CLI --quiet: no output on findings', $qOut === []);
check('CLI --quiet: exit 2 on findings', $qCode === 2);
exec("{$php} {$lib} refs " . escapeshellarg($csvFile) . ' --column store --in US,CA,DE --quiet 2>&1', $qOut2, $qCode2);
check('CLI --quiet: exit 0 when clean', $qCode2 === 0 && $qOut2 === []);

// --- refs --composite: tuple (merchant,store) ⊆ merchant_store tuples ---
$childTuples = ['header' => ['merchant', 'store'], 'rows' => [
    ['merchant' => 'M1', 'store' => 'PL'],
    ['merchant' => 'M2', 'store' => 'PL'],   // offending: (M2,PL) not in ref
    ['merchant' => 'M1', 'store' => 'UA'],
]];
file_put_contents($tmp . '/mstore.csv', "merchant,store\nM1,PL\nM1,UA\n");
$refKeys = validate_ref_tuples($tmp . '/mstore.csv', ['merchant', 'store']);
$cf = validate_refs_composite($childTuples, ['merchant', 'store'], $refKeys);
check('refs composite: 1 offending tuple', count($cf) === 1 && $cf[0]['value'] === 'M2+PL');
check('refs composite: (M1,PL) and (M1,UA) pass', count(array_filter($cf, fn ($x) => str_contains($x['value'], 'M1'))) === 0);
$okComposite = validate_refs_composite(['header' => ['merchant', 'store'], 'rows' => [['merchant' => 'M1', 'store' => 'UA']]], ['merchant', 'store'], $refKeys);
check('refs composite: all valid → none', $okComposite === []);
check('refs composite: missing column flagged', validate_refs_composite(['header' => ['merchant'], 'rows' => []], ['merchant', 'store'], $refKeys)[0]['value'] === 'MISSING COLUMN');

// CLI composite round-trip
file_put_contents($tmp . '/child.csv', "merchant,store\nM1,PL\nM2,PL\n");
exec("{$php} {$lib} refs " . escapeshellarg($tmp . '/child.csv') . ' --column merchant --column store --ref-file ' . escapeshellarg($tmp . '/mstore.csv') . ' --ref-column merchant --ref-column store --composite --quiet 2>&1', $cOut, $cCode);
check('CLI refs --composite: exit 2 on missing tuple', $cCode === 2);

// --- product-refs: orphan SKUs across a CSV tree (catalog-removal coverage) ---
$abstractFile = $tmp . '/keep_abstract.csv';
$concreteFile = $tmp . '/keep_concrete.csv';
file_put_contents($abstractFile, "abstract_sku,name\nA1,Alpha\nA2,Beta\n");
file_put_contents($concreteFile, "concrete_sku\nC1\nC2\n");

// kept-set: union of two files + inline --keep-in
$kept = validate_build_kept_set([$abstractFile . ':abstract_sku', $concreteFile . ':concrete_sku'], ['EX1']);
check('product-refs: kept-set unions two files + keep-in', count($kept) === 5 && isset($kept['A1'], $kept['C2'], $kept['EX1']));
check('product-refs: keep-from missing column throws', (function () use ($abstractFile): bool {
    try {
        validate_collect_keep_from($abstractFile . ':nope', []);

        return false;
    } catch (RuntimeException) {
        return true;
    }
})());

// dirty tree: subdirs prove recursive discovery; a scalar orphan, a list orphan, a 100%-orphan column, an excludable file
$tree = $tmp . '/tree';
@mkdir($tree . '/a', 0777, true);
@mkdir($tree . '/b', 0777, true);
@mkdir($tree . '/c', 0777, true);
file_put_contents($tree . '/a/prices.csv', "sku,price\nA1,10\nX9,20\n");            // sku scalar: X9 orphan
file_put_contents($tree . '/b/bundles.csv', "id,component_skus\n1,\"A1,C1\"\n2,\"A1,ZZ\"\n"); // _skus list: ZZ orphan
file_put_contents($tree . '/b/options.csv', "sku,label\nOPT1,x\nOPT2,y\n");         // sku scalar: 100% orphan
file_put_contents($tree . '/c/combined_product.csv', "sku\nQQ\n");                  // excludable file: QQ orphan

$defaultPatterns = ['sku', 'abstract_sku', 'concrete_sku', 'product_sku', 'product'];

$allFiles = validate_discover_csvs($tree, []);
check('product-refs: discovers all csv in tree (recursive)', count($allFiles) === 4);
check('product-refs: --exclude substring skips a file', count(validate_discover_csvs($tree, ['combined_product'])) === 3);

$scan = validate_product_refs($allFiles, $kept, $defaultPatterns, '_skus', []);
check('product-refs: 5 orphans across tree', count($scan['findings']) === 5);
check('product-refs: scalar orphan X9 found', count(array_filter($scan['findings'], fn ($x) => $x['value'] === 'X9' && str_contains($x['column'], 'sku'))) === 1);
$zz = array_filter($scan['findings'], fn ($x) => $x['value'] === 'ZZ');
check('product-refs: list (_skus) orphan ZZ found', count($zz) === 1 && array_values($zz)[0]['column'] === 'component_skus');
$listSummary = array_values(array_filter($scan['columns'], fn ($c) => $c['column'] === 'component_skus'));
check('product-refs: _skus column flagged list=true', $listSummary !== [] && $listSummary[0]['list'] === true);
$optSummary = array_values(array_filter($scan['columns'], fn ($c) => str_contains($c['file'], 'options.csv')));
check('product-refs: 100%-orphan column in summary (orphan==total)', $optSummary !== [] && $optSummary[0]['orphanTokens'] === 2 && $optSummary[0]['totalTokens'] === 2);
$cleanColSummary = array_values(array_filter($scan['columns'], fn ($c) => str_contains($c['file'], 'bundles.csv')));
check('product-refs: summary lists columns with 0 orphans too (coverage view)', $cleanColSummary !== [] && $cleanColSummary[0]['totalTokens'] === 4 && $cleanColSummary[0]['orphanTokens'] === 1);

$dropped = validate_product_refs($allFiles, $kept, $defaultPatterns, '_skus', ['sku']);
check('product-refs: --exclude-column drops a column everywhere', count($dropped['findings']) === 1 && $dropped['findings'][0]['value'] === 'ZZ');

// clean tree: every token in the kept-set (incl. EX1 from --keep-in) → no orphans
$cleanTree = $tmp . '/clean';
@mkdir($cleanTree . '/x', 0777, true);
file_put_contents($cleanTree . '/x/ok.csv', "sku,bonus_skus\nA1,\"C1,A2\"\nEX1,\"C2\"\n");
$cleanScan = validate_product_refs(validate_discover_csvs($cleanTree, []), $kept, $defaultPatterns, '_skus', []);
check('product-refs: clean tree → no orphans', $cleanScan['findings'] === []);

// CLI round-trips
$keepArgs = '--keep-from ' . escapeshellarg($abstractFile . ':abstract_sku') . ' --keep-from ' . escapeshellarg($concreteFile . ':concrete_sku');
$prJson = shell_exec("{$php} {$lib} product-refs " . escapeshellarg($tree) . " {$keepArgs} --exclude combined_product 2>&1");
$prRep = json_decode((string) $prJson, true);
check('CLI product-refs: status error on orphans', ($prRep['status'] ?? '') === 'error');
check('CLI product-refs: findingCount 4 (combined_product excluded)', ($prRep['findingCount'] ?? null) === 4);
check('CLI product-refs: columns summary present', isset($prRep['columns']) && is_array($prRep['columns']));

exec("{$php} {$lib} product-refs " . escapeshellarg($cleanTree) . " {$keepArgs} --keep-in EX1 --quiet 2>&1", $prOut, $prCode);
check('CLI product-refs --quiet: exit 0 clean tree (--keep-in honored)', $prCode === 0 && $prOut === []);
exec("{$php} {$lib} product-refs " . escapeshellarg($tree) . " {$keepArgs} --quiet 2>&1", $prOut2, $prCode2);
check('CLI product-refs --quiet: exit 2 on orphans', $prCode2 === 2 && $prOut2 === []);

// unreadable keep-from → error exit 2 (never a silent empty keep-set)
exec("{$php} {$lib} product-refs " . escapeshellarg($tree) . ' --keep-from ' . escapeshellarg($tmp . '/no_such.csv:abstract_sku') . ' --quiet 2>&1', $prOut3, $prCode3);
check('CLI product-refs: unreadable --keep-from → exit 2', $prCode3 === 2);

// usage error: no keep source
exec("{$php} {$lib} product-refs " . escapeshellarg($tree) . ' --quiet 2>&1', $prOut4, $prCode4);
check('CLI product-refs: missing keep source → exit 2', $prCode4 === 2);

// --- preflight: one driver over a manifest, auto-discovering url/search/price/order ---
file_put_contents($tmp . '/pf_url.csv', "sku,url.en_US\nA,/en/x\nB,/en/x\nC,/en/y\n");        // dup /en/x
file_put_contents($tmp . '/pf_search.csv', "sku,is_searchable.en_US\nA,1\nB,\n");             // 1 blank
file_put_contents($tmp . '/pf_price.csv', "sku,value_gross,value_net\nA,0,10\nB,20,\n");      // gross 0, net empty
file_put_contents($tmp . '/pf.yml', implode("\n", [
    'version: 0',
    'actions:',
    '  - data_entity: currency-store',   // order violation: base 'currency' comes AFTER this
    '    source: pf_url.csv',
    '  - data_entity: currency',
    '    source: pf_search.csv',
    '  - data_entity: product-price',
    '    source: pf_price.csv',
    '  - data_entity: locale',
    '    source: pf_missing.csv',         // unreadable source
]) . "\n");
$pf = validate_preflight($tmp . '/pf.yml', $tmp);
check('preflight: sourcesChecked counts all manifest sources', $pf['sourcesChecked'] === 4);
check('preflight: unreadable source flagged', $pf['unreadableSources'] === ['pf_missing.csv']);
check('preflight: url.<locale> duplicate found', count($pf['urlDuplicates']) === 1 && $pf['urlDuplicates'][0]['value'] === '/en/x');
check('preflight: is_searchable blank found', count($pf['searchableBlanks']) === 1 && $pf['searchableBlanks'][0]['blanks'] === 1);
check('preflight: gross empty-or-0 is a hard problem (value_gross only)', count($pf['priceMissing']) === 1 && $pf['priceMissing'][0]['column'] === 'value_gross');
check('preflight: net empty beside present gross is a WARNING, not a problem', count($pf['priceNetWarnings']) === 1 && $pf['priceNetWarnings'][0]['column'] === 'value_net');
check('preflight: order violation (base currency after currency-store)', count(array_filter($pf['orderViolations'], fn ($v) => $v['entity'] === 'currency-store')) === 1);

$entries = validate_manifest_entries($tmp . '/pf.yml', $tmp);
check('preflight: manifest entries ordered + paired', $entries[0]['data_entity'] === 'currency-store' && $entries[0]['source'] === 'pf_url.csv');

// clean manifest → no problems, exit 0
file_put_contents($tmp . '/pf_clean_url.csv', "sku,url.en_US\nA,/en/x\nB,/en/y\n");
file_put_contents($tmp . '/pf_clean_search.csv', "sku,is_searchable.en_US\nA,1\nB,1\n");
file_put_contents($tmp . '/pf_clean_price.csv', "sku,value_gross,value_net\nA,10,8\nB,20,16\n");
file_put_contents($tmp . '/pf_clean.yml', implode("\n", [
    'version: 0',
    'actions:',
    '  - data_entity: currency',
    '    source: pf_clean_url.csv',
    '  - data_entity: currency-store',
    '    source: pf_clean_search.csv',
    '  - data_entity: product-price',
    '    source: pf_clean_price.csv',
]) . "\n");
$pfClean = validate_preflight($tmp . '/pf_clean.yml', $tmp);
check('preflight: clean manifest → zero problems', $pfClean['unreadableSources'] === [] && $pfClean['urlDuplicates'] === [] && $pfClean['searchableBlanks'] === [] && $pfClean['priceMissing'] === [] && $pfClean['orderViolations'] === []);

exec("{$php} {$lib} preflight " . escapeshellarg($tmp . '/pf.yml') . ' --base ' . escapeshellarg($tmp) . ' --quiet 2>&1', $pfOut, $pfCode);
check('CLI preflight --quiet: exit 2 on problems', $pfCode === 2 && $pfOut === []);
exec("{$php} {$lib} preflight " . escapeshellarg($tmp . '/pf_clean.yml') . ' --base ' . escapeshellarg($tmp) . ' --quiet 2>&1', $pfOut2, $pfCode2);
check('CLI preflight --quiet: exit 0 clean', $pfCode2 === 0 && $pfOut2 === []);
$pfJson = shell_exec("{$php} {$lib} preflight " . escapeshellarg($tmp . '/pf.yml') . ' --base ' . escapeshellarg($tmp) . ' 2>&1');
$pfRep = json_decode((string) $pfJson, true);
check('CLI preflight: problemCount aggregates gating groups only', ($pfRep['problemCount'] ?? null) === 5);
check('CLI preflight: warningCount carries the non-gating net warnings', ($pfRep['warningCount'] ?? null) === 1);

// preflight: shipment prices are legitimately free — net-empty/gross-0 not flagged
file_put_contents($tmp . '/shipment_price.csv', "shipment_method_key,store,currency,value_net,value_gross\nsm,PL,PLN,,0\n");
file_put_contents($tmp . '/pf_ship.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: shipment-price', '    source: shipment_price.csv']) . "\n");
check('preflight: free shipment price (net-empty/gross-0) not flagged', validate_preflight($tmp . '/pf_ship.yml', $tmp)['priceMissing'] === []);

// preflight: net-ONLY file (no value_gross column) — empty net IS the missing price (hard)
file_put_contents($tmp . '/pf_netonly.csv', "sku,value_net\nA,10\nB,\n");
file_put_contents($tmp . '/pf_netonly.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: product-price', '    source: pf_netonly.csv']) . "\n");
$pfNetOnly = validate_preflight($tmp . '/pf_netonly.yml', $tmp);
check('preflight: net-only file — empty net is a hard problem', count($pfNetOnly['priceMissing']) === 1 && $pfNetOnly['priceMissing'][0]['column'] === 'value_net' && $pfNetOnly['priceNetWarnings'] === []);

// preflight: url uniqueness is CROSS-FILE (the real spy_url constraint) — each file clean alone, colliding together
file_put_contents($tmp . '/pf_xf_a.csv', "sku,url.en_US\nA,/en/shared\n");
file_put_contents($tmp . '/pf_xf_b.csv', "key,url.en_US\nK,/en/shared\n");
file_put_contents($tmp . '/pf_xf.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: product-abstract', '    source: pf_xf_a.csv', '  - data_entity: category', '    source: pf_xf_b.csv']) . "\n");
$pfXf = validate_preflight($tmp . '/pf_xf.yml', $tmp);
check('preflight: cross-file url duplicate found', count($pfXf['urlDuplicates']) === 1 && $pfXf['urlDuplicates'][0]['value'] === '/en/shared' && $pfXf['urlDuplicates'][0]['rows'] === 2);
check('preflight: cross-file url duplicate names both files', str_contains((string) $pfXf['urlDuplicates'][0]['files'], 'pf_xf_a.csv') && str_contains((string) $pfXf['urlDuplicates'][0]['files'], 'pf_xf_b.csv'));

// preflight: navigation-node urls are link TARGETS — legitimately repeated, exempt from the url check
file_put_contents($tmp . '/navigation_node.csv', "navigation_key,node_key,url.en_US\nMAIN,n1,/en/cat\nFOOTER,n2,/en/cat\n");
file_put_contents($tmp . '/pf_nav.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: navigation-node', '    source: navigation_node.csv']) . "\n");
check('preflight: navigation-node url repeats not flagged', validate_preflight($tmp . '/pf_nav.yml', $tmp)['urlDuplicates'] === []);

// preflight: a store-definition entry APPENDED AFTER the catalog is an order violation
// (first occurrence sits at the top — the check must use the LAST occurrence)
file_put_contents($tmp . '/pf_ls1.csv', "locale_name,store_name\nen_US,PL\n");
file_put_contents($tmp . '/pf_cat.csv', "abstract_sku,name.en_US\nA,x\n");
file_put_contents($tmp . '/pf_ls2.csv', "locale_name,store_name\nuk_UA,UA\n");
file_put_contents($tmp . '/pf_late.yml', implode("\n", [
    'version: 0', 'actions:',
    '  - data_entity: locale-store', '    source: pf_ls1.csv',
    '  - data_entity: product-abstract', '    source: pf_cat.csv',
    '  - data_entity: locale-store', '    source: pf_ls2.csv',
]) . "\n");
$pfLate = validate_preflight($tmp . '/pf_late.yml', $tmp);
check('preflight: late store-definition entry (after catalog) flagged', count(array_filter($pfLate['orderViolations'], fn ($v) => $v['entity'] === 'locale-store' && str_contains($v['detail'], 'AFTER the catalog'))) === 1);

// preflight --baseline: findings present in a previous report are suppressed; only NEW findings gate
$baseJson = shell_exec("{$php} {$lib} preflight " . escapeshellarg($tmp . '/pf.yml') . ' --base ' . escapeshellarg($tmp) . ' 2>&1');
file_put_contents($tmp . '/pf_baseline.json', (string) $baseJson);
exec("{$php} {$lib} preflight " . escapeshellarg($tmp . '/pf.yml') . ' --base ' . escapeshellarg($tmp) . ' --baseline ' . escapeshellarg($tmp . '/pf_baseline.json') . ' --quiet 2>&1', $pfBlOut, $pfBlCode);
check('CLI preflight --baseline: identical findings suppressed → exit 0', $pfBlCode === 0);
// a NEW finding (fresh url dup not in the baseline) still gates
file_put_contents($tmp . '/pf_url.csv', "sku,url.en_US\nA,/en/x\nB,/en/x\nC,/en/y\nD,/en/y\n"); // adds dup /en/y
exec("{$php} {$lib} preflight " . escapeshellarg($tmp . '/pf.yml') . ' --base ' . escapeshellarg($tmp) . ' --baseline ' . escapeshellarg($tmp . '/pf_baseline.json') . ' --quiet 2>&1', $pfBl2Out, $pfBl2Code);
check('CLI preflight --baseline: NEW finding still gates → exit 2', $pfBl2Code === 2);
file_put_contents($tmp . '/pf_url.csv', "sku,url.en_US\nA,/en/x\nB,/en/x\nC,/en/y\n"); // restore fixture

// product-refs: sku-as-underscore-token headers are auto-discovered (otherwise a reduce reads falsely green)
$tokTree = $tmp . '/tok';
@mkdir($tokTree, 0777, true);
file_put_contents($tokTree . '/product_discontinued.csv', "sku_concrete,note\nZZ-GONE,x\n");
file_put_contents($tokTree . '/product_review.csv', "abstract_product_sku,rating\nZZ-GONE2,5\n");
$tokScan = validate_product_refs(validate_discover_csvs($tokTree, []), $kept, $defaultPatterns, '_skus', []);
$tokCols = array_map(fn ($c) => $c['column'], $tokScan['columns']);
check('product-refs: sku_concrete discovered via sku-token rule', in_array('sku_concrete', $tokCols, true));
check('product-refs: abstract_product_sku discovered via sku-token rule', in_array('abstract_product_sku', $tokCols, true));
check('product-refs: token-rule orphans found', count($tokScan['findings']) === 2);

// preflight --locales: foreign locale columns/rows flagged
file_put_contents($tmp . '/pf_loc_cols.csv', "sku,name.en_US,name.pl_PL,name.de_DE\nA,a,a2,x\n");
file_put_contents($tmp . '/pf_loc_rows.csv', "sku,locale\nA,pl_PL\nB,de_DE\n");
file_put_contents($tmp . '/pf_loc.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: x', '    source: pf_loc_cols.csv', '  - data_entity: y', '    source: pf_loc_rows.csv']) . "\n");
$pfNoLoc = validate_preflight($tmp . '/pf_loc.yml', $tmp);
check('preflight: no locale set → no foreign-locale findings', $pfNoLoc['foreignLocaleColumns'] === [] && $pfNoLoc['foreignLocaleRows'] === []);
$pfLoc = validate_preflight($tmp . '/pf_loc.yml', $tmp, ['en_US', 'pl_PL', 'uk_UA']);
check('preflight: foreign locale COLUMN (de_DE) flagged', count($pfLoc['foreignLocaleColumns']) === 1 && $pfLoc['foreignLocaleColumns'][0]['locale'] === 'de_DE');
check('preflight: foreign locale ROW (de_DE) flagged', count($pfLoc['foreignLocaleRows']) === 1 && $pfLoc['foreignLocaleRows'][0]['locale'] === 'de_DE');
check('preflight: project-locale column pl_PL not flagged', count(array_filter($pfLoc['foreignLocaleColumns'], fn ($x) => $x['locale'] === 'pl_PL')) === 0);
exec("{$php} {$lib} preflight " . escapeshellarg($tmp . '/pf_loc.yml') . ' --base ' . escapeshellarg($tmp) . ' --locales en_US,pl_PL,uk_UA --quiet 2>&1', $lcOut, $lcCode);
check('CLI preflight --locales: exit 2 on foreign locale', $lcCode === 2);

// preflight --locales: the en_US glossary is the translator fallback layer, imported even when no
// store serves en_US — its rows are not foreign; en_US rows in any other file still are.
file_put_contents($tmp . '/glossary.en_US.csv', "key,translation,locale\ncart.checkout,Checkout,en_US\n");
file_put_contents($tmp . '/glossary.de_DE.csv', "key,translation,locale\ncart.checkout,Zur Kasse,de_DE\n");
file_put_contents($tmp . '/pf_en_rows.csv', "sku,locale\nA,en_US\n");
file_put_contents($tmp . '/pf_gl.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: glossary', '    source: glossary.en_US.csv', '  - data_entity: glossary', '    source: glossary.de_DE.csv', '  - data_entity: y', '    source: pf_en_rows.csv']) . "\n");
$pfGl = validate_preflight($tmp . '/pf_gl.yml', $tmp, ['de_DE', 'pl_PL']);
$glFiles = array_map(fn ($x) => basename($x['file']) . ':' . $x['locale'], $pfGl['foreignLocaleRows']);
check('preflight: en_US glossary rows are NOT foreign on a de_DE+pl_PL project (fallback layer)', !in_array('glossary.en_US.csv:en_US', $glFiles, true));
check('preflight: en_US rows in a non-glossary file are still foreign', in_array('pf_en_rows.csv:en_US', $glFiles, true));
$pfGl2 = validate_preflight($tmp . '/pf_gl.yml', $tmp, ['pl_PL']);
check('preflight: a non-fallback foreign glossary locale (de_DE) is still flagged', in_array('glossary.de_DE.csv:de_DE', array_map(fn ($x) => basename($x['file']) . ':' . $x['locale'], $pfGl2['foreignLocaleRows']), true));

// preflight: a shipment-method / shipment-carrier discount clause compares the numeric id — a name never matches
file_put_contents($tmp . '/discount.csv', "discount_key,decision_rule_query_string,collector_query_string\n"
    . "free-by-name,sub-total >= '50',shipment-method = 'DHL Standard'\n"
    . "free-by-id,sub-total >= '50',shipment-method = '1'\n"
    . "carrier-mixed,shipment-carrier is in '2;DPD',sku = '*'\n");
file_put_contents($tmp . '/pf_disc.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: discount', '    source: discount.csv']) . "\n");
$pfDisc = validate_preflight($tmp . '/pf_disc.yml', $tmp);
$discVals = array_map(fn ($x) => $x['discount'] . '=' . $x['value'], $pfDisc['discountShipmentByName']);
check('preflight: shipment-method = \'<name>\' → discountShipmentByName', in_array('free-by-name=DHL Standard', $discVals, true));
check('preflight: shipment-method = \'<id>\' is fine; only the non-numeric item of an is-in list is flagged', count($discVals) === 2 && in_array('carrier-mixed=DPD', $discVals, true));
check('preflight: discountShipmentByName is a gating group', in_array('discountShipmentByName', validate_preflight_groups(), true));

// preflight: category_store — root-only rows are the shipped shape; once a store names any child, every child must be stated
file_put_contents($tmp . '/category.csv', "category_key,parent_category_key,is_in_menu\nroot,,1\nmen,root,1\nwomen,root,1\nmen-jackets,men,1\n");
file_put_contents($tmp . '/category_store.csv', "category_key,included_store_names,excluded_store_names\nroot,\"DE,PL\",\n");
file_put_contents($tmp . '/pf_cat.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: category', '    source: category.csv', '  - data_entity: category-store', '    source: category_store.csv']) . "\n");
check('preflight: root-only category_store (fresh-import propagation) → no categoryStoreIncomplete', validate_preflight($tmp . '/pf_cat.yml', $tmp)['categoryStoreIncomplete'] === []);
file_put_contents($tmp . '/category_store.csv', "category_key,included_store_names,excluded_store_names\nroot,\"DE,PL\",\nmen-jackets,DE,PL\nwomen,,DE\n");
$pfCat = validate_preflight($tmp . '/pf_cat.yml', $tmp);
$catByStore = array_column($pfCat['categoryStoreIncomplete'], 'sample', 'store');
check('preflight: a child added with its own row → siblings relying on propagation are flagged for that store', ($catByStore['DE'] ?? []) === ['men']);
check('preflight: an excluded store counts as stated; a store no child row names is not in explicit mode', !isset($catByStore['PL']));
file_put_contents($tmp . '/category_store.csv', "category_key,included_store_names,excluded_store_names\nroot,\"DE,PL\",\nmen-jackets,DE,PL\nwomen,,DE\nmen,,\n");
check('preflight: a child row with both store lists empty (take the parent\'s stores) counts as stated', validate_preflight($tmp . '/pf_cat.yml', $tmp)['categoryStoreIncomplete'] === []);

// manifest-diff: entities in old-not-new = missing (with row counts); new-not-old = added
file_put_contents($tmp . '/md_old.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: product-abstract', '    source: pf_price.csv', '  - data_entity: product-shipment-type', '    source: pf_url.csv', '  - data_entity: discount', '    source: pf_search.csv']) . "\n");
file_put_contents($tmp . '/md_new.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: product-abstract', '    source: pf_price.csv', '  - data_entity: cms-block', '    source: pf_url.csv']) . "\n");
$md = validate_manifest_diff($tmp . '/md_old.yml', $tmp . '/md_new.yml', $tmp);
check('manifest-diff: 2 missing entities', count($md['missing']) === 2);
check('manifest-diff: product-shipment-type flagged missing', count(array_filter($md['missing'], fn ($x) => $x['data_entity'] === 'product-shipment-type')) === 1);
check('manifest-diff: missing entity carries row count', array_values(array_filter($md['missing'], fn ($x) => $x['data_entity'] === 'product-shipment-type'))[0]['rows'] !== null);
check('manifest-diff: cms-block flagged added', count($md['added']) === 1 && $md['added'][0]['data_entity'] === 'cms-block');
exec("{$php} {$lib} manifest-diff " . escapeshellarg($tmp . '/md_old.yml') . ' ' . escapeshellarg($tmp . '/md_new.yml') . ' --base ' . escapeshellarg($tmp) . ' --quiet 2>&1', $mdOut, $mdCode);
check('CLI manifest-diff: exit 2 when entities missing', $mdCode === 2);

// preflight shape checks: C1 color_code, C4 visibility enum, C5 approval, C6 merchant-product, tax-set check
file_put_contents($tmp . '/sh_pa.csv', "abstract_sku,name.en_US,tax_set_name,visibility\nA,Alpha,Standard Tax,1\n"); // no color_code (C1); bad tax_set_name (tax-set check); bad visibility (C4)
file_put_contents($tmp . '/sh_tax.csv', "tax_set_name,rate\nStandard Taxes,19\n");
file_put_contents($tmp . '/sh.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: tax', '    source: sh_tax.csv', '  - data_entity: product-abstract', '    source: sh_pa.csv', '  - data_entity: merchant-product-offer', '    source: sh_pa.csv']) . "\n");
$shChecks = array_column(validate_preflight($tmp . '/sh.yml', $tmp)['shapeWarnings'], 'check');
check('preflight shape C1: color_code missing on product-abstract', in_array('color_code', $shChecks, true));
check('preflight shape C4: visibility enum violation', in_array('visibility_enum', $shChecks, true));
check('preflight shape tax-set: tax_set_name not in tax source', in_array('tax_set_name', $shChecks, true));
check('preflight shape C5: product-abstract without approval-status', in_array('product-approval-status', $shChecks, true));
check('preflight shape C6: merchant-product-offer without merchant-product', in_array('merchant-product', $shChecks, true));
file_put_contents($tmp . '/sh_pa_ok.csv', "abstract_sku,name.en_US,tax_set_name,color_code,visibility\nA,Alpha,Standard Taxes,#fff,PDP\n");
file_put_contents($tmp . '/sh_ok.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: tax', '    source: sh_tax.csv', '  - data_entity: product-abstract', '    source: sh_pa_ok.csv', '  - data_entity: product-approval-status', '    source: sh_tax.csv', '  - data_entity: merchant-product', '    source: sh_tax.csv', '  - data_entity: merchant-product-offer', '    source: sh_pa_ok.csv']) . "\n");
check('preflight shape: correct shapes → no shape warnings', validate_preflight($tmp . '/sh_ok.yml', $tmp)['shapeWarnings'] === []);

// refs multi-file: each column checked only against files that have it
file_put_contents($tmp . '/d3_a.csv', "key\nK1\n");
file_put_contents($tmp . '/d3_b.csv', "attribute_key\nK2\nZZ\n");
$d3 = json_decode((string) shell_exec("{$php} {$lib} refs " . escapeshellarg($tmp . '/d3_a.csv') . ' ' . escapeshellarg($tmp . '/d3_b.csv') . ' --column key --column attribute_key --in K1,K2 2>&1'), true);
check('refs multi-file: no false MISSING for per-file columns', ($d3['findingCount'] ?? null) === 1);
check('refs multi-file: the real orphan (ZZ) is found', ($d3['findings'][0]['value'] ?? '') === 'ZZ');
exec("{$php} {$lib} refs " . escapeshellarg($tmp . '/d3_a.csv') . ' ' . escapeshellarg($tmp . '/d3_b.csv') . ' --column nope --in K1 --quiet 2>&1', $d3n, $d3nCode);
check('refs multi-file: column absent from every file → exit 2 (MISSING)', $d3nCode === 2);

// recursively remove tree fixtures (glob below is non-recursive)
foreach ([$tree, $cleanTree] as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($root);
}

// --- manifest-refs: whole-FK-graph sweep by column-name convention ---
$mr = $tmp . '/mr';
@mkdir($mr, 0777, true);
file_put_contents($mr . '/product_abstract.csv', "abstract_sku,name\nA1,x\nA2,y\n");
file_put_contents($mr . '/product_concrete.csv', "concrete_sku,abstract_sku\nC1,A1\nC2,A2\n");
file_put_contents($mr . '/merchant.csv', "merchant_reference,name\nM1,Acme\n");
file_put_contents($mr . '/product_offer.csv', "product_offer_reference,concrete_sku,merchant_reference\nOFR1,C1,M1\nOFR2,C9,M9\n");
file_put_contents($mr . '/price_product_offer.csv', "product_offer_reference,value\nOFR1,10\nOFR3,20\n");
file_put_contents($mr . '/category_store.csv', "category_key,store\nCAT1,DE\n");
$mrManifest = $mr . '/full.yml';
file_put_contents(
    $mrManifest,
    "actions:\n"
    . "    - data_entity: product-abstract\n      source: product_abstract.csv\n"
    . "    - data_entity: product-concrete\n      source: product_concrete.csv\n"
    . "    - data_entity: merchant\n      source: merchant.csv\n"
    . "    - data_entity: merchant-product-offer\n      source: product_offer.csv\n"
    . "    - data_entity: price-product-offer\n      source: price_product_offer.csv\n"
    . "    - data_entity: category-store\n      source: category_store.csv\n"
);
$mrRes = validate_manifest_refs($mrManifest, $mr);
check('manifest-refs: 3 orphaned references found', count($mrRes['findings']) === 3);
check('manifest-refs: orphan concrete_sku C9 caught', count(array_filter($mrRes['findings'], fn ($f) => $f['value'] === 'C9' && $f['column'] === 'concrete_sku')) === 1);
check('manifest-refs: orphan merchant_reference M9 caught', count(array_filter($mrRes['findings'], fn ($f) => $f['value'] === 'M9' && $f['column'] === 'merchant_reference')) === 1);
check('manifest-refs: orphan product_offer_reference OFR3 caught', count(array_filter($mrRes['findings'], fn ($f) => $f['value'] === 'OFR3' && $f['column'] === 'product_offer_reference')) === 1);
check('manifest-refs: valid refs not flagged', count(array_filter($mrRes['findings'], fn ($f) => in_array($f['value'], ['A1', 'A2', 'C1', 'C2', 'M1', 'OFR1'], true))) === 0);
check('manifest-refs: category_key UNCHECKED (no producer entity), CAT1 not flagged', in_array('category_key', $mrRes['unchecked'], true) && count(array_filter($mrRes['findings'], fn ($f) => $f['value'] === 'CAT1')) === 0);
check('manifest-refs: checked families include merchant_reference + product_offer_reference', in_array('merchant_reference', $mrRes['checked'], true) && in_array('product_offer_reference', $mrRes['checked'], true));

$mrOut1 = [];
$mrCode1 = 0;
exec("{$php} {$lib} manifest-refs " . escapeshellarg($mrManifest) . ' --base ' . escapeshellarg($mr) . ' --quiet', $mrOut1, $mrCode1);
check('manifest-refs CLI: exit 2 on orphans', $mrCode1 === 2);
file_put_contents($mr . '/product_offer.csv', "product_offer_reference,concrete_sku,merchant_reference\nOFR1,C1,M1\n");
file_put_contents($mr . '/price_product_offer.csv', "product_offer_reference,value\nOFR1,10\n");
$mrOut2 = [];
$mrCode2 = 0;
exec("{$php} {$lib} manifest-refs " . escapeshellarg($mrManifest) . ' --base ' . escapeshellarg($mr) . ' --quiet', $mrOut2, $mrCode2);
check('manifest-refs CLI: exit 0 when clean', $mrCode2 === 0);

// sales_unit_key family: sales-unit-store references a sales unit that must exist
$su = $tmp . '/su';
@mkdir($su, 0777, true);
file_put_contents($su . '/sales_unit.csv', "sales_unit_key,concrete_sku\nos-su-1,C1\nos-su-2,C2\n");
file_put_contents($su . '/sales_unit_store.csv', "sales_unit_key,store_name\nos-su-1,NO\nsales_unit_9,NO\n"); // sales_unit_9 orphan
$suManifest = $su . '/full.yml';
file_put_contents(
    $suManifest,
    "actions:\n"
    . "    - data_entity: product-measurement-sales-unit\n      source: sales_unit.csv\n"
    . "    - data_entity: product-measurement-sales-unit-store\n      source: sales_unit_store.csv\n"
);
$suRes = validate_manifest_refs($suManifest, $su);
check('manifest-refs: sales_unit_key orphan caught', count(array_filter($suRes['findings'], fn ($f) => $f['value'] === 'sales_unit_9' && $f['column'] === 'sales_unit_key')) === 1);
check('manifest-refs: valid sales_unit_key not flagged', count(array_filter($suRes['findings'], fn ($f) => $f['value'] === 'os-su-1')) === 0);

// --- orphan-files: CSVs on disk under the roots that no manifest source references ---
file_put_contents($mr . '/stray_demo.csv', "sku\nX1\n");
$ofRes = validate_orphan_files($mrManifest, [$mr], $mr);
check('orphan-files: unreferenced stray file flagged', count(array_filter($ofRes['findings'], fn ($f) => str_ends_with($f['file'], 'stray_demo.csv'))) === 1);
check('orphan-files: referenced file not flagged', count(array_filter($ofRes['findings'], fn ($f) => str_ends_with($f['file'], 'product_abstract.csv'))) === 0);
$ofOut = [];
$ofCode = 0;
exec("{$php} {$lib} orphan-files " . escapeshellarg($mrManifest) . ' ' . escapeshellarg($mr) . ' --base ' . escapeshellarg($mr) . ' --quiet', $ofOut, $ofCode);
check('orphan-files CLI: exit 2 with an orphan present', $ofCode === 2);
unlink($mr . '/stray_demo.csv');
$ofOut2 = [];
$ofCode2 = 0;
exec("{$php} {$lib} orphan-files " . escapeshellarg($mrManifest) . ' ' . escapeshellarg($mr) . ' --base ' . escapeshellarg($mr) . ' --quiet', $ofOut2, $ofCode2);
check('orphan-files CLI: exit 0 when tree == manifest', $ofCode2 === 0);

// --- threshold-glossary: derived message key must resolve in every project locale ---
$tg = $tmp . '/tg';
@mkdir($tg . '/NO', 0777, true);
@mkdir($tg . '/common', 0777, true);
file_put_contents($tg . '/NO/sales_order_threshold.csv', "store,currency,threshold_type_key,threshold,fee,message_glossary_key\nNO,NOK,hard-minimum-threshold,4000,,\nNO,NOK,soft-minimum-threshold,100000,,\n");
// glossary carries nb_NO for hard-minimum only; soft-minimum + pl_PL are missing
file_put_contents(
    $tg . '/common/glossary.csv',
    "key,translation,locale\n"
    . "sales-order-threshold.hard-minimum-threshold.no.nok.message,Legg til varer,nb_NO\n"
    . "sales-order-threshold.hard-minimum-threshold.no.nok.message,Add items,pl_PL\n"
    . "sales-order-threshold.soft-minimum-threshold.no.nok.message,Legg til varer,nb_NO\n"
);
$tgManifest = $tg . '/full.yml';
file_put_contents(
    $tgManifest,
    "actions:\n"
    . "    - data_entity: sales-order-threshold\n      source: NO/sales_order_threshold.csv\n"
    . "    - data_entity: glossary\n      source: common/glossary.csv\n"
);
$tgRes = validate_threshold_glossary($tgManifest, $tg, ['nb_NO', 'pl_PL']);
// misses: hard-minimum×pl_PL is present (ok); soft-minimum×nb_NO present (ok); soft-minimum×pl_PL missing → 1
check('threshold-glossary: 1 missing (soft-minimum × pl_PL)', count($tgRes['findings']) === 1);
check('threshold-glossary: names the missing locale + derived key', $tgRes['findings'][0]['locale'] === 'pl_PL' && $tgRes['findings'][0]['key'] === 'sales-order-threshold.soft-minimum-threshold.no.nok.message');
check('threshold-glossary: counted both rows and found both files', $tgRes['thresholdRows'] === 2 && $tgRes['thresholdFiles'] === 1 && $tgRes['glossaryFiles'] === 1);
check('threshold-glossary: present key×locale not flagged', count(array_filter($tgRes['findings'], fn ($f) => $f['type'] === 'hard-minimum-threshold')) === 0);

// all locales present → clean
file_put_contents(
    $tg . '/common/glossary.csv',
    "key,translation,locale\n"
    . "sales-order-threshold.hard-minimum-threshold.no.nok.message,x,nb_NO\n"
    . "sales-order-threshold.hard-minimum-threshold.no.nok.message,x,pl_PL\n"
    . "sales-order-threshold.soft-minimum-threshold.no.nok.message,x,nb_NO\n"
    . "sales-order-threshold.soft-minimum-threshold.no.nok.message,x,pl_PL\n"
);
check('threshold-glossary: clean when every key×locale present', validate_threshold_glossary($tgManifest, $tg, ['nb_NO', 'pl_PL'])['findings'] === []);

// no glossary rows at all → every derived key×locale missing (4)
file_put_contents($tg . '/common/glossary.csv', "key,translation,locale\n");
check('threshold-glossary: no glossary → all 4 key×locale missing', count(validate_threshold_glossary($tgManifest, $tg, ['nb_NO', 'pl_PL'])['findings']) === 4);

// explicit message_glossary_key overrides the derived key
file_put_contents($tg . '/NO/sales_order_threshold.csv', "store,currency,threshold_type_key,threshold,fee,message_glossary_key\nNO,NOK,hard-minimum-threshold,4000,,custom.threshold.msg\n");
file_put_contents($tg . '/common/glossary.csv', "key,translation,locale\ncustom.threshold.msg,x,nb_NO\n");
check('threshold-glossary: explicit message_glossary_key checked instead of derived', validate_threshold_glossary($tgManifest, $tg, ['nb_NO'])['findings'] === []);

// merchant-relationship threshold entity is auto-generated → must not be checked (it would be a false positive)
@mkdir($tg . '/NO', 0777, true);
file_put_contents($tg . '/NO/sales_order_threshold_per_merchant_relationship.csv', "merchant_relation_key,store,currency,threshold_type_key,threshold,fee,message_glossary_key\nMR1,NO,NOK,soft-minimum-threshold-fixed-fee,4000,,\n");
file_put_contents($tg . '/NO/sales_order_threshold.csv', "store,currency,threshold_type_key,threshold,fee,message_glossary_key\nNO,NOK,hard-minimum-threshold,4000,,\n");
file_put_contents($tg . '/common/glossary.csv', "key,translation,locale\nsales-order-threshold.hard-minimum-threshold.no.nok.message,x,nb_NO\n");
$tgMrManifest = $tg . '/full_mr.yml';
file_put_contents(
    $tgMrManifest,
    "actions:\n"
    . "    - data_entity: sales-order-threshold\n      source: NO/sales_order_threshold.csv\n"
    . "    - data_entity: merchant-relationship-sales-order-threshold\n      source: NO/sales_order_threshold_per_merchant_relationship.csv\n"
    . "    - data_entity: glossary\n      source: common/glossary.csv\n"
);
$tgMrRes = validate_threshold_glossary($tgMrManifest, $tg, ['nb_NO']);
check('threshold-glossary: merchant-relationship entity excluded (only regular row counted)', $tgMrRes['thresholdRows'] === 1 && $tgMrRes['findings'] === []);

$tgOut = [];
$tgCode = 0;
exec("{$php} {$lib} threshold-glossary " . escapeshellarg($tgManifest) . ' --locales nb_NO,pl_PL --base ' . escapeshellarg($tg) . ' --quiet', $tgOut, $tgCode);
check('threshold-glossary CLI: exit 2 when a key×locale is missing (pl_PL)', $tgCode === 2);
$tgUsage = [];
$tgUsageCode = 0;
exec("{$php} {$lib} threshold-glossary " . escapeshellarg($tgManifest) . ' --base ' . escapeshellarg($tg) . ' --quiet', $tgUsage, $tgUsageCode);
check('threshold-glossary CLI: usage error (exit 2) when --locales omitted', $tgUsageCode === 2);

// manifest parser must pair data_entity/source within a list item regardless of key order
$po = $tmp . '/po';
@mkdir($po, 0777, true);
file_put_contents($po . '/a.csv', "x\n1\n");
file_put_contents($po . '/b.csv', "y\n2\n");
$poManifest = $po . '/full.yml';
file_put_contents(
    $poManifest,
    "actions:\n"
    . "    - source: a.csv\n      data_entity: entity-a\n"   // source BEFORE data_entity
    . "    - data_entity: entity-b\n      source: b.csv\n"
);
$poEntries = validate_manifest_entries($poManifest, $po);
check('manifest parser: source-before-data_entity paired correctly (A2)', count($poEntries) === 2
    && $poEntries[0]['data_entity'] === 'entity-a' && $poEntries[0]['source'] === 'a.csv'
    && $poEntries[1]['data_entity'] === 'entity-b' && $poEntries[1]['source'] === 'b.csv');

// derived key is fully lowercased — a mixed-case threshold type must still resolve
$b9 = $tmp . '/b9';
@mkdir($b9 . '/NO', 0777, true);
@mkdir($b9 . '/common', 0777, true);
file_put_contents($b9 . '/NO/sot.csv', "store,currency,threshold_type_key,threshold,fee,message_glossary_key\nNO,NOK,Hard-Minimum-Threshold,4000,,\n");
file_put_contents($b9 . '/common/g.csv', "key,translation,locale\nsales-order-threshold.hard-minimum-threshold.no.nok.message,x,nb_NO\n");
file_put_contents($b9 . '/full.yml', "actions:\n    - data_entity: sales-order-threshold\n      source: NO/sot.csv\n    - data_entity: glossary\n      source: common/g.csv\n");
$b9res = validate_threshold_glossary($b9 . '/full.yml', $b9, ['nb_NO']);
check('threshold-glossary: mixed-case type key fully lowercased (B9)', $b9res['findings'] === [] && $b9res['thresholdRows'] === 1);

// empty-threshold row is skipped (importer skips it), counted, and the 0-rows warning fires
$d1 = $tmp . '/d1';
@mkdir($d1 . '/NO', 0777, true);
@mkdir($d1 . '/common', 0777, true);
file_put_contents($d1 . '/NO/sot.csv', "store,currency,threshold_type_key,threshold,fee,message_glossary_key\nNO,NOK,hard-minimum-threshold,,,\n");
file_put_contents($d1 . '/common/g.csv', "key,translation,locale\n");
file_put_contents($d1 . '/full.yml', "actions:\n    - data_entity: sales-order-threshold\n      source: NO/sot.csv\n    - data_entity: glossary\n      source: common/g.csv\n");
$d1res = validate_threshold_glossary($d1 . '/full.yml', $d1, ['nb_NO']);
check('threshold-glossary: empty-threshold row skipped, no false missing key (D1/D2)', $d1res['findings'] === [] && $d1res['thresholdRows'] === 0 && $d1res['skippedRows'] === 1);
check('threshold-glossary: warns when files present but 0 checkable rows (D3)', count($d1res['warnings']) === 1);

// a threshold of 0 is skipped by Spryker's importer ('0' is falsy) → must not demand a glossary key
$b13 = $tmp . '/b13';
@mkdir($b13 . '/NO', 0777, true);
@mkdir($b13 . '/common', 0777, true);
file_put_contents($b13 . '/NO/sot.csv', "store,currency,threshold_type_key,threshold,fee,message_glossary_key\nNO,NOK,hard-minimum-threshold,0,,\n");
file_put_contents($b13 . '/common/g.csv', "key,translation,locale\n");
file_put_contents($b13 . '/full.yml', "actions:\n    - data_entity: sales-order-threshold\n      source: NO/sot.csv\n    - data_entity: glossary\n      source: common/g.csv\n");
$b13res = validate_threshold_glossary($b13 . '/full.yml', $b13, ['nb_NO']);
check('threshold-glossary: threshold=0 row skipped, no false missing key (B13)', $b13res['findings'] === [] && $b13res['thresholdRows'] === 0 && $b13res['skippedRows'] === 1);

// --- known-set: keep entity-map.yml honest against the manifest + count gate ---
$ks = $tmp . '/ks';
@mkdir($ks . '/skills', 0777, true);
@mkdir($ks . '/data', 0777, true);
// A manifest with a source-less declaration (return-reason): validate_manifest_entries drops it,
// and known-set must still not call it stale.
file_put_contents($ks . '/full.yml', "actions:\n"
    . "  - data_entity: store\n    source: data/store.csv\n"
    . "  - data_entity: stock\n    source: data/warehouse.csv\n"
    . "  - data_entity: product-abstract\n    source: data/product_abstract.csv\n"
    . "  - data_entity: return-reason\n");
@mkdir($ks . '/data', 0777, true);
file_put_contents($ks . '/data/store.csv', "name\nDE\n");
file_put_contents($ks . '/data/warehouse.csv', "warehouse_name\nW1\n");
file_put_contents($ks . '/data/product_abstract.csv', "abstract_sku\nC1\n");

// parse_entity_map basics
file_put_contents($ks . '/map-good.yml', "# header\n"
    . "- entity: store\n  source: store.csv\n  class: structural\n  why: \"stores\"\n"
    . "- entity: stock\n  source: warehouse.csv\n  class: structural\n  why: \"stock defs\"\n"
    . "- entity: product-abstract\n  source: product_abstract.csv\n  class: content\n  why: \"demo\"\n"
    . "- entity: return-reason\n  source: ~\n  class: structural\n  why: \"rma\"\n");
$parsed = validate_parse_entity_map($ks . '/map-good.yml');
check('known-set: parse_entity_map reads 4 rows', count($parsed) === 4);
check('known-set: parse reads class + why', $parsed[0]['class'] === 'structural' && $parsed[1]['why'] === 'stock defs');

// clean case: map matches manifest, source-less entity present → NO problems
$ksClean = validate_known_set($ks . '/full.yml', $ks . '/map-good.yml', $ks . '/skills', $ks);
check('known-set: clean map → no missing', $ksClean['missingFromMap'] === []);
check('known-set: source-less return-reason NOT stale', $ksClean['staleMapRows'] === []);
check('known-set: clean map → no badSource', $ksClean['badSource'] === []);
check('known-set: clean map → no unclassified/noWhy', $ksClean['unclassified'] === [] && $ksClean['structuralNoWhy'] === []);

// missing-from-map: drop product-abstract from the map
file_put_contents($ks . '/map-missing.yml', "- entity: store\n  source: store.csv\n  class: structural\n  why: \"s\"\n"
    . "- entity: stock\n  source: warehouse.csv\n  class: structural\n  why: \"s\"\n"
    . "- entity: return-reason\n  source: ~\n  class: structural\n  why: \"r\"\n");
$ksMiss = validate_known_set($ks . '/full.yml', $ks . '/map-missing.yml', $ks . '/skills', $ks);
check('known-set: manifest entity absent from map flagged', $ksMiss['missingFromMap'] === ['product-abstract']);

// stale map row: map names an entity the manifest no longer imports
file_put_contents($ks . '/map-stale.yml', "- entity: store\n  source: store.csv\n  class: structural\n  why: \"s\"\n"
    . "- entity: stock\n  source: warehouse.csv\n  class: structural\n  why: \"s\"\n"
    . "- entity: product-abstract\n  source: product_abstract.csv\n  class: content\n  why: \"d\"\n"
    . "- entity: return-reason\n  source: ~\n  class: structural\n  why: \"r\"\n"
    . "- entity: gone-entity\n  source: gone.csv\n  class: content\n  why: \"x\"\n");
$ksStale = validate_known_set($ks . '/full.yml', $ks . '/map-stale.yml', $ks . '/skills', $ks);
check('known-set: stale map row flagged', $ksStale['staleMapRows'] === ['gone-entity']);

// unclassified + structural-without-why both gate
file_put_contents($ks . '/map-bad.yml', "- entity: store\n  source: store.csv\n  class: unclassified\n  why: \"\"\n"
    . "- entity: stock\n  source: warehouse.csv\n  class: structural\n  why: \"\"\n"
    . "- entity: product-abstract\n  source: product_abstract.csv\n  class: content\n  why: \"d\"\n"
    . "- entity: return-reason\n  source: ~\n  class: structural\n  why: \"r\"\n");
$ksBad = validate_known_set($ks . '/full.yml', $ks . '/map-bad.yml', $ks . '/skills', $ks);
check('known-set: unclassified row flagged', $ksBad['unclassified'] === ['store']);
check('known-set: structural without why flagged', $ksBad['structuralNoWhy'] === ['stock']);

// badSource: map names a file the manifest does not import for that entity
file_put_contents($ks . '/map-src.yml', "- entity: store\n  source: store.csv\n  class: structural\n  why: \"s\"\n"
    . "- entity: stock\n  source: warehouse_WRONG.csv\n  class: structural\n  why: \"s\"\n"
    . "- entity: product-abstract\n  source: product_abstract.csv\n  class: content\n  why: \"d\"\n"
    . "- entity: return-reason\n  source: ~\n  class: structural\n  why: \"r\"\n");
$ksSrc = validate_known_set($ks . '/full.yml', $ks . '/map-src.yml', $ks . '/skills', $ks);
check('known-set: wrong source basename flagged', count($ksSrc['badSource']) === 1 && $ksSrc['badSource'][0]['entity'] === 'stock');

// count gate: flag real counts (incl. widened nouns + thousands separator),
// exempt zero + count-ok, and do NOT swallow a JSON list comma into a hit.
file_put_contents($ks . '/skills/a.md', "shipped 457 products across stores\n"      // caught
    . "the demo has ~44 nodes pointing at the old tree\n"                            // caught (widened noun)
    . "a stale state file says 1,178 docs long after cleanup\n"                      // caught (thousands sep)
    . "header-only files have 0 products\n"                                          // exempt (zero)
    . "product_count: 20, categories: [a, b]\n"                                      // NOT caught (JSON comma, not '20 categories')
    . "the target is ~20 products <!-- count-ok: illustrative -->\n");            // exempt (count-ok)
$ksCount = validate_scan_counts($ks . '/skills');
$matches = array_map(static fn ($c) => $c['match'], $ksCount);
check('known-set: count gate flags products + widened noun + thousands',
    count($matches) === 3
    && in_array('457 products', $matches, true)
    && in_array('~44 nodes', $matches, true)
    && in_array('1,178 docs', $matches, true));
check('known-set: count gate does not swallow a JSON list comma', !in_array('20, categories', $matches, true));

// emit-map: one row per data_entity INCLUDING source-less, preserving existing class/why
$emit = validate_emit_map($ks . '/full.yml', $ks . '/map-good.yml', $ks);
check('known-set: emit-map emits all 4 entities (incl source-less)', substr_count($emit, "- entity:") === 4);
check('known-set: emit-map preserves existing class', str_contains($emit, "class: content"));
check('known-set: emit-map marks source-less as ~', str_contains($emit, "source: ~"));
// --emit-map --out writes the skeleton itself (no shell redirect) and prints a short JSON status
$emitOut = $tmp . '/skeleton.yml';
$emitJson = json_decode((string) shell_exec("{$php} {$lib} known-set " . escapeshellarg($ks . '/full.yml') . ' --map ' . escapeshellarg($ks . '/map-good.yml') . ' --base ' . escapeshellarg($ks) . ' --emit-map --out ' . escapeshellarg($emitOut) . ' 2>&1'), true);
check('known-set: --emit-map --out writes the file', is_file($emitOut) && file_get_contents($emitOut) === $emit);
check('known-set: --emit-map --out reports path + entity count', ($emitJson['emitMap'] ?? null) === $emitOut && ($emitJson['entities'] ?? null) === 4);

// --- preflight: invariants the skills state that no other check covers ---
$g = $tmp . '/gate';
foreach (['stores/EU', 'stores/DE', 'stores/UK', 'shared', 'shared/workflow', 'catalog', 'local'] as $d) {
    @mkdir($g . '/' . $d, 0777, true);
}
file_put_contents($g . '/stores/EU/store.csv', "name\nDE\nUK\n");
file_put_contents($g . '/stores/DE/locale_store.csv', "locale_name,store_name\nde_DE,DE\nen_GB,DE\n");
file_put_contents($g . '/stores/UK/locale_store.csv', "locale_name,store_name\nen_GB,UK\n");
file_put_contents($g . '/stores/UK/cms_block_store.csv', "block_key,store_name\nb1,DE\nb2,DE\n");
file_put_contents($g . '/stores/UK/shipment_price.csv', "shipment_method_key,store,currency,value_net,value_gross\nstd,UK,GBP,0,0\n");
file_put_contents($g . '/stores/DE/cms_block_store.csv', "block_key,store_name\nb1,DE\n");
file_put_contents($g . '/shared/product_review.csv', "sku,rating\n");
file_put_contents($g . '/shared/glossary.csv', "key,translation,locale\nk1,a,en_GB\nk1,b,de_DE\n");
file_put_contents($g . '/shared/glossary_en_GB.csv', "key,translation,locale\nk2,a,en_GB\n");
file_put_contents($g . '/catalog/product_abstract.csv', "abstract_sku,category_key,color_code,template_name\nA1,models,#fff,Catalog (default)\nA2,models,#000,Default Category\n");
file_put_contents($g . '/catalog/product_abstract_extra.csv', "abstract_sku,category_key,color_code,template_name\nA3,models,#111,Catalog (default)\n");
file_put_contents($g . '/catalog/product_abstract_approval_status.csv', "sku,approval_status\nA1,approved\n");
file_put_contents($g . '/catalog/category_template.csv', "template_name,template_path\nCatalog (default),@CatalogPage/x.twig\n");
file_put_contents($g . '/catalog/category.csv', "category_key,parent_category_key,is_in_menu,template_name\nroot,,0,Catalog (default)\nmodels,root,1,Default Category\n");
file_put_contents($g . '/catalog/product_search_attribute_map.csv', "attribute_key,target_field\nfarbe,string-facet\n");
file_put_contents($g . '/catalog/product_search_attribute.csv', "key,filter_type,position\nfarbe,multi-select,1\ndelivery_time,multi-select,2\n");
file_put_contents($g . '/catalog/product_price.csv', "abstract_sku,store,currency,value_net,value_gross\nA1,UK,GBP,396.40,39640\nA2,UK,GBP,100,120\n");
file_put_contents($g . '/shared/budget.csv', "cost_center_key,amount,currency_iso_code\ncc1,2500.00,EUR\n");
file_put_contents($g . '/shared/workflow/company_onboarding.xml', "<workflow/>\n");
file_put_contents($g . '/shared/workflow.csv', "name,definition\nonboard,data/import/gate/shared/workflow/company_onboarding.xml\ngone,data/import/gate/shared/workflow/missing.xml\n");
file_put_contents($g . '/shared/terms.pdf', "%PDF-1.4\n");
file_put_contents($g . '/README.md', "readme\n");
file_put_contents($g . '/local/.gitkeep', '');
$gateYml = $g . '/local/full_EU.yml';
file_put_contents($gateYml, implode("\n", [
    'version: 0',
    'actions:',
    '  - data_entity: store',                      '    source: data/import/gate/stores/EU/store.csv',
    '  - data_entity: locale-store',               '    source: data/import/gate/stores/DE/locale_store.csv',
    '  - data_entity: locale-store',               '    source: data/import/gate/stores/UK/locale_store.csv',
    '  - data_entity: glossary',                   '    source: data/import/gate/shared/glossary.csv',
    '  - data_entity: glossary',                   '    source: data/import/gate/shared/glossary_en_GB.csv',
    '  - data_entity: category-template',          '    source: data/import/gate/catalog/category_template.csv',
    '  - data_entity: category',                   '    source: data/import/gate/catalog/category.csv',
    '  - data_entity: product-abstract',           '    source: data/import/gate/catalog/product_abstract.csv',
    '  - data_entity: product-abstract',           '    source: data/import/gate/catalog/product_abstract_extra.csv',
    '  - data_entity: product-abstract-approval-status', '    source: data/import/gate/catalog/product_abstract_approval_status.csv',
    '  - data_entity: product-price',              '    source: data/import/gate/catalog/product_price.csv',
    '  - data_entity: product-search-attribute-map', '    source: data/import/gate/catalog/product_search_attribute_map.csv',
    '  - data_entity: product-search-attribute',   '    source: data/import/gate/catalog/product_search_attribute.csv',
    '  - data_entity: product-review',             '    source: data/import/gate/shared/product_review.csv',
    '  - data_entity: cms-block-store',            '    source: data/import/gate/stores/DE/cms_block_store.csv',
    '  - data_entity: cms-block-store',            '    source: data/import/gate/stores/UK/cms_block_store.csv',
    '  - data_entity: shipment-price',             '    source: data/import/gate/stores/UK/shipment_price.csv',
    '  - data_entity: budget',                     '    source: data/import/gate/shared/budget.csv',
    '  - data_entity: workflow',                   '    source: data/import/gate/shared/workflow.csv',
]) . "\n");
@mkdir($tmp . '/data/import', 0777, true);
@rename($g, $tmp . '/data/import/gate');
$g = $tmp . '/data/import/gate';
$gateYml = $g . '/local/full_EU.yml';
$pg = validate_preflight($gateYml, $tmp);
check('preflight headerOnlySources: 0-row source flagged', count($pg['headerOnlySources']) === 1 && str_ends_with($pg['headerOnlySources'][0]['file'], 'product_review.csv'));
check('preflight glossaryMultiLocale: shared file flagged, per-locale file clean', count($pg['glossaryMultiLocale']) === 1 && str_ends_with($pg['glossaryMultiLocale'][0]['file'], '/glossary.csv') && $pg['glossaryMultiLocale'][0]['locales'] === ['de_DE', 'en_GB']);
check('preflight duplicateEntitySources: product-abstract from two files in one dir', count($pg['duplicateEntitySources']) === 1 && $pg['duplicateEntitySources'][0]['entity'] === 'product-abstract');
check('preflight duplicateEntitySources: per-store split (cms-block-store DE+UK) is NOT a finding', count(array_filter($pg['duplicateEntitySources'], fn ($d) => $d['entity'] === 'cms-block-store')) === 0);
check('preflight storeDirMismatch: UK dir carrying DE flagged with the column + count', count($pg['storeDirMismatch']) === 1 && $pg['storeDirMismatch'][0]['expected'] === 'UK' && $pg['storeDirMismatch'][0]['found'] === ['DE'] && $pg['storeDirMismatch'][0]['column'] === 'store_name' && $pg['storeDirMismatch'][0]['rows'] === 2);
check('preflight searchAttributeOverlap: farbe flagged, delivery_time not', count($pg['searchAttributeOverlap']) === 1 && $pg['searchAttributeOverlap'][0]['key'] === 'farbe');
check('preflight categoryTemplateUnknown: Default Category flagged in category.csv only (CMS templates are a different namespace)', count($pg['categoryTemplateUnknown']) === 1 && $pg['categoryTemplateUnknown'][0]['template'] === 'Default Category' && str_ends_with($pg['categoryTemplateUnknown'][0]['file'], 'category.csv'));
check('preflight undeclaredStore: clean when every store is declared', $pg['undeclaredStore'] === []);
@mkdir($tmp . '/config/install', 0777, true);
@mkdir($g . '/stripe', 0777, true);
file_put_contents($g . '/stripe/payment_method_store.csv', "payment_method_key,store\nstripe,DE\nstripe,AT\n");
file_put_contents($g . '/stripe/stripe.yml', "version: 0\nactions:\n  - data_entity: payment-method-store\n    source: data/import/gate/stripe/payment_method_store.csv\n");
file_put_contents($tmp . '/config/install/docker.yml', "sections:\n  demodata:\n    import:\n      command: 'vendor/bin/console data:import --config=data/import/local/full_\${SPRYKER_REGION}.yml'\n    stripe:\n      command: 'vendor/bin/console data:import --config=data/import/gate/stripe/stripe.yml -vvv'\n");
check('gate discovery: install-recipe --config= manifests are discovered, templated ones skipped', validate_discover_manifests($tmp, 'data/import/gate/local') === [realpath($gateYml), realpath($g . '/stripe/stripe.yml')]);
$gateS = validate_gate([$gateYml, $g . '/stripe/stripe.yml'], $tmp, [], null, [$g], false);
$undeclared = array_values(array_filter($gateS['checks']['preflight']['findings'], fn ($f) => $f['group'] === 'undeclaredStore'));
check('gate undeclaredStore: vendor config naming AT flagged against the catalogue manifest\'s stores', count($undeclared) === 1 && $undeclared[0]['values'] === ['AT'] && $gateS['stores'] === ['DE', 'UK']);
@mkdir($tmp . '/vendor/acme/pay/data/import', 0777, true);
file_put_contents($tmp . '/vendor/acme/pay/data/import/glossary.csv', "key,translation,locale\nk,a,en_US\nk,b,de_DE\n");
file_put_contents($tmp . '/vendor/acme/pay/data/import/payment_method_store.csv', "payment_method_key,store\np,DE\np,AT\n");
file_put_contents($tmp . '/vendor/acme/pay/data/import/pay.yml', "version: 0\nactions:\n  - data_entity: glossary\n    source: vendor/acme/pay/data/import/glossary.csv\n  - data_entity: payment-method-store\n    source: vendor/acme/pay/data/import/payment_method_store.csv\n");
$pfVendor = validate_preflight($tmp . '/vendor/acme/pay/data/import/pay.yml', $tmp, ['en_GB', 'de_DE'], ['DE', 'UK']);
check('preflight vendor sources: project-data groups skipped (foreign locale, multi-locale glossary), store refs still checked', $pfVendor['foreignLocaleRows'] === [] && $pfVendor['glossaryMultiLocale'] === [] && count($pfVendor['undeclaredStore']) === 1 && $pfVendor['undeclaredStore'][0]['values'] === ['AT']);
$noVendor = $tmp . '/novendor';
@mkdir($noVendor . '/data/import/local', 0777, true);
file_put_contents($noVendor . '/data/import/local/full_EU.yml', "version: 0\nactions:\n  - data_entity: glossary\n    source: vendor/spryker-eco/stripe/data/import/glossary.csv\n");
check('paths/preflight: a missing vendor/ source on an un-booted clone (no vendor dir) is not a finding', validate_paths($noVendor . '/data/import/local/full_EU.yml', $noVendor) === [] && validate_preflight($noVendor . '/data/import/local/full_EU.yml', $noVendor)['unreadableSources'] === []);
check('gate: stripe CSV referenced by the second manifest is not an orphan', count(array_filter($gateS['checks']['orphan-files']['findings'], fn ($f) => str_ends_with($f['file'], 'payment_method_store.csv'))) === 0);
check('preflight rootCategoryNotInMenu: root with is_in_menu=0 flagged', count($pg['rootCategoryNotInMenu']) === 1 && $pg['rootCategoryNotInMenu'][0]['key'] === 'root');
check('preflight approvalStatusMissing: A2 + A3 have no approval row', count($pg['approvalStatusMissing']) === 1 && $pg['approvalStatusMissing'][0]['missing'] === 2 && $pg['approvalStatusMissing'][0]['sample'] === ['A2', 'A3']);
check('preflight priceNonInteger: decimal value_net + budget amount flagged, integers not', count($pg['priceNonInteger']) === 2
    && count(array_filter($pg['priceNonInteger'], fn ($p) => $p['column'] === 'value_net' && $p['rows'] === 1)) === 1
    && count(array_filter($pg['priceNonInteger'], fn ($p) => $p['column'] === 'amount')) === 1);
check('preflight embeddedPathsMissing: missing.xml flagged, existing xml not', count($pg['embeddedPathsMissing']) === 1 && str_ends_with($pg['embeddedPathsMissing'][0]['path'], 'missing.xml'));
$pgJson = shell_exec("{$php} {$lib} preflight " . escapeshellarg($gateYml) . ' --base ' . escapeshellarg($tmp) . ' 2>&1');
$pgRep = json_decode((string) $pgJson, true);
check('CLI preflight: new groups are gating (problemCount includes them)', ($pgRep['problemCount'] ?? 0) >= 11 && isset($pgRep['storeDirMismatch']));
file_put_contents($tmp . '/gate-baseline.json', (string) $pgJson);
$pgBase = validate_preflight_apply_baseline($pg, $tmp . '/gate-baseline.json');
check('preflight --baseline: suppresses the new groups', $pgBase['storeDirMismatch'] === [] && $pgBase['headerOnlySources'] === [] && $pgBase['priceNonInteger'] === []);

$of = validate_orphan_files($gateYml, [$g], $tmp);
$ofFiles = array_map(fn ($f) => basename($f['file']), $of['findings']);
check('orphan-files: unreferenced PDF flagged', in_array('terms.pdf', $ofFiles, true));
check('orphan-files: XML referenced from a CSV value is NOT an orphan', !in_array('company_onboarding.xml', $ofFiles, true));
check('orphan-files: README/.gitkeep/manifest yml exempt', !in_array('README.md', $ofFiles, true) && !in_array('.gitkeep', $ofFiles, true) && !in_array('full_EU.yml', $ofFiles, true));
check('orphan-files: referenced count includes embedded paths', $of['referenced'] === 20);

check('gate discovery: finds data/import/local/full_*.yml under base (+ the install recipe\'s literal --config manifests)', validate_discover_manifests($tmp) === [realpath($g . '/stripe/stripe.yml')] && validate_discover_manifests($tmp, 'data/import/gate/local') === [realpath($gateYml), realpath($g . '/stripe/stripe.yml')]);
file_put_contents($tmp . '/deploy.dev.yml', "version: '0.1'\nregions:\n  EU:\n    stores:\n      DE: {}\n  US:\n    stores: {}\nx: y\n");
file_put_contents($g . '/local/full_ROBOT.yml', "version: 0\nactions: []\n");
check('gate discovery: only regions booted by deploy.dev.yml (full_ROBOT.yml skipped)', validate_deploy_regions($tmp . '/deploy.dev.yml') === ['EU', 'US'] && validate_discover_manifests($tmp, 'data/import/gate/local') === [realpath($gateYml), realpath($g . '/stripe/stripe.yml')]);
@unlink($g . '/local/full_ROBOT.yml');
file_put_contents($tmp . '/data/import/comment.yml', "actions:\n  - data_entity: store\n    source: data/import/gate/stores/EU/store.csv # add stores\n  - data_entity: x\n    source: 'data/import/gate/stores/EU/store.csv'\n");
check('manifest parsing: inline YAML comments and quotes stripped from source', validate_paths($tmp . '/data/import/comment.yml', $tmp) === [] && validate_manifest_entries($tmp . '/data/import/comment.yml', $tmp)[0]['source'] === 'data/import/gate/stores/EU/store.csv');
check('gate discovery: locales from locale-store sources, sorted unique', validate_discover_locales(validate_manifest_entries($gateYml, $tmp)) === ['de_DE', 'en_GB']);

$gate = validate_gate([$gateYml], $tmp, [], null, [$g], false);
check('gate: runs every check and names it', array_keys($gate['checks']) === ['paths', 'preflight', 'manifest-refs', 'threshold-glossary', 'orphan-files', 'sku-coverage', 'duplicate-keys', 'cms-block-store', 'store-coverage', 'locale-coverage', 'attribute-glossary', 'bundle-stock', 'tree-scope']);
check('gate: preflight findings gate', $gate['checks']['preflight']['status'] === 'error' && $gate['gatingCount'] > 0);
check('gate: locales auto-discovered when not given', $gate['locales'] === ['de_DE', 'en_GB']);
check('gate: orphan-files is a warning unless --strict', $gate['checks']['orphan-files']['status'] === 'warning');
$gateStrict = validate_gate([$gateYml], $tmp, [], null, [$g], true);
check('gate --strict: orphan-files gates', $gateStrict['checks']['orphan-files']['status'] === 'error');
exec("{$php} {$lib} gate " . escapeshellarg($gateYml) . ' --base ' . escapeshellarg($tmp) . ' --root ' . escapeshellarg($g) . ' --quiet 2>&1', $gOut, $gCode);
check('CLI gate --quiet: exit 2 on gating findings', $gCode === 2 && $gOut === []);
$gJson = json_decode((string) shell_exec("{$php} {$lib} gate " . escapeshellarg($gateYml) . ' --base ' . escapeshellarg($tmp) . ' --root ' . escapeshellarg($g) . ' 2>&1'), true);
check('CLI gate: JSON carries status/check/gatingCount/summary', ($gJson['check'] ?? '') === 'gate' && ($gJson['status'] ?? '') === 'error' && isset($gJson['gatingCount'], $gJson['summary']));
file_put_contents($tmp . '/gate-baseline.json', (string) json_encode(validate_gate([$gateYml, $g . '/stripe/stripe.yml'], $tmp, [], null, [$g], true)));
$gateB = validate_gate([$gateYml, $g . '/stripe/stripe.yml'], $tmp, [], $tmp . '/gate-baseline.json', [$g], true);
check('gate --baseline <gate report>: every known finding suppressed → ok', $gateB['status'] === 'ok' && $gateB['gatingCount'] === 0 && ($gateB['checks']['preflight']['baselined'] ?? 0) > 0);
file_put_contents($g . '/catalog/product_price.csv', "abstract_sku,store,currency,value_net,value_gross\nA1,UK,GBP,396.40,39640\nA2,UK,GBP,100,120\nA9,UK,GBP,1.5,2\n");
$gateB2 = validate_gate([$gateYml, $g . '/stripe/stripe.yml'], $tmp, [], $tmp . '/gate-baseline.json', [$g], true);
check('gate --baseline: a NEW finding still gates (new orphan abstract A9 in manifest-refs)', $gateB2['status'] === 'error' && $gateB2['checks']['manifest-refs']['findingCount'] === 1);
// --- relative embedded paths, list-valued category_key, CRLF, inventory
@mkdir($g . '/shared/files', 0777, true);
file_put_contents($g . '/shared/files/Acme_terms.pdf', "%PDF-1.4\n");
file_put_contents($g . '/shared/file.csv', "file_reference,path\nf1,gate/shared/files/Acme_terms.pdf\nf2,gate/shared/files/missing.pdf\n");
file_put_contents($g . '/shared/crlf.csv', "key,value\r\na,1\r\nb,2\r\n");
file_put_contents($g . '/catalog/product_abstract.csv', "abstract_sku,category_key,color_code,template_name\nA1,models,#fff,Catalog (default)\nA2,\"models,root\",#000,Default Category\nA3,\"models,ghost\",#111,Catalog (default)\nA4,models,#222,Catalog (default)\nA5,models,#333,Catalog (default)\n");
file_put_contents($g . '/catalog/product_concrete.csv', "abstract_sku,concrete_sku\nA1,A1-1\nA2,A2-1\nA3,A3-1\nA4,A4-1\nA5,A5-1\n");
file_put_contents($g . '/catalog/merchant_product.csv', "merchant_reference,sku\nm1,A1\nm1,A2\nm1,A4\nm1,A5\n");
file_put_contents($g . '/catalog/product_shipment_type.csv', "concrete_sku,shipment_type_key\nA1-1,delivery\nA2-1,delivery\nA4-1,delivery\nA5-1,delivery\n");
file_put_contents($gateYml, file_get_contents($gateYml)
    . "  - data_entity: file\n    source: data/import/gate/shared/file.csv\n"
    . "  - data_entity: crlf\n    source: data/import/gate/shared/crlf.csv\n"
    . "  - data_entity: product-concrete\n    source: data/import/gate/catalog/product_concrete.csv\n"
    . "  - data_entity: merchant-product\n    source: data/import/gate/catalog/merchant_product.csv\n"
    . "  - data_entity: product-shipment-type\n    source: data/import/gate/catalog/product_shipment_type.csv\n");
$pg2 = validate_preflight($gateYml, $tmp);
$embMissing = array_map(fn ($f) => $f['path'], $pg2['embeddedPathsMissing']);
check('embeddedPathsMissing: relative asset path resolved against the import root; the missing one flagged', in_array('gate/shared/files/missing.pdf', $embMissing, true) && !in_array('gate/shared/files/Acme_terms.pdf', $embMissing, true));
$of2 = validate_orphan_files($gateYml, [$g], $tmp);
check('orphan-files: a PDF referenced by a RELATIVE cell path is not an orphan', count(array_filter($of2['findings'], fn ($f) => str_ends_with($f['file'], 'Acme_terms.pdf'))) === 0);
check('preflight lineEndings: CRLF file flagged with a count', count($pg2['lineEndings']) === 1 && str_ends_with($pg2['lineEndings'][0]['file'], 'crlf.csv') && $pg2['lineEndings'][0]['crlfLines'] === 3);
$mr = validate_manifest_refs($gateYml, $tmp);
$catFindings = array_values(array_filter($mr['findings'], fn ($f) => $f['family'] === 'category_key'));
check('manifest-refs: list-valued category_key split on comma — only the unresolvable PART is a finding', count($catFindings) === 1 && $catFindings[0]['value'] === 'ghost');
$inv = validate_inventory($gateYml, $tmp);
$invByEntity = array_column($inv, null, 'source');
check('inventory: one line per manifest source with entity, rows and a text sample', count($inv) === count(validate_manifest_entries($gateYml, $tmp)) && $invByEntity['data/import/gate/catalog/category_template.csv']['rows'] === 1 && in_array('Catalog (default)', $invByEntity['data/import/gate/catalog/category_template.csv']['sample'], true));
$invPlain = shell_exec("{$php} {$lib} inventory " . escapeshellarg($gateYml) . ' --base ' . escapeshellarg($tmp) . ' --plain');
check('CLI inventory --plain: tab table headed entity/rows/source/sample', str_starts_with((string) $invPlain, "entity\trows\tsource\tsample\n") && str_contains((string) $invPlain, "product-abstract\t5\t"));
$sc = validate_sku_coverage($gateYml, $tmp);
$scA3 = array_values(array_filter($sc['findings'], fn ($f) => $f['sku'] === 'A3'));
$scA31 = array_values(array_filter($sc['findings'], fn ($f) => $f['sku'] === 'A3-1'));
check('sku-coverage: abstract A3 missing from merchant_product, concrete A3-1 missing from product_shipment_type', count($scA3) === 1 && $scA3[0]['missingFrom'] === ['merchant_product.csv'] && count($scA31) === 1 && $scA31[0]['missingFrom'] === ['product_shipment_type.csv']);
check('sku-coverage: SKUs present everywhere are not findings', count(array_filter($sc['findings'], fn ($f) => $f['sku'] === 'A1')) === 0);
$gateW = validate_gate([$gateYml], $tmp, [], null, [$g], false);
check('gate: sku-coverage runs as a WARNING check, never gating', $gateW['checks']['sku-coverage']['status'] === 'warning' && $gateW['checks']['sku-coverage']['findingCount'] === 2 && !str_contains(json_encode($gateW['summary']), 'sku-coverage: 2 finding(s)'));
check('gate: baselineSafe true when no errors', $gateW['baselineSafe'] === true);
file_put_contents($tmp . '/corrupt-baseline.json', json_encode(['check' => 'gate', 'errors' => ['gate: cannot read baseline'], 'checks' => ['preflight' => ['findings' => []]]]));
$gateC = validate_gate([$gateYml], $tmp, [], $tmp . '/corrupt-baseline.json', [$g], false);
check('gate: a corrupt baseline is a gating error naming the recapture command', $gateC['status'] === 'error' && count(array_filter($gateC['errors'], fn ($e) => str_contains($e, 'CORRUPT') && str_contains($e, '--save'))) === 1 && $gateC['baselineSafe'] === false);
exec("{$php} {$lib} gate " . escapeshellarg($gateYml) . ' --base ' . escapeshellarg($tmp) . ' --root ' . escapeshellarg($g) . ' --baseline ' . escapeshellarg($tmp . '/corrupt-baseline.json') . ' --save ' . escapeshellarg($tmp . '/should-not-exist.json') . ' 2>&1', $svOut, $svCode);
check('CLI gate --save: refused when the report is not baseline-safe', $svCode === 2 && !is_file($tmp . '/should-not-exist.json') && str_contains(implode("\n", $svOut), 'refused'));
$svJson = json_decode((string) shell_exec("{$php} {$lib} gate " . escapeshellarg($gateYml) . ' --base ' . escapeshellarg($tmp) . ' --root ' . escapeshellarg($g) . ' --save ' . escapeshellarg($tmp . '/saved-baseline.json')), true);
$saved = json_decode((string) file_get_contents($tmp . '/saved-baseline.json'), true);
check('CLI gate --save: writes the full report after the run, prints a compact summary', ($svJson['saved'] ?? '') === $tmp . '/saved-baseline.json' && !isset($svJson['checks']) && ($saved['check'] ?? '') === 'gate' && ($saved['baselineSafe'] ?? false) === true && ($saved['checks']['preflight']['findingCount'] ?? 0) > 0);
exec("{$php} {$lib} gate " . escapeshellarg($gateYml) . ' --base ' . escapeshellarg($tmp) . ' --root ' . escapeshellarg($g) . ' --baseline ' . escapeshellarg($tmp . '/saved-baseline.json') . ' --quiet 2>&1', $svOut2, $svCode2);
check('CLI gate with the saved baseline: every known finding suppressed → not exit 2', $svCode2 !== 2);
$c = $tmp . '/data/import/clean';
@mkdir($c . '/local', 0777, true);
file_put_contents($c . '/store.csv', "name\nDE\n");
file_put_contents($c . '/locale_store.csv', "locale_name,store_name\nde_DE,DE\n");
file_put_contents($c . '/local/full_EU.yml', "version: 0\nactions:\n  - data_entity: store\n    source: data/import/clean/store.csv\n  - data_entity: locale-store\n    source: data/import/clean/locale_store.csv\n");
$gateClean = validate_gate([$c . '/local/full_EU.yml'], $tmp, [], null, [$c], true);
check('gate: clean tree → status ok, 0 gating', $gateClean['status'] === 'ok' && $gateClean['gatingCount'] === 0);
exec("{$php} {$lib} gate " . escapeshellarg($c . '/local/full_EU.yml') . ' --base ' . escapeshellarg($tmp) . ' --root ' . escapeshellarg($c) . ' --strict --quiet 2>&1', $gcOut, $gcCode);
check('CLI gate --strict --quiet: exit 0 on a clean tree', $gcCode === 0);

// --- import-order, coverage, duplicate-keys,
$r = $tmp . '/data/import/r3';
foreach (['stores/GB', 'stores/DE', 'catalog', 'cms', 'shared', 'local'] as $sub) {
    @mkdir($r . '/' . $sub, 0777, true);
}
file_put_contents($r . '/stores/store.csv', "name\nGB\nDE\n");
file_put_contents($r . '/stores/GB/locale_store.csv', "locale_name,store_name\nen_GB,GB\n");
file_put_contents($r . '/stores/DE/locale_store.csv', "locale_name,store_name\nde_DE,DE\n");
file_put_contents($r . '/catalog/product_price.csv', "abstract_sku,store,currency,value_net,value_gross\nA1,GB,EUR,100,120\nA2,GB,EUR,100,120\n");
file_put_contents($r . '/stores/GB/payment_method_store.csv', "payment_method_key,store\npay,GB\n");
file_put_contents($r . '/stores/DE/payment_method_store.csv', "payment_method_key,store\npay,DE\n");
file_put_contents($r . '/catalog/product_image.csv', "image_set_name,locale,abstract_sku,external_url_large\ndefault,en_GB,A1,http://x/a.png\n");
file_put_contents($r . '/shared/glossary.csv', "key,translation,locale\nproduct.attribute.colour,Colour,en_GB\n");
file_put_contents($r . '/shared/glossary.de_DE.csv', "key,translation,locale\nproduct.attribute.colour,Farbe,de_DE\n");
file_put_contents($r . '/shared/company.csv', "key,name,is_active\nacme,Northwind Trading Ltd,true\nother,Other Ltd,true\nacme,Test Company,true\n");
file_put_contents($r . '/shared/company_role.csv', "company_role_key,company_role_name,company_key\nacme_Admin,Admin,acme\nacme_Admin,Admin,acme\n");
file_put_contents($r . '/catalog/product_abstract.csv', "abstract_sku,name.en_GB,name.de_DE,url.en_GB,url.de_DE,description.en_GB,description.de_DE\nA1,Product C,Produkt C,/ie/a1,/de/a1,Product Alpha,Product Alpha\nA2,Product Bs,Product Bs,/ie/a2,/de/a2,Product Beta,Stahlkappe\n");
file_put_contents($r . '/catalog/product_abstract_store.csv', "abstract_sku,store_name\nA1,GB\nA1,DE\nA2,GB\nA2,DE\n");
file_put_contents($r . '/cms/cms_block.csv', "block_key,block_name,active,placeholder.content.en_GB,placeholder.link.en_GB\nblck-a,A,1,hello,\nblck-b,B,1,hello,\nblck-b-de,B DE,1,hallo,\nblck-off,Off,0,x,\nblck-add,Add,1,content,\nblck-add,Add,1,,link\n");
file_put_contents($r . '/cms/cms_block_store.csv', "block_key,store_name\nblck-b,GB\nblck-b-de,DE\nblck-add,GB\nblck-add,DE\n");
file_put_contents($r . '/cms/cms_slot.csv', "slot_key,name\nslt-1,Slot 1\n");
file_put_contents($r . '/cms/cms_slot_block.csv', "slot_key,block_key,position\nslt-1,blck-b,1\nslt-1,blck-ghost,2\n");
file_put_contents($r . '/catalog/product_attribute_key.csv', "attribute_key,is_super\ncolour,0\nsize,1\n");
file_put_contents($r . '/catalog/configurable_bundle_template_slot.csv', "configurable_bundle_template_slot_key,configurable_bundle_template_key,product_list_key\ncbts-1,cbt-1,pl-1\n");
file_put_contents($r . '/catalog/product_list_to_concrete_product.csv', "product_list_key,concrete_sku\npl-1,A1-1\npl-1,A1-2\npl-1,A1-3\n");
file_put_contents($r . '/catalog/product_stock.csv', "concrete_sku,name,quantity,is_never_out_of_stock\nA1-1,Warehouse1,5,0\nA1-2,Warehouse1,0,1\nA1-3,Warehouse1,0,0\n");
$r3Actions = [
    ['store', 'stores/store.csv'], ['locale-store', 'stores/GB/locale_store.csv'], ['locale-store', 'stores/DE/locale_store.csv'],
    ['glossary', 'shared/glossary.csv'], ['glossary', 'shared/glossary.de_DE.csv'],
    ['payment-method-store', 'stores/GB/payment_method_store.csv'], ['payment-method-store', 'stores/DE/payment_method_store.csv'],
    ['company', 'shared/company.csv'], ['company-role', 'shared/company_role.csv'],
    ['product-attribute-key', 'catalog/product_attribute_key.csv'],
    ['product-abstract', 'catalog/product_abstract.csv'], ['product-abstract-store', 'catalog/product_abstract_store.csv'],
    ['product-image', 'catalog/product_image.csv'], ['product-price', 'catalog/product_price.csv'], ['product-stock', 'catalog/product_stock.csv'],
    ['cms-block', 'cms/cms_block.csv'], ['cms-slot', 'cms/cms_slot.csv'], ['cms-slot-block', 'cms/cms_slot_block.csv'], ['cms-block-store', 'cms/cms_block_store.csv'],
    ['configurable-bundle-template-slot', 'catalog/configurable_bundle_template_slot.csv'],
    ['product-list-product-concrete', 'catalog/product_list_to_concrete_product.csv'],
];
$r3Yml = $r . '/local/full_R3.yml';
$r3Body = "version: 0\nactions:\n";
foreach ($r3Actions as [$entity, $src]) {
    $r3Body .= "  - data_entity: {$entity}\n    source: data/import/r3/{$src}\n";
}
file_put_contents($r3Yml, $r3Body);
$r3Entries = validate_manifest_entries($r3Yml, $tmp);
$r3Stores = validate_discover_stores($r3Entries);
$r3Locales = validate_discover_locales($r3Entries);
check('r3 fixture: stores and locales discovered', $r3Stores === ['DE', 'GB'] && $r3Locales === ['de_DE', 'en_GB']);

$badOrderYml = $r . '/local/bad_order.yml';
file_put_contents($badOrderYml, implode('', array_map(
    static fn (array $a): string => "  - data_entity: {$a[0]}\n    source: data/import/r3/stores/store.csv\n",
    [['merchant-relationship'], ['company-business-unit'], ['merchant'], ['cms-slot-block'], ['cms-block'], ['cms-slot'], ['product-concrete'], ['product-abstract']],
)) . '');
file_put_contents($badOrderYml, "version: 0\nactions:\n" . file_get_contents($badOrderYml));
$ov = validate_preflight_order(validate_manifest_entries($badOrderYml, $tmp));
$ovBy = [];
foreach ($ov as $v) {
    $ovBy[$v['entity']] = ($ovBy[$v['entity']] ?? 0) + 1;
}
check('import-order: merchant-relationship before BOTH its parents → 2 violations', ($ovBy['merchant-relationship'] ?? 0) === 2);
check('import-order: cms-slot-block before cms-block and cms-slot → 2 violations', ($ovBy['cms-slot-block'] ?? 0) === 2);
check('import-order: product-concrete before product-abstract flagged, and the detail names both action numbers', ($ovBy['product-concrete'] ?? 0) === 1
    && count(array_filter($ov, fn ($v) => $v['entity'] === 'product-concrete' && str_contains($v['detail'], 'action #7') && str_contains($v['detail'], 'action #8'))) === 1);
$goodOrderYml = $r . '/local/good_order.yml';
file_put_contents($goodOrderYml, "version: 0\nactions:\n" . implode('', array_map(
    static fn (string $e): string => "  - data_entity: {$e}\n    source: data/import/r3/stores/store.csv\n",
    ['company-business-unit', 'merchant', 'merchant-relationship', 'cms-block', 'cms-slot', 'cms-slot-block', 'product-abstract', 'product-concrete', 'product-image', 'customer', 'customer-address'],
)));
check('import-order: a correctly ordered manifest → no violations', validate_preflight_order(validate_manifest_entries($goodOrderYml, $tmp)) === []);
check('import-order: a dependency ABSENT from the manifest is not a violation', validate_preflight_order([['data_entity' => 'merchant-relationship', 'source' => 's', 'file' => 's', 'exists' => false]]) === []);
$lateYml = $r . '/local/late_parent.yml';
file_put_contents($lateYml, "version: 0\nactions:\n" . implode('', array_map(
    static fn (string $e): string => "  - data_entity: {$e}\n    source: data/import/r3/stores/store.csv\n",
    ['merchant', 'merchant-relationship', 'merchant'],
)));
check('import-order: a SECOND merchant entry after the relation is still a violation (last occurrence)', count(validate_preflight_order(validate_manifest_entries($lateYml, $tmp))) === 1);

$sc2 = validate_store_coverage($r3Yml, $tmp, $r3Stores);
check('store-coverage: DE has zero product-price rows while GB has some', count($sc2['findings']) === 1 && $sc2['findings'][0]['value'] === 'DE' && $sc2['findings'][0]['group'] === 'product-price');
check('store-coverage: an entity SPLIT one-file-per-store is complete, not a finding', count(array_filter($sc2['findings'], fn ($f) => $f['group'] === 'payment-method-store')) === 0);
file_put_contents($r . '/catalog/product_price.csv', "abstract_sku,store,currency,value_net,value_gross\nA1,GB,EUR,100,120\nA2,DE,EUR,100,120\n");
check('store-coverage: both stores present → no finding', validate_store_coverage($r3Yml, $tmp, $r3Stores)['findings'] === []);
file_put_contents($r . '/catalog/product_price.csv', "abstract_sku,store,currency,value_net,value_gross\nA1,GB,EUR,100,120\nA2,GB,EUR,100,120\n");

$lc = validate_locale_coverage($r3Yml, $tmp, $r3Locales);
check('locale-coverage: de_DE has zero product-image rows while en_GB has some', count($lc['findings']) === 1 && $lc['findings'][0]['value'] === 'de_DE' && $lc['findings'][0]['group'] === 'product-image');
check('locale-coverage: the glossary split over one file per locale is complete', count(array_filter($lc['findings'], fn ($f) => $f['group'] === 'glossary')) === 0);
check('locale-coverage: no locales known → nothing checked (never a wall of false findings)', validate_locale_coverage($r3Yml, $tmp, [])['findings'] === []);

$tc = validate_translation_coverage([$r . '/catalog/product_abstract.csv'], 'en_GB', 'de_DE');
$tcBy = array_column($tc['findings'], null, 'base');
check('translation-coverage: the untranslated description row is found', isset($tcBy['description']) && $tcBy['description']['identical'] === 1 && $tcBy['description']['compared'] === 2);
check('translation-coverage: a translated name is not a finding, an identical short/proper name is', ($tcBy['name']['identical'] ?? 0) === 1 && $tcBy['name']['sample'] === ['Product Bs']);
check('translation-coverage: url columns are skipped by name (identical by design)', !isset($tcBy['url']));
check('translation-coverage: attribute_key_N columns are skipped (a key is not prose)', validate_translation_skip_base('attribute_key_1') && !validate_translation_skip_base('meta_keywords'));
check('translation-coverage: pure Twig/CSS markup is not "untranslated text"', validate_translatable_text("{{ content_navigation('main') }}") === '' && validate_translatable_text('<p>Waterproof</p>') === 'Waterproof');
file_put_contents($r . '/catalog/translated.csv', "sku,name.en_GB,name.de_DE\nA1,Product C,Produkt C\nA2,XL,XL\n");
$tcClean = validate_translation_coverage([$r . '/catalog/translated.csv'], 'en_GB', 'de_DE');
check('translation-coverage: fully translated file → no finding (and "XL" is below the 3-letter floor)', $tcClean['findings'] === [] && $tcClean['columnsCompared'] === 1);

$dk = validate_duplicate_keys($r3Yml, $tmp);
check('duplicate-keys: the contradicting company key is a gating finding naming the column', count($dk['findings']) === 1 && $dk['findings'][0]['value'] === 'acme' && $dk['findings'][0]['differing'] === ['name']);
check('duplicate-keys: rows that fill each other\'s blanks are ADDITIVE, not findings', count($dk['additive']) === 1 && $dk['additive'][0]['sample'] === ['blck-add']);
check('duplicate-keys: byte-identical repeats are reported separately, never gating', count($dk['identical']) === 1 && str_ends_with($dk['identical'][0]['file'], 'company_role.csv') && $dk['identical'][0]['duplicates'] === 1);
check('duplicate-keys: a relation file (product_abstract_store.csv) has no derivable key and is skipped', validate_duplicate_key_columns('/x/product_abstract_store.csv', ['abstract_sku', 'store_name']) === []);
check('duplicate-keys: the table covers the shipped primary keys', validate_duplicate_key_columns('/x/glossary.de_DE.csv', ['key', 'translation', 'locale']) === ['key', 'locale']
    && validate_duplicate_key_columns('/x/product_abstract.csv', ['abstract_sku']) === ['abstract_sku']
    && validate_duplicate_key_columns('/x/cms_block.csv', ['block_key']) === ['block_key']
    && validate_duplicate_key_columns('/x/product_list.csv', ['product_list_key', 'name']) === ['product_list_key']);
file_put_contents($r . '/shared/company.csv', "key,name,is_active\nacme,Northwind Trading Ltd,true\nother,Other Ltd,true\n");
check('duplicate-keys: unique keys → no gating finding', validate_duplicate_keys($r3Yml, $tmp)['findings'] === []);
file_put_contents($r . '/shared/company.csv', "key,name,is_active\nacme,Northwind Trading Ltd,true\nother,Other Ltd,true\nacme,Test Company,true\n");

$cb = validate_cms_block_store($r3Yml, $tmp, $r3Stores);
$cbKinds = array_count_values(array_column($cb['findings'], 'kind'));
check('cms-block-store: an active block with no store row is a finding', ($cbKinds['noStoreRow'] ?? 0) === 1 && count(array_filter($cb['findings'], fn ($f) => $f['block'] === 'blck-a')) === 1);
check('cms-block-store: a slot bound to a block that does not exist is a finding', ($cbKinds['unknownBlock'] ?? 0) === 1 && count(array_filter($cb['findings'], fn ($f) => $f['block'] === 'blck-ghost')) === 1);
check('cms-block-store: an INACTIVE block is not a finding', count(array_filter($cb['findings'], fn ($f) => $f['block'] === 'blck-off')) === 0);
check('cms-block-store: a per-store twin pair covers both stores, reported as twins not findings', ($cbKinds['storeMissing'] ?? 0) === 0 && count($cb['twins']) === 2);
check('cms-block-store: blck-1 and blck-10 are NOT twins (the separator is required)', !validate_cms_block_twin('blck-1', 'blck-10') && validate_cms_block_twin('blck-9', 'blck-9-de'));
file_put_contents($r . '/cms/cms_block_store.csv', "block_key,store_name\nblck-a,GB\nblck-b,GB\nblck-b-de,DE\nblck-add,GB\nblck-add,DE\n");
$cbPartial = validate_cms_block_store($r3Yml, $tmp, $r3Stores);
check('cms-block-store: a block assigned to ONE store with no twin is a storeMissing finding', count(array_filter($cbPartial['findings'], fn ($f) => $f['kind'] === 'storeMissing' && $f['block'] === 'blck-a' && $f['store'] === 'DE')) === 1);
file_put_contents($r . '/cms/cms_block_store.csv', "block_key,store_name\nblck-b,GB\nblck-b-de,DE\nblck-add,GB\nblck-add,DE\n");

$ag = validate_attribute_glossary($r3Yml, $tmp, $r3Locales);
check('attribute-glossary: the key with no product.attribute.<key> row is flagged once per locale', count($ag['findings']) === 2 && array_column($ag['findings'], 'key') === ['size', 'size'] && $ag['findings'][0]['glossaryKey'] === 'product.attribute.size');
check('attribute-glossary: a key present in every locale is not a finding', count(array_filter($ag['findings'], fn ($f) => $f['key'] === 'colour')) === 0);
check('attribute-glossary: a manifest with no glossary at all reports nothing (it cannot conclude)', validate_attribute_glossary($goodOrderYml, $tmp, $r3Locales)['findings'] === []);

$bs = validate_bundle_stock($r3Yml, $tmp);
check('bundle-stock: the zero-stock concrete of a bundle slot is a finding', count($bs['findings']) === 1 && $bs['findings'][0]['sku'] === 'A1-3' && $bs['concretes'] === 3);
check('bundle-stock: quantity>0 and is_never_out_of_stock both pass', count(array_filter($bs['findings'], fn ($f) => in_array($f['sku'], ['A1-1', 'A1-2'], true))) === 0);
check('bundle-stock: no bundle slots in the manifest → nothing reported', validate_bundle_stock($goodOrderYml, $tmp)['findings'] === []);

$absFinding = ['headerOnlySources' => [['file' => $r . '/catalog/product_price.csv', 'entity' => 'product-price']]];
file_put_contents($tmp . '/rel-baseline.json', (string) json_encode(['headerOnlySources' => [['file' => './data/import/r3/catalog/product_price.csv', 'entity' => 'product-price']]]));
check('baseline: a ./-prefixed relative baseline entry suppresses the absolute finding', validate_preflight_apply_baseline($absFinding, $tmp . '/rel-baseline.json', $tmp)['headerOnlySources'] === []);
check('baseline: a baseline entry for a DIFFERENT file suppresses nothing', count(validate_preflight_apply_baseline(['headerOnlySources' => [['file' => $r . '/catalog/product_image.csv', 'entity' => 'product-image']]], $tmp . '/rel-baseline.json', $tmp)['headerOnlySources']) === 1);
check('baseline: path normalisation is symmetric and ./-tolerant', validate_normalise_finding_path('./data/import/r3/catalog/product_price.csv', $tmp) === 'data/import/r3/catalog/product_price.csv'
    && validate_normalise_finding_path($r . '/catalog/product_price.csv', $tmp) === 'data/import/r3/catalog/product_price.csv'
    && validate_normalise_finding_path('data/import/r3/gone.csv', $tmp) === 'data/import/r3/gone.csv');
$gateR3 = validate_gate([$r3Yml], $tmp, [], null, [$r], false);
$relBaseline = $gateR3;
foreach ($relBaseline['checks'] as $name => $chk) {
    $relBaseline['checks'][$name]['findings'] = array_map(static function (array $f) use ($tmp): array {
        if (isset($f['file']) && is_string($f['file']) && str_starts_with($f['file'], $tmp . '/')) {
            $f['file'] = './' . substr($f['file'], strlen($tmp) + 1);
        }

        return $f;
    }, (array) $chk['findings']);
}
file_put_contents($tmp . '/rel-gate-baseline.json', (string) json_encode($relBaseline));
$gateRel = validate_gate([$r3Yml], $tmp, [], $tmp . '/rel-gate-baseline.json', [$r], false);
check('gate --baseline: a baseline whose paths are ./-relative still suppresses absolute findings', ($gateRel['checks']['duplicate-keys']['findingCount'] ?? -1) === 0 && ($gateRel['checks']['duplicate-keys']['baselined'] ?? 0) > 0);

check('tree-scope: a single top-level dir under data/import/ → no finding', validate_tree_scope([$r3Yml], $tmp)['findings'] === []);
$strayYml = $r . '/local/stray.yml';
file_put_contents($strayYml, "version: 0\nactions:\n  - data_entity: store\n    source: data/import/r3/stores/store.csv\n  - data_entity: currency\n    source: data/import/clean/store.csv\n  - data_entity: locale-store\n    source: data/import/r3/stores/GB/locale_store.csv\n");
$ts = validate_tree_scope([$strayYml], $tmp);
check('tree-scope: the source outside the majority directory is named, with the expected dir', count($ts['findings']) === 1 && $ts['findings'][0]['dir'] === 'clean' && $ts['expected'] === 'r3');
check('tree-scope: a vendor source is exempt', validate_tree_scope([$g . '/stripe/stripe.yml'], $tmp)['findings'] === []);

check('gate: duplicate-keys and cms-block-store GATE; coverage/attribute/bundle/tree-scope WARN', $gateR3['checks']['duplicate-keys']['status'] === 'error'
    && $gateR3['checks']['cms-block-store']['status'] === 'error'
    && $gateR3['checks']['store-coverage']['status'] === 'warning'
    && $gateR3['checks']['locale-coverage']['status'] === 'warning'
    && $gateR3['checks']['attribute-glossary']['status'] === 'warning'
    && $gateR3['checks']['bundle-stock']['status'] === 'warning'
    && $gateR3['checks']['tree-scope']['status'] === 'ok');
check('gate: a warning-only check never adds to gatingCount', $gateR3['checks']['store-coverage']['findingCount'] === 1 && $gateR3['warningCount'] >= 5);
$gateOrder = validate_gate([$badOrderYml], $tmp, [], null, [$r], false);
check('gate: the import-order dependency table gates through preflight\'s orderViolations', $gateOrder['checks']['preflight']['status'] === 'error'
    && count(array_filter($gateOrder['checks']['preflight']['findings'], fn ($f) => $f['group'] === 'orderViolations' && $f['entity'] === 'merchant-relationship')) === 2
    && count(array_filter($gateR3['checks']['preflight']['findings'], fn ($f) => $f['group'] === 'orderViolations')) === 0);

foreach ([
    'import-order' => 0,
    'store-coverage' => 1,
    'locale-coverage' => 1,
    'duplicate-keys' => 2,
    'cms-block-store' => 2,
    'attribute-glossary' => 1,
    'bundle-stock' => 1,
    'tree-scope' => 0,
] as $sub => $expected) {
    $out = [];
    $code = 0;
    exec("{$php} {$lib} {$sub} " . escapeshellarg($r3Yml) . ' --base ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    $rep = json_decode(implode("\n", $out), true);
    check("CLI {$sub}: exit {$expected}, JSON names the check", $code === $expected && ($rep['check'] ?? '') === $sub);
}
exec("{$php} {$lib} translation-coverage " . escapeshellarg($r . '/catalog/product_abstract.csv') . ' --from en_GB --to de_DE 2>&1', $tcOut, $tcCode);
$tcRep = json_decode(implode("\n", $tcOut), true);
check('CLI translation-coverage: exit 1 (warning) and names both locales', $tcCode === 1 && ($tcRep['from'] ?? '') === 'en_GB' && ($tcRep['to'] ?? '') === 'de_DE' && ($tcRep['findingCount'] ?? 0) >= 1);
exec("{$php} {$lib} translation-coverage " . escapeshellarg($r3Yml) . ' --from en_GB 2>&1', $tcBad, $tcBadCode);
check('CLI translation-coverage: --to missing → usage, exit 2', $tcBadCode === 2 && str_contains(implode(' ', $tcBad), '--from <locale> --to <locale>'));
exec("{$php} {$lib} 2>&1", $usageOut, $usageCode);
$usage = implode(' ', $usageOut);
check('CLI: no arguments still prints usage, now listing every new subcommand', $usageCode === 2
    && count(array_filter(['import-order', 'store-coverage', 'locale-coverage', 'translation-coverage', 'duplicate-keys', 'cms-block-store', 'attribute-glossary', 'bundle-stock', 'tree-scope', 'demo-needs'], fn ($s) => str_contains($usage, $s))) === 10);

// --- demo-needs: does the data support the demo? ---
$sc = $tmp . '/data/import/sc';
foreach (['catalog', 'cms', 'shared', 'local'] as $sub) {
    @mkdir($sc . '/' . $sub, 0777, true);
}
file_put_contents($sc . '/catalog/product_abstract.csv', "abstract_sku,name.en_GB,attribute_key_1,value_1,attribute_key_2,value_2\nPROD-1,Product A,colour,black,attr-a,yes\nPROD-2,Product A green,colour,green,attr-a,yes\nPROD-3,Product B,colour,black,attr-a,no\nDEAD-1,Out-of-stock item,colour,navy,attr-a,yes\n");
file_put_contents($sc . '/catalog/product_concrete.csv', "concrete_sku,abstract_sku,name.en_GB\nPROD-1-S,PROD-1,S\nPROD-1-M,PROD-1,M\nPROD-2-S,PROD-2,S\nPROD-3-42,PROD-3,42\nDEAD-1-S,DEAD-1,S\n");
file_put_contents($sc . '/catalog/product_stock.csv', "concrete_sku,name,quantity,is_never_out_of_stock\nPROD-1-S,W1,4,0\nPROD-1-M,W1,0,0\nPROD-2-S,W1,3,0\nPROD-3-42,W1,0,1\nDEAD-1-S,W1,0,0\n");
file_put_contents($sc . '/catalog/category.csv', "category_key,parent_category_key,name.en_GB\ncategory-a,root,Category A\n");
file_put_contents($sc . '/catalog/product_attribute_key.csv', "attribute_key,is_super\ncolour,1\nattr-a,0\n");
file_put_contents($sc . '/cms/cms_block.csv', "block_key,block_name,active,placeholder.content.en_US\nblck-hero,Hero,1,Example headline\nblck-off,Off,0,Hidden panel\n");
file_put_contents($sc . '/shared/customer.csv', "customer_reference,email,first_name\nDE--1,buyer@example.com,Sonia\n");
$scYml = $sc . '/local/full_SC.yml';
file_put_contents($scYml, "version: 0\nactions:\n"
    . "  - data_entity: product-abstract\n    source: data/import/sc/catalog/product_abstract.csv\n"
    . "  - data_entity: product-concrete\n    source: data/import/sc/catalog/product_concrete.csv\n"
    . "  - data_entity: product-stock\n    source: data/import/sc/catalog/product_stock.csv\n"
    . "  - data_entity: category\n    source: data/import/sc/catalog/category.csv\n"
    . "  - data_entity: product-attribute-key\n    source: data/import/sc/catalog/product_attribute_key.csv\n"
    . "  - data_entity: cms-block\n    source: data/import/sc/cms/cms_block.csv\n"
    . "  - data_entity: customer\n    source: data/import/sc/shared/customer.csv\n");

$scFile = $tmp . '/demo-prep.md';
file_put_contents($scFile, "# Demo needs\n\n## S1 — Seasonal look\nstory: A site manager buys a complete bundle.\npersona: buyer@example.com\nproducts: PROD-1, PROD-2\ncategories: category-a\nfacets: colour\nblocks: blck-hero\nwording: \"Example headline\"\n");
file_put_contents($tmp . '/demo-prep-alt.md', "## S9 — Alt vocabulary\nactor: buyer@example.com\nskus: PROD-1\nattributes: colour\nstrings: \"Example headline\"\n");
$alt = validate_parse_needs($tmp . '/demo-prep-alt.md');
check('demo-needs parse: actor/skus/attributes/strings are the same fields', $alt['S9']['persona'] === ['buyer@example.com'] && $alt['S9']['products'] === ['PROD-1'] && $alt['S9']['facets'] === ['colour'] && $alt['S9']['wording'] === ['Example headline']);
$parsed = validate_parse_needs($scFile);
check('demo-needs parse: one section, keys collected', count($parsed) === 1 && $parsed['S1']['products'] === ['PROD-1', 'PROD-2'] && $parsed['S1']['wording'] === ['Example headline'] && $parsed['S1']['title'] === 'Seasonal look');
$res = validate_demo_needs($scFile, [$scYml], $tmp);
check('demo-needs: a satisfied beat produces no finding', $res['findings'] === []);

file_put_contents($scFile, "## S2 — Gaps\npersona: nobody@example.com\nproducts: DEAD-1, GHOST-1\ncategories: category-c\nfacets: attr-a, spec_class\nblocks: blck-off, blck-ghost\nwording: \"<campaign headline>\"\n");
$res2 = validate_demo_needs($scFile, [$scYml], $tmp);
$g2 = array_count_values(array_column($res2['findings'], 'group'));
check('demo-needs: a product no row carries → productMissing', ($g2['productMissing'] ?? 0) === 1);
check('demo-needs: every variant out of stock → productOutOfStock', ($g2['productOutOfStock'] ?? 0) === 1);
check('demo-needs: a category that does not exist → categoryMissing', ($g2['categoryMissing'] ?? 0) === 1);
check('demo-needs: a facet with no attribute key → facetMissing', ($g2['facetMissing'] ?? 0) === 1);
check('demo-needs: a facet whose own products carry one value → facetDoesNotNarrow', ($g2['facetDoesNotNarrow'] ?? 0) === 1);
check('demo-needs: an inactive block → blockInactive, a missing one → blockMissing', ($g2['blockInactive'] ?? 0) === 1 && ($g2['blockMissing'] ?? 0) === 1);
check('demo-needs: a persona with no customer row → personaMissing', ($g2['personaMissing'] ?? 0) === 1);
check('demo-needs: wording quoted in the brief but paraphrased in content → wordingMissing', ($g2['wordingMissing'] ?? 0) === 1);

exec("{$php} {$lib} demo-needs " . escapeshellarg($scFile) . ' ' . escapeshellarg($scYml) . ' --base ' . escapeshellarg($tmp) . ' 2>&1', $scOut, $scCode);
$scRep = json_decode(implode("\n", $scOut), true);
check('CLI demo-needs: gating exit 2 and names the needs file', $scCode === 2 && ($scRep['check'] ?? '') === 'demo-needs' && ($scRep['beats'] ?? []) === ['S2']);
exec("{$php} {$lib} demo-needs " . escapeshellarg($tmp . '/nope.md') . ' ' . escapeshellarg($scYml) . ' 2>&1', $scBad, $scBadCode);
check('CLI demo-needs: a missing needs file → usage, exit 2', $scBadCode === 2);

// demo-needs: prose values in the key lines are skipped, not a finding
file_put_contents($tmp . '/demo-prep-prose.md', "## S3 — Prose\nproducts: folding table (kit 109,99 € / 129,99 zł), PROD-1\ncategories: [category-a]   # the main tree\nfacets: colour (black, green), size\npersona: Sonia (buyer@example.com)\n");
$prose = validate_parse_needs($tmp . '/demo-prep-prose.md');
check('demo-needs parse: commas inside parentheses / between digits do not split; parenthesised text dropped', $prose['S3']['products'] === ['PROD-1'] && $prose['S3']['facets'] === ['colour', 'size']);
check('demo-needs parse: a prose value is recorded as skipped (not an identifier)', count($prose['S3']['skipped']) === 1 && $prose['S3']['skipped'][0]['value'] === 'folding table' && $prose['S3']['skipped'][0]['key'] === 'products');
check('demo-needs parse: outer [ ] and a trailing # comment are dropped; persona keeps the address inside prose', $prose['S3']['categories'] === ['category-a'] && $prose['S3']['persona'] === ['buyer@example.com']);
$resProse = validate_demo_needs($tmp . '/demo-prep-prose.md', [$scYml], $tmp);
check('demo-needs: prose produces no productMissing, and is reported under skipped', array_filter($resProse['findings'], fn ($f) => $f['group'] === 'productMissing') === [] && count($resProse['skipped']) === 1 && $resProse['skipped'][0]['beat'] === 'S3');

// demo-needs: shipment / payment method names are checkout wording — shipment.csv carries them verbatim
file_put_contents($sc . '/shared/shipment.csv', "shipment_method_key,name,carrier,taxSetName\nsc-standard,Carrier Standard,Carrier,Shipment Taxes\n");
file_put_contents($sc . '/shared/payment_method.csv', "payment_method_key,payment_method_name,payment_provider_key,payment_provider_name,is_active\nscInvoice,Pay by invoice,Dummy,Dummy,1\n");
file_put_contents($scYml, file_get_contents($scYml) . "  - data_entity: shipment\n    source: data/import/sc/shared/shipment.csv\n  - data_entity: payment-method\n    source: data/import/sc/shared/payment_method.csv\n");
file_put_contents($tmp . '/demo-prep-ship.md', "## S4 — Checkout\nwording: \"Carrier Standard\", \"Pay by invoice\", \"Overnight Express\"\n");
$resShip = validate_demo_needs($tmp . '/demo-prep-ship.md', [$scYml], $tmp);
$shipMissing = array_column(array_filter($resShip['findings'], fn ($f) => $f['group'] === 'wordingMissing'), 'subject');
check('demo-needs: a shipment method name / payment method name in the brief is found in shipment.csv / payment_method.csv', array_values($shipMissing) === ['Overnight Express']);

// recursive: $tmp now contains a subdir (sub/) from the absent directory-recursion tests
$tmpIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($tmpIt as $entry) {
    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($tmp);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
