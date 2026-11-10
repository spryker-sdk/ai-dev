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
