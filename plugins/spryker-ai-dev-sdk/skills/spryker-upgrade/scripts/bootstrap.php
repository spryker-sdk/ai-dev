<?php

/**
 * Shared bootstrap for the spryker-upgrade detectors.
 *
 * The scripts ship with the plugin/skill, but every path they read and write belongs to the
 * *project* being upgraded — which is a different directory. So nothing here may be derived from
 * __DIR__: the project root comes from the working directory (or an explicit override), and the
 * state directory lives inside the project so baselines survive between phases.
 *
 * Overrides:
 *   SPRYKER_PROJECT_ROOT      absolute path to the project root (skips discovery)
 *   SPRYKER_UPGRADE_STATE_DIR absolute path for snapshots/reports (default <root>/.spryker-upgrade/state)
 */

declare(strict_types=1);

/**
 * Locate the Spryker project root: the nearest ancestor of the working directory that has a
 * composer.json plus a project source or config tree. Never falls back to the script's own
 * directory — a detector that silently scanned the plugin instead of the project would report a
 * clean run for the wrong codebase.
 */
function spryker_upgrade_project_root(): string
{
    $explicit = getenv('SPRYKER_PROJECT_ROOT');
    if (is_string($explicit) && $explicit !== '') {
        $real = realpath($explicit);
        if ($real === false || !is_file($real . '/composer.json')) {
            fwrite(STDERR, "SPRYKER_PROJECT_ROOT does not point at a composer project: $explicit\n");
            exit(2);
        }

        return $real;
    }

    $dir = (string)getcwd();
    while (true) {
        if (
            is_file($dir . '/composer.json')
            && (is_dir($dir . '/src/Pyz') || is_dir($dir . '/config/Shared') || is_dir($dir . '/src/Orm'))
        ) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    fwrite(
        STDERR,
        "Could not find a Spryker project root above the current directory.\n"
        . "Run these scripts from the project root, or set SPRYKER_PROJECT_ROOT.\n"
    );
    exit(2);
}

/**
 * Where baselines and reports live. Inside the project (they describe the project and must survive
 * across phases), and self-gitignoring so no snapshot, vendor baseline or merge artifact can ever
 * be picked up by a stray `git add -A`.
 */
function spryker_upgrade_state_dir(string $projectRoot): string
{
    $explicit = getenv('SPRYKER_UPGRADE_STATE_DIR');
    $stateDir = is_string($explicit) && $explicit !== ''
        ? $explicit
        : $projectRoot . '/.spryker-upgrade/state';

    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0775, true);
    }
    $ignoreRoot = dirname($stateDir);
    if (is_dir($ignoreRoot) && !is_file($ignoreRoot . '/.gitignore')) {
        file_put_contents($ignoreRoot . '/.gitignore', "*\n");
    }

    return $stateDir;
}

/**
 * Project-relative path for output, so reports stay readable regardless of where the scripts live.
 */
function spryker_upgrade_rel(string $path, string $projectRoot): string
{
    return str_replace($projectRoot . '/', '', $path);
}
