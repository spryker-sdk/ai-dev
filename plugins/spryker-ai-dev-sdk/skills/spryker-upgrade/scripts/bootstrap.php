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
 * be staged by `git add -A`.
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

/**
 * Run git in the project root without a shell.
 *
 * @param list<string> $args
 *
 * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
 */
function spryker_upgrade_git(string $projectRoot, array $args): array
{
    $process = proc_open(
        array_merge(['git', '-C', $projectRoot, '-c', 'core.quotePath=false'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return [127, '', 'could not start git'];
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * The file holding the commit the upgrade started from (written once in Phase 0).
 */
function spryker_upgrade_base_ref_file(string $stateDir): string
{
    return $stateDir . '/base-ref';
}

/**
 * The recorded base commit, or null when Phase 0 has not recorded one.
 */
function spryker_upgrade_read_base_ref(string $stateDir): ?string
{
    $file = spryker_upgrade_base_ref_file($stateDir);
    if (!is_file($file)) {
        return null;
    }
    $ref = trim((string)file_get_contents($file));

    return $ref === '' ? null : $ref;
}

/**
 * Write `git rev-parse HEAD` to the base-ref file. An existing base ref is kept unless $force,
 * so re-running Phase 0 cannot move the base past the upgrade's own commits.
 *
 * @return array{ref: string, written: bool}
 */
function spryker_upgrade_record_base_ref(string $projectRoot, string $stateDir, bool $force = false): array
{
    $existing = spryker_upgrade_read_base_ref($stateDir);
    if ($existing !== null && !$force) {
        return ['ref' => $existing, 'written' => false];
    }
    [$code, $stdout, $stderr] = spryker_upgrade_git($projectRoot, ['rev-parse', 'HEAD']);
    if ($code !== 0) {
        fwrite(STDERR, "git rev-parse HEAD failed: " . trim($stderr) . "\n");
        exit(2);
    }
    $ref = trim($stdout);
    file_put_contents(spryker_upgrade_base_ref_file($stateDir), $ref . "\n");

    return ['ref' => $ref, 'written' => true];
}
