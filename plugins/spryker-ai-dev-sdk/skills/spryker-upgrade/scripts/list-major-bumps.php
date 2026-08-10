<?php

/**
 * Lock-diff classifier (upgrade process requirement: every MAJOR module bump has a migration
 * guide on docs.spryker.com that MUST be located and followed; NEW packages must go through
 * the developer opt-in gate).
 *
 * Compares composer.lock.before from the state dir (saved in preflight) with the current
 * composer.lock and classifies every spryker* package change:
 *   MAJOR — migration guide mandatory (emits doc-search + GitHub compare/changelog URLs)
 *   MINOR/PATCH — changelog review sufficient
 *   NEW   — feeds the developer opt-in feature gate
 *   REMOVED — must be explained (replaced? dropped?)
 *
 * Usage:
 *   php $UP/list-major-bumps.php          # human output
 *   php $UP/list-major-bumps.php --json   # machine output only
 *
 * Exit code 1 when MAJOR bumps exist (reminder that guides must be processed), 0 otherwise.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
$stateDir = spryker_upgrade_state_dir($root);
$beforeFile = $stateDir . '/composer.lock.before';
$reportFile = $stateDir . '/lock-diff-report.json';

if (!is_file($beforeFile)) {
    fwrite(STDERR, 'No baseline at ' . spryker_upgrade_rel($beforeFile, $root) . " — copy composer.lock there during preflight.\n");
    exit(2);
}

/** @return array<string, string> package => version */
function lockVersions(string $file): array
{
    $data = json_decode((string)file_get_contents($file), true);
    $versions = [];
    foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $pkg) {
        $versions[$pkg['name']] = $pkg['version'];
    }

    return $versions;
}

function isSprykerPackage(string $name): bool
{
    return (bool)preg_match('#^(spryker|spryker-shop|spryker-eco|spryker-feature|spryker-sdk)/#', $name);
}

/** @return array{0:int,1:int,2:int} */
function semver(string $version): array
{
    preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $m);

    return [(int)($m[1] ?? 0), (int)($m[2] ?? 0), (int)($m[3] ?? 0)];
}

function moduleName(string $package): string
{
    $short = substr($package, (int)strrpos($package, '/') + 1);

    return str_replace('-', '', ucwords($short, '-'));
}

$before = lockVersions($beforeFile);
$after = lockVersions($root . '/composer.lock');

$major = $minorPatch = $new = $removed = [];

foreach ($after as $name => $version) {
    if (!isSprykerPackage($name)) {
        continue;
    }
    if (!isset($before[$name])) {
        $new[] = ['package' => $name, 'version' => $version];
        continue;
    }
    if ($before[$name] === $version) {
        continue;
    }
    [$oldMajor] = semver($before[$name]);
    [$newMajor] = semver($version);
    $entry = ['package' => $name, 'from' => $before[$name], 'to' => $version];

    if ($newMajor > $oldMajor) {
        $module = moduleName($name);
        $entry += [
            'module' => $module,
            'migrationGuideSearch' => 'https://docs.spryker.com/search?q=' . rawurlencode('migration guide ' . $module),
            'changelog' => sprintf('https://github.com/%s/blob/%s/CHANGELOG.md', $name, ltrim($version, 'v')),
            'compare' => sprintf('https://github.com/%s/compare/%s...%s', $name, ltrim($before[$name], 'v'), ltrim($version, 'v')),
        ];
        $major[] = $entry;
    } else {
        $minorPatch[] = $entry;
    }
}

foreach ($before as $name => $version) {
    if (isSprykerPackage($name) && !isset($after[$name])) {
        $removed[] = ['package' => $name, 'version' => $version];
    }
}

$sort = fn (array &$list) => usort($list, fn ($a, $b) => strcmp($a['package'], $b['package']));
$sort($major);
$sort($minorPatch);
$sort($new);
$sort($removed);

$report = [
    'createdAt' => date('c'),
    'major' => $major,
    'minorPatch' => $minorPatch,
    'new' => $new,
    'removed' => $removed,
];
if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

if (in_array('--json', $argv, true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($major === [] ? 0 : 1);
}

printf(
    "Spryker package changes: %d MAJOR, %d minor/patch, %d NEW, %d removed.\n\n",
    count($major),
    count($minorPatch),
    count($new),
    count($removed)
);

if ($major !== []) {
    echo "MAJOR bumps — a migration guide MUST be located and followed for each:\n";
    foreach ($major as $m) {
        printf(
            "  %s  %s -> %s\n    guide search: %s\n    changelog:    %s\n    compare:      %s\n",
            $m['package'],
            $m['from'],
            $m['to'],
            $m['migrationGuideSearch'],
            $m['changelog'],
            $m['compare']
        );
    }
    echo "\n";
}
if ($new !== []) {
    echo "NEW packages — developer opt-in gate required before wiring anything:\n";
    foreach ($new as $n) {
        printf("  %s %s\n", $n['package'], $n['version']);
    }
    echo "\n";
}
if ($removed !== []) {
    echo "REMOVED packages — verify each was intentionally replaced/dropped:\n";
    foreach ($removed as $r) {
        printf("  %s (was %s)\n", $r['package'], $r['version']);
    }
    echo "\n";
}

echo 'Full report: ' . spryker_upgrade_rel($reportFile, $root) . "\n";
exit($major === [] ? 0 : 1);
