<?php

/**
 * Storage and search row counts: the number of rows in every `*_storage` and `*_search` table (the
 * Spryker publish tables), recorded before and after the upgrade on the same data.
 *
 * A publisher, a storage or search plugin, or an event subscriber lost in the upgrade leaves its
 * publish table with fewer rows, or none, after the same import. Counting the tables shows it
 * without opening a page.
 *
 * The database is reachable only from the cli container, so the script is standalone: copy it into
 * the state directory (inside the project, so the container sees it) and run it there.
 *
 * Usage:
 *   cp $UP/storage-search-counts.php .spryker-upgrade/state/
 *   docker/sdk cli php .spryker-upgrade/state/storage-search-counts.php --snapshot <label>
 *       [--dsn <pdo dsn>] [--user <name>] [--password-env <VAR>]
 *   php .spryker-upgrade/state/storage-search-counts.php --compare <before> <after>
 *
 * Connection: SPRYKER_DB_ENGINE (mysql|pgsql), SPRYKER_DB_HOST, SPRYKER_DB_PORT,
 * SPRYKER_DB_DATABASE, SPRYKER_DB_USERNAME and SPRYKER_DB_PASSWORD, as the Docker SDK sets them in the
 * cli container; --dsn, --user and --password-env (the name of the variable holding the password)
 * override them.
 *
 * --snapshot writes storage-search-counts-<label>.json next to this file. --compare takes two labels
 * or two file paths and writes storage-search-counts-report.json next to the <after> file.
 *
 * The comparison is valid only when both snapshots ran on the same data/import set and the same
 * stores, after a full import, with the publish queues drained (worker idle, queue lengths 0).
 *
 * Exit: --snapshot 0 written, 2 usage or connection error. --compare 0 no table lost rows, 1 a table
 * lost rows, was emptied or disappeared, 2 usage error or a missing snapshot.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

const SSC_TABLE_PATTERN = '/^[A-Za-z0-9_]+_(storage|search)$/';
const SSC_CONDITIONS = [
    'same data/import set and same stores as the other snapshot',
    'a full import (data:import with the same configuration)',
    'publish queues drained before the snapshot (worker idle, queue lengths 0)',
];

/**
 * The engine name for a PDO DSN or a Docker SDK engine value: mysql, pgsql or sqlite.
 */
function ssc_engine(string $dsnOrEngine): string
{
    $prefix = strtolower(explode(':', $dsnOrEngine)[0]);

    return match ($prefix) {
        'pgsql', 'postgres', 'postgresql' => 'pgsql',
        'sqlite' => 'sqlite',
        default => 'mysql',
    };
}

/**
 * DSN, user and password from the options and the Docker SDK environment.
 *
 * @param array<string, string> $options
 * @param array<string, string> $env
 *
 * @return array{dsn: string, user: ?string, password: ?string, engine: string, database: string, host: string}
 */
function ssc_connection(array $options, array $env): array
{
    $engine = ssc_engine($options['dsn'] ?? ($env['SPRYKER_DB_ENGINE'] ?? 'mysql'));
    $database = (string)($env['SPRYKER_DB_DATABASE'] ?? '');
    $host = (string)($env['SPRYKER_DB_HOST'] ?? '');
    if (isset($options['dsn'])) {
        $dsn = $options['dsn'];
        if (preg_match('/dbname=([^;]+)/', $dsn, $m)) {
            $database = $m[1];
        } elseif ($engine === 'sqlite') {
            $database = substr($dsn, 7);
        }
        $host = preg_match('/host=([^;]+)/', $dsn, $m) ? $m[1] : $host;
    } else {
        $port = (string)($env['SPRYKER_DB_PORT'] ?? ($engine === 'pgsql' ? '5432' : '3306'));
        $dsn = $engine === 'pgsql'
            ? sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database)
            : sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $database);
    }
    $passwordVariable = $options['password-env'] ?? 'SPRYKER_DB_PASSWORD';

    return [
        'dsn' => $dsn,
        'user' => $options['user'] ?? ($env['SPRYKER_DB_USERNAME'] ?? null),
        'password' => $env[$passwordVariable] ?? null,
        'engine' => $engine,
        'database' => $database,
        'host' => $host,
    ];
}

/**
 * Row count of every `*_storage` and `*_search` base table, by table name.
 *
 * @return array<string, int>
 */
function ssc_count_tables(PDO $pdo, string $engine): array
{
    $listQuery = match ($engine) {
        'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table'",
        'pgsql' => "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'",
        default => "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'",
    };
    $quote = $engine === 'mysql' ? '`' : '"';
    $counts = [];
    foreach ($pdo->query($listQuery)->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $table = (string)$table;
        if (preg_match(SSC_TABLE_PATTERN, $table) !== 1) {
            continue;
        }
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $quote . $table . $quote)->fetchColumn();
    }
    ksort($counts);

    return $counts;
}

/**
 * The snapshot document for a set of counts.
 *
 * @param array<string, int> $counts
 *
 * @return array<string, mixed>
 */
function ssc_snapshot(string $label, string $engine, string $database, string $host, array $counts): array
{
    return [
        'label' => $label,
        'createdAt' => date('c'),
        'engine' => $engine,
        'database' => $database,
        'host' => $host,
        'tableCount' => count($counts),
        'totalRows' => array_sum($counts),
        'validWhen' => SSC_CONDITIONS,
        'tables' => $counts,
    ];
}

/**
 * Per-table comparison of two snapshots. Status: same, grew, dropped, emptied, disappeared, appeared.
 * dropped, emptied and disappeared fail the comparison.
 *
 * @param array<string, int> $before
 * @param array<string, int> $after
 *
 * @return list<array{table: string, before: ?int, after: ?int, delta: ?int, status: string, failing: bool}>
 */
function ssc_compare(array $before, array $after): array
{
    $tables = array_unique(array_merge(array_keys($before), array_keys($after)));
    sort($tables);
    $rows = [];
    foreach ($tables as $table) {
        $old = $before[$table] ?? null;
        $new = $after[$table] ?? null;
        $status = match (true) {
            $new === null => 'disappeared',
            $old === null => 'appeared',
            $old > 0 && $new === 0 => 'emptied',
            $new < $old => 'dropped',
            $new > $old => 'grew',
            default => 'same',
        };
        $rows[] = [
            'table' => $table,
            'before' => $old,
            'after' => $new,
            'delta' => $old !== null && $new !== null ? $new - $old : null,
            'status' => $status,
            'failing' => in_array($status, ['disappeared', 'emptied', 'dropped'], true),
        ];
    }

    return $rows;
}

/**
 * The snapshot file for a label or path: the path itself, next to this script, or in the project's
 * state directory.
 */
function ssc_snapshot_file(string $labelOrPath): ?string
{
    if (is_file($labelOrPath)) {
        return $labelOrPath;
    }
    $name = 'storage-search-counts-' . $labelOrPath . '.json';
    $stateDir = getenv('SPRYKER_UPGRADE_STATE_DIR');
    $candidates = [__DIR__ . '/' . $name, getcwd() . '/.spryker-upgrade/state/' . $name];
    if (is_string($stateDir) && $stateDir !== '') {
        array_unshift($candidates, $stateDir . '/' . $name);
    }
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return array{0: ?string, 1: array<string, string>, 2: list<string>} mode, options, positional arguments
 */
function ssc_parse_arguments(array $arguments): array
{
    $mode = null;
    $options = [];
    $positional = [];
    for ($i = 0; $i < count($arguments); $i++) {
        $argument = $arguments[$i];
        if (in_array($argument, ['--snapshot', '--compare', '--help'], true)) {
            $mode = substr($argument, 2);
        } elseif (in_array($argument, ['--dsn', '--user', '--password-env'], true) && isset($arguments[$i + 1])) {
            $options[substr($argument, 2)] = $arguments[++$i];
        } elseif (preg_match('/^--(dsn|user|password-env)=(.*)$/', $argument, $m)) {
            $options[$m[1]] = $m[2];
        } elseif (str_starts_with($argument, '--')) {
            return [null, [], [$argument]];
        } else {
            $positional[] = $argument;
        }
    }

    return [$mode, $options, $positional];
}

function ssc_main(array $argv): int
{
    $usage = "Usage: php storage-search-counts.php --snapshot <label> [--dsn <dsn>] [--user <name>] [--password-env <VAR>]\n"
        . "       php storage-search-counts.php --compare <before> <after>\n";
    [$mode, $options, $positional] = ssc_parse_arguments(array_slice($argv, 1));
    if ($mode === 'help') {
        fwrite(STDOUT, $usage);

        return 0;
    }

    if ($mode === 'snapshot') {
        $label = $positional[0] ?? '';
        if (count($positional) !== 1 || preg_match('/^[A-Za-z0-9_.-]+$/', $label) !== 1) {
            fwrite(STDERR, "--snapshot needs one label (letters, digits, _ . -).\n" . $usage);

            return 2;
        }
        $connection = ssc_connection($options, getenv());
        if (!isset($options['dsn']) && ($connection['host'] === '' || $connection['database'] === '')) {
            fwrite(STDERR, "SPRYKER_DB_HOST / SPRYKER_DB_DATABASE are not set: run inside the cli container (docker/sdk cli php …) or pass --dsn.\n");

            return 2;
        }
        try {
            $pdo = new PDO($connection['dsn'], $connection['user'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $counts = ssc_count_tables($pdo, $connection['engine']);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Database error: ' . $exception->getMessage() . "\n");

            return 2;
        }
        $snapshot = ssc_snapshot($label, $connection['engine'], $connection['database'], $connection['host'], $counts);
        $file = __DIR__ . '/storage-search-counts-' . $label . '.json';
        file_put_contents($file, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fwrite(STDOUT, sprintf(
            "Snapshot '%s' written: %s\n  %s database %s: %d storage/search tables, %d rows.\n",
            $label,
            $file,
            $connection['engine'],
            $connection['database'],
            $snapshot['tableCount'],
            $snapshot['totalRows']
        ));
        if ($snapshot['totalRows'] === 0) {
            fwrite(STDOUT, "  NOTE: no rows — the environment holds no published data, so this snapshot cannot detect a loss.\n");
        }

        return 0;
    }

    if ($mode === 'compare') {
        if (count($positional) !== 2) {
            fwrite(STDERR, $usage);

            return 2;
        }
        $files = array_map('ssc_snapshot_file', $positional);
        foreach ($files as $index => $file) {
            if ($file === null) {
                fwrite(STDERR, "No snapshot found for '{$positional[$index]}' (storage-search-counts-{$positional[$index]}.json).\n");

                return 2;
            }
        }
        [$before, $after] = array_map(static fn(string $f): array => json_decode((string)file_get_contents($f), true) ?: [], $files);
        $rows = ssc_compare((array)($before['tables'] ?? []), (array)($after['tables'] ?? []));
        $failing = array_values(array_filter($rows, static fn(array $r): bool => $r['failing']));
        $notes = [];
        foreach (['engine', 'database'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $notes[] = sprintf('%s differs: %s vs %s', $field, $before[$field] ?? '-', $after[$field] ?? '-');
            }
        }
        $reportFile = dirname((string)$files[1]) . '/storage-search-counts-report.json';
        file_put_contents($reportFile, json_encode([
            'createdAt' => date('c'),
            'before' => ['label' => $before['label'] ?? null, 'createdAt' => $before['createdAt'] ?? null, 'tableCount' => $before['tableCount'] ?? null, 'totalRows' => $before['totalRows'] ?? null],
            'after' => ['label' => $after['label'] ?? null, 'createdAt' => $after['createdAt'] ?? null, 'tableCount' => $after['tableCount'] ?? null, 'totalRows' => $after['totalRows'] ?? null],
            'validWhen' => SSC_CONDITIONS,
            'notes' => $notes,
            'failing' => count($failing),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        fwrite(STDOUT, sprintf("Storage/search rows: %s (%d rows) -> %s (%d rows)\n\n", $before['label'] ?? '?', $before['totalRows'] ?? 0, $after['label'] ?? '?', $after['totalRows'] ?? 0));
        foreach ($rows as $row) {
            if ($row['status'] === 'same') {
                continue;
            }
            fwrite(STDOUT, sprintf(
                "  %-11s %-60s %8s -> %-8s %s\n",
                strtoupper($row['status']),
                $row['table'],
                $row['before'] ?? '-',
                $row['after'] ?? '-',
                $row['delta'] === null ? '' : sprintf('%+d', $row['delta'])
            ));
        }
        foreach ($notes as $note) {
            fwrite(STDOUT, "  NOTE: $note\n");
        }
        fwrite(STDOUT, "\nValid only when: " . implode('; ', SSC_CONDITIONS) . ".\n");
        fwrite(STDOUT, $failing === []
            ? "OK: no storage/search table lost rows.\n"
            : sprintf("FOUND %d table(s) that lost rows or disappeared: trace each to its publisher and storage/search plugin wiring.\n", count($failing)));
        fwrite(STDOUT, "Full report: $reportFile\n");

        return $failing === [] ? 0 : 1;
    }

    fwrite(STDERR, $positional !== [] && str_starts_with($positional[0], '--') ? "Unknown argument: {$positional[0]}\n" . $usage : $usage);

    return 2;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(ssc_main($argv));
}
