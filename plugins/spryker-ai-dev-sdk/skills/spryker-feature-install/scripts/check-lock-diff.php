<?php

/**
 * Lock-diff check for a feature install: which packages the `composer require` moved, and which of
 * them the feature does not need.
 *
 * `--update-with-dependencies` (the guides' default) lets composer move any package the new feature
 * shares a dependency with. A move outside the feature's dependency tree is an unrequested upgrade
 * inside a feature branch — the developer accepts it or narrows the update. A `dev-*` constraint on
 * a package the install requires pulls unreleased code and is never part of a feature install;
 * other dev constraints already in composer.json are listed as a note.
 *
 * Usage:
 *   php check-lock-diff.php --before <composer.lock copy> [--after composer.lock]
 *       [--composer composer.json] (--feature <package>... | --checklist <checklist.json>)
 *
 *   --feature     a package the install requires; repeatable
 *   --checklist   read the packages from the composer rows that are not skipped
 *
 * Exit codes: 0 every move is inside the feature tree and no required package has a dev
 * constraint; 1 moves outside the tree or such a dev constraint (listed); 2 usage or unreadable input.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/**
 * @return array<string, array{before: ?string, after: ?string}>
 */
function cld_moved(array $before, array $after): array
{
    $moved = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $name) {
        $old = $before[$name]['version'] ?? null;
        $new = $after[$name]['version'] ?? null;
        if ($old !== $new) {
            $moved[$name] = ['before' => $old, 'after' => $new];
        }
    }
    ksort($moved);

    return $moved;
}

/**
 * @param list<string> $roots
 *
 * @return array<string, true> every package reachable from the roots through `require`
 */
function cld_tree(array $packages, array $roots): array
{
    $seen = [];
    $queue = array_map('strtolower', $roots);
    while ($queue !== []) {
        $name = array_shift($queue);
        if (isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        foreach (array_keys($packages[$name]['require'] ?? []) as $dependency) {
            $dependency = strtolower($dependency);
            if (isset($packages[$dependency]) && !isset($seen[$dependency])) {
                $queue[] = $dependency;
            }
        }
    }

    return $seen;
}

/**
 * @return array<string, string> package => dev constraint, for Spryker packages
 */
function cld_dev_constraints(array $composerJson): array
{
    $dev = [];
    foreach (['require', 'require-dev'] as $section) {
        foreach ($composerJson[$section] ?? [] as $name => $constraint) {
            if (preg_match('#^spryker[a-z\-]*/#', (string)$name) && preg_match('/(^|\s|\|)dev-/', (string)$constraint)) {
                $dev[$name] = (string)$constraint;
            }
        }
    }

    return $dev;
}

/**
 * @return list<string>
 */
function cld_features_from_checklist(array $checklist): array
{
    $features = [];
    foreach ($checklist['rows'] as $row) {
        if (($row['kind'] ?? '') === 'composer' && !fi_is_skip($row['decision'] ?? null)) {
            array_push($features, ...array_keys($row['packages'] ?? []));
        }
    }

    return array_values(array_unique($features));
}

function cld_main(array $argv): int
{
    $root = fi_project_root();
    $options = ['before' => null, 'after' => $root . '/composer.lock', 'composer' => $root . '/composer.json', 'checklist' => null];
    $features = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--feature') {
            $features[] = (string)($argv[++$i] ?? '');
        } elseif (in_array($arg, ['--before', '--after', '--composer', '--checklist'], true)) {
            $options[substr($arg, 2)] = $argv[++$i] ?? null;
        } else {
            fwrite(STDERR, "unknown argument $arg\n");

            return 2;
        }
    }
    if ($options['checklist'] !== null) {
        $checklist = fi_load_checklist($options['checklist']);
        if ($checklist === null) {
            fwrite(STDERR, "{$options['checklist']} is not a readable checklist\n");

            return 2;
        }
        array_push($features, ...cld_features_from_checklist($checklist));
    }
    if ($options['before'] === null || $features === [] || !is_file($options['before']) || !is_file($options['after'])) {
        fwrite(STDERR, "usage: php check-lock-diff.php --before <composer.lock copy> [--after composer.lock] [--composer composer.json] (--feature <package>... | --checklist <checklist.json>)\n");

        return 2;
    }

    $after = fi_lock_packages($options['after']);
    $moved = cld_moved(fi_lock_packages($options['before']), $after);
    $tree = cld_tree($after, $features);
    $outside = array_diff_key($moved, $tree);
    $dev = cld_dev_constraints((array)json_decode((string)@file_get_contents($options['composer']), true));

    fwrite(STDOUT, sprintf("%d package(s) moved; %d inside the tree of %s.\n", count($moved), count($moved) - count($outside), implode(', ', array_unique($features))));
    if ($outside !== []) {
        fwrite(STDOUT, "\nMoved outside the feature tree — accept each, or narrow the update:\n");
        foreach ($outside as $name => $versions) {
            fwrite(STDOUT, sprintf("  %-55s %s -> %s\n", $name, $versions['before'] ?? '(new)', $versions['after'] ?? '(removed)'));
        }
    }
    $featureSet = array_map('strtolower', $features);
    $devOnFeature = array_filter($dev, static fn (string $name): bool => in_array(strtolower($name), $featureSet, true), ARRAY_FILTER_USE_KEY);
    if ($devOnFeature !== []) {
        fwrite(STDOUT, "\nDev constraint on a package this install requires — set a released constraint (^<release>):\n");
        foreach ($devOnFeature as $name => $constraint) {
            fwrite(STDOUT, sprintf("  %-55s %s\n", $name, $constraint));
        }
    }
    $devOther = array_diff_key($dev, $devOnFeature);
    if ($devOther !== []) {
        fwrite(STDOUT, "\nnote: other dev constraints in composer.json (not part of this install):\n");
        foreach ($devOther as $name => $constraint) {
            fwrite(STDOUT, sprintf("  %-55s %s\n", $name, $constraint));
        }
    }

    return ($outside === [] && $devOnFeature === []) ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(cld_main($argv));
}
