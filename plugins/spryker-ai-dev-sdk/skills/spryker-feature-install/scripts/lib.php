<?php

/**
 * Shared helpers for the spryker-feature-install scripts.
 *
 * Every path the scripts read and write belongs to the project, never to the skill: the project
 * root is the working directory unless SPRYKER_PROJECT_ROOT says otherwise.
 */

declare(strict_types=1);

const FI_DECISIONS = ['apply-guide', 'apply-reference', 'merge', 'already-present'];
const FI_STATUSES = ['open', 'done', 'skipped', 'failed'];
const FI_DATA_KINDS = ['glossary', 'data-row', 'data-import'];
const FI_DATA_CLASSES = ['feature-data', 'project-content', 'demo-data'];
const FI_VERDICTS_PASSING = ['PASS', 'ACCEPTED'];

function fi_project_root(): string
{
    $explicit = getenv('SPRYKER_PROJECT_ROOT');
    $root = is_string($explicit) && $explicit !== '' ? $explicit : (string)getcwd();
    $real = realpath($root);

    return $real !== false ? $real : $root;
}

/**
 * @return array<string, mixed>|null
 */
function fi_load_checklist(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $checklist = json_decode((string)file_get_contents($path), true);

    return is_array($checklist) && isset($checklist['rows']) && is_array($checklist['rows']) ? $checklist : null;
}

function fi_save_checklist(string $path, array $checklist): void
{
    file_put_contents($path, json_encode($checklist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

function fi_is_skip(?string $decision): bool
{
    return is_string($decision) && str_starts_with($decision, 'skip:');
}

/**
 * The console command a shell line runs: `vendor/bin/console data:import glossary` → `data:import`.
 * Environment prefixes (`GLUE_APPLICATION=X vendor/bin/glue cache:warmup`) are ignored.
 */
function fi_command_name(string $command): string
{
    if (preg_match('/(?:^|\s)(?:vendor\/bin\/)?(?:console|glue)\s+([a-z0-9:\-]+)/', $command, $m)) {
        return $m[1];
    }

    return strtok(trim($command), ' ') ?: trim($command);
}

/**
 * The full console invocation without the binary and flags: `console data:import glossary -vvv`
 * → `data:import glossary`. Two calls of one command with other arguments are other steps.
 */
function fi_command_signature(string $command): string
{
    if (!preg_match('/(?:^|\s)(?:vendor\/bin\/)?(?:console|glue)\s+(.+)$/', trim($command), $m)) {
        return trim($command);
    }
    $parts = array_filter(preg_split('/\s+/', $m[1]) ?: [], static fn (string $p): bool => !preg_match('/^-(v+|-no-ansi|-no-interaction|n)$/', $p));

    return implode(' ', $parts);
}

function fi_short_name(string $class): string
{
    $parts = explode('\\', $class);

    return (string)end($parts);
}

/**
 * @return array<string, array<string, mixed>> package name => lock entry
 */
function fi_lock_packages(string $lockFile): array
{
    $lock = json_decode((string)@file_get_contents($lockFile), true);
    $packages = [];
    foreach (['packages', 'packages-dev'] as $key) {
        foreach ($lock[$key] ?? [] as $package) {
            if (isset($package['name'])) {
                $packages[strtolower($package['name'])] = $package;
            }
        }
    }

    return $packages;
}

/**
 * A released version as four numbers (`v1.2.3` → [1, 2, 3, 0]); null for a branch (`dev-master`).
 *
 * @return list<int>|null
 */
function fi_version_parts(string $version): ?array
{
    $version = strtolower(trim($version));
    if ($version === '' || str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) {
        return null;
    }
    $version = (string)preg_replace('/^v/', '', $version);
    $version = (string)preg_replace('/[-+].*$/', '', $version);
    if (!preg_match('/^\d+(\.\d+){0,3}$/', $version)) {
        return null;
    }
    $parts = array_map('intval', explode('.', $version));

    return array_pad($parts, 4, 0);
}

function fi_version_compare(array $a, array $b): int
{
    for ($i = 0; $i < 4; $i++) {
        if ($a[$i] !== $b[$i]) {
            return $a[$i] <=> $b[$i];
        }
    }

    return 0;
}

/**
 * Does a released version satisfy a composer constraint? `^`, `~`, wildcards, comparison operators,
 * ranges (`1.0 - 2.0`), AND (space or comma) and OR (`||`). Null when it cannot tell: a branch, an
 * alias (`dev-master as 1.0`) or a form it does not read.
 */
function fi_version_satisfies(string $version, string $constraint): ?bool
{
    $parts = fi_version_parts($version);
    $constraint = trim((string)preg_replace('/@\w+/', '', $constraint));
    if ($parts === null || str_contains($constraint, ' as ') || str_contains($constraint, 'dev-')) {
        return null;
    }
    if ($constraint === '' || $constraint === '*') {
        return true;
    }
    $anyKnown = false;
    foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
        $alternative = (string)preg_replace('/(>=|<=|!=|==|<>|>|<|=|\^|~)\s+/', '$1', trim($alternative));
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $alternative, $range)) {
            $alternative = '>=' . $range[1] . ' <=' . $range[2];
        }
        $result = true;
        foreach (preg_split('/[\s,]+/', $alternative) ?: [] as $atom) {
            $atomResult = fi_version_satisfies_atom($parts, $atom);
            if ($atomResult === null) {
                $result = null;
                break;
            }
            $result = $result && $atomResult;
        }
        if ($result === true) {
            return true;
        }
        $anyKnown = $anyKnown || $result === false;
    }

    return $anyKnown ? false : null;
}

/**
 * @param list<int> $parts
 */
function fi_version_satisfies_atom(array $parts, string $atom): ?bool
{
    if ($atom === '' || $atom === '*') {
        return true;
    }
    if (!preg_match('/^(>=|<=|!=|==|<>|>|<|=|\^|~)?v?([0-9x*]+(?:\.[0-9x*]+){0,3})$/i', $atom, $m)) {
        return null;
    }
    $operator = $m[1];
    $segments = explode('.', strtolower($m[2]));
    $wildcard = array_search(true, array_map(static fn (string $s): bool => $s === 'x' || $s === '*', $segments), true);
    if ($wildcard !== false) {
        $base = array_pad(array_map('intval', array_slice($segments, 0, (int)$wildcard)), 4, 0);
        $upper = $base;
        if ((int)$wildcard === 0) {
            return true;
        }
        $upper[(int)$wildcard - 1]++;

        return fi_version_compare($parts, $base) >= 0 && fi_version_compare($parts, $upper) < 0;
    }
    $given = count($segments);
    $base = array_pad(array_map('intval', $segments), 4, 0);
    $cmp = fi_version_compare($parts, $base);
    if ($operator === '^') {
        $upper = [0, 0, 0, 0];
        if ($base[0] > 0 || $given === 1) {
            $upper[0] = $base[0] + 1;
        } elseif ($base[1] > 0 || $given === 2) {
            $upper[1] = $base[1] + 1;
        } else {
            $upper[2] = $base[2] + 1;
        }

        return $cmp >= 0 && fi_version_compare($parts, $upper) < 0;
    }
    if ($operator === '~') {
        $upper = [0, 0, 0, 0];
        if ($given <= 1) {
            $upper[0] = $base[0] + 1;
        } else {
            $upper[0] = $base[0];
            $upper[$given - 2] = $base[$given - 2] + 1;
            for ($i = 1; $i < $given - 2; $i++) {
                $upper[$i] = $base[$i];
            }
        }

        return $cmp >= 0 && fi_version_compare($parts, $upper) < 0;
    }

    return match ($operator) {
        '>=' => $cmp >= 0,
        '<=' => $cmp <= 0,
        '>' => $cmp > 0,
        '<' => $cmp < 0,
        '!=', '<>' => $cmp !== 0,
        default => $cmp === 0,
    };
}
