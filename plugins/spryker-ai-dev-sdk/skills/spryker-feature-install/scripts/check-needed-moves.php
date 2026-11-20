<?php

/**
 * Needed-moves check: which of the packages the install moved does the feature really need?
 *
 * `composer require` updates every package it is allowed to (`-w`, `-W`), not only the ones the
 * feature needs. A package inside the feature's dependency tree is not needed at its new version
 * just because it moved: when its old version still satisfies every package that requires it, and
 * no package declares a conflict with it, the move is not needed, and a move that is not needed is
 * risk with no benefit (a newer module that drops a mail transport, breaks a Back Office build or
 * changes a constructor the project overrides). Each such move goes back to its old version, unless
 * the developer gives a reason to keep it.
 *
 * Usage (from the project root, or set SPRYKER_PROJECT_ROOT):
 *   php check-needed-moves.php --before <composer.lock copy> [--after composer.lock]
 *       [--composer composer.json] [--accepted <dod.json>]
 *
 *   --accepted   moves listed with a reason in dod.json `accepted_moves` are not reported as open
 *
 * Per moved package: `needed` (a requirer or a conflict rules out the old version, named),
 * `not needed` (the old version satisfies every requirer), or `unknown` (a constraint it cannot
 * read, for example a branch). New and removed packages are listed, not judged.
 *
 * Exit codes: 0 every move is needed or accepted; 1 a move is not needed or unknown, and not
 * accepted; 2 usage or unreadable input.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/**
 * @return array<string, array{before: ?string, after: ?string, verdict: string, why: list<string>}>
 */
function cnm_classify(array $before, array $after, array $composerJson): array
{
    $result = [];
    $names = array_unique(array_merge(array_keys($before), array_keys($after)));
    sort($names);
    foreach ($names as $name) {
        $old = $before[$name]['version'] ?? null;
        $new = $after[$name]['version'] ?? null;
        if ($old === $new) {
            continue;
        }
        if ($old === null || $new === null) {
            $result[$name] = ['before' => $old, 'after' => $new, 'verdict' => $old === null ? 'new' : 'removed', 'why' => []];
            continue;
        }
        $blockers = [];
        $unknown = [];
        $requirers = [];
        foreach ($after as $requirer => $package) {
            foreach (['require' => 'requires', 'conflict' => 'conflicts with'] as $section => $relation) {
                foreach ($package[$section] ?? [] as $dependency => $constraint) {
                    if (strtolower($dependency) === $name) {
                        $requirers[] = [$requirer, $relation, (string)$constraint];
                    }
                }
            }
        }
        foreach (['require', 'require-dev'] as $section) {
            foreach ($composerJson[$section] ?? [] as $dependency => $constraint) {
                if (strtolower((string)$dependency) === $name) {
                    $requirers[] = ['composer.json', 'requires', (string)$constraint];
                }
            }
        }
        foreach ($requirers as [$requirer, $relation, $constraint]) {
            $satisfied = fi_version_satisfies($old, $constraint);
            if ($satisfied === null) {
                $unknown[] = "$requirer $relation $constraint";
            } elseif ($relation === 'requires' && $satisfied === false) {
                $blockers[] = "$requirer requires $constraint";
            } elseif ($relation === 'conflicts with' && $satisfied === true) {
                $blockers[] = "$requirer conflicts with $constraint";
            }
        }
        $result[$name] = match (true) {
            $blockers !== [] => ['before' => $old, 'after' => $new, 'verdict' => 'needed', 'why' => $blockers],
            $unknown !== [] => ['before' => $old, 'after' => $new, 'verdict' => 'unknown', 'why' => $unknown],
            default => ['before' => $old, 'after' => $new, 'verdict' => 'not needed', 'why' => ["$old satisfies every requirer"]],
        };
    }

    return $result;
}

/**
 * @return array<string, array{before: ?string, after: ?string, verdict: string, why: list<string>}> the
 *         not-needed and unknown moves that have no reason in `accepted_moves`
 */
function cnm_open(array $classified, array $acceptedMoves): array
{
    return array_filter(
        $classified,
        static fn (array $move, string $name): bool => in_array($move['verdict'], ['not needed', 'unknown'], true)
            && trim((string)($acceptedMoves[$name] ?? '')) === '',
        ARRAY_FILTER_USE_BOTH
    );
}

function cnm_main(array $argv): int
{
    $root = fi_project_root();
    $options = ['before' => null, 'after' => $root . '/composer.lock', 'composer' => $root . '/composer.json', 'accepted' => null];
    for ($i = 1; $i < count($argv); $i++) {
        $key = substr((string)$argv[$i], 2);
        if (!array_key_exists($key, $options) || !str_starts_with((string)$argv[$i], '--')) {
            fwrite(STDERR, "unknown argument {$argv[$i]}\n");

            return 2;
        }
        $options[$key] = $argv[++$i] ?? null;
    }
    if ($options['before'] === null || !is_file($options['before']) || !is_file((string)$options['after'])) {
        fwrite(STDERR, "usage: php check-needed-moves.php --before <composer.lock copy> [--after composer.lock] [--composer composer.json] [--accepted <dod.json>]\n");

        return 2;
    }
    $composerJson = (array)json_decode((string)@file_get_contents((string)$options['composer']), true);
    $classified = cnm_classify(fi_lock_packages($options['before']), fi_lock_packages((string)$options['after']), $composerJson);
    $dod = $options['accepted'] !== null ? (array)json_decode((string)@file_get_contents($options['accepted']), true) : [];
    $open = cnm_open($classified, (array)($dod['accepted_moves'] ?? []));

    $counts = array_count_values(array_column($classified, 'verdict'));
    fwrite(STDOUT, sprintf("%d package(s) moved: %s.\n", count($classified), implode(', ', array_map(static fn ($v, $n) => "$n $v", array_keys($counts), $counts))));
    foreach (['not needed', 'unknown', 'needed', 'new', 'removed'] as $verdict) {
        foreach ($classified as $name => $move) {
            if ($move['verdict'] !== $verdict) {
                continue;
            }
            $accepted = trim((string)($dod['accepted_moves'][$name] ?? ''));
            fwrite(STDOUT, sprintf("  %-11s %-50s %s -> %s%s\n", strtoupper($verdict), $name, $move['before'] ?? '(none)', $move['after'] ?? '(none)', $accepted !== '' ? '   accepted: ' . $accepted : ''));
            if ($move['why'] !== [] && $verdict !== 'not needed') {
                fwrite(STDOUT, '              ' . implode('; ', array_slice($move['why'], 0, 3)) . "\n");
            }
        }
    }
    if ($open !== []) {
        fwrite(STDOUT, sprintf("\n%d move(s) the feature does not need: move each back (composer require <package>:<old version>), or record the developer's reason in dod.json accepted_moves.\n", count($open)));
    }

    return $open === [] ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(cnm_main($argv));
}
