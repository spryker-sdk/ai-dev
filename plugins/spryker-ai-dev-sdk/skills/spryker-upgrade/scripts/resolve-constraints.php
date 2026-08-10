<?php

/**
 * Iterative constraint resolver (upgrade blocker: root composer.json pins individual module
 * constraints that the target release group's feature packages cannot satisfy).
 *
 * A Spryker project lists hundreds of individual modules alongside the spryker-feature/*
 * meta-packages. Bumping the feature packages surfaces a wall of
 * "... but it conflicts with your root composer.json require (X)" errors — and fixing them is
 * inherently iterative: each round of bumps reveals the next layer of transitive requirements.
 *
 * This script automates the loop: run composer update, parse the root-conflicts, raise those
 * constraints to what the dependency tree asks for, repeat until composer resolves or no
 * further progress is possible.
 *
 * Every bump is recorded in state/constraint-resolution-log.json so the diff is reviewable, and
 * bumps that cross a MAJOR boundary are flagged — those are exactly the packages whose
 * migration guides must be processed (Lane 0), so the log doubles as the guide worklist.
 *
 * Usage:
 *   php $UP/resolve-constraints.php [--max-rounds=8] [--dry-run]
 *
 * Exit codes: 0 = composer resolved, 1 = unresolved conflicts remain (see report), 2 = usage.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
$stateDir = spryker_upgrade_state_dir($root);
$logFile = $stateDir . '/constraint-resolution-log.json';
$composerFile = $root . '/composer.json';

$maxRounds = 8;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--max-rounds=(\d+)$/', $arg, $m)) {
        $maxRounds = max(1, (int)$m[1]);
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}

/**
 * Composer's caret on a 0.x version is major-locked ("^0.2.1" means >=0.2.1 <0.3.0), so a
 * 0.19 -> 0.20 move is a BREAKING bump even though it looks like a minor.
 */
function crossesMajorBoundary(string $from, string $to): bool
{
    $parse = static function (string $c): array {
        preg_match('/(\d+)(?:\.(\d+))?/', $c, $m);

        return [(int)($m[1] ?? 0), (int)($m[2] ?? 0)];
    };
    [$fromMajor, $fromMinor] = $parse($from);
    [$toMajor, $toMinor] = $parse($to);

    if ($fromMajor !== $toMajor) {
        return true;
    }

    return $fromMajor === 0 && $fromMinor !== $toMinor;
}

/**
 * Pick the highest version mentioned in a composer requirement expression
 * (e.g. "^4.3.0 || ^5.0.0" -> "5.0.0").
 */
function highestVersionIn(string $expression): ?string
{
    if (!preg_match_all('/(\d+\.\d+(?:\.\d+)?)/', $expression, $m)) {
        return null;
    }
    $versions = $m[1];
    usort($versions, 'version_compare');

    return (string)end($versions);
}

/**
 * Runs a FULL composer update, deliberately not a filtered one.
 *
 * A partial update (`composer update "spryker/*"`) re-uses the lock's view of the root
 * requirements, so a constraint you just *removed* from composer.json is still reported as a root
 * conflict — composer will insist "your root composer.json require (^3.0.0)" for a package that is
 * no longer in the file. That produces phantom, unfixable conflicts. A release-group upgrade
 * touches the whole tree anyway, so resolve against the real composer.json every time.
 */
function runComposerUpdate(string $root): array
{
    $cmd = 'cd ' . escapeshellarg($root) . ' && composer update '
        . '--with-all-dependencies --ignore-platform-reqs --no-scripts --no-interaction 2>&1';
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    return ['exitCode' => $exitCode, 'output' => implode("\n", $output)];
}

/**
 * @return array<string, string> package => required constraint expression
 */
function parseRootConflicts(string $output): array
{
    $conflicts = [];
    $pattern = '#require ((?:spryker|spryker-shop|spryker-eco|spryker-feature)/[a-z0-9-]+) '
        . '([^\s]+(?:\s*\|\|\s*[^\s]+)*) -> found .*? but it conflicts with your root '
        . 'composer\.json require \(([^)]+)\)#';

    foreach (explode("\n", $output) as $line) {
        if (preg_match($pattern, $line, $m)) {
            $package = $m[1];
            $wanted = highestVersionIn($m[2]);
            if ($wanted === null) {
                continue;
            }
            // keep the highest demand across all lines mentioning this package
            if (!isset($conflicts[$package]) || version_compare($wanted, $conflicts[$package], '>')) {
                $conflicts[$package] = $wanted;
            }
        }
    }

    return $conflicts;
}

function currentConstraint(array $composerJson, string $package): ?string
{
    return $composerJson['require'][$package] ?? $composerJson['require-dev'][$package] ?? null;
}

/**
 * Rewrite one constraint in the raw composer.json text, preserving formatting and key order.
 */
function rewriteConstraint(string $raw, string $package, string $newConstraint): array
{
    $quoted = preg_quote($package, '#');
    $count = 0;
    $updated = preg_replace(
        '#("' . $quoted . '"\s*:\s*")[^"]+(")#',
        '${1}' . $newConstraint . '${2}',
        $raw,
        1,
        $count
    );

    return [$updated ?? $raw, $count > 0];
}

$log = [
    'createdAt' => date('c'),
    'rounds' => [],
    'majorBumps' => [],
    'unpinned' => [],
    'unresolved' => [],
];
$resolved = false;

/**
 * Constraint history per package. Two failure modes this guards against:
 *  - LOWERING: composer reports whichever demand it hit first, so a naive "set to what the tree
 *    wants" can walk a constraint *down* and undo earlier progress.
 *  - OSCILLATION: when an old cohort of modules demands "^3" and a new one demands "^4", the
 *    greedy per-package loop flips the same constraint back and forth forever. That is the
 *    signature of a cohort that must move together (e.g. the ~20 Angular-20 merchant-portal-gui
 *    modules all pinned to zed-ui ^3 while gui-table 4 needs ^4).
 */
$history = [];

for ($round = 1; $round <= $maxRounds; $round++) {
    printf("=== round %d: running composer update ===\n", $round);
    $result = runComposerUpdate($root);

    if ($result['exitCode'] === 0 && !str_contains($result['output'], 'could not be resolved')) {
        printf("composer resolved successfully in round %d.\n", $round);
        $resolved = true;
        $log['rounds'][] = ['round' => $round, 'status' => 'resolved', 'bumps' => []];
        break;
    }

    $conflicts = parseRootConflicts($result['output']);
    if ($conflicts === []) {
        echo "composer failed but no root-constraint conflicts were parsed — manual review needed.\n";
        $tail = implode("\n", array_slice(explode("\n", $result['output']), -40));
        $log['rounds'][] = ['round' => $round, 'status' => 'unparsed-failure', 'outputTail' => $tail];
        $log['unresolved'][] = 'unparsed composer failure — see outputTail in the last round';
        break;
    }

    $raw = (string)file_get_contents($composerFile);
    $json = json_decode($raw, true);
    $bumps = [];

    foreach ($conflicts as $package => $wantedVersion) {
        $current = currentConstraint($json, $package);
        if ($current === null) {
            // transitive-only package: pinning it here would add a new root requirement
            $bumps[] = ['package' => $package, 'from' => null, 'to' => '^' . $wantedVersion, 'applied' => false,
                'note' => 'not a root requirement — resolved transitively, no action'];
            continue;
        }
        $newConstraint = '^' . $wantedVersion;
        if ($newConstraint === $current) {
            $bumps[] = ['package' => $package, 'from' => $current, 'to' => $newConstraint, 'applied' => false,
                'note' => 'already at requested constraint — a deeper dependency blocks this'];
            continue;
        }

        $currentVersion = highestVersionIn($current) ?? '0';

        // Never walk a constraint downwards.
        if (version_compare($wantedVersion, $currentVersion, '<')) {
            $bumps[] = ['package' => $package, 'from' => $current, 'to' => $newConstraint, 'applied' => false,
                'note' => 'would LOWER the constraint — an old cohort still demands this; needs a cohort bump'];
            $history[$package][] = $newConstraint;
            continue;
        }

        // Oscillation: this exact constraint was already tried for this package before.
        if (in_array($newConstraint, $history[$package] ?? [], true)) {
            $bumps[] = ['package' => $package, 'from' => $current, 'to' => $newConstraint, 'applied' => false,
                'note' => 'OSCILLATION — conflicting cohorts demand different majors of this package'];
            continue;
        }
        $history[$package][] = $newConstraint;
        [$raw, $ok] = rewriteConstraint($raw, $package, $newConstraint);
        $isMajor = crossesMajorBoundary($current, $newConstraint);
        $bumps[] = [
            'package' => $package,
            'from' => $current,
            'to' => $newConstraint,
            'applied' => $ok,
            'major' => $isMajor,
        ];
        if ($ok && $isMajor) {
            $log['majorBumps'][$package] = ['from' => $current, 'to' => $newConstraint, 'round' => $round];
        }
    }

    $applied = array_filter($bumps, fn ($b) => $b['applied']);
    printf("  %d conflict(s) parsed, %d constraint(s) bumped\n", count($conflicts), count($applied));
    foreach ($bumps as $b) {
        printf(
            "    %-58s %s -> %s%s\n",
            $b['package'],
            $b['from'] ?? '(transitive)',
            $b['to'],
            $b['applied'] ? (($b['major'] ?? false) ? '  [MAJOR]' : '') : '  [skipped: ' . ($b['note'] ?? 'no match') . ']'
        );
    }

    $log['rounds'][] = ['round' => $round, 'status' => 'bumped', 'bumps' => $bumps];

    if ($applied === []) {
        echo "  no further progress possible — remaining conflicts need manual decisions.\n";
        foreach ($bumps as $b) {
            $log['unresolved'][] = sprintf(
                '%s: has %s, tree wants %s (%s)',
                $b['package'],
                $b['from'] ?? '(transitive)',
                $b['to'],
                $b['note'] ?? 'constraint could not be rewritten'
            );
        }
        break;
    }

    if ($dryRun) {
        echo "  --dry-run: composer.json not written; stopping after one round.\n";
        break;
    }

    file_put_contents($composerFile, $raw);
}

if (!$resolved && $log['unresolved'] === [] && count($log['rounds']) >= $maxRounds) {
    $log['unresolved'][] = sprintf('hit --max-rounds=%d without resolving', $maxRounds);
}

file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "\n";
if ($log['majorBumps'] !== []) {
    printf("MAJOR bumps applied (%d) — each needs its migration guide processed:\n", count($log['majorBumps']));
    foreach ($log['majorBumps'] as $package => $info) {
        printf("  %-58s %s -> %s\n", $package, $info['from'], $info['to']);
    }
    echo "\n";
}
if ($log['unresolved'] !== []) {
    echo "UNRESOLVED — manual decisions required:\n";
    foreach ($log['unresolved'] as $u) {
        echo "  - $u\n";
    }
    echo "\n";
}
printf("Log: %s\n", str_replace($root . '/', '', $logFile));

exit($resolved ? 0 : 1);
