<?php

/**
 * Capability map search without the MCP server: the fallback of the reuse gate.
 *
 * Reads the published capability map index (index.json: one card per shipped Spryker capability),
 * searches it for the given terms and, per hit, prints the fields the searchSprykerCapabilities
 * tool returns: slug, capability, status, matched, installStatus, features, missingPackages,
 * missingOptionalPackages, composerRequire, url. The install status comes from the project's
 * composer.lock. It is a port of the MCP tool's ranking and install-status rules (CapabilitySearcher,
 * CapabilityInstallStatusResolver in spryker-sdk/ai-dev), kept deliberately simple, so ranking can
 * differ slightly from the tool: treat a miss as weaker evidence than a miss from the tool.
 *
 * Dependency-free PHP (8.1+, standard build with mbstring). Report-only:
 * it writes nothing but the download cache.
 *
 * Usage (from the project root, or set SPRYKER_PROJECT_ROOT):
 *   php capability-search.php "<terms>" ["<terms>" ...] [--limit=10] [--index=<url|path>] [--lock=<path>] [--json]
 *
 *   Each positional argument is one query of space-separated terms (3+ characters; short terms
 *   match whole words only), like the tool's `query`. Run several short queries in one call.
 *   --index   index.json URL or local file; a mirror. Default: env CAPABILITY_MAP_INDEX_URL, then
 *             https://spryker-capability-map.s3.eu-central-1.amazonaws.com/index.json
 *   --lock    composer.lock to read (default: <project root>/composer.lock)
 *   --json    print only the JSON line (no summary line)
 *
 * Output: a one-line summary, then one line of compact JSON
 * {queries: [{query, terms, count, hits[]}], source: {url, stale}, note}.
 * A downloaded index is cached for 24 hours in CAPABILITY_MAP_STATE_DIR (default:
 * <system temp>/ai-dev-capability-map); an expired cache is used, flagged stale, when the download fails.
 * Exit code: 0 on success (also with no hits), 2 on a usage error, 3 when the index cannot be loaded.
 */

declare(strict_types=1);

const CAPSEARCH_DEFAULT_INDEX_URL = 'https://spryker-capability-map.s3.eu-central-1.amazonaws.com/index.json';
const CAPSEARCH_CACHE_TTL = 86400;
const CAPSEARCH_TIMEOUT = 15;
const CAPSEARCH_MIN_TERM_LENGTH = 3;
const CAPSEARCH_SHORT_TERM_LENGTH = 4;
const CAPSEARCH_MIN_STEM_LENGTH = 3;
const CAPSEARCH_MAX_LIMIT = 50;
const CAPSEARCH_STEM_SUFFIXES = ['ies', 'es', 's'];
const CAPSEARCH_STATUS_RANK = ['shipped' => 0, 'partial' => 1, 'planned' => 2];
const CAPSEARCH_OPTIONAL_MARKERS = ['-rest-api', '-storefront-api', '-backend-api'];
const CAPSEARCH_NOTE = 'Fallback search (no MCP server): ranking is weaker than the tool. The map describes the latest Spryker release line; '
    . 'an installed package may be older than its card. No hit is not proof that Spryker lacks the capability: also search the documentation and the installed modules before building new.';

function capsearch_fail(string $message, int $code): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * @return array{queries: array<string>, limit: int, index: string, lock: string|null, json: bool}
 */
function capsearch_parse_args(array $argv): array
{
    $queries = [];
    $limit = 10;
    $index = null;
    $lock = null;
    $json = false;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $limit = max(1, min(CAPSEARCH_MAX_LIMIT, (int)substr($arg, 8)));
        } elseif (str_starts_with($arg, '--index=')) {
            $index = substr($arg, 8);
        } elseif (str_starts_with($arg, '--lock=')) {
            $lock = substr($arg, 7);
        } elseif ($arg === '--json') {
            $json = true;
        } elseif (str_starts_with($arg, '--')) {
            capsearch_fail("Unknown option: $arg", 2);
        } else {
            $queries[] = $arg;
        }
    }

    if ($queries === []) {
        capsearch_fail('Usage: php capability-search.php "<terms>" ["<terms>" ...] [--limit=10] [--index=<url|path>] [--lock=<path>] [--json]', 2);
    }

    if ($index === null || $index === '') {
        $env = getenv('CAPABILITY_MAP_INDEX_URL');
        $index = is_string($env) && $env !== '' ? $env : CAPSEARCH_DEFAULT_INDEX_URL;
    }

    return ['queries' => $queries, 'limit' => $limit, 'index' => $index, 'lock' => $lock, 'json' => $json];
}

function capsearch_project_root(): ?string
{
    $explicit = getenv('SPRYKER_PROJECT_ROOT');
    if (is_string($explicit) && $explicit !== '') {
        $real = realpath($explicit);

        return $real === false ? null : $real;
    }

    $dir = (string)getcwd();
    while (true) {
        if (is_file($dir . '/composer.json')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }
}

/**
 * @return array<string, string>|null package name (lowercase) => version, null without a readable composer.lock
 */
function capsearch_installed_packages(?string $lockPath): ?array
{
    if ($lockPath === null || !is_file($lockPath)) {
        return null;
    }

    $lock = json_decode((string)file_get_contents($lockPath), true);
    if (!is_array($lock)) {
        return null;
    }

    $installed = [];
    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] ?? [] as $package) {
            if (is_array($package) && isset($package['name'])) {
                $installed[strtolower((string)$package['name'])] = (string)($package['version'] ?? '');
            }
        }
    }

    return $installed;
}

function capsearch_decode_index(string $content): ?array
{
    $index = json_decode($content, true);

    return is_array($index) && isset($index['cards']) && is_array($index['cards']) ? $index : null;
}

function capsearch_download(string $url): ?string
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => CAPSEARCH_TIMEOUT, CURLOPT_FAILONERROR => true]);
        $body = curl_exec($handle);

        return is_string($body) ? $body : null;
    }

    $context = stream_context_create(['http' => ['timeout' => CAPSEARCH_TIMEOUT], 'https' => ['timeout' => CAPSEARCH_TIMEOUT]]);
    $body = @file_get_contents($url, false, $context);

    return is_string($body) ? $body : null;
}

/**
 * @return array{cards: array<array<string, mixed>>, stale: bool}
 */
function capsearch_load_index(string $source): array
{
    $isUrl = (bool)preg_match('#^https?://#i', $source);

    if (!$isUrl) {
        $content = is_file($source) ? (string)file_get_contents($source) : '';
        $index = capsearch_decode_index($content);
        if ($index === null) {
            capsearch_fail("The capability map index could not be read from $source", 3);
        }

        return ['cards' => $index['cards'], 'stale' => false];
    }

    $stateDir = getenv('CAPABILITY_MAP_STATE_DIR');
    $stateDir = is_string($stateDir) && $stateDir !== '' ? $stateDir : sys_get_temp_dir() . '/ai-dev-capability-map';
    $cacheFile = $stateDir . '/index-' . substr(md5($source), 0, 12) . '.json';

    if (is_file($cacheFile) && time() - (int)filemtime($cacheFile) < CAPSEARCH_CACHE_TTL) {
        $index = capsearch_decode_index((string)file_get_contents($cacheFile));
        if ($index !== null) {
            return ['cards' => $index['cards'], 'stale' => false];
        }
    }

    $body = capsearch_download($source);
    $index = $body === null ? null : capsearch_decode_index($body);
    if ($index !== null) {
        if (is_dir($stateDir) || @mkdir($stateDir, 0775, true)) {
            $tmp = $cacheFile . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $body) !== false) {
                @rename($tmp, $cacheFile);
            }
        }

        return ['cards' => $index['cards'], 'stale' => false];
    }

    $index = is_file($cacheFile) ? capsearch_decode_index((string)file_get_contents($cacheFile)) : null;
    if ($index !== null) {
        return ['cards' => $index['cards'], 'stale' => true];
    }

    capsearch_fail("The capability map could not be loaded from $source (download failed, no cache). Use the INDEX.md and card fetch fallback.", 3);
}

/**
 * @return array<string>
 */
function capsearch_extract_terms(string $query): array
{
    $terms = preg_split('/[\s,;]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_unique(array_filter($terms, fn (string $term): bool => mb_strlen($term) >= CAPSEARCH_MIN_TERM_LENGTH)));
}

function capsearch_stem(string $term): string
{
    foreach (CAPSEARCH_STEM_SUFFIXES as $suffix) {
        if (str_ends_with($term, $suffix) && mb_strlen($term) - mb_strlen($suffix) >= CAPSEARCH_MIN_STEM_LENGTH) {
            return mb_substr($term, 0, -mb_strlen($suffix));
        }
    }

    return $term;
}

function capsearch_count(string $term, string $lowerText): int
{
    $total = 0;
    foreach (array_unique([capsearch_stem($term), $term]) as $variant) {
        if (mb_strlen($variant) <= CAPSEARCH_SHORT_TERM_LENGTH) {
            $total += (int)preg_match_all('/\b' . preg_quote($variant, '/') . '\w{0,2}\b/u', $lowerText);

            continue;
        }
        $total += substr_count($lowerText, $variant);
    }

    return $total;
}

/**
 * @param array<string, mixed> $card
 * @param array<string> $terms
 *
 * @return array{card: array<string, mixed>, matched: array<string, array<string>>, sortKey: array<int>}|null
 */
function capsearch_score_card(array $card, array $terms): ?array
{
    $strongText = mb_strtolower(($card['capability'] ?? '') . ' ' . implode(' ', $card['synonyms'] ?? []));
    $moduleText = mb_strtolower(implode(' ', $card['modules'] ?? []));
    $bodyText = mb_strtolower(implode("\n", $card['sections'] ?? []));

    $strongWeight = 0;
    $strong = $modules = $body = [];
    foreach ($terms as $term) {
        $count = capsearch_count($term, $strongText);
        $strongWeight += $count;
        if ($count > 0) {
            $strong[] = $term;
        }
        if (capsearch_count($term, $moduleText) > 0) {
            $modules[] = $term;
        }
        if (capsearch_count($term, $bodyText) > 0) {
            $body[] = $term;
        }
    }

    if ($strong === [] && $modules === [] && $body === []) {
        return null;
    }

    $bodyOnly = array_values(array_diff($body, $strong, $modules));

    return [
        'card' => $card,
        'matched' => ['capability' => $strong, 'modules' => $modules, 'body' => $bodyOnly],
        'sortKey' => [-count($strong), -$strongWeight, -count($modules), -count($bodyOnly), CAPSEARCH_STATUS_RANK[$card['status'] ?? ''] ?? 3],
    ];
}

/**
 * @param array<string, mixed> $card
 * @param array<string, string>|null $installed
 *
 * @return array<string, mixed>
 */
function capsearch_install_status(array $card, ?array $installed): array
{
    $features = [];
    foreach ($card['features'] ?? [] as $name) {
        $key = strtolower((string)$name);
        $features[] = ['name' => $name, 'installed' => $installed === null ? null : isset($installed[$key]), 'version' => $installed[$key] ?? null];
    }

    $modules = [];
    foreach ($card['modules'] ?? [] as $path) {
        if (!str_starts_with((string)$path, 'vendor/')) {
            continue;
        }
        $package = strtolower(substr((string)$path, 7));
        $optional = false;
        foreach (CAPSEARCH_OPTIONAL_MARKERS as $marker) {
            $optional = $optional || str_contains($package, $marker);
        }
        $modules[] = ['package' => $package, 'optional' => $optional, 'installed' => $installed === null ? null : isset($installed[$package])];
    }

    $missing = fn (array $entries, string $key): array => array_values(array_map(fn (array $e) => $e[$key], array_filter($entries, fn (array $e): bool => $e['installed'] === false)));
    $required = array_filter($modules, fn (array $m): bool => !$m['optional']);
    $missingPackages = $missing($required, 'package');
    $missingOptional = $missing(array_filter($modules, fn (array $m): bool => $m['optional']), 'package');
    $missingFeatures = $missing($features, 'name');

    if ($installed === null) {
        $status = 'unknown';
    } else {
        $checked = $required ?: $modules;
        if ($checked === []) {
            $status = count($missingFeatures) < count($features) ? 'installed' : 'not_installed';
        } else {
            $count = count(array_filter($checked, fn (array $m): bool => $m['installed'] === true));
            $status = $count === count($checked) ? 'installed' : ($count > 0 ? 'partially_installed' : 'not_installed');
        }
    }

    // One feature is required by name; several features are alternatives, so the missing module packages are named instead.
    $toRequire = count($features) === 1 && $missingFeatures !== [] ? $missingFeatures : $missingPackages;
    $composerRequire = $status === 'installed' || $toRequire === [] ? null : 'composer require ' . implode(' ', $toRequire);

    return [
        'installStatus' => $status,
        'features' => $features,
        'missingPackages' => $missingPackages,
        'missingOptionalPackages' => $missingOptional,
        'composerRequire' => $composerRequire,
    ];
}

function capsearch_card_url(string $indexSource, array $card): string
{
    $isUrl = (bool)preg_match('#^https?://#i', $indexSource);
    $base = $isUrl ? substr($indexSource, 0, (int)strrpos($indexSource, '/') + 1) : CAPSEARCH_DEFAULT_INDEX_URL;
    $base = $isUrl ? $base : substr($base, 0, (int)strrpos($base, '/') + 1);

    return $base . ($card['file'] ?? '');
}

/**
 * @param array<array<string, mixed>> $cards
 * @param array<string, string>|null $installed
 *
 * @return array<string, mixed>
 */
function capsearch_run_query(string $query, array $cards, ?array $installed, int $limit, string $indexSource): array
{
    $terms = capsearch_extract_terms($query);
    $scored = [];
    foreach ($cards as $card) {
        if (($card['status'] ?? '') === 'planned') {
            continue;
        }
        $hit = $terms === [] ? null : capsearch_score_card($card, $terms);
        if ($hit !== null) {
            $scored[] = $hit;
        }
    }
    usort($scored, fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey']);

    $hits = [];
    foreach (array_slice($scored, 0, $limit) as $hit) {
        $card = $hit['card'];
        $hits[] = [
            'slug' => basename((string)($card['file'] ?? ''), '.md'),
            'capability' => $card['capability'] ?? '',
            'status' => $card['status'] ?? '',
            'matched' => $hit['matched'],
        ] + capsearch_install_status($card, $installed) + ['url' => capsearch_card_url($indexSource, $card)];
    }

    return ['query' => $query, 'terms' => $terms, 'count' => count($hits), 'hits' => $hits];
}

function capsearch_main(array $argv): void
{
    $args = capsearch_parse_args($argv);

    $lockPath = $args['lock'];
    if ($lockPath === null) {
        $root = capsearch_project_root();
        $lockPath = $root === null ? null : $root . '/composer.lock';
    }
    $installed = capsearch_installed_packages($lockPath);

    $index = capsearch_load_index($args['index']);

    $queries = [];
    $summary = [];
    foreach ($args['queries'] as $query) {
        $result = capsearch_run_query($query, $index['cards'], $installed, $args['limit'], $args['index']);
        $queries[] = $result;
        $top = array_slice(array_map(fn (array $h): string => $h['slug'] . ' (' . $h['installStatus'] . ')', $result['hits']), 0, 3);
        $summary[] = '"' . $query . '": ' . ($result['count'] === 0 ? 'no hits' : implode(', ', $top));
    }

    $output = [
        'queries' => $queries,
        'source' => ['url' => $args['index'], 'stale' => $index['stale']],
        'note' => CAPSEARCH_NOTE,
    ];

    if (!$args['json']) {
        echo 'capability-search: ' . implode(' | ', $summary)
            . ($installed === null ? ' [no composer.lock: installStatus unknown]' : '')
            . ($index['stale'] ? ' [stale cache]' : '') . "\n";
    }
    echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

capsearch_main($argv);
