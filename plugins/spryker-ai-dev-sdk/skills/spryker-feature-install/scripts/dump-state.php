<?php

/**
 * State dump and compare for the release-step proof: is a deployed environment the same as a fresh
 * install?
 *
 * The proof (references/release-steps.md) compares the state after the normal deploy with the state
 * of a fresh install of the same branch. This script makes both dumps the same way, and compares
 * them, so the comparison is repeatable and every difference is named.
 *
 * Per database table: the row count and a hash of the rows, with identifiers and timestamps left
 * out (columns `id_*`, `fk_*`, `*_at`, `uuid`, `key` of storage tables, and the same keys inside JSON
 * values), so that the same data imported in another order or under other ids compares equal. Per
 * search index: the document count. Tables that hold runtime state, not data (sessions, locks, queue
 * and OAuth tokens, migrations, change logs), are left out.
 *
 * The database is reachable only from the cli container, so the script is standalone: copy it into
 * the state directory (inside the project, so the container sees it) and run it there.
 *
 * Usage:
 *   cp $FI/dump-state.php $STATE/
 *   docker/sdk cli php $STATE/dump-state.php --dump deployed      writes state-deployed.json next to it
 *   docker/sdk cli php $STATE/dump-state.php --dump fresh
 *   php $STATE/dump-state.php --compare deployed fresh             (labels or file paths)
 *       [--dsn <pdo dsn>] [--user <name>] [--password-env <VAR>]
 *
 * Connection: SPRYKER_DB_ENGINE (mysql|pgsql), SPRYKER_DB_HOST, SPRYKER_DB_PORT, SPRYKER_DB_DATABASE,
 * SPRYKER_DB_USERNAME, SPRYKER_DB_PASSWORD as the Docker SDK sets them; SPRYKER_SEARCH_HOST and
 * SPRYKER_SEARCH_PORT for the search engine (left out when not set). --dsn, --user and
 * --password-env override the database settings.
 *
 * Exit: --dump 0 written, 2 usage or connection error. --compare 0 equal, 1 differences (listed),
 * 2 usage.
 */

declare(strict_types=1);

const DS_SKIP_TABLES = '/^(propel_migration|spy_queue_process|spy_.*_lock|spy_oauth_(access|refresh)_token|spy_session.*|spy_event_behavior_entity_change|spy_.*_log|spy_customer_session|spy_.*_history_log)$/';
const DS_SKIP_COLUMN = '/^(id_.*|fk_.*|.*_at|uuid|key|created|updated|reference)$/';
const DS_SKIP_JSON_KEY = '/^(id|uuid|fk.*|id[A-Z_].*|.*Id|.*_id|.*At|.*_at|_timestamp|key)$/';

function ds_strip_json(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $clean = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && preg_match(DS_SKIP_JSON_KEY, $key)) {
            continue;
        }
        $clean[$key] = ds_strip_json($item);
    }
    if (array_is_list($clean)) {
        $encoded = array_map(static fn ($item) => json_encode($item), $clean);
        sort($encoded);

        return $encoded;
    }
    ksort($clean);

    return $clean;
}

/**
 * The row as compared: identifier and timestamp columns removed, JSON values normalised the same way.
 */
function ds_normalise_row(array $row): string
{
    $clean = [];
    foreach ($row as $column => $value) {
        if (preg_match(DS_SKIP_COLUMN, (string)$column)) {
            continue;
        }
        if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = json_encode(ds_strip_json($decoded));
            }
        }
        $clean[$column] = $value;
    }
    ksort($clean);

    return json_encode($clean);
}

/**
 * @return array<string, array{rows: int, hash: string}>
 */
function ds_dump_tables(PDO $pdo, string $engine): array
{
    $tables = match ($engine) {
        'sqlite' => $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN),
        'pgsql' => $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN),
        default => $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
    };
    sort($tables);
    $result = [];
    foreach ($tables as $table) {
        if (preg_match(DS_SKIP_TABLES, (string)$table)) {
            continue;
        }
        $quoted = $engine === 'mysql' ? '`' . $table . '`' : '"' . $table . '"';
        $hashes = [];
        $statement = $pdo->query('SELECT * FROM ' . $quoted);
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $hashes[] = md5(ds_normalise_row($row));
        }
        sort($hashes);
        $result[(string)$table] = ['rows' => count($hashes), 'hash' => md5(implode('', $hashes))];
    }

    return $result;
}

/**
 * @return array<string, int> index => document count; empty when no search engine is configured
 */
function ds_dump_search(array $env): array
{
    $host = $env['SPRYKER_SEARCH_HOST'] ?? '';
    if ($host === '' || !function_exists('curl_init')) {
        return [];
    }
    $handle = curl_init(sprintf('http://%s:%s/_cat/indices?format=json', $host, $env['SPRYKER_SEARCH_PORT'] ?? '9200'));
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $indices = json_decode((string)curl_exec($handle), true);
    $counts = [];
    foreach (is_array($indices) ? $indices : [] as $index) {
        if (isset($index['index']) && !str_starts_with((string)$index['index'], '.')) {
            $counts[(string)$index['index']] = (int)($index['docs.count'] ?? 0);
        }
    }
    ksort($counts);

    return $counts;
}

/**
 * @return list<string> one line per difference
 */
function ds_compare(array $a, array $b): array
{
    $differences = [];
    $tables = array_unique(array_merge(array_keys($a['tables'] ?? []), array_keys($b['tables'] ?? [])));
    sort($tables);
    foreach ($tables as $table) {
        $left = $a['tables'][$table] ?? null;
        $right = $b['tables'][$table] ?? null;
        if ($left === null || $right === null) {
            $differences[] = sprintf('table %s exists only in %s', $table, $left === null ? 'the second dump' : 'the first dump');
        } elseif ($left['rows'] !== $right['rows']) {
            $differences[] = sprintf('table %s: %d rows vs %d rows', $table, $left['rows'], $right['rows']);
        } elseif ($left['hash'] !== $right['hash']) {
            $differences[] = sprintf('table %s: same row count (%d), different content', $table, $left['rows']);
        }
    }
    $indices = array_unique(array_merge(array_keys($a['search'] ?? []), array_keys($b['search'] ?? [])));
    sort($indices);
    foreach ($indices as $index) {
        $left = $a['search'][$index] ?? null;
        $right = $b['search'][$index] ?? null;
        if ($left !== $right) {
            $differences[] = sprintf('search index %s: %s vs %s documents', $index, $left ?? 'none', $right ?? 'none');
        }
    }

    return $differences;
}

function ds_file(string $labelOrPath): string
{
    return is_file($labelOrPath) ? $labelOrPath : __DIR__ . '/state-' . $labelOrPath . '.json';
}

function ds_main(array $argv): int
{
    $usage = "usage: php dump-state.php --dump <label> [--dsn <dsn>] [--user <name>] [--password-env <VAR>]\n       php dump-state.php --compare <label|file> <label|file>\n";
    $mode = $argv[1] ?? '';
    if ($mode === '--compare' && isset($argv[2], $argv[3])) {
        $a = json_decode((string)@file_get_contents(ds_file($argv[2])), true);
        $b = json_decode((string)@file_get_contents(ds_file($argv[3])), true);
        if (!is_array($a) || !is_array($b)) {
            fwrite(STDERR, "cannot read both dumps\n");

            return 2;
        }
        $differences = ds_compare($a, $b);
        fwrite(STDOUT, $differences === []
            ? sprintf("EQUAL: %s and %s hold the same data (%d tables, %d search indices).\n", $argv[2], $argv[3], count($a['tables'] ?? []), count($a['search'] ?? []))
            : sprintf("%d difference(s) between %s and %s:\n  %s\n", count($differences), $argv[2], $argv[3], implode("\n  ", $differences)));

        return $differences === [] ? 0 : 1;
    }
    if ($mode !== '--dump' || !isset($argv[2]) || str_starts_with($argv[2], '-')) {
        fwrite(STDERR, $usage);

        return 2;
    }
    $label = $argv[2];
    $options = ['dsn' => null, 'user' => null, 'password-env' => null];
    for ($i = 3; $i < count($argv); $i++) {
        $key = substr((string)$argv[$i], 2);
        if (!array_key_exists($key, $options)) {
            fwrite(STDERR, $usage);

            return 2;
        }
        $options[$key] = $argv[++$i] ?? null;
    }
    $env = getenv();
    $engine = (string)($env['SPRYKER_DB_ENGINE'] ?? 'mysql');
    $dsn = $options['dsn'] ?? sprintf('%s:host=%s;port=%s;dbname=%s', $engine === 'pgsql' ? 'pgsql' : 'mysql', $env['SPRYKER_DB_HOST'] ?? 'localhost', $env['SPRYKER_DB_PORT'] ?? ($engine === 'pgsql' ? '5432' : '3306'), $env['SPRYKER_DB_DATABASE'] ?? '');
    $engine = strtolower((string)strtok($dsn, ':'));
    try {
        $pdo = new PDO($dsn, $options['user'] ?? ($env['SPRYKER_DB_USERNAME'] ?? null), $options['password-env'] !== null ? (getenv($options['password-env']) ?: null) : ($env['SPRYKER_DB_PASSWORD'] ?? null), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $exception) {
        fwrite(STDERR, 'cannot connect: ' . $exception->getMessage() . "\n");

        return 2;
    }
    $dump = ['label' => $label, 'createdAt' => date('c'), 'tables' => ds_dump_tables($pdo, $engine), 'search' => ds_dump_search($env)];
    $file = __DIR__ . '/state-' . $label . '.json';
    file_put_contents($file, json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDOUT, sprintf("%s: %d tables, %d search indices -> %s\n", $label, count($dump['tables']), count($dump['search']), $file));

    return 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(ds_main($argv));
}
