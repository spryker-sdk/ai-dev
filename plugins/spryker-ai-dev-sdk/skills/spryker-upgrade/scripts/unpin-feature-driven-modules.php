<?php

/**
 * Cohort un-pinner (upgrade blocker: a group of modules must move to a new major *together*,
 * and individual root pins deadlock the resolver).
 *
 * Some Spryker majors are cohort migrations, not module migrations — the Angular 20 move, for
 * example, bumps ~20 `*-merchant-portal-gui` modules at once because they all share
 * `spryker/zed-ui`. If the project pins each of those modules individually, composer sees one
 * half of the cohort demanding `zed-ui ^3` and the other half `^4`, and no per-package bump can
 * break the tie (`resolve-constraints.php` reports this as OSCILLATION).
 *
 * The fix is to stop pinning modules the feature meta-packages already govern: a
 * `spryker-feature/*` package declares exact module requirements for its release group, so the
 * root pin adds nothing but a conflict surface. Spryker's own 202606 demoshop pins none of
 * these modules directly — composer.lock remains the record of resolved versions.
 *
 * Usage:
 *   php $UP/unpin-feature-driven-modules.php --match=merchant-portal-gui,zed-ui,gui-table
 *   php $UP/unpin-feature-driven-modules.php --all          # every feature-governed module
 *   php $UP/unpin-feature-driven-modules.php --match=... --dry-run
 *
 * Only removes a pin when some installed `spryker-feature/*` package actually requires that
 * module, so nothing the features do NOT govern is ever silently dropped.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
$stateDir = spryker_upgrade_state_dir($root);
$reportFile = $stateDir . '/unpinned-modules.json';
$composerFile = $root . '/composer.json';

$match = [];
$all = false;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--match=(.+)$/', $arg, $m)) {
        $match = array_filter(array_map('trim', explode(',', $m[1])));
    } elseif ($arg === '--all') {
        $all = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}
if ($match === [] && !$all) {
    fwrite(STDERR, "Provide --match=<substr,substr> or --all.\n");
    exit(2);
}

/**
 * Modules required by any spryker-feature package — those are feature-governed and safe to unpin.
 *
 * Read from composer.lock, NOT from vendor/: feature packages are composer *metapackages*, so
 * they carry requirements but install no files and have no vendor/ directory to inspect.
 *
 * @return array<string, list<string>> module => feature packages requiring it
 */
function collectFeatureGovernedModules(string $root): array
{
    $lockFile = $root . '/composer.lock';
    if (!is_file($lockFile)) {
        return [];
    }
    $lock = json_decode((string)file_get_contents($lockFile), true);

    $governed = [];
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
        $name = $package['name'] ?? '';
        if (!str_starts_with($name, 'spryker-feature/')) {
            continue;
        }
        foreach (array_keys($package['require'] ?? []) as $module) {
            if (preg_match('#^(spryker|spryker-shop|spryker-eco)/#', $module)) {
                $governed[$module][] = $name;
            }
        }
    }

    return $governed;
}

$governed = collectFeatureGovernedModules($root);
if ($governed === []) {
    fwrite(STDERR, "No spryker-feature packages found in composer.lock — run composer install first.\n");
    exit(2);
}

$raw = (string)file_get_contents($composerFile);
$json = json_decode($raw, true);

$candidates = [];
foreach (($json['require'] ?? []) as $module => $constraint) {
    if (!isset($governed[$module])) {
        continue;
    }
    if (!$all) {
        $hit = false;
        foreach ($match as $needle) {
            if (str_contains($module, $needle)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            continue;
        }
    }
    $candidates[$module] = [
        'constraint' => $constraint,
        'governedBy' => array_values(array_unique($governed[$module])),
    ];
}

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}
file_put_contents($reportFile, json_encode([
    'createdAt' => date('c'),
    'dryRun' => $dryRun,
    'unpinned' => $candidates,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf("%d feature-governed module pin(s) matched:\n", count($candidates));
foreach ($candidates as $module => $info) {
    printf("  %-62s %-12s (via %s)\n", $module, $info['constraint'], $info['governedBy'][0]);
}

if ($candidates === []) {
    exit(0);
}

if ($dryRun) {
    echo "\n--dry-run: composer.json unchanged.\n";
    exit(0);
}

// Remove each matched line from the raw text so formatting and key order survive.
$updated = $raw;
$removed = 0;
foreach (array_keys($candidates) as $module) {
    $quoted = preg_quote($module, '#');
    $pattern = '#^[ \t]*"' . $quoted . '"\s*:\s*"[^"]*",?\r?\n#m';
    $result = preg_replace($pattern, '', $updated, 1, $count);
    if ($result !== null && $count > 0) {
        $updated = $result;
        $removed++;
    }
}

// A removed last entry can leave a trailing comma before the closing brace.
$updated = preg_replace('#,(\s*\})#', '${1}', $updated);

if (json_decode($updated, true) === null) {
    fwrite(STDERR, "Refusing to write: result would be invalid JSON. composer.json untouched.\n");
    exit(1);
}
file_put_contents($composerFile, $updated);

printf(
    "\nRemoved %d root pin(s); the feature meta-packages now govern these versions.\n"
    . "Review with: git diff composer.json   —   resolved versions stay recorded in composer.lock.\n"
    . "Report: %s\n",
    $removed,
    str_replace($root . '/', '', $reportFile)
);
exit(0);
