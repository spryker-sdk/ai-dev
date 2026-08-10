<?php

/**
 * Reads Symfony/Spryker WebProfiler profiles from disk and emits performance
 * metrics as JSON. Run inside the application container so the collector
 * classes are autoloadable:
 *
 *   SCRIPT=.claude/skills/spryker-profiler/scripts/profiler-read.php
 *   docker/sdk cli php $SCRIPT --list
 *   docker/sdk cli php $SCRIPT --url=/en/cart --verbose
 *   docker/sdk cli php $SCRIPT --token=8f4622
 *   docker/sdk cli php $SCRIPT --worst=queries --scan=200 --limit=10
 */

declare(strict_types=1);

$projectRoot = locateProjectRoot(readProjectRootOption($argv));

require_once $projectRoot . '/vendor/autoload.php';

/**
 * Reads --project-root before the autoloader is available, so option parsing
 * cannot depend on anything loaded later.
 */
function readProjectRootOption(array $argv): ?string
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--project-root=')) {
            return rtrim(substr($argument, 15), '/');
        }
    }

    return null;
}

/**
 * Finds the Spryker project root.
 *
 * The script may live outside the project entirely — a skill installed as a
 * plugin runs from the plugin cache, where walking up from __DIR__ never finds
 * vendor/. The working directory is the reliable anchor because the script is
 * invoked through `docker/sdk cli` from the project, so check it first and only
 * then fall back to the script's own location for a direct in-project run.
 */
function locateProjectRoot(?string $override): string
{
    if ($override !== null) {
        if (!is_file($override . '/vendor/autoload.php')) {
            fwrite(STDERR, sprintf('No vendor/autoload.php under --project-root=%s', $override) . PHP_EOL);

            exit(1);
        }

        return $override;
    }

    $candidates = [getcwd() ?: '', __DIR__];

    foreach ($candidates as $candidate) {
        $root = walkUpToVendor($candidate);

        if ($root !== null) {
            return $root;
        }
    }

    fwrite(STDERR, sprintf(
        "Could not locate vendor/autoload.php from working directory (%s) or script directory (%s).\n"
        . "Run this from the Spryker project root, e.g.:\n"
        . "  docker/sdk cli php <path-to>/profiler-read.php --list\n"
        . "Or pass --project-root=/data explicitly.\n",
        getcwd() ?: 'unknown',
        __DIR__,
    ));

    exit(1);
}

function walkUpToVendor(string $directory): ?string
{
    if ($directory === '') {
        return null;
    }

    while (!is_file($directory . '/vendor/autoload.php')) {
        $parent = dirname($directory);

        if ($parent === $directory) {
            return null;
        }

        $directory = $parent;
    }

    return $directory;
}

// Without this, json_encode renders rounded floats as 302.80000000000001.
ini_set('serialize_precision', '-1');

use Symfony\Component\HttpKernel\Profiler\Profile;

$defaultProfilerDirs = [
    $projectRoot . '/data/tmp/profiler',
    $projectRoot . '/data/cache/codeBucket/profiler',
];

$options = parseOptions($argv);
$directory = resolveProfilerDirectory($options['dir'] ?? null, $defaultProfilerDirs);
$index = readIndex($directory);

if ($index === []) {
    fail(sprintf('No profiles found in %s. Reproduce the request first.', $directory));
}

$result = match (true) {
    isset($options['list']) => listProfiles($directory, $index, (int)($options['limit'] ?? 20)),
    isset($options['worst']) => rankProfiles($directory, $index, $options),
    isset($options['trace']) => traceRequest($directory, $index, $options, $defaultProfilerDirs),
    default => describeProfiles($directory, selectProfiles($index, $options), $options),
};

echo json_encode(
    ['source' => $directory, 'newest_profile' => humanAge((int)$index[0]['time'])] + $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
) . PHP_EOL;

/**
 * One browser action is rarely one profile. A Yves page load fans out into
 * Zed/Backend Gateway calls (each profiled separately, in a different
 * directory), and the page's AJAX requests are profiled as their own
 * top-level entries. Reading only the parent hides most of the real cost —
 * a Yves profile reports no SQL at all because Yves has no Propel collector,
 * while its Zed children may run hundreds of queries.
 *
 * This walks the whole tree: the entry profile, the Zed calls it made (linked
 * by X-Debug-Token in the zed_request log), and sibling requests that belong
 * to the same page load (AJAX, ESI) grouped by time window.
 *
 * @param array<int, string> $allDirectories
 * @return array<string, mixed>
 */
function traceRequest(string $directory, array $index, array $options, array $allDirectories): array
{
    requireOptionValue($options, 'trace', '--trace=85a44b');

    [$directory, $index, $root] = locateTraceRoot($directory, $index, $options, $allDirectories);
    $rootProfile = readProfileWithoutTree($directory, $root['token']);

    if ($rootProfile === null) {
        fail(sprintf('Profile %s is indexed but its file is gone (pruned after 2 days).', $root['token']));
    }

    $entry = [
        'role' => 'entry',
        'token' => $root['token'],
        'method' => $root['method'],
        'url' => $root['url'],
        'status' => $root['status'],
        'age' => humanAge((int)$root['time']),
        'profiler_url' => buildProfilerUrl($root['url'], $root['token']),
        'source' => $directory,
    ] + loadMetrics($directory, $root['token'], isset($options['verbose']));

    $children = collectZedChildren($rootProfile, $allDirectories, $options);
    $siblings = isset($options['no-siblings'])
        ? []
        : collectSiblingRequests($index, $root, (int)($options['window'] ?? 10));

    return [
        'trace' => $root['token'],
        'entry_request' => $entry,
        'zed_calls' => $children,
        'related_requests' => $siblings,
        'totals' => sumTree($entry, $children, $siblings),
        'note' => 'totals cover entry + zed_calls only; absent collectors contribute 0, not "no work"',
    ];
}

/**
 * The auto-picked directory is whichever application wrote last, which is
 * routinely not the one the trace token came from — tracing a Yves page right
 * after any Back Office request would otherwise fail. Search every known
 * directory for the token before giving up.
 *
 * @param array<int, array<string, string>> $index
 * @param array<int, string> $allDirectories
 * @return array{0: string, 1: array<int, array<string, string>>, 2: array<string, string>}
 */
function locateTraceRoot(string $directory, array $index, array $options, array $allDirectories): array
{
    $rows = selectProfiles($index, ['token' => $options['trace']] + $options);

    if ($rows !== []) {
        return [$directory, $index, $rows[0]];
    }

    foreach ($allDirectories as $candidate) {
        $candidate = rtrim($candidate, '/');

        if ($candidate === $directory || !is_file($candidate . '/index.csv')) {
            continue;
        }

        $candidateIndex = readIndex($candidate);
        $rows = selectProfiles($candidateIndex, ['token' => $options['trace']] + $options);

        if ($rows !== []) {
            return [$candidate, $candidateIndex, $rows[0]];
        }
    }

    fail(sprintf(
        'No profile matching "%s" in any profiler directory (%s). Use --list to find a live token.',
        $options['trace'],
        implode(', ', array_unique(array_merge([$directory], $allDirectories))),
    ));
}

/**
 * The zed_request collector logs every Zed call with the callee's own debug
 * token, which is what makes cross-application linking possible at all.
 * Children live in a different profiler directory than the Yves parent, so
 * every known directory is searched.
 *
 * @param array<int, string> $directories
 * @return array<int, array<string, mixed>>
 */
function collectZedChildren(object $profile, array $directories, array $options): array
{
    if (!$profile->hasCollector('zed_request')) {
        return [];
    }

    $calls = [];

    foreach ($profile->getCollector('zed_request')->getLogs() as $position => $log) {
        $token = $log['debug']['X-Debug-Token'] ?? null;
        $destination = $log['destination'] ?? '(unknown)';

        if ($token === null) {
            $calls[] = [
                'position' => $position,
                'destination' => $destination,
                'profile' => 'not linked — the callee did not return X-Debug-Token '
                    . '(profiler disabled for that application?)',
            ];

            continue;
        }

        $found = locateProfile($token, $directories);

        if ($found === null) {
            $calls[] = [
                'position' => $position,
                'destination' => $destination,
                'token' => $token,
                'profiler_url' => $log['debug']['X-Debug-Token-Link'] ?? null,
                'profile' => 'expired — stored profile no longer on disk',
            ];

            continue;
        }

        // The callee's own X-Debug-Token-Link is authoritative — it carries the
        // application's real host, which the caller cannot derive. Fall back to
        // building the URL from the destination when the header is absent.
        $metrics = loadMetrics($found['directory'], $token, isset($options['verbose']));
        $calls[] = [
            'position' => $position,
            'destination' => $destination,
            'role' => 'zed_call',
            'token' => $token,
            'profiler_url' => $log['debug']['X-Debug-Token-Link'] ?? buildProfilerUrl($destination, $token),
        ]
            + $metrics
            + ['source' => $found['directory']];
    }

    return $calls;
}

/**
 * A single page load also produces separate top-level profiles for its AJAX
 * calls, which the Zed-token chain cannot reveal because the browser — not
 * PHP — issues them. Grouping by a short time window after the entry request
 * recovers them. This is a heuristic: it can catch unrelated concurrent
 * traffic, so callers should sanity-check the URLs.
 *
 * @return array<int, array<string, mixed>>
 */
function collectSiblingRequests(array $index, array $root, int $windowSeconds): array
{
    $rootTime = (int)$root['time'];
    $siblings = [];

    foreach ($index as $row) {
        if ($row['token'] === $root['token']) {
            continue;
        }

        $delta = (int)$row['time'] - $rootTime;

        if ($delta < 0 || $delta > $windowSeconds) {
            continue;
        }

        $siblings[] = [
            'token' => $row['token'],
            'method' => $row['method'],
            'url' => $row['url'],
            'status' => $row['status'],
            'seconds_after_entry' => $delta,
            'profiler_url' => buildProfilerUrl($row['url'], $row['token']),
            'likely_ajax' => isLikelyAjax($row['url']),
        ];
    }

    return $siblings;
}

/**
 * @param array<int, string> $directories
 * @return array{directory: string}|null
 */
function locateProfile(string $token, array $directories): ?array
{
    foreach ($directories as $candidate) {
        $candidate = rtrim($candidate, '/');

        if (is_file(profileFilePath($candidate, $token))) {
            return ['directory' => $candidate];
        }
    }

    return null;
}

function isLikelyAjax(string $url): bool
{
    $path = parse_url($url, PHP_URL_PATH) ?? '';

    foreach (['/ajax', '/async', 'widget', 'counter', '.json', '/cart/quantity', '/wishlist'] as $marker) {
        if (stripos($path, $marker) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int, array<string, mixed>> $children
 * @param array<int, array<string, mixed>> $siblings
 * @return array<string, mixed>
 */
function sumTree(array $entry, array $children, array $siblings): array
{
    $queries = $entry['database']['queries'] ?? 0;
    $duplicates = $entry['database']['duplicates'] ?? 0;
    $redis = $entry['redis']['calls'] ?? 0;
    $elasticsearch = $entry['elasticsearch']['calls'] ?? 0;
    $duration = $entry['duration_ms'] ?? 0.0;

    foreach ($children as $child) {
        $queries += $child['database']['queries'] ?? 0;
        $duplicates += $child['database']['duplicates'] ?? 0;
        $redis += $child['redis']['calls'] ?? 0;
        $elasticsearch += $child['elasticsearch']['calls'] ?? 0;
        $duration += $child['duration_ms'] ?? 0.0;
    }

    return [
        'profiles_in_tree' => 1 + count($children),
        'related_requests_found' => count($siblings),
        'queries' => $queries,
        'duplicate_queries' => $duplicates,
        'redis' => $redis,
        'elasticsearch' => $elasticsearch,
        'zed_calls' => count($children),
        'summed_duration_ms' => round($duration, 1),
        'duration_caveat' => 'double-counts nested Zed time; compare branches, not wall-clock',
    ];
}

/**
 * @return array<string, string>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');
        $options[$key] = $value;
    }

    return $options;
}

/**
 * Applications write to different profiler directories (Yves uses the code
 * bucket path, Zed the tmp path), so the most recently written index wins
 * rather than the first one found.
 */
function resolveProfilerDirectory(?string $override, array $candidates): string
{
    if ($override !== null) {
        $override = rtrim($override, '/');

        if (!is_file($override . '/index.csv')) {
            fail(sprintf('No index.csv in %s.', $override));
        }

        return $override;
    }

    $newest = null;
    $newestTime = 0;

    foreach ($candidates as $candidate) {
        $index = rtrim($candidate, '/') . '/index.csv';

        if (is_file($index) && filemtime($index) > $newestTime) {
            $newestTime = filemtime($index);
            $newest = rtrim($candidate, '/');
        }
    }

    if ($newest === null) {
        fail(sprintf('No index.csv found in: %s', implode(', ', $candidates)));
    }

    return $newest;
}

/**
 * Newest profiles first. Columns: token,ip,method,url,time,parent,status,type.
 *
 * @return array<int, array<string, string>>
 */
function readIndex(string $directory): array
{
    $handle = fopen($directory . '/index.csv', 'r');
    $rows = [];

    // Explicit escape matches Symfony's fputcsv default and keeps PHP 8.4+
    // from emitting a deprecation notice into the JSON stream.
    while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
        if (count($row) < 7) {
            continue;
        }

        $rows[] = [
            'token' => $row[0],
            'method' => $row[2],
            'url' => $row[3],
            'time' => $row[4],
            'status' => $row[6],
        ];
    }

    fclose($handle);

    return array_reverse($rows);
}

/**
 * @param array<int, array<string, string>> $index
 *
 * @return array<int, array<string, string>>
 */
function selectProfiles(array $index, array $options): array
{
    if (isset($options['token'])) {
        requireOptionValue($options, 'token', '--token=85a44b');

        $matches = array_values(array_filter(
            $index,
            static fn (array $row): bool => str_starts_with($row['token'], $options['token']),
        ));

        return array_slice($matches, 0, 1);
    }

    $limit = (int)($options['limit'] ?? 1);

    if (!isset($options['url'])) {
        return array_slice($index, 0, $limit);
    }

    requireOptionValue($options, 'url', '--url=/en/cart');

    $matches = array_values(array_filter(
        $index,
        static fn (array $row): bool => str_contains($row['url'], $options['url']),
    ));

    return array_slice($matches, 0, $limit);
}

/**
 * Symfony prunes stored profiles after 2 days but keeps their index rows, so a
 * listed profile may no longer be readable — flagging that here saves the
 * round-trip of picking a token only to find it expired.
 *
 * @param array<int, array<string, string>> $index
 */
function listProfiles(string $directory, array $index, int $limit): array
{
    return [
        'count' => count($index),
        'profiles' => array_map(
            static function (array $row) use ($directory): array {
                $entry = $row + ['age' => humanAge((int)$row['time'])];

                if (!is_file(profileFilePath($directory, $row['token']))) {
                    $entry['expired'] = true;
                }

                return $entry;
            },
            array_slice($index, 0, $limit),
        ),
    ];
}

/**
 * Ranks recent profiles by a metric so an outlier can be found without knowing
 * in advance which request is slow.
 *
 * @param array<int, array<string, string>> $index
 */
function rankProfiles(string $directory, array $index, array $options): array
{
    $requested = (int)($options['scan'] ?? 100);
    $scanned = array_slice($index, 0, $requested);
    // A bare --worst (parsed as "1") means "use the default"; anything else must
    // be a known metric, or usort would rank by a missing key (?? 0) and present
    // arbitrary index order as a plausible-looking result.
    $metric = in_array($options['worst'], ['', '1'], true) ? 'queries' : $options['worst'];
    $rankable = [
        'queries', 'duplicate_queries', 'redis', 'elasticsearch',
        'zed_requests', 'external_http', 'memory_mb', 'duration_ms',
    ];

    if (!in_array($metric, $rankable, true)) {
        fail(sprintf('Unknown metric "%s". Rankable: %s.', $metric, implode(', ', $rankable)));
    }

    $rows = [];
    $missing = 0;

    foreach ($scanned as $row) {
        $metrics = loadMetrics($directory, $row['token']);

        if ($metrics === null) {
            $missing++;
            continue;
        }

        $rows[] = [
            'token' => $row['token'],
            'url' => $row['url'],
            'profiler_url' => buildProfilerUrl($row['url'], $row['token']),
            'queries' => $metrics['database']['queries'],
            'duplicate_queries' => $metrics['database']['duplicates'],
            'redis' => $metrics['redis']['calls'],
            'elasticsearch' => $metrics['elasticsearch']['calls'],
            'zed_requests' => $metrics['zed_requests']['calls'],
            'external_http' => $metrics['external_http']['calls'],
            'memory_mb' => $metrics['memory_mb'],
            'duration_ms' => $metrics['duration_ms'],
        ];
    }

    usort($rows, static fn (array $a, array $b): int => ($b[$metric] ?? 0) <=> ($a[$metric] ?? 0));

    // Symfony prunes stored profiles after 2 days but leaves index.csv intact,
    // so most index rows can point at files that no longer exist. Surfacing the
    // gap keeps a ranking over 3 survivors from looking like one over 300.
    $result = [
        'ranked_by' => $metric,
        'requested_scan' => $requested,
        'actually_analysed' => count($rows),
        'expired_or_missing' => $missing,
    ];

    if ($rows === []) {
        $result['warning'] = 'No stored profiles survived. Reproduce the request, then re-run.';
    } elseif ($missing > count($rows)) {
        $result['warning'] = sprintf(
            'Only %d of %d indexed profiles still exist; ranking covers a small recent slice.',
            count($rows),
            $requested,
        );
    }

    $result['worst'] = array_slice($rows, 0, (int)($options['limit'] ?? 10));

    return $result;
}

/**
 * @param array<int, array<string, string>> $rows
 */
function describeProfiles(string $directory, array $rows, array $options): array
{
    if ($rows === []) {
        fail(sprintf(
            'No profile matched in %s. Each application writes to its own directory '
            . '(Yves: data/cache/codeBucket/profiler; Zed/Back Office/Merchant Portal/Glue: data/tmp/profiler) '
            . 'and the auto-picked one may be wrong — pass --dir explicitly, or use --list to see what is here.',
            $directory,
        ));
    }

    $profiles = [];
    $expired = 0;

    foreach ($rows as $row) {
        $metrics = loadMetrics($directory, $row['token'], isset($options['verbose']));

        if ($metrics === null) {
            $expired++;

            continue;
        }

        $profiles[] = [
            'token' => $row['token'],
            'method' => $row['method'],
            'url' => $row['url'],
            'status' => $row['status'],
            'age' => humanAge((int)$row['time']),
            'profiler_url' => buildProfilerUrl($row['url'], $row['token']),
        ] + $metrics;
    }

    if ($profiles === []) {
        fail(sprintf(
            'Matched %d indexed profile(s), but all have expired from disk (Symfony prunes '
            . 'after 2 days). Reproduce the request, then re-run.',
            $expired,
        ));
    }

    if ($expired > 0) {
        return ['expired_matches_skipped' => $expired, 'profiles' => $profiles];
    }

    return count($profiles) === 1 ? $profiles[0] : ['profiles' => $profiles];
}

/**
 * Loads one profile and reduces its collectors to the metrics that the
 * performance rule cares about.
 */
function loadMetrics(string $directory, string $token, bool $verbose = false): ?array
{
    $profile = readProfileWithoutTree($directory, $token);

    if ($profile === null) {
        return null;
    }

    $metrics = [
        'duration_ms' => readTime($profile),
        'memory_mb' => readMemory($profile),
        'database' => readDatabase($profile, $verbose),
        'redis' => countCalls($profile, 'redis', 'getCalls'),
        'elasticsearch' => countCalls($profile, 'elasticsearch', 'getLogs'),
        'zed_requests' => countCalls($profile, 'zed_request', 'getLogs'),
        'external_http' => countCalls($profile, 'external_http', 'getLogs'),
        'logs' => readLogs($profile, $verbose),
        'audit_log' => readAuditLog($profile, $verbose),
        'exception' => readException($profile, $verbose),
        'twig' => readTwig($profile),
        'events' => readEvents($profile),
        'http' => readHttp($profile),
        'session' => readSession($profile),
        'runtime' => readRuntime($profile),
    ];

    // A profile only carries the collectors its application registered, and an
    // absent collector is never the same as a zero measurement. The I/O metrics
    // above always report themselves — including "absent" — because a missing
    // one is itself the finding (Yves has no Propel collector, so its "0
    // queries" must never read as "no database work"). The diagnostic blocks
    // are dropped when absent or empty: they exist to explain a request, and
    // "nothing logged, nothing thrown" explains nothing worth printing —
    // especially in a trace, where every block repeats per profile. Only
    // "incompatible" survives, because it flags a real reader/collector
    // mismatch. ($value === [] covers readers that returned no signal.)
    $alwaysReported = ['duration_ms', 'memory_mb', 'database', 'redis', 'elasticsearch', 'zed_requests', 'external_http'];

    foreach ($metrics as $key => $value) {
        if (in_array($key, $alwaysReported, true) || !is_array($value)) {
            continue;
        }

        if ($value === [] || ($value['collector'] ?? null) === 'absent') {
            unset($metrics[$key]);
        }
    }

    // Profiles are large (~150KB serialized) and reference their children.
    // Drop them before the next read so bulk scans stay flat in memory.
    $profile->setChildren([]);
    unset($profile);

    return $metrics;
}

/**
 * Reads a single profile without hydrating its parent or children.
 *
 * FileProfilerStorage::read() recursively loads the whole request tree, which
 * exhausts memory when scanning many profiles. Only this request's collectors
 * are needed, so the stored payload is decoded directly.
 */
function readProfileWithoutTree(string $directory, string $token): ?Profile
{
    $file = profileFilePath($directory, $token);

    if (!is_file($file)) {
        return null;
    }

    $data = @gzdecode(file_get_contents($file)) ?: file_get_contents($file);
    $data = @unserialize($data);

    if (!is_array($data) || !isset($data['data'])) {
        return null;
    }

    $profile = new Profile($token);
    $profile->setCollectors($data['data']);

    return $profile;
}

function profileFilePath(string $directory, string $token): string
{
    return sprintf('%s/%s/%s/%s', $directory, substr($token, -2, 2), substr($token, -4, 2), $token);
}

function readDatabase(object $profile, bool $verbose): array
{
    if (!$profile->hasCollector('propel')) {
        return ['queries' => 0, 'unique' => 0, 'duplicates' => 0, 'collector' => 'absent'];
    }

    $collector = $profile->getCollector('propel');
    $total = $collector->getTotalQueryCount();
    $unique = $collector->getTotalUniqueQueryCount();

    $database = [
        'queries' => $total,
        'unique' => $unique,
        'duplicates' => $total - $unique,
    ];

    // Queries recorded inside a startSegment()/endSegment() block are stored
    // separately from the main log, so without this they are invisible even
    // though they are counted in the totals above.
    $segments = readSegments($collector);

    if ($segments !== []) {
        $database['segments'] = $segments;
    }

    if ($verbose) {
        $queries = $collector->getQueries();
        usort($queries, static fn (array $a, array $b): int => ($b['count'] ?? 1) <=> ($a['count'] ?? 1));

        $database['top_repeated'] = array_map(
            static fn (array $query): array => [
                'count' => $query['count'] ?? 1,
                'sql' => substr((string)($query['sql'] ?? ''), 0, 200),
            ],
            array_slice($queries, 0, 5),
        );
    }

    return $database;
}

/**
 * Builds a link to the rendered profiler page for a profile.
 *
 * The host is taken from the request's own recorded URL rather than from
 * deploy.dev.yml, so links stay correct across applications (Yves, Back Office,
 * Merchant Portal, Glue) and across environments without any configuration.
 */
function buildProfilerUrl(string $requestUrl, string $token): string
{
    $host = parse_url($requestUrl, PHP_URL_HOST);
    $scheme = parse_url($requestUrl, PHP_URL_SCHEME) ?: 'http';

    if (!is_string($host) || $host === '') {
        return '';
    }

    return sprintf('%s://%s/_profiler/%s', $scheme, $host, $token);
}

/**
 * Reduces each named SQL segment to its query counts.
 *
 * Segments only exist when application code wraps a block in
 * PropelInMemoryLogger::startSegment()/endSegment(), so an empty result usually
 * means nothing was instrumented rather than that no queries ran.
 *
 * @return array<string, array<string, int>>
 */
function readSegments(object $collector): array
{
    if (!method_exists($collector, 'getSegmentedQueries')) {
        return [];
    }

    $segments = [];

    foreach ($collector->getSegmentedQueries() as $key => $data) {
        $queries = (int)($data['queryCount'] ?? 0);
        $unique = (int)($data['uniqueQueryCount'] ?? 0);

        $segments[$key] = [
            'queries' => $queries,
            'unique' => $unique,
            'duplicates' => $queries - $unique,
        ];
    }

    return $segments;
}

function countCalls(object $profile, string $name, string $method): array
{
    if (!$profile->hasCollector($name)) {
        return ['calls' => 0, 'collector' => 'absent'];
    }

    $collector = $profile->getCollector($name);

    if (!method_exists($collector, $method)) {
        return ['calls' => 0, 'collector' => 'incompatible'];
    }

    return ['calls' => count($collector->{$method}())];
}

/**
 * Returns the collector when it is present and exposes the expected API.
 *
 * Collector classes differ between applications and Spryker versions, so every
 * reader below probes for its methods rather than assuming them. The two
 * failure modes are reported differently on purpose: "absent" means the
 * application never registered the collector, while "incompatible" means it is
 * recording data this reader cannot interpret — usually a renamed API after an
 * upgrade. Collapsing the second into the first would hide a real regression
 * behind a benign-looking result.
 *
 * @param array<int, string> $methods
 *
 * @return array{0: ?object, 1: ?array<string, string>}
 */
function collectorWithApi(object $profile, string $name, array $methods): array
{
    if (!$profile->hasCollector($name)) {
        return [null, ['collector' => 'absent']];
    }

    $collector = $profile->getCollector($name);

    foreach ($methods as $method) {
        if (!method_exists($collector, $method)) {
            return [null, ['collector' => 'incompatible', 'class' => $collector::class]];
        }
    }

    return [$collector, null];
}

/**
 * Application log entries recorded during the request.
 *
 * Errors and warnings here are frequently the real explanation for a slow or
 * failing request, and deprecations surface upgrade debt that no other
 * collector reports.
 */
function readLogs(object $profile, bool $verbose): array
{
    [$collector, $unavailable] = collectorWithApi($profile, 'logger', ['countErrors']);

    if ($collector === null) {
        return $unavailable;
    }

    // Zero counts are omitted; an empty result means "nothing logged", which
    // needs no lines to say.
    $logs = array_filter([
        'errors' => $collector->countErrors(),
        'warnings' => method_exists($collector, 'countWarnings') ? $collector->countWarnings() : 0,
        'deprecations' => method_exists($collector, 'countDeprecations') ? $collector->countDeprecations() : 0,
    ]);

    if (!method_exists($collector, 'getLogs')) {
        return $logs;
    }

    $entries = $collector->getLogs();
    $total = is_countable($entries) ? count($entries) : 0;

    if ($total > 0) {
        $logs['total'] = $total;
    }

    if ($verbose && $total > 0) {
        $logs['messages'] = summariseLogMessages($entries);
    }

    return $logs;
}

/**
 * Reduces log entries to the most frequent messages.
 *
 * Raw entries carry stack traces and context objects that would dwarf the rest
 * of the output, and a repeated message is the signal worth surfacing.
 *
 * @param iterable<mixed> $entries
 *
 * @return array<int, array<string, mixed>>
 */
function summariseLogMessages(iterable $entries, int $limit = 5): array
{
    $counts = [];
    $levels = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $message = substr(trim((string)($entry['message'] ?? '')), 0, 200);

        if ($message === '') {
            continue;
        }

        $counts[$message] = ($counts[$message] ?? 0) + 1;
        $levels[$message] = (string)($entry['priorityName'] ?? $entry['level_name'] ?? '');
    }

    arsort($counts);

    $messages = [];

    foreach (array_slice($counts, 0, $limit, true) as $message => $count) {
        $messages[] = array_filter([
            'count' => $count,
            'level' => $levels[$message],
            'message' => $message,
        ], static fn (mixed $value): bool => $value !== '');
    }

    return $messages;
}

/**
 * Spryker audit log entries, grouped by channel.
 *
 * This is a separate stream from the application log — security and compliance
 * events land here and appear nowhere else in the profile.
 */
function readAuditLog(object $profile, bool $verbose): array
{
    [$collector, $unavailable] = collectorWithApi($profile, 'audit_log', ['getLogsCount']);

    if ($collector === null) {
        return $unavailable;
    }

    $entries = (int)$collector->getLogsCount();
    $errors = method_exists($collector, 'getErrorCount') ? (int)$collector->getErrorCount() : 0;

    // No entries and no errors — nothing was audited, so print nothing.
    if ($entries === 0 && $errors === 0) {
        return [];
    }

    $auditLog = array_filter(['entries' => $entries, 'errors' => $errors]);

    if (method_exists($collector, 'getChannels')) {
        $channels = $collector->getChannels();

        if (is_array($channels) && $channels !== []) {
            $auditLog['channels'] = array_values($channels);
        }
    }

    if ($verbose && method_exists($collector, 'getLogs')) {
        $auditLog['messages'] = summariseLogMessages($collector->getLogs());
    }

    return $auditLog;
}

/**
 * The exception that terminated the request, when there was one.
 *
 * A profile with a recorded exception explains a bad status code far faster
 * than any other collector, so this reports even in non-verbose mode.
 */
function readException(object $profile, bool $verbose): array
{
    [$collector, $unavailable] = collectorWithApi($profile, 'exception', ['hasException', 'getMessage']);

    if ($collector === null) {
        return $unavailable;
    }

    // Nothing thrown is the normal case and needs no lines to say — the block
    // appears only when there is an exception to explain.
    if (!$collector->hasException()) {
        return [];
    }

    $exception = [
        'thrown' => true,
        'message' => substr((string)$collector->getMessage(), 0, 300),
        'status_code' => method_exists($collector, 'getStatusCode') ? $collector->getStatusCode() : null,
    ];

    if ($verbose && method_exists($collector, 'getTrace')) {
        $trace = $collector->getTrace();
        $exception['trace_frames'] = is_countable($trace) ? count($trace) : 0;
    }

    return array_filter($exception, static fn (mixed $value): bool => $value !== null);
}

/**
 * Template rendering counts and time.
 *
 * A page rendering hundreds of templates points at the view layer rather than
 * at storage, which the I/O counters alone cannot distinguish.
 */
function readTwig(object $profile): array
{
    [$collector, $unavailable] = collectorWithApi($profile, 'twig', ['getTemplateCount']);

    if ($collector === null) {
        return $unavailable;
    }

    // Template count and render time carry the view-layer signal; block and
    // macro counts track template count without adding a decision.
    $twig = ['templates' => (int)$collector->getTemplateCount()];

    if (method_exists($collector, 'getTime')) {
        $twig['render_ms'] = round((float)$collector->getTime(), 1);
    }

    return $twig;
}

/**
 * Event listener activity, including Spryker's own application events.
 *
 * Listeners run synchronously inside the request, so a large called-listener
 * count is a cost centre that no I/O metric attributes.
 */
function readEvents(object $profile): array
{
    $events = [];
    [$collector, $unavailable] = collectorWithApi($profile, 'events', ['getCalledListeners']);

    if ($collector !== null) {
        $called = $collector->getCalledListeners();
        $events['called_listeners'] = is_countable($called) ? count($called) : 0;

        if (method_exists($collector, 'getOrphanedEvents')) {
            $orphaned = $collector->getOrphanedEvents();
            $orphanedCount = is_countable($orphaned) ? count($orphaned) : 0;

            if ($orphanedCount > 0) {
                $events['orphaned'] = $orphanedCount;
            }
        }
    }

    [$applicationEvents] = collectorWithApi($profile, 'application_events', ['getEventCount']);

    if ($applicationEvents !== null) {
        $events['application_events'] = (int)$applicationEvents->getEventCount();
    }

    return $events === [] ? $unavailable : $events;
}

/**
 * Routing identity: which controller and route actually handled the request
 * ties a profile back to the code under investigation. Method and path are
 * left out — the profile envelope already prints them.
 */
function readHttp(object $profile): array
{
    $http = [];
    [$request, $unavailable] = collectorWithApi($profile, 'request', ['getMethod']);

    if ($request !== null) {
        $controller = readController($request);

        // The collector stores the literal string "n/a" when it cannot resolve
        // the controller, which reads as data but says nothing.
        if ($controller !== null && $controller !== 'n/a') {
            $http['controller'] = $controller;
        }

        if (method_exists($request, 'getRoute')) {
            $route = $request->getRoute();
            $http['route'] = is_string($route) ? $route : null;
        }
    }

    [$router] = collectorWithApi($profile, 'router', ['getRedirect']);

    if ($router !== null && $router->getRedirect()) {
        $http['redirect_to'] = method_exists($router, 'getTargetUrl') ? (string)$router->getTargetUrl() : true;
    }

    $http = array_filter($http, static fn (mixed $value): bool => $value !== null && $value !== '');

    return $http === [] && $request === null ? $unavailable : $http;
}

/**
 * Resolves the controller to a readable string.
 *
 * The collector stores it as a class/method array for resolvable controllers
 * and as a plain string otherwise, and closures arrive with no name at all.
 */
function readController(object $request): ?string
{
    if (!method_exists($request, 'getController')) {
        return null;
    }

    $controller = $request->getController();

    if (is_string($controller)) {
        return $controller;
    }

    // The value is a Symfony VarDumper Data object rather than a plain array,
    // so it is normalised through its own array representation.
    if (is_object($controller) && method_exists($controller, 'getValue')) {
        $controller = $controller->getValue(true);
    }

    if (!is_array($controller)) {
        return null;
    }

    $class = $controller['class'] ?? null;
    $method = $controller['method'] ?? null;

    if (!is_string($class) || $class === '') {
        return null;
    }

    return is_string($method) && $method !== '' ? sprintf('%s::%s', $class, $method) : $class;
}

/**
 * Session payload size.
 *
 * A large session is written back on every request, so it is a per-request
 * cost that never appears in the query or storage counters.
 */
function readSession(object $profile): array
{
    [$collector, $unavailable] = collectorWithApi($profile, 'session', ['getSessionAttributes']);

    if ($collector === null) {
        return $unavailable;
    }

    $attributes = $collector->getSessionAttributes();

    if (is_object($attributes) && method_exists($attributes, 'getValue')) {
        $attributes = $attributes->getValue(true);
    }

    if (!is_array($attributes)) {
        return ['collector' => 'incompatible', 'class' => $collector::class];
    }

    // An empty session costs nothing and needs no lines to say so.
    if ($attributes === []) {
        return [];
    }

    return [
        'attributes' => count($attributes),
        'bytes' => strlen(@serialize($attributes) ?: ''),
    ];
}

/**
 * PHP runtime facts that change how every other number should be read.
 *
 * Xdebug being loaded inflates wall-clock time several-fold, and debug mode
 * disables caches — without these, timings get compared across incomparable
 * runs. Only true flags are printed: a missing runtime block means neither
 * was on, and the timings are as comparable as this environment gets.
 */
function readRuntime(object $profile): array
{
    [$collector] = collectorWithApi($profile, 'config', ['getPhpVersion']);

    if ($collector === null) {
        return [];
    }

    $runtime = [];

    if (method_exists($collector, 'isDebug') && $collector->isDebug()) {
        $runtime['debug'] = true;
    }

    if (method_exists($collector, 'hasXdebug') && $collector->hasXdebug()) {
        $runtime['xdebug'] = true;
    }

    return $runtime;
}

function readTime(object $profile): float
{
    if (!$profile->hasCollector('time')) {
        return 0.0;
    }

    return round($profile->getCollector('time')->getDuration(), 1);
}

function readMemory(object $profile): float
{
    if (!$profile->hasCollector('memory')) {
        return 0.0;
    }

    return round($profile->getCollector('memory')->getMemory() / 1048576, 1);
}

function humanAge(int $timestamp): string
{
    $seconds = time() - $timestamp;

    return match (true) {
        $seconds < 60 => $seconds . 's ago',
        $seconds < 3600 => intdiv($seconds, 60) . 'm ago',
        $seconds < 86400 => intdiv($seconds, 3600) . 'h ago',
        default => intdiv($seconds, 86400) . 'd ago',
    };
}

/**
 * parseOptions() defaults a valueless flag to "1", which would otherwise
 * substring-match an arbitrary profile — silently the wrong one.
 */
function requireOptionValue(array $options, string $key, string $example): void
{
    if (($options[$key] ?? '') === '' || $options[$key] === '1') {
        fail(sprintf('--%s requires a value, e.g. %s. Use --list to find one.', $key, $example));
    }
}

/**
 * Errors go to stdout as JSON so they survive the `sed -n '/^{/,$p'` filter
 * that strips the docker/sdk banner — a stderr message would vanish there.
 */
function fail(string $message): never
{
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit(1);
}
