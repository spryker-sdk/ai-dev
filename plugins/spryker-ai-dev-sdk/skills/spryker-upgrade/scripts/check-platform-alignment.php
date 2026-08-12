<?php

/**
 * Platform-alignment detector: is the machine you are resolving on the machine the project runs on?
 *
 * A Spryker project declares `require.php: ">=8.3"` and runs `image.tag: spryker/php:8.3`. A
 * developer laptop on PHP 8.5 satisfies `">=8.3"`, so composer raises no objection — and then
 * resolves dependencies (usually dev tooling: doctrine/instantiator, symfony/*, phpunit) to versions
 * that require PHP 8.4+. The resulting `composer.lock` installs perfectly on that laptop and fails in
 * every container:
 *
 *     Your lock file does not contain a compatible set of packages.
 *     doctrine/instantiator 2.1.0 requires php ^8.4 -> your php version (8.3.32) does not satisfy that
 *
 * On the validation run this was discovered only when `docker/sdk up` died at `composer install`,
 * many phases after the damage, and it also meant an entire characterization suite had been running
 * on the wrong PHP minor — so "the tests pass" had not meant what it appeared to mean.
 *
 * The fix is two-part, because a host can be wrong in two independent ways:
 *   1. wrong PHP VERSION      -> `config.platform.php` in composer.json, set to the deployment PHP;
 *   2. wrong EXTENSION SET    -> resolve inside the container; a host without ext-redis cannot
 *                                resolve spryker/redis at all, and `--ignore-platform-req` only
 *                                re-creates problem 1 by pretending the requirement is met.
 *
 * Run this in Phase 0, BEFORE the first composer update. It is static: it reads composer.json,
 * composer.lock and the deploy files, and compares against the running PHP. No container needed.
 *
 * Usage:
 *   php $UP/check-platform-alignment.php               # report
 *   php $UP/check-platform-alignment.php --target=8.3.2 # override the detected deployment PHP
 *
 * Exit 1 on any misalignment that can produce an uninstallable lock, 0 when aligned.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
$options = getopt('', ['target:', 'help']) ?: [];

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php check-platform-alignment.php [--target=8.3.2]\n");
    exit(0);
}

$composerJsonPath = $root . '/composer.json';
if (!is_file($composerJsonPath)) {
    fwrite(STDERR, "No composer.json at {$composerJsonPath}\n");
    exit(2);
}

$composerJson = json_decode((string)file_get_contents($composerJsonPath), true);
if (!is_array($composerJson)) {
    fwrite(STDERR, "composer.json is not valid JSON\n");
    exit(2);
}

$declaredPlatform = $composerJson['config']['platform']['php'] ?? null;
$requirePhp = $composerJson['require']['php'] ?? null;
$hostPhp = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;

/**
 * The deployment PHP, read from every deploy file's image tag. Disagreement between environments is
 * itself worth reporting — it means there is no single platform to resolve for.
 */
$imageTags = [];
foreach (glob($root . '/deploy*.yml') ?: [] as $deployFile) {
    $contents = (string)file_get_contents($deployFile);
    if (preg_match('#tag:\s*spryker/php:([0-9]+\.[0-9]+(?:\.[0-9]+)?)#', $contents, $m)) {
        $imageTags[basename($deployFile)] = $m[1];
    }
}

$distinctTags = array_values(array_unique($imageTags));

/**
 * Target precedence matters. `config.platform.php` is what composer ACTUALLY resolves against, so it
 * wins whenever it is declared — inferring `.0` from an `8.3` image tag instead produces false
 * positives against any package with a patch-level constraint (`~8.3.2`) and can recommend a *lower*
 * pin than the project already has. Only fall back to the image tag when nothing is declared.
 */
$targetPhp = $options['target'] ?? null;
$targetSource = $targetPhp !== null ? '--target' : null;

if ($targetPhp === null && $declaredPlatform !== null) {
    $targetPhp = (string)$declaredPlatform;
    $targetSource = 'config.platform.php';
}

if ($targetPhp === null && count($distinctTags) === 1) {
    // "8.3" from a tag is a LINE, not a version. Assume .0 — the conservative floor for that line.
    $targetPhp = substr_count($distinctTags[0], '.') === 1 ? $distinctTags[0] . '.0' : $distinctTags[0];
    $targetSource = 'deploy image tag (assumed floor)';
}

$problems = [];
$notes = [];

fwrite(STDOUT, "Platform alignment\n\n");
fwrite(STDOUT, sprintf("  host PHP (resolving here)   %s\n", $hostPhp));
fwrite(STDOUT, sprintf(
    "  deploy image tag(s)         %s\n",
    $imageTags === [] ? '(none found in deploy*.yml)' : implode(', ', array_map(
        static fn(string $f, string $v): string => "{$f}={$v}",
        array_keys($imageTags),
        array_values($imageTags),
    )),
));
fwrite(STDOUT, sprintf("  composer require.php        %s\n", $requirePhp ?? '(absent)'));
fwrite(STDOUT, sprintf("  config.platform.php         %s\n", $declaredPlatform ?? "\033[33mABSENT\033[0m"));
fwrite(STDOUT, sprintf(
    "  target used for this check  %s%s\n\n",
    $targetPhp ?? '(undetermined)',
    $targetSource !== null ? "  (from {$targetSource})" : '',
));

if (count($distinctTags) > 1) {
    $problems[] = 'deploy files disagree on the PHP image tag (' . implode(' vs ', $distinctTags)
        . ') — there is no single platform to resolve for. Reconcile them first.';
}

if ($imageTags === []) {
    $notes[] = 'No spryker/php image tag found in any deploy*.yml, so the deployment PHP could not be '
        . 'derived. Pass --target=<version> to check against the real one.';
}

// The host/deployment minor gap is the actual hazard — a patch difference is harmless.
if ($targetPhp !== null) {
    $hostMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $targetMinor = implode('.', array_slice(explode('.', $targetPhp), 0, 2));

    if ($hostMinor !== $targetMinor) {
        if ($declaredPlatform === null) {
            $problems[] = "host PHP is {$hostMinor} but the project deploys on {$targetMinor}, and "
                . 'config.platform.php is ABSENT. Any composer update run here can produce a lock that '
                . 'does not install in the container. require.php (' . ($requirePhp ?? 'absent')
                . ") does NOT prevent this — the host satisfies it.";
        } else {
            $notes[] = "host PHP ({$hostMinor}) differs from the deployment PHP ({$targetMinor}), but "
                . "config.platform.php is set to {$declaredPlatform}, so composer resolves for the "
                . 'target. Extensions are still not emulated — see below.';
        }
    }
}

if ($declaredPlatform !== null && $targetPhp !== null) {
    $declaredMinor = implode('.', array_slice(explode('.', (string)$declaredPlatform), 0, 2));
    $targetMinor = implode('.', array_slice(explode('.', $targetPhp), 0, 2));
    if ($declaredMinor !== $targetMinor) {
        $problems[] = "config.platform.php is {$declaredPlatform} but the deploy image is "
            . "{$targetMinor}.x — the pin points at the wrong platform.";
    }
}

/**
 * The decisive check: does the CURRENT lock actually install on the target PHP? This is what
 * `docker/sdk up` will discover the hard way.
 */
$lockPath = $root . '/composer.lock';
$incompatible = [];
$extensionsRequired = [];

if (is_file($lockPath) && $targetPhp !== null) {
    $autoload = $root . '/vendor/autoload.php';
    $haveSemver = false;
    if (is_file($autoload)) {
        require_once $autoload;
        $haveSemver = class_exists(\Composer\Semver\Semver::class);
    }

    $lock = json_decode((string)file_get_contents($lockPath), true);
    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] ?? [] as $package) {
            foreach ($package['require'] ?? [] as $dependency => $constraint) {
                if ($dependency === 'php' && $haveSemver) {
                    try {
                        if (!\Composer\Semver\Semver::satisfies($targetPhp, $constraint)) {
                            $incompatible[] = [$section, $package['name'], $package['version'], $constraint];
                        }
                    } catch (Throwable) {
                        // an unparseable constraint is not our finding to make
                    }
                }
                if (str_starts_with($dependency, 'ext-')) {
                    $extensionsRequired[$dependency] = true;
                }
            }
        }
    }

    if (!$haveSemver) {
        $notes[] = 'composer/semver not autoloadable (run composer install), so the lock could not be '
            . 'checked against the target PHP — the most important check here was skipped.';
    }
}

if ($incompatible !== []) {
    $problems[] = sprintf(
        '%d locked package(s) cannot install on PHP %s — this lock is already broken for the container:',
        count($incompatible),
        $targetPhp,
    );
}

// Extensions the lock needs that this host lacks: proof the host cannot resolve here at all.
$missingExtensions = [];
foreach (array_keys($extensionsRequired) as $extension) {
    $name = substr($extension, 4);
    if (!extension_loaded($name)) {
        $missingExtensions[] = $extension;
    }
}
foreach ($composerJson['require'] ?? [] as $dependency => $_) {
    if (str_starts_with($dependency, 'ext-') && !extension_loaded(substr($dependency, 4))) {
        $missingExtensions[] = $dependency;
    }
}
$missingExtensions = array_values(array_unique($missingExtensions));

foreach ($problems as $problem) {
    fwrite(STDOUT, "  \033[31mPROBLEM\033[0m  {$problem}\n");
}
foreach ($incompatible as [$section, $name, $version, $constraint]) {
    fwrite(STDOUT, sprintf("             %-14s %-34s %-12s php=%s\n", $section, $name, $version, $constraint));
}
if ($problems !== []) {
    fwrite(STDOUT, "\n");
}

if ($missingExtensions !== []) {
    fwrite(STDOUT, sprintf(
        "  \033[33mNOTE\033[0m     this host is missing %d required extension(s): %s\n"
        . "             Composer cannot resolve the packages needing them here at ALL. Run composer in\n"
        . "             the container (`docker/sdk cli composer …`). Do NOT use --ignore-platform-req:\n"
        . "             it fakes the requirement and re-creates the uninstallable lock.\n\n",
        count($missingExtensions),
        implode(', ', array_slice($missingExtensions, 0, 8)) . (count($missingExtensions) > 8 ? ', …' : ''),
    ));
}

foreach ($notes as $note) {
    fwrite(STDOUT, "  \033[33mNOTE\033[0m     {$note}\n");
}

if ($problems === []) {
    fwrite(STDOUT, "\n  Aligned: a composer update run here resolves for the deployment platform.\n");
    if ($missingExtensions !== []) {
        fwrite(STDOUT, "  (Still prefer the container — the extension gap above is real.)\n");
    }
    exit(0);
}

$suggested = $targetPhp ?? '<deployment php>';
fwrite(STDOUT, <<<TEXT

  Fix before running any composer update:

    "config": { "platform": { "php": "{$suggested}" } }

  Prefer the LOWEST patch that satisfies the lock over the image's current patch — spryker/php:8.x is
  a floating tag, so environments pull different patches. Then re-resolve INSIDE the container and
  confirm with `docker/sdk cli composer check-platform-reqs` (must report 0 failures).

  Anything already resolved on a mismatched host must be re-resolved: the existing lock is suspect,
  and so is any test result produced against it.

TEXT);

exit(1);
