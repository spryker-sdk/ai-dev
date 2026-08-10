<?php

/**
 * Constraint-style detector (upgrade blocker: the project pins direct Spryker module
 * constraints tighter than the feature meta-packages need, so bumping spryker-feature/*
 * alone cannot resolve).
 *
 * Spryker projects list both feature meta-packages (spryker-feature/*, release-group pinned)
 * and hundreds of individual modules. A tilde constraint on a module ("~8.22.1") allows only
 * patch upgrades, so when the new release group's feature package requires "^8.28.0" composer
 * reports a root-conflict instead of upgrading. Exact pins behave the same way.
 *
 * Run this BEFORE the composer update: it turns a wall of 40+ "conflicts with your root
 * composer.json require" errors into an explicit, reviewable list.
 *
 * Usage:
 *   php $UP/check-constraint-style.php            # report only
 *   php $UP/check-constraint-style.php --relax    # rewrite ~/exact -> ^ in composer.json
 *
 * Exit code 1 when patch-locked constraints exist (report mode), 0 when clean or after --relax.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
$stateDir = spryker_upgrade_state_dir($root);
$reportFile = $stateDir . '/constraint-style-report.json';
$composerFile = $root . '/composer.json';

$relax = in_array('--relax', $argv, true);

$raw = (string)file_get_contents($composerFile);
$json = json_decode($raw, true);
$merged = collectMergedConstraints($root, $json);

function isSprykerModule(string $name): bool
{
    // feature meta-packages are release-group pinned on purpose — only individual modules matter
    return (bool)preg_match('#^(spryker|spryker-shop|spryker-eco)/#', $name);
}

/**
 * Branch/wildcard constraints ("dev-main", "*", "@dev") are not version pins — they already
 * float, so they never produce a root-conflict and must not be rewritten.
 */
function isFloatingConstraint(string $constraint): bool
{
    $c = trim($constraint);

    return $c === '*'
        || str_starts_with($c, 'dev-')
        || str_contains($c, '@dev')
        || str_ends_with($c, '.x-dev');
}

/**
 * wikimedia/composer-merge-plugin merges other composer.json files INTO the root package, so
 * their requirements become root requirements at resolve time. Composer then reports them as
 * "conflicts with your root composer.json require (X)" for packages that are nowhere in the
 * project's own composer.json — untraceable unless the merged files are inspected too.
 *
 * These files usually live in vendor/, i.e. they belong to another repository. A stale constraint
 * there blocks the whole upgrade and can only be fixed upstream.
 *
 * @return array<string, array<string, string>> merged file => (package => constraint)
 */
function collectMergedConstraints(string $root, array $json): array
{
    $includes = $json['extra']['merge-plugin']['include'] ?? [];
    if (is_string($includes)) {
        $includes = [$includes];
    }
    $merged = [];
    foreach ($includes as $pattern) {
        foreach (glob($root . '/' . $pattern) ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $constraints = [];
            foreach (($data['require'] ?? []) as $name => $constraint) {
                if (preg_match('#^(spryker|spryker-shop|spryker-eco|spryker-feature)/#', $name)) {
                    $constraints[$name] = $constraint;
                }
            }
            if ($constraints !== []) {
                $merged[str_replace($root . '/', '', $file)] = $constraints;
            }
        }
    }

    return $merged;
}

$patchLocked = [];
$caret = 0;
$floating = [];
$thirdPartyPinned = [];
$featurePins = [];
foreach (array_merge($json['require'] ?? [], $json['require-dev'] ?? []) as $name => $constraint) {
    if (isFloatingConstraint($constraint)) {
        if (isSprykerModule($name)) {
            $floating[$name] = $constraint;
        }
        continue;
    }
    $first = $constraint[0] ?? '';

    // Feature meta-packages are exact-pinned to a release group BY DESIGN — that is the Spryker
    // layout, and moving those pins is what the upgrade does. Never report them as pinned deps.
    if (str_starts_with($name, 'spryker-feature/')) {
        $featurePins[$name] = $constraint;
        continue;
    }

    if (!isSprykerModule($name)) {
        // A third-party package pinned exactly (e.g. "twig/twig": "3.20") blocks Spryker
        // modules that need a newer one — including security-driven bumps. Reported, never
        // auto-relaxed: third-party majors carry their own breaking changes.
        if ($first !== '^' && $first !== '~' && $first !== '>' && $first !== '<' && !str_contains($constraint, '|')) {
            $thirdPartyPinned[$name] = $constraint;
        }
        continue;
    }

    if ($first === '^') {
        $caret++;
        continue;
    }
    $patchLocked[$name] = [
        'constraint' => $constraint,
        'style' => $first === '~' ? 'tilde' : 'exact-or-other',
    ];
}

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}
file_put_contents($reportFile, json_encode([
    'createdAt' => date('c'),
    'caretConstraints' => $caret,
    'patchLocked' => $patchLocked,
    'floating' => $floating,
    'thirdPartyPinned' => $thirdPartyPinned,
    'featurePins' => $featurePins,
    'mergedConstraints' => $merged,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf(
    "Spryker module constraints: %d minor-open (^), %d patch-locked (~ or exact), %d floating (dev/branch).\n",
    $caret,
    count($patchLocked),
    count($floating)
);

if ($merged !== []) {
    $files = count($merged);
    $constraintCount = array_sum(array_map('count', $merged));
    printf(
        "\ncomposer-merge-plugin merges %d file(s) contributing %d Spryker constraint(s) INTO the\n"
        . "root package. Composer reports these as \"your root composer.json require\" even though\n"
        . "they are not in this project's composer.json:\n",
        $files,
        $constraintCount
    );
    // Group by package so a single stale constraint is obvious.
    $byPackage = [];
    foreach ($merged as $file => $constraints) {
        foreach ($constraints as $package => $constraint) {
            $byPackage[$package][$constraint][] = $file;
        }
    }
    ksort($byPackage);
    foreach ($byPackage as $package => $variants) {
        foreach ($variants as $constraint => $files2) {
            printf("  %-42s %-16s (%d file(s), e.g. %s)\n", $package, $constraint, count($files2), $files2[0]);
        }
    }
    printf(
        "\nIf any of these live under vendor/, they belong to ANOTHER repository and a stale\n"
        . "constraint there blocks this upgrade until it is fixed upstream.\n"
    );
}

if ($thirdPartyPinned !== []) {
    printf(
        "\n%d exactly-pinned third-party package(s) — these can block Spryker modules that need a\n"
        . "newer version (including security fixes). Review each by hand; not auto-relaxed:\n",
        count($thirdPartyPinned)
    );
    foreach ($thirdPartyPinned as $name => $constraint) {
        printf("  %-50s %s\n", $name, $constraint);
    }
}

if ($patchLocked === []) {
    echo "\nOK: no patch-locked Spryker module constraints — feature meta-packages can drive the upgrade.\n";
    exit($thirdPartyPinned === [] ? 0 : 1);
}

if (!$relax) {
    echo "\nThese will block the upgrade with root-conflict errors:\n";
    foreach ($patchLocked as $name => $info) {
        printf("  %-60s %s\n", $name, $info['constraint']);
    }
    printf(
        "\nRe-run with --relax to rewrite them to caret (^) constraints, which allows minor\n"
        . "upgrades inside the same major. Majors stay blocked on purpose — a major bump needs\n"
        . "its migration guide (see list-major-bumps.php).\nFull report: %s\n",
        str_replace($root . '/', '', $reportFile)
    );
    exit(1);
}

// --relax: rewrite in the raw file to preserve formatting and key order.
$updated = $raw;
$changed = 0;
foreach ($patchLocked as $name => $info) {
    $quoted = preg_quote($name, '#');
    $pattern = '#("' . $quoted . '"\s*:\s*")[~]?([0-9])#';
    $result = preg_replace($pattern, '${1}^${2}', $updated, 1, $count);
    if ($result !== null && $count > 0) {
        $updated = $result;
        $changed++;
    }
}
file_put_contents($composerFile, $updated);

printf("Relaxed %d constraint(s) to caret in composer.json. Review with: git diff composer.json\n", $changed);
exit(0);
