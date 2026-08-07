<?php

declare(strict_types=1);

/**
 * Zero-dependency test for lib/csv.php. Run: php lib/tests/csv.test.php
 * Exits 0 if all pass, 1 otherwise. No phpunit (zero-dep rule).
 */

require __DIR__ . '/csv.php';

$failures = 0;
$count = 0;

function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        echo "  ok   {$name}\n";
    } else {
        echo "  FAIL {$name}\n";
        $failures++;
    }
}

$tmp = sys_get_temp_dir() . '/csv_test_' . getmypid();
@mkdir($tmp, 0777, true);

// ---------------------------------------------------------------------------
// Fixture: exercises the RFC-4180 traps that appear in real Spryker CSVs —
// a multi-line quoted field, an embedded comma, and embedded doubled quotes.
// ---------------------------------------------------------------------------
$fixture = $tmp . '/fixture.csv';
file_put_contents($fixture, implode("\n", [
    'sku,name.en_US,is_searchable.en_US',
    'A1,"Widget, deluxe",yes',
    'A2,"Multi' . "\n" . 'line name",',              // multi-line quoted + blank is_searchable
    'A3,"He said ""hi""",no',                         // embedded doubled quotes
]) . "\n");

// --- csv_read: structure + header-name access ---
$data = csv_read($fixture);
check('header parsed in order', $data['header'] === ['sku', 'name.en_US', 'is_searchable.en_US']);
check('row count (multi-line field counts as ONE row)', count($data['rows']) === 3);
check('embedded comma preserved', $data['rows'][0]['name.en_US'] === 'Widget, deluxe');
check('multi-line field preserved', $data['rows'][1]['name.en_US'] === "Multi\nline name");
check('blank cell → empty string (not missing)', $data['rows'][1]['is_searchable.en_US'] === '');
check('embedded doubled-quote decoded', $data['rows'][2]['name.en_US'] === 'He said "hi"');
check('access by header name', $data['rows'][0]['sku'] === 'A1');

// --- csv_write: round-trip fidelity ---
$roundtrip = $tmp . '/roundtrip.csv';
csv_write($roundtrip, $data['header'], $data['rows']);
$reread = csv_read($roundtrip);
check('round-trip: header identical', $reread['header'] === $data['header']);
check('round-trip: rows identical', $reread['rows'] === $data['rows']);

// --- csv_write: deterministic quoting (only quote when required) ---
$plain = $tmp . '/plain.csv';
csv_write($plain, ['a', 'b'], [['a' => 'x', 'b' => 'y']]);
check('no needless quoting', file_get_contents($plain) === "a,b\nx,y\n");
$quoted = $tmp . '/quoted.csv';
csv_write($quoted, ['a'], [['a' => 'has,comma']]);
check('quote only when needed', file_get_contents($quoted) === "a\n\"has,comma\"\n");

// --- csv_write: missing key → '', unknown key ignored ---
$partial = $tmp . '/partial.csv';
csv_write($partial, ['a', 'b'], [['a' => '1', 'z' => 'ignored']]);
check('missing key becomes empty, unknown key dropped', file_get_contents($partial) === "a,b\n1,\n");

// --- csv_filter ---
$rows = [
    ['store' => 'US', 'cur' => 'USD'],
    ['store' => 'CA', 'cur' => 'CAD'],
    ['store' => 'US', 'cur' => 'EUR'],
];
check('filter single column', csv_filter($rows, ['store' => 'US']) === [$rows[0], $rows[2]]);
check('filter multi column (AND)', csv_filter($rows, ['store' => 'US', 'cur' => 'EUR']) === [$rows[2]]);
check('filter unknown column matches nothing', csv_filter($rows, ['nope' => 'x']) === []);
check('filter no match returns []', csv_filter($rows, ['store' => 'MX']) === []);

// match modes (the email-block use case: keep rows whose key has a prefix)
$blocks = [
    ['block_key' => 'cms-block-email--registration', 'x' => '1'],
    ['block_key' => 'cms-block-email--order', 'x' => '2'],
    ['block_key' => 'cms-block-home-banner', 'x' => '3'],
];
check('match prefix: keeps only email blocks', csv_filter($blocks, ['block_key' => 'cms-block-email--'], 'prefix') === [$blocks[0], $blocks[1]]);
check('match contains', csv_filter($blocks, ['block_key' => 'home'], 'contains') === [$blocks[2]]);
check('match exact still default', csv_filter($blocks, ['block_key' => 'cms-block-email--order']) === [$blocks[1]]);

// --- CLI: columns / read / filter / delete via subprocess ---
$php = PHP_BINARY;
$lib = escapeshellarg(__DIR__ . '/csv.php');
$f = escapeshellarg($fixture);

$colsJson = shell_exec("{$php} {$lib} columns {$f}");
$cols = json_decode((string) $colsJson, true);
check('CLI columns: status ok', ($cols['status'] ?? '') === 'ok');
check('CLI columns: rowCount 3', ($cols['rowCount'] ?? null) === 3);

$delOut = $tmp . '/deleted.csv';
$delJson = shell_exec("{$php} {$lib} delete {$f} --where sku=A1 --out " . escapeshellarg($delOut));
$del = json_decode((string) $delJson, true);
check('CLI delete: matched 1', ($del['matchedRows'] ?? null) === 1);
check('CLI delete: result 2', ($del['resultRows'] ?? null) === 2);
check('CLI delete: wrote file', in_array($delOut, $del['written'] ?? [], true) && file_exists($delOut));
$afterDelete = csv_read($delOut);
check('CLI delete: A1 gone', csv_filter($afterDelete['rows'], ['sku' => 'A1']) === []);

$badJson = shell_exec("{$php} {$lib} read /no/such/file.csv 2>/dev/null");
$bad = json_decode((string) $badJson, true);
check('CLI missing file: status error', ($bad['status'] ?? '') === 'error');

// preview (no --out/--in-place) is the destructive-op gate's evidence — the
// counts are full, the echoed rows are capped (a glossary preview once printed 626 KB)
$bigPrev = $tmp . '/preview_big.csv';
$bigRows = "id,locale\n";
for ($i = 1; $i <= 60; $i++) {
    $bigRows .= "r{$i},keep\n";
}
$bigRows .= "x1,drop\n";
file_put_contents($bigPrev, $bigRows);
$prevJson = shell_exec("{$php} {$lib} delete " . escapeshellarg($bigPrev) . ' --where locale=drop');
$prev = json_decode((string) $prevJson, true);
check('CLI preview: matchedRows is the full count', ($prev['matchedRows'] ?? null) === 1);
check('CLI preview: resultRows is the full count', ($prev['resultRows'] ?? null) === 60);
check('CLI preview: echoed rows capped at 20 by default', is_array($prev['rows'] ?? null) && count($prev['rows']) === 20 && ($prev['rowsShown'] ?? null) === 20);
$prevLimJson = shell_exec("{$php} {$lib} delete " . escapeshellarg($bigPrev) . ' --where locale=drop --limit 5');
$prevLim = json_decode((string) $prevLimJson, true);
check('CLI preview: --limit overrides the cap', is_array($prevLim['rows'] ?? null) && count($prevLim['rows']) === 5 && ($prevLim['rowsShown'] ?? null) === 5);

// ---------------------------------------------------------------------------
// General mechanics (formerly the "locale-duplicate" concept — now concept-free
// ops the AI/skill drives with its own parameters).
// ---------------------------------------------------------------------------

// duplicate-columns: the locale-copy use case, but the function knows nothing of locales.
$wide = [
    'header' => ['sku', 'name.de_DE', 'name.en_US', 'is_searchable.en_US', 'url.en_US'],
    'rows' => [
        ['sku' => 'A1', 'name.de_DE' => 'Ding', 'name.en_US' => 'Widget', 'is_searchable.en_US' => 'yes', 'url.en_US' => '/en/w'],
        ['sku' => 'A2', 'name.de_DE' => '', 'name.en_US' => 'Gadget', 'is_searchable.en_US' => '', 'url.en_US' => '/en/g'],
    ],
];
$dc = csv_duplicate_columns($wide, 'en_US', 'fr_CA', ['url']);
check('dup-cols: name.fr_CA added', in_array('name.fr_CA', $dc['header'], true));
check('dup-cols: grouped after last name.* (name.en_US)', $dc['header'][array_search('name.en_US', $dc['header'], true) + 1] === 'name.fr_CA');
check('dup-cols: value copied', $dc['rows'][0]['name.fr_CA'] === 'Widget');
check('dup-cols: is_searchable copied verbatim (blank stays blank)', $dc['rows'][1]['is_searchable.fr_CA'] === '');
check('dup-cols: url base skipped (base needs transform, not copy)', !in_array('url.fr_CA', $dc['header'], true) && in_array('url', $dc['skipped'], true));
check('dup-cols: idempotent', csv_duplicate_columns($dc, 'en_US', 'fr_CA', ['url'])['added'] === []);

// duplicate-rows: the glossary use case, concept-free.
$rowsData = ['header' => ['key', 'translation', 'locale'], 'rows' => [
    ['key' => 'hi', 'translation' => 'Hello', 'locale' => 'en_US'],
    ['key' => 'bye', 'translation' => 'Bye', 'locale' => 'en_US'],
    ['key' => 'hi', 'translation' => 'Hallo', 'locale' => 'de_DE'],
]];
$dr = csv_duplicate_rows($rowsData, 'locale', 'en_US', 'fr_CA');
check('dup-rows: 2 cloned', $dr['added'] === 2);
check('dup-rows: fr_CA content = en_US', csv_filter($dr['rows'], ['locale' => 'fr_CA', 'key' => 'hi'])[0]['translation'] === 'Hello');
check('dup-rows: idempotent', csv_duplicate_rows($dr, 'locale', 'en_US', 'fr_CA')['added'] === 0);

// set: substitution use case (store/currency).
$st = csv_set(['header' => ['sku', 'store'], 'rows' => [['sku' => 'A', 'store' => 'DE'], ['sku' => 'B', 'store' => 'AT']]], 'store', 'US', ['store' => 'DE']);
check('set: only matching row changed', $st['rows'][0]['store'] === 'US' && $st['rows'][1]['store'] === 'AT');
check('set: reports changed count', $st['changed'] === 1);
$stAll = csv_set(['header' => ['sku', 'store'], 'rows' => [['sku' => 'A', 'store' => 'DE']]], 'store', 'US');
check('set: empty where = all rows', $stAll['rows'][0]['store'] === 'US');

// select: projection.
$sel = csv_select($wide, ['sku', 'name.en_US']);
check('select: header is the projection', $sel['header'] === ['sku', 'name.en_US']);
check('select: rows projected', $sel['rows'][0] === ['sku' => 'A1', 'name.en_US' => 'Widget']);

// replace: URL prefix rewrite use case (literal + regex).
$urls = ['header' => ['sku', 'url.fr_CA'], 'rows' => [
    ['sku' => 'A1', 'url.fr_CA' => '/en/widget'],
    ['sku' => 'A2', 'url.fr_CA' => '/en/gadget'],
]];
$rep = csv_replace($urls, 'url.fr_CA', '/en/', '/fr/');
check('replace literal: prefix rewritten', $rep['rows'][0]['url.fr_CA'] === '/fr/widget' && $rep['changed'] === 2);
$repRe = csv_replace($urls, 'url.fr_CA', '#^/en/#', '/fr/', true);
check('replace regex: anchored prefix', $repRe['rows'][0]['url.fr_CA'] === '/fr/widget');
check('replace missing column throws', (function () use ($urls) { try { csv_replace($urls, 'nope', 'a', 'b'); return false; } catch (RuntimeException) { return true; } })());

// scale: currency rate conversion use case.
$prices = ['header' => ['sku', 'value_gross', 'currency'], 'rows' => [
    ['sku' => 'A1', 'value_gross' => '1000', 'currency' => 'USD'],
    ['sku' => 'A2', 'value_gross' => '', 'currency' => 'USD'],       // empty → skipped
    ['sku' => 'A3', 'value_gross' => 'n/a', 'currency' => 'USD'],    // non-numeric → skipped
]];
$sc = csv_scale($prices, 'value_gross', 1.47);
check('scale: numeric multiplied + rounded', $sc['rows'][0]['value_gross'] === '1470' && $sc['changed'] === 1);
check('scale: empty + non-numeric skipped', $sc['skipped'] === 2);
$scNoRound = csv_scale(['header' => ['v'], 'rows' => [['v' => '10']]], 'v', 1.5, false);
check('scale --no-round keeps float', $scNoRound['rows'][0]['v'] === '15');
$scWhere = csv_scale($prices, 'value_gross', 2.0, true, ['currency' => 'USD']);
check('scale --where applies to matching only', $scWhere['rows'][0]['value_gross'] === '2000');

// scale JSON: volume-price tiers embedded in a cell (price_data.volume_prices).
// String-pattern based (NOT json_decode) — the real demoshop cells are often
// invalid JSON with empty values like `"gross_price":`.
$vol = ['header' => ['sku', 'price_data.volume_prices'], 'rows' => [
    ['sku' => 'A1', 'price_data.volume_prices' => '[{"quantity":10,"net_price":900,"gross_price":1000},{"quantity":50,"net_price":800,"gross_price":900}]'],
    ['sku' => 'A2', 'price_data.volume_prices' => ''],  // empty cell → skipped
    ['sku' => 'A3', 'price_data.volume_prices' => '[{"quantity":5,"net_price":350,"gross_price":}]'],  // real-world INVALID JSON: empty gross_price
]];
$sj = csv_scale($vol, 'price_data.volume_prices', 2.0, true, [], 'exact', ['net_price', 'gross_price']);
$d0 = json_decode($sj['rows'][0]['price_data.volume_prices'], true);
check('scale-json: tier net_price scaled', $d0[0]['net_price'] === 1800 && $d0[1]['net_price'] === 1600);
check('scale-json: tier gross_price scaled', $d0[0]['gross_price'] === 2000);
check('scale-json: non-target key (quantity) untouched', $d0[0]['quantity'] === 10);
check('scale-json: empty cell skipped', $sj['skipped'] === 1);
// the invalid-JSON row: net_price scales, empty gross_price left as-is (no crash)
check('scale-json: invalid-JSON net_price scaled', str_contains($sj['rows'][2]['price_data.volume_prices'], '"net_price":700'));
check('scale-json: invalid-JSON empty gross_price untouched', str_contains($sj['rows'][2]['price_data.volume_prices'], '"gross_price":}'));

// distinct: value→count inspection (replaces awk|sort|uniq -c).
$distData = ['header' => ['sku', 'currency'], 'rows' => [
    ['sku' => 'A', 'currency' => 'EUR'],
    ['sku' => 'B', 'currency' => 'EUR'],
    ['sku' => 'C', 'currency' => 'USD'],
    ['sku' => 'D', 'currency' => ''],       // empty counted under ''
]];
$dist = csv_distinct($distData, 'currency');
check('distinct: 3 distinct values', count($dist) === 3);
check('distinct: sorted by count desc (EUR first, 2)', $dist[0]['value'] === 'EUR' && $dist[0]['count'] === 2);
check('distinct: empty cell counted', in_array(['value' => '', 'count' => 1], $dist, true));
check('distinct: missing column throws', (function () use ($distData) { try { csv_distinct($distData, 'nope'); return false; } catch (RuntimeException) { return true; } })());

// CLI: --plain output for inspection commands (no JSON to parse), and clean
// JSON error (not an uncaught throw) when a command hits a missing column.
$php = PHP_BINARY;
$lib = escapeshellarg(__DIR__ . '/csv.php');
$distFile = $tmp . '/dist.csv';
file_put_contents($distFile, "sku,currency\nA,EUR\nB,EUR\nC,USD\n");
$plain = shell_exec("{$php} {$lib} distinct " . escapeshellarg($distFile) . ' --column currency --plain 2>&1');
check('CLI distinct --plain: line output "count\tvalue"', trim((string) $plain) === "2\tEUR\n1\tUSD");
$colsPlain = shell_exec("{$php} {$lib} columns " . escapeshellarg($distFile) . ' --plain 2>&1');
check('CLI columns --plain: one per line', trim((string) $colsPlain) === "sku\ncurrency");

// columns: multiple files in one call (replaces shell for-loops over many CSVs).
$f2 = $tmp . '/two.csv';
file_put_contents($f2, "a,b,c\n1,2,3\n");
$multiPlain = shell_exec("{$php} {$lib} columns " . escapeshellarg($distFile) . ' ' . escapeshellarg($f2) . ' --plain 2>&1');
check('CLI columns multi --plain: per-file delimiter + columns', trim((string) $multiPlain) === "== {$distFile} ==\nsku\ncurrency\n== {$f2} ==\na\nb\nc");
$multiJson = json_decode((string) shell_exec("{$php} {$lib} columns " . escapeshellarg($distFile) . ' ' . escapeshellarg($f2) . ' 2>&1'), true);
check('CLI columns multi JSON: files array', count($multiJson['files'] ?? []) === 2 && $multiJson['files'][1]['header'] === ['a', 'b', 'c']);
$multiErr = json_decode((string) shell_exec("{$php} {$lib} columns " . escapeshellarg($distFile) . ' ' . escapeshellarg($tmp . '/nope.csv') . ' 2>&1'), true);
check('CLI columns multi: missing file reported inline, batch survives', count($multiErr['files'] ?? []) === 2 && isset($multiErr['files'][1]['error']));

// count: data-row count per file, many files in ONE call (replaces `for f in …; do … | grep rowCount; done`)
$cntOne = json_decode((string) shell_exec("{$php} {$lib} count " . escapeshellarg($distFile) . ' 2>&1'), true);
check('CLI count single: rowCount 3', ($cntOne['rowCount'] ?? null) === 3);
$cntPlain = trim((string) shell_exec("{$php} {$lib} count " . escapeshellarg($distFile) . ' ' . escapeshellarg($f2) . ' --plain 2>&1'));
check('CLI count multi --plain: rowCount<TAB>file per line', $cntPlain === "3\t{$distFile}\n1\t{$f2}");
$cntJson = json_decode((string) shell_exec("{$php} {$lib} count " . escapeshellarg($distFile) . ' ' . escapeshellarg($f2) . ' 2>&1'), true);
check('CLI count multi JSON: per-file rowCounts', ($cntJson['files'][0]['rowCount'] ?? null) === 3 && ($cntJson['files'][1]['rowCount'] ?? null) === 1);
$cntErr = json_decode((string) shell_exec("{$php} {$lib} count " . escapeshellarg($distFile) . ' ' . escapeshellarg($tmp . '/nope.csv') . ' 2>&1'), true);
check('CLI count: missing file inline, batch survives', ($cntErr['files'][0]['rowCount'] ?? null) === 3 && isset($cntErr['files'][1]['error']));
$errJson = shell_exec("{$php} {$lib} distinct " . escapeshellarg($distFile) . ' --column nope 2>&1');
$errRep = json_decode((string) $errJson, true);
check('CLI missing column → clean JSON error (no stack trace)', ($errRep['status'] ?? '') === 'error' && str_contains($errRep['errors'][0] ?? '', "no 'nope' column"));

// --in-place + batch multi-file mutation (replaces shell for-loops).
$ip1 = $tmp . '/ip1.csv';
$ip2 = $tmp . '/ip2.csv';
file_put_contents($ip1, "sku,store\nA,DE\nB,DE\n");
file_put_contents($ip2, "sku,store\nC,DE\n");
// single file --in-place: writes back to itself
$ipJson = json_decode((string) shell_exec("{$php} {$lib} set " . escapeshellarg($ip1) . ' --column store --value PL --in-place 2>&1'), true);
check('set --in-place: single-file flat report', ($ipJson['status'] ?? '') === 'ok' && ($ipJson['written'][0] ?? '') === $ip1);
check('set --in-place: file rewritten', str_contains((string) file_get_contents($ip1), 'A,PL'));
// batch: two files in one call, --in-place
$batchJson = json_decode((string) shell_exec("{$php} {$lib} set " . escapeshellarg($ip1) . ' ' . escapeshellarg($ip2) . ' --column store --value UA --in-place 2>&1'), true);
check('set batch: files[] report with 2 entries', count($batchJson['files'] ?? []) === 2);
check('set batch: both files rewritten in place', str_contains((string) file_get_contents($ip1), 'A,UA') && str_contains((string) file_get_contents($ip2), 'C,UA'));
// multiple files without --in-place is an error (can't target one --out)
$multiErrJson = json_decode((string) shell_exec("{$php} {$lib} set " . escapeshellarg($ip1) . ' ' . escapeshellarg($ip2) . ' --column store --value X --out /tmp/x.csv 2>&1'), true);
check('batch without --in-place → error', ($multiErrJson['status'] ?? '') === 'error');
// batch replace --in-place (URL-prefix style across files)
file_put_contents($ip1, "sku,url\nA,/en/a\n");
file_put_contents($ip2, "sku,url\nB,/en/b\n");
shell_exec("{$php} {$lib} replace " . escapeshellarg($ip1) . ' ' . escapeshellarg($ip2) . " --column url --search '#^/en/#' --with /pl/ --regex --in-place 2>&1");
check('replace batch --in-place: both rewritten', str_contains((string) file_get_contents($ip1), '/pl/a') && str_contains((string) file_get_contents($ip2), '/pl/b'));
// distinct across multiple files
file_put_contents($ip1, "sku,cur\nA,EUR\n");
file_put_contents($ip2, "sku,cur\nB,PLN\n");
$distMultiJson = json_decode((string) shell_exec("{$php} {$lib} distinct " . escapeshellarg($ip1) . ' ' . escapeshellarg($ip2) . ' --column cur 2>&1'), true);
check('distinct multi-file: files[] with per-file distinct', count($distMultiJson['files'] ?? []) === 2 && $distMultiJson['files'][1]['distinct'][0]['value'] === 'PLN');

// list-valued --to: fan out over several locales/stores in one call.
$fanFile = $tmp . '/fan.csv';
file_put_contents($fanFile, "sku,name.en_US,url.en_US\nA,Widget,/en/a\n");
$fanJson = json_decode((string) shell_exec("{$php} {$lib} duplicate-columns " . escapeshellarg($fanFile) . ' --from en_US --to pl_PL,uk_UA --skip-base url --in-place 2>&1'), true);
check('dup-columns list --to: both locales added', in_array('name.pl_PL', $fanJson['addedColumns'] ?? [], true) && in_array('name.uk_UA', $fanJson['addedColumns'] ?? [], true));
check('dup-columns list --to: url skipped for both', !in_array('url.pl_PL', $fanJson['addedColumns'] ?? [], true));
$glossFile = $tmp . '/gloss.csv';
file_put_contents($glossFile, "key,translation,locale\nk,V,en_US\n");
$drJson = json_decode((string) shell_exec("{$php} {$lib} duplicate-rows " . escapeshellarg($glossFile) . ' --column locale --from en_US --to pl_PL,uk_UA --in-place 2>&1'), true);
check('dup-rows list --to: 2 rows added (one per locale)', ($drJson['addedRows'] ?? 0) === 2);

// scale --rates: per-currency factors in one call.
$rateFile = $tmp . '/rates.csv';
file_put_contents($rateFile, "sku,value_gross,currency\nA,1000,PLN\nB,1000,UAH\nC,1000,EUR\n");
shell_exec("{$php} {$lib} scale " . escapeshellarg($rateFile) . ' --column value_gross --rates PLN=4.3,UAH=45 --in-place 2>&1');
$rateData = csv_read($rateFile);
check('scale --rates: PLN×4.3', csv_filter($rateData['rows'], ['sku' => 'A'])[0]['value_gross'] === '4300');
check('scale --rates: UAH×45', csv_filter($rateData['rows'], ['sku' => 'B'])[0]['value_gross'] === '45000');
check('scale --rates: unlisted currency (EUR) untouched', csv_filter($rateData['rows'], ['sku' => 'C'])[0]['value_gross'] === '1000');

// scale: repeated --column scales net + gross together in one call (D1 regression — was a TypeError)
$multiColFile = $tmp . '/multicol.csv';
file_put_contents($multiColFile, "sku,value_net,value_gross\nA,100,200\n");
$mc = json_decode((string) shell_exec("{$php} {$lib} scale " . escapeshellarg($multiColFile) . ' --column value_net --column value_gross --by 2 --in-place 2>&1'), true);
check('scale repeated --column: status ok (no TypeError)', ($mc['status'] ?? '') === 'ok');
$mcData = csv_read($multiColFile);
check('scale repeated --column: net doubled', csv_filter($mcData['rows'], ['sku' => 'A'])[0]['value_net'] === '200');
check('scale repeated --column: gross doubled', csv_filter($mcData['rows'], ['sku' => 'A'])[0]['value_gross'] === '400');

// read: multiple files rejected, not silently truncated to the first (D2 regression)
$rd1 = $tmp . '/rd1.csv';
$rd2 = $tmp . '/rd2.csv';
file_put_contents($rd1, "a\n1\n");
file_put_contents($rd2, "a\n2\n");
$rdJson = json_decode((string) shell_exec("{$php} {$lib} read " . escapeshellarg($rd1) . ' ' . escapeshellarg($rd2) . ' 2>&1'), true);
check('read: multiple files → error (no silent truncation)', ($rdJson['status'] ?? '') === 'error');

// apply-translations: source→target map, only target column changes (safe by construction).
$trFile = $tmp . '/tr.csv';
file_put_contents($trFile, "sku,name.en_US,name.uk_UA,url.uk_UA\nA,Widget,Widget,/uk/a\nB,Gadget,Gadget,/uk/b\nC,Thing,Thing,/uk/c\n");
$trMap = $tmp . '/map.csv';
file_put_contents($trMap, "source,target\nWidget,Віджет\nGadget,Гаджет\n");   // C/Thing intentionally unmapped
$trData0 = csv_read($trFile);
$trJson = json_decode((string) shell_exec("{$php} {$lib} apply-translations " . escapeshellarg($trFile) . ' --source-column name.en_US --target-column name.uk_UA --map ' . escapeshellarg($trMap) . ' --in-place 2>&1'), true);
check('apply-translations: 2 cells translated', ($trJson['translatedCells'] ?? 0) === 2);
$trData1 = csv_read($trFile);
check('apply-translations: target translated', csv_filter($trData1['rows'], ['sku' => 'A'])[0]['name.uk_UA'] === 'Віджет');
check('apply-translations: unmapped source left as-is', csv_filter($trData1['rows'], ['sku' => 'C'])[0]['name.uk_UA'] === 'Thing');
check('apply-translations: source column untouched', csv_filter($trData1['rows'], ['sku' => 'A'])[0]['name.en_US'] === 'Widget');
check('apply-translations: non-target column (url) byte-identical', array_column($trData0['rows'], 'url.uk_UA') === array_column($trData1['rows'], 'url.uk_UA'));
check('apply-translations: missing map columns → clean error', ($e = json_decode((string) shell_exec("{$php} {$lib} apply-translations " . escapeshellarg($trFile) . ' --target-column name.uk_UA --map ' . escapeshellarg($trFile) . ' --in-place 2>&1'), true)) && ($e['status'] ?? '') === 'error');

// REGRESSION (review): scale must reject a bad factor, not silently zero prices.
$badScaleFile = $tmp . '/badscale.csv';
file_put_contents($badScaleFile, "sku,value_gross,currency\nA,1000,PLN\n");
$bs1 = json_decode((string) shell_exec("{$php} {$lib} scale " . escapeshellarg($badScaleFile) . ' --column value_gross --by abc --in-place 2>&1'), true);
check('scale --by non-numeric → error, not zeroed', ($bs1['status'] ?? '') === 'error');
check('scale --by non-numeric: file untouched (still 1000)', str_contains((string) file_get_contents($badScaleFile), '1000'));
$bs2 = json_decode((string) shell_exec("{$php} {$lib} scale " . escapeshellarg($badScaleFile) . ' --column value_gross --by 0 --in-place 2>&1'), true);
check('scale --by 0 → error (0 is never a valid factor)', ($bs2['status'] ?? '') === 'error');
$bs3 = json_decode((string) shell_exec("{$php} {$lib} scale " . escapeshellarg($badScaleFile) . ' --column value_gross --rates PLN= --in-place 2>&1'), true);
check('scale --rates with empty rate → error', ($bs3['status'] ?? '') === 'error');
check('scale bad rate: file still 1000', str_contains((string) file_get_contents($badScaleFile), '1000'));

// REGRESSION (review): a malformed --where must be a hard error, not silently dropped.
$whereFile = $tmp . '/where.csv';
file_put_contents($whereFile, "sku,currency\nA,PLN\nB,UAH\n");
$w1 = json_decode((string) shell_exec("{$php} {$lib} delete " . escapeshellarg($whereFile) . ' --where currency=PLN --where TYPObadpair --in-place 2>&1'), true);
check('delete with malformed --where → error (no silent broadening)', ($w1['status'] ?? '') === 'error');
check('delete malformed --where: nothing deleted (both rows remain)', substr_count((string) file_get_contents($whereFile), "\n") === 3);

// set-membership filter/delete (--in / --in-file): keep/drop rows whose column value is in a set.
$setFile = $tmp . '/set1.csv';
$setFile2 = $tmp . '/set2.csv';
file_put_contents($setFile, "sku,name\nABC-1,a\nXYZ-9,b\nQWE-5,c\nZZZ-0,d\n");
file_put_contents($setFile2, "sku,name\nABC-1,e\nNOPE-2,f\n");
$keep = $tmp . '/keep.txt';
file_put_contents($keep, "ABC-1\nQWE-5\n");
// --in-file across TWO files in one call, in place
$setJson = json_decode((string) shell_exec("{$php} {$lib} filter " . escapeshellarg($setFile) . ' ' . escapeshellarg($setFile2) . ' --in-file sku=' . escapeshellarg($keep) . ' --in-place 2>&1'), true);
check('filter --in-file batch: files[] with 2 entries', count($setJson['files'] ?? []) === 2);
$s1 = csv_read($setFile);
$s2 = csv_read($setFile2);
check('filter --in-file: kept only set members (file1: ABC-1,QWE-5)', array_column($s1['rows'], 'sku') === ['ABC-1', 'QWE-5']);
check('filter --in-file: file2 kept only ABC-1', array_column($s2['rows'], 'sku') === ['ABC-1']);
// --in inline + delete (drop members)
file_put_contents($setFile, "sku\nA\nB\nC\n");
shell_exec("{$php} {$lib} delete " . escapeshellarg($setFile) . ' --in sku=B,C --in-place 2>&1');
check('delete --in: dropped set members, kept the rest', array_column(csv_read($setFile)['rows'], 'sku') === ['A']);
// malformed --in (no =) is an error, not silent
$badIn = json_decode((string) shell_exec("{$php} {$lib} filter " . escapeshellarg($setFile) . ' --in bogusnoeq --in-place 2>&1'), true);
check('filter --in malformed → error', ($badIn['status'] ?? '') === 'error');
// no condition at all → error
$noCond = json_decode((string) shell_exec("{$php} {$lib} filter " . escapeshellarg($setFile) . ' --out ' . escapeshellarg($tmp . '/x.csv') . ' 2>&1'), true);
check('filter with no --where/--in → error', ($noCond['status'] ?? '') === 'error');

// drop-columns: remove named/suffix-matched columns (inverse of select).
$drop1 = csv_drop_columns($wide, ['name.de_DE']);
check('drop-columns: named column removed from header', !in_array('name.de_DE', $drop1['header'], true));
check('drop-columns: named column removed from rows', !array_key_exists('name.de_DE', $drop1['rows'][0]));
check('drop-columns: other columns kept in order', $drop1['header'] === ['sku', 'name.en_US', 'is_searchable.en_US', 'url.en_US']);
check('drop-columns: reports dropped', $drop1['dropped'] === ['name.de_DE']);
$dropMulti = csv_drop_columns($wide, ['name.de_DE', 'url.en_US']);
check('drop-columns: multiple named columns removed', $dropMulti['header'] === ['sku', 'name.en_US', 'is_searchable.en_US']);
$dropSuffix = csv_drop_columns($wide, [], ['.en_US']);
check('drop-columns: --suffix drops whole family', $dropSuffix['header'] === ['sku', 'name.de_DE']);
check('drop-columns: --suffix reports every dropped column', $dropSuffix['dropped'] === ['name.en_US', 'is_searchable.en_US', 'url.en_US']);
$dropAbsent = csv_drop_columns($wide, ['nope']);
check('drop-columns: absent column is a no-op (nothing dropped, header unchanged)', $dropAbsent['dropped'] === [] && $dropAbsent['header'] === $wide['header']);
check('drop-columns: absent column reported in skippedColumns', $dropAbsent['skippedColumns'] === ['nope']);

// CLI drop-columns --out (single file); multi-line quoted field survives a drop.
$dropFile = $tmp . '/drop.csv';
file_put_contents($dropFile, implode("\n", [
    'sku,name.en_US,name.de_DE',
    'A1,"Multi' . "\n" . 'line",Ding',
    'A2,Widget,Dings',
]) . "\n");
$dropOut = $tmp . '/drop_out.csv';
$dropJson = json_decode((string) shell_exec("{$php} {$lib} drop-columns " . escapeshellarg($dropFile) . ' --column name.de_DE --out ' . escapeshellarg($dropOut) . ' 2>&1'), true);
check('CLI drop-columns --out: status ok', ($dropJson['status'] ?? '') === 'ok');
check('CLI drop-columns --out: droppedColumns reported', ($dropJson['droppedColumns'] ?? []) === ['name.de_DE']);
$dropRead = csv_read($dropOut);
check('CLI drop-columns --out: header without dropped column', $dropRead['header'] === ['sku', 'name.en_US']);
check('CLI drop-columns --out: multi-line quoted field preserved', $dropRead['rows'][0]['name.en_US'] === "Multi\nline");

// CLI drop-columns repeated --column (proves the repeatable flag through parse).
$multiColFile = $tmp . '/multicol.csv';
file_put_contents($multiColFile, "sku,a,b,c\n1,x,y,z\n");
$mcJson = json_decode((string) shell_exec("{$php} {$lib} drop-columns " . escapeshellarg($multiColFile) . ' --column a --column c --in-place 2>&1'), true);
check('CLI drop-columns repeated --column: both dropped', ($mcJson['droppedColumns'] ?? []) === ['a', 'c']);
check('CLI drop-columns repeated --column: only b remains', csv_read($multiColFile)['header'] === ['sku', 'b']);
$noArgJson = json_decode((string) shell_exec("{$php} {$lib} drop-columns " . escapeshellarg($multiColFile) . ' --in-place 2>&1'), true);
check('drop-columns with no --column/--suffix → error', ($noArgJson['status'] ?? '') === 'error');

// CLI drop-columns batch --in-place: --suffix family + an absent --column (reported, not fatal).
$dcp1 = $tmp . '/dcp1.csv';
$dcp2 = $tmp . '/dcp2.csv';
file_put_contents($dcp1, "sku,name.de_DE,name.en_US,url.de_DE\nA,Ding,Widget,/de/a\n");
file_put_contents($dcp2, "sku,name.de_DE,name.en_US\nB,Sache,Gadget\n");   // no url.de_DE, no 'price' col
$dcpJson = json_decode((string) shell_exec("{$php} {$lib} drop-columns " . escapeshellarg($dcp1) . ' ' . escapeshellarg($dcp2) . ' --suffix .de_DE --column price --in-place 2>&1'), true);
check('drop-columns batch: files[] with 2 entries', count($dcpJson['files'] ?? []) === 2);
check('drop-columns batch: suffix family stripped from file1', csv_read($dcp1)['header'] === ['sku', 'name.en_US']);
check('drop-columns batch: suffix stripped from file2 (only name.de_DE present)', csv_read($dcp2)['header'] === ['sku', 'name.en_US']);
check('drop-columns batch: absent --column reported per-file, batch survives (status ok)', ($dcpJson['files'][0]['skippedColumns'] ?? null) === ['price'] && ($dcpJson['status'] ?? '') === 'ok');

// rename-columns: rename header + row keys by old:new pairs.
$rc = csv_rename_columns(['header' => ['abstract_sku', 'value_net', 'store'], 'rows' => [['abstract_sku' => 'A1', 'value_net' => '10', 'store' => 'NO']]], [['abstract_sku', 'concrete_sku'], ['value_net', 'net']]);
check('rename-columns: header renamed', $rc['header'] === ['concrete_sku', 'net', 'store']);
check('rename-columns: row keys renamed, values kept', $rc['rows'][0] === ['concrete_sku' => 'A1', 'net' => '10', 'store' => 'NO']);
check('rename-columns: renamed pairs reported', $rc['renamed'] === [['abstract_sku', 'concrete_sku'], ['value_net', 'net']]);
$rcAbsent = csv_rename_columns(['header' => ['sku'], 'rows' => [['sku' => 'A']]], [['nope', 'x']]);
check('rename-columns: absent old → skipped, header unchanged', $rcAbsent['header'] === ['sku'] && $rcAbsent['skippedColumns'] === ['nope']);
$rcCollide = csv_rename_columns(['header' => ['a', 'b'], 'rows' => [['a' => '1', 'b' => '2']]], [['a', 'b']]);
check('rename-columns: rename onto existing column skipped (no silent merge)', $rcCollide['header'] === ['a', 'b'] && $rcCollide['skippedColumns'] === ['a']);

// CLI rename-columns --in-place: the ostrem offer-price case (product_price columns → offer refs).
$rnFile = $tmp . '/rn.csv';
file_put_contents($rnFile, "concrete_sku,value_net,value_gross\nOST-1,10,12\n");
$rnJson = json_decode((string) shell_exec("{$php} {$lib} rename-columns " . escapeshellarg($rnFile) . ' --rename concrete_sku:product_offer_reference --in-place 2>&1'), true);
check('CLI rename-columns: status ok + renamedColumns reported', ($rnJson['status'] ?? '') === 'ok' && ($rnJson['renamedColumns'][0] ?? []) === ['concrete_sku', 'product_offer_reference']);
check('CLI rename-columns: header rewritten in place', csv_read($rnFile)['header'] === ['product_offer_reference', 'value_net', 'value_gross']);
$rnBad = json_decode((string) shell_exec("{$php} {$lib} rename-columns " . escapeshellarg($rnFile) . ' --rename noColon --in-place 2>&1'), true);
check('rename-columns: malformed --rename (no colon) → error', ($rnBad['status'] ?? '') === 'error');
$rnNoArg = json_decode((string) shell_exec("{$php} {$lib} rename-columns " . escapeshellarg($rnFile) . ' --in-place 2>&1'), true);
check('rename-columns: no --rename → error', ($rnNoArg['status'] ?? '') === 'error');
$rnb1 = $tmp . '/rnb1.csv';
$rnb2 = $tmp . '/rnb2.csv';
file_put_contents($rnb1, "a,b\n1,2\n");
file_put_contents($rnb2, "a,b\n3,4\n");
$rnbJson = json_decode((string) shell_exec("{$php} {$lib} rename-columns " . escapeshellarg($rnb1) . ' ' . escapeshellarg($rnb2) . ' --rename a:x --rename b:y --in-place 2>&1'), true);
check('rename-columns batch: files[] with 2 entries', count($rnbJson['files'] ?? []) === 2);
check('rename-columns batch: both files renamed', csv_read($rnb1)['header'] === ['x', 'y'] && csv_read($rnb2)['header'] === ['x', 'y']);

// derive: target = source × factor (cross-column arithmetic scale can't do).
$dv = csv_derive(['header' => ['sku', 'value_net'], 'rows' => [['sku' => 'A', 'value_net' => '100'], ['sku' => 'B', 'value_net' => '']]], 'value_gross', 'value_net', 1.19);
check('derive: target column created', in_array('value_gross', $dv['header'], true));
check('derive: target = source × factor (rounded)', $dv['rows'][0]['value_gross'] === '119');
check('derive: empty source skipped, target blank', $dv['rows'][1]['value_gross'] === '');
check('derive: changed/skipped counts', $dv['changed'] === 1 && $dv['skipped'] === 1);
$dvNoRound = csv_derive(['header' => ['n'], 'rows' => [['n' => '10']]], 'g', 'n', 1.25, false);
check('derive: --no-round keeps float', $dvNoRound['rows'][0]['g'] === '12.5');
$dvOnlyEmpty = csv_derive(['header' => ['n', 'g'], 'rows' => [['n' => '10', 'g' => '99'], ['n' => '20', 'g' => '']]], 'g', 'n', 2.0, true, [], 'exact', true);
check('derive: --only-empty fills blanks, keeps existing', $dvOnlyEmpty['rows'][0]['g'] === '99' && $dvOnlyEmpty['rows'][1]['g'] === '40');
$dvThrew = false;
try {
    csv_derive(['header' => ['x'], 'rows' => []], 'g', 'n', 2.0);
} catch (Throwable $e) {
    $dvThrew = true;
}
check('derive: missing source column throws', $dvThrew);

$dvFile = $tmp . '/dv.csv';
file_put_contents($dvFile, "sku,value_net\nA,100\nB,200\n");
$dvJson = json_decode((string) shell_exec("{$php} {$lib} derive " . escapeshellarg($dvFile) . ' --target value_gross --source value_net --factor 1.19 --in-place 2>&1'), true);
check('CLI derive: status ok, 2 changed', ($dvJson['status'] ?? '') === 'ok' && ($dvJson['changed'] ?? 0) === 2);
$dvRead = csv_read($dvFile);
check('CLI derive: gross computed in place', $dvRead['header'] === ['sku', 'value_net', 'value_gross'] && $dvRead['rows'][0]['value_gross'] === '119' && $dvRead['rows'][1]['value_gross'] === '238');
$dvBad = json_decode((string) shell_exec("{$php} {$lib} derive " . escapeshellarg($dvFile) . ' --target g --source value_net --factor abc --in-place 2>&1'), true);
check('derive: non-numeric --factor → error (nothing zeroed)', ($dvBad['status'] ?? '') === 'error');
$dvNoArg = json_decode((string) shell_exec("{$php} {$lib} derive " . escapeshellarg($dvFile) . ' --source value_net --factor 2 --in-place 2>&1'), true);
check('derive: missing --target → error', ($dvNoArg['status'] ?? '') === 'error');

// B4 regression — a file arg placed AFTER the first flag is a stray positional → error, not silently ignored.
$b4a = $tmp . '/b4a.csv';
$b4b = $tmp . '/b4b.csv';
file_put_contents($b4a, "sku,store\nA,X\n");
file_put_contents($b4b, "sku,store\nB,X\n");
$b4 = json_decode((string) shell_exec("{$php} {$lib} set " . escapeshellarg($b4a) . ' --column store --value Y ' . escapeshellarg($b4b) . ' --in-place 2>&1'), true);
check('CLI: file arg after first flag → error, not silent skip (B4)', ($b4['status'] ?? '') === 'error');
check('CLI: the stray-arg error left the second file untouched (B4)', csv_read($b4b)['rows'][0]['store'] === 'X');

// A1/A2 regression — filter/delete must ERROR on an ABSENT column, not silently keep-nothing.
// (Following clean.md's cms_block step with the wrong `name` column wiped every email body and reported "ok".)
$cmsFile = $tmp . '/cms_block.csv';
file_put_contents($cmsFile, "block_key,block_name\ncms-block-email--registration,Reg\nhome-hero,Hero\n");
$fMiss = json_decode((string) shell_exec("{$php} {$lib} filter " . escapeshellarg($cmsFile) . ' --where name=cms-block-email-- --match prefix --out ' . escapeshellarg($tmp . '/o.csv') . ' 2>&1'), true);
check('filter: absent column → error, not header-only wipe (A2)', ($fMiss['status'] ?? '') === 'error');
$dMiss = json_decode((string) shell_exec("{$php} {$lib} delete " . escapeshellarg($cmsFile) . ' --where name=x --out ' . escapeshellarg($tmp . '/o.csv') . ' 2>&1'), true);
check('delete: absent column → error (A2)', ($dMiss['status'] ?? '') === 'error');
$fOk = json_decode((string) shell_exec("{$php} {$lib} filter " . escapeshellarg($cmsFile) . ' --where block_key=cms-block-email-- --match prefix --out ' . escapeshellarg($tmp . '/ok.csv') . ' 2>&1'), true);
check('filter: correct column keeps the match (A1)', ($fOk['status'] ?? '') === 'ok' && count(csv_read($tmp . '/ok.csv')['rows']) === 1);
$fTrunc = json_decode((string) shell_exec("{$php} {$lib} filter " . escapeshellarg($cmsFile) . ' --where block_key=__none__ --out ' . escapeshellarg($tmp . '/trunc.csv') . ' 2>&1'), true);
check('filter: never-matching VALUE on a real column → ok, header-only (clean truncation preserved)', ($fTrunc['status'] ?? '') === 'ok' && count(csv_read($tmp . '/trunc.csv')['rows']) === 0);

// A3 regression — replace --regex with an invalid pattern must ERROR, not blank the column.
$reFile = $tmp . '/re.csv';
file_put_contents($reFile, "url\n/en/x\n");
$reBad = json_decode((string) shell_exec("{$php} {$lib} replace " . escapeshellarg($reFile) . " --column url --search '^/en/' --with /pl/ --regex --in-place 2>&1"), true);
check('replace --regex: invalid pattern → error (A3)', ($reBad['status'] ?? '') === 'error');
check('replace --regex: invalid pattern left the cell unchanged (A3)', csv_read($reFile)['rows'][0]['url'] === '/en/x');

// B5 regression — scale --rates with a wrong --currency-column must ERROR, not silently convert nothing.
$b5File = $tmp . '/b5.csv';
file_put_contents($b5File, "currency,value_gross\nPLN,100\n");
$b5 = json_decode((string) shell_exec("{$php} {$lib} scale " . escapeshellarg($b5File) . ' --column value_gross --rates PLN=4.3 --currency-column currency_code --in-place 2>&1'), true);
check('scale --rates: absent currency-column → error, not silent 0 (B5)', ($b5['status'] ?? '') === 'error');
check('scale --rates: value unchanged after the error (B5)', csv_read($b5File)['rows'][0]['value_gross'] === '100');

// cleanup
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
