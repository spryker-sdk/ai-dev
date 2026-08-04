<?php

declare(strict_types=1);

/** Zero-dependency test for lib/validate.php. Run: php lib/tests/validate.test.php */

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

// REGRESSION (real-file bug): wrong/missing column must NOT silently pass.
// Real currency_store.csv uses 'store_name', not 'store' — checking 'store' found nothing and said ok.
$wrongCol = validate_refs(['header' => ['currency_code', 'store_name'], 'rows' => [['currency_code' => 'USD', 'store_name' => 'US']]], ['store'], ['US', 'CA']);
check('refs: missing column flagged (no false pass)', count($wrongCol) === 1 && $wrongCol[0]['value'] === 'MISSING COLUMN');
check('refs: correct column name passes', validate_refs(['header' => ['currency_code', 'store_name'], 'rows' => [['currency_code' => 'USD', 'store_name' => 'US']]], ['store_name'], ['US', 'CA']) === []);

// refs with --split (multi-value cell like included_store_names "US,CA")
$multi = ['header' => ['cat', 'included'], 'rows' => [['cat' => 'root', 'included' => 'US,CA'], ['cat' => 'x', 'included' => 'US,MX']]];
$fm = validate_refs($multi, ['included'], ['US', 'CA'], ',');
check('refs split: MX flagged, US/CA ok', count($fm) === 1 && $fm[0]['value'] === 'MX');

// --- required: no blank cells (is_searchable, spike V2a) ---
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
// directory args must be RECURSED, not silently passed (regression: fopen on a dir succeeded, fgets failed → false ok)
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

// --- refs --composite: tuple (merchant,store) ⊆ merchant_store tuples (#12) ---
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

// preflight: shipment prices are legitimately free — net-empty/gross-0 NOT flagged (D4 regression)
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

// product-refs: sku-as-underscore-token headers are auto-discovered (the reduce false-green fix)
$tokTree = $tmp . '/tok';
@mkdir($tokTree, 0777, true);
file_put_contents($tokTree . '/product_discontinued.csv', "sku_concrete,note\nZZ-GONE,x\n");
file_put_contents($tokTree . '/product_review.csv', "abstract_product_sku,rating\nZZ-GONE2,5\n");
$tokScan = validate_product_refs(validate_discover_csvs($tokTree, []), $kept, $defaultPatterns, '_skus', []);
$tokCols = array_map(fn ($c) => $c['column'], $tokScan['columns']);
check('product-refs: sku_concrete discovered via sku-token rule', in_array('sku_concrete', $tokCols, true));
check('product-refs: abstract_product_sku discovered via sku-token rule', in_array('abstract_product_sku', $tokCols, true));
check('product-refs: token-rule orphans found', count($tokScan['findings']) === 2);

// preflight --locales: foreign locale columns/rows flagged (B6a)
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

// manifest-diff (B3): entities in old-not-new = missing (with row counts); new-not-old = added
file_put_contents($tmp . '/md_old.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: product-abstract', '    source: pf_price.csv', '  - data_entity: product-shipment-type', '    source: pf_url.csv', '  - data_entity: discount', '    source: pf_search.csv']) . "\n");
file_put_contents($tmp . '/md_new.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: product-abstract', '    source: pf_price.csv', '  - data_entity: cms-block', '    source: pf_url.csv']) . "\n");
$md = validate_manifest_diff($tmp . '/md_old.yml', $tmp . '/md_new.yml', $tmp);
check('manifest-diff: 2 missing entities', count($md['missing']) === 2);
check('manifest-diff: product-shipment-type flagged missing', count(array_filter($md['missing'], fn ($x) => $x['data_entity'] === 'product-shipment-type')) === 1);
check('manifest-diff: missing entity carries row count', array_values(array_filter($md['missing'], fn ($x) => $x['data_entity'] === 'product-shipment-type'))[0]['rows'] !== null);
check('manifest-diff: cms-block flagged added', count($md['added']) === 1 && $md['added'][0]['data_entity'] === 'cms-block');
exec("{$php} {$lib} manifest-diff " . escapeshellarg($tmp . '/md_old.yml') . ' ' . escapeshellarg($tmp . '/md_new.yml') . ' --base ' . escapeshellarg($tmp) . ' --quiet 2>&1', $mdOut, $mdCode);
check('CLI manifest-diff: exit 2 when entities missing', $mdCode === 2);

// preflight shape checks: C1 color_code, C4 visibility enum, C5 approval, C6 merchant-product, F2 tax_set_name
file_put_contents($tmp . '/sh_pa.csv', "abstract_sku,name.en_US,tax_set_name,visibility\nA,Alpha,Standard Tax,1\n"); // no color_code (C1); bad tax_set_name (F2); bad visibility (C4)
file_put_contents($tmp . '/sh_tax.csv', "tax_set_name,rate\nStandard Taxes,19\n");
file_put_contents($tmp . '/sh.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: tax', '    source: sh_tax.csv', '  - data_entity: product-abstract', '    source: sh_pa.csv', '  - data_entity: merchant-product-offer', '    source: sh_pa.csv']) . "\n");
$shChecks = array_column(validate_preflight($tmp . '/sh.yml', $tmp)['shapeWarnings'], 'check');
check('preflight shape C1: color_code missing on product-abstract', in_array('color_code', $shChecks, true));
check('preflight shape C4: visibility enum violation', in_array('visibility_enum', $shChecks, true));
check('preflight shape F2: tax_set_name not in tax source', in_array('tax_set_name', $shChecks, true));
check('preflight shape C5: product-abstract without approval-status', in_array('product-approval-status', $shChecks, true));
check('preflight shape C6: merchant-product-offer without merchant-product', in_array('merchant-product', $shChecks, true));
file_put_contents($tmp . '/sh_pa_ok.csv', "abstract_sku,name.en_US,tax_set_name,color_code,visibility\nA,Alpha,Standard Taxes,#fff,PDP\n");
file_put_contents($tmp . '/sh_ok.yml', implode("\n", ['version: 0', 'actions:', '  - data_entity: tax', '    source: sh_tax.csv', '  - data_entity: product-abstract', '    source: sh_pa_ok.csv', '  - data_entity: product-approval-status', '    source: sh_tax.csv', '  - data_entity: merchant-product', '    source: sh_tax.csv', '  - data_entity: merchant-product-offer', '    source: sh_pa_ok.csv']) . "\n");
check('preflight shape: correct shapes → no shape warnings', validate_preflight($tmp . '/sh_ok.yml', $tmp)['shapeWarnings'] === []);

// refs multi-file: each column checked only against files that have it (D3 regression)
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

// recursive: $tmp now contains a subdir (sub/) from the absent directory-recursion tests
$tmpIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($tmpIt as $entry) {
    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($tmp);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
