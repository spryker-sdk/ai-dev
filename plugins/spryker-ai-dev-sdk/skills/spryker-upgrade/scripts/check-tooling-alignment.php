<?php

/**
 * Tooling-alignment detector: compares the project's development tooling with the reference shop of
 * the target release.
 *
 * A module upgrade leaves tooling behind unless it is compared explicitly: the Docker SDK pin, the
 * static-analysis and test packages, and the service versions in the deploy files all move with the
 * release, but nothing in composer's resolution forces them to.
 *
 * Compared:
 *   - composer require / require-dev constraints of the tooling set: phpstan/phpstan and the
 *     PHPStan extensions (phpstan/*, spryker-sdk/phpstan-*), spryker-sdk/evaluator,
 *     spryker/code-sniffer, phpunit/phpunit, codeception/*
 *   - .git.docker (Docker SDK pin), and whether the docker/ checkout matches the project's pin
 *   - for every deploy*.yml present in both: image.tag and services.<name>.engine / .version for
 *     search, broker, database, key_value_store, session, scheduler
 *
 * The script never touches the network: fetch the reference (git clone --branch <tag> / gh) first
 * and pass its directory.
 *
 * Usage:
 *   php $UP/check-tooling-alignment.php --reference <dir>
 *
 * Exit 0 aligned, 1 when anything differs, 2 on usage error. `project-only` and `missing-in-project`
 * rows are informational, except a `.git.docker` the project lacks.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

const TA_PACKAGE_PATTERN = '#^(phpstan/.+|spryker-sdk/phpstan-.+|spryker-sdk/evaluator|spryker/code-sniffer|phpunit/phpunit|codeception/.+)$#';
const TA_SERVICES = ['search', 'broker', 'database', 'key_value_store', 'session', 'scheduler'];

/**
 * Tooling package constraints of a composer.json, by package name.
 *
 * @return array<string, array{constraint: string, section: string}>
 */
function ta_tooling_packages(array $composerJson): array
{
    $packages = [];
    foreach (['require', 'require-dev'] as $section) {
        foreach ($composerJson[$section] ?? [] as $name => $constraint) {
            if (is_string($name) && preg_match(TA_PACKAGE_PATTERN, $name)) {
                $packages[$name] = ['constraint' => trim((string)$constraint), 'section' => $section];
            }
        }
    }

    return $packages;
}

/**
 * Scalar values of a YAML document by dotted key path. Handles the block-mapping subset used by
 * deploy files; sequences, anchors and multi-line scalars are skipped.
 *
 * @return array<string, string>
 */
function ta_yaml_scalars(string $yaml): array
{
    $values = [];
    $stack = [];
    foreach (explode("\n", str_replace("\r\n", "\n", $yaml)) as $row) {
        if (trim($row) === '' || preg_match('/^\s*(#|---|\.\.\.)/', $row)) {
            continue;
        }
        if (!preg_match('/^(\s*)([\w.\-]+|"[^"]*"|\'[^\']*\')\s*:(?:\s+(.*))?$/', $row, $m)) {
            continue;
        }
        $indent = strlen($m[1]);
        $key = trim($m[2], '"\'');
        while ($stack !== [] && end($stack)['indent'] >= $indent) {
            array_pop($stack);
        }
        $path = implode('.', array_merge(array_column($stack, 'key'), [$key]));
        $value = trim((string)preg_replace('/\s+#.*$/', '', $m[3] ?? ''));
        if ($value === '' || $value === '|' || $value === '>') {
            $stack[] = ['indent' => $indent, 'key' => $key];

            continue;
        }
        if (!str_starts_with($value, '&') && !str_starts_with($value, '*')) {
            $values[$path] = trim($value, '"\'');
        }
    }

    return $values;
}

/**
 * The compared values of one deploy file.
 *
 * @return array<string, string|null>
 */
function ta_deploy_values(string $yaml): array
{
    $scalars = ta_yaml_scalars($yaml);
    $values = ['image.tag' => $scalars['image.tag'] ?? null];
    foreach (TA_SERVICES as $service) {
        foreach (['engine', 'version'] as $field) {
            $key = "services.$service.$field";
            if (isset($scalars[$key])) {
                $values[$key] = $scalars[$key];
            }
        }
    }

    return $values;
}

/**
 * All comparison rows between a project and a reference directory.
 *
 * @return list<array{group: string, item: string, project: ?string, reference: ?string, status: string}>
 */
function ta_compare(string $projectDir, string $referenceDir): array
{
    $rows = [];
    $row = static function (string $group, string $item, ?string $project, ?string $reference) use (&$rows): void {
        $status = match (true) {
            $project === $reference => 'same',
            $project === null => 'missing-in-project',
            $reference === null => 'project-only',
            default => 'differs',
        };
        $rows[] = ['group' => $group, 'item' => $item, 'project' => $project, 'reference' => $reference, 'status' => $status];
    };

    $projectPackages = ta_tooling_packages(json_decode((string)file_get_contents($projectDir . '/composer.json'), true) ?: []);
    $referencePackages = ta_tooling_packages(json_decode((string)file_get_contents($referenceDir . '/composer.json'), true) ?: []);
    $names = array_unique(array_merge(array_keys($projectPackages), array_keys($referencePackages)));
    sort($names);
    foreach ($names as $name) {
        $row('composer', $name, $projectPackages[$name]['constraint'] ?? null, $referencePackages[$name]['constraint'] ?? null);
    }

    $readPin = static fn(string $dir): ?string => is_file($dir . '/.git.docker') ? trim((string)file_get_contents($dir . '/.git.docker')) : null;
    $projectPin = $readPin($projectDir);
    $referencePin = $readPin($referenceDir);
    if ($projectPin !== null || $referencePin !== null) {
        $row('docker-sdk', '.git.docker', $projectPin, $referencePin);
    }

    $projectDeploys = array_map('basename', glob($projectDir . '/deploy*.yml') ?: []);
    $referenceDeploys = array_map('basename', glob($referenceDir . '/deploy*.yml') ?: []);
    foreach (array_intersect($projectDeploys, $referenceDeploys) as $deployFile) {
        $projectValues = ta_deploy_values((string)file_get_contents($projectDir . '/' . $deployFile));
        $referenceValues = ta_deploy_values((string)file_get_contents($referenceDir . '/' . $deployFile));
        $keys = array_unique(array_merge(array_keys($projectValues), array_keys($referenceValues)));
        foreach ($keys as $key) {
            if (($projectValues[$key] ?? null) === null && ($referenceValues[$key] ?? null) === null) {
                continue;
            }
            $row('deploy', "$deployFile $key", $projectValues[$key] ?? null, $referenceValues[$key] ?? null);
        }
    }

    return $rows;
}

/**
 * Whether the docker/ checkout is at the commit the project's .git.docker names. Null when it
 * cannot be determined locally.
 */
function ta_docker_checkout_matches(string $projectDir, ?string $pin): ?bool
{
    if ($pin === null || !file_exists($projectDir . '/docker/.git')) {
        return null;
    }
    $revParse = static function (string $revision) use ($projectDir): ?string {
        $process = proc_open(['git', '-C', $projectDir . '/docker', 'rev-parse', '--verify', '--quiet', $revision . '^{commit}'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $out = trim((string)stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && $out !== '' ? $out : null;
    };
    $head = $revParse('HEAD');
    $pinned = $revParse($pin) ?? $revParse('origin/' . $pin);
    if ($head === null || $pinned === null) {
        return null;
    }

    return $head === $pinned;
}

/**
 * Whether a comparison row fails the alignment check.
 *
 * @param array{group: string, item: string, project: ?string, reference: ?string, status: string} $row
 */
function ta_is_failing(array $row): bool
{
    return $row['status'] === 'differs' || ($row['status'] === 'missing-in-project' && $row['group'] === 'docker-sdk');
}

function ta_main(array $argv): int
{
    require_once __DIR__ . '/bootstrap.php';

    $options = getopt('', ['reference:', 'help']) ?: [];
    if (isset($options['help'])) {
        fwrite(STDOUT, "Usage: php check-tooling-alignment.php --reference <reference shop dir>\n");

        return 0;
    }
    $referenceDir = is_string($options['reference'] ?? null) ? rtrim($options['reference'], '/') : '';
    if ($referenceDir === '' || !is_file($referenceDir . '/composer.json')) {
        fwrite(STDERR, "--reference <dir> is required and must contain the reference shop's composer.json.\n");

        return 2;
    }
    $referenceDir = (string)realpath($referenceDir);

    $root = spryker_upgrade_project_root();
    $stateDir = spryker_upgrade_state_dir($root);
    $reportFile = $stateDir . '/tooling-alignment-report.json';

    $rows = ta_compare($root, $referenceDir);
    $projectPin = is_file($root . '/.git.docker') ? trim((string)file_get_contents($root . '/.git.docker')) : null;
    $checkoutMatches = ta_docker_checkout_matches($root, $projectPin);
    if ($checkoutMatches === false) {
        $rows[] = ['group' => 'docker-sdk', 'item' => 'docker/ checkout', 'project' => 'HEAD', 'reference' => $projectPin, 'status' => 'differs'];
    }

    $failing = array_values(array_filter($rows, 'ta_is_failing'));
    file_put_contents($reportFile, json_encode([
        'createdAt' => date('c'),
        'reference' => $referenceDir,
        'differences' => count($failing),
        'dockerCheckoutMatchesPin' => $checkoutMatches,
        'rows' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    fwrite(STDOUT, sprintf("Tooling alignment vs reference %s\n\n", $referenceDir));
    $width = max(array_map(static fn(array $r): int => strlen($r['item']), $rows ?: [['item' => '']])) + 2;
    fwrite(STDOUT, sprintf("  %-11s %-{$width}s %-22s %-22s %s\n", 'group', 'item', 'project', 'reference', 'status'));
    foreach ($rows as $r) {
        fwrite(STDOUT, sprintf(
            "  %-11s %-{$width}s %-22s %-22s %s\n",
            $r['group'],
            $r['item'],
            $r['project'] ?? '-',
            $r['reference'] ?? '-',
            $r['status'] === 'same' ? 'ok' : strtoupper($r['status'])
        ));
    }
    if ($checkoutMatches === null && $projectPin !== null) {
        fwrite(STDOUT, "\n  NOTE: could not verify that the docker/ checkout matches .git.docker (no local git data).\n");
    }
    fwrite(STDOUT, "\n" . ($failing === []
        ? "OK: tooling matches the reference (PROJECT-ONLY and MISSING-IN-PROJECT rows are informational).\n"
        : sprintf("%d difference(s): align them with the reference release.\n", count($failing))));
    fwrite(STDOUT, 'Full report: ' . spryker_upgrade_rel($reportFile, $root) . "\n");

    return $failing === [] ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(ta_main($argv));
}
