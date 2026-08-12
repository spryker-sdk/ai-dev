<?php

/**
 * Dead-override detector (upgrade use case: overridden core method removed in a new module version).
 *
 * PHP lets a class override a method that no longer exists in its parent chain — the project
 * business logic silently stops being called. This script makes that visible.
 *
 * Usage:
 *   php $UP/check-dead-overrides.php snapshot   # BEFORE composer update
 *   php $UP/check-dead-overrides.php verify     # AFTER composer update
 *
 * snapshot: records every Pyz method that currently overrides a method declared in a vendor
 *           (Spryker*) ancestor into the state dir as override-map.json
 * verify:   recomputes the map and reports every previously-recorded override whose vendor
 *           counterpart disappeared. Exit code 1 when conflicts are found.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
require $root . '/vendor/autoload.php';

$stateDir = spryker_upgrade_state_dir($root);
$stateFile = $stateDir . '/override-map.json';
$reportFile = $stateDir . '/dead-overrides-report.json';

// Child-process mode must be handled BEFORE the usage guard below, otherwise every child exits
// with the usage error and the parent reads that as "this batch fataled".
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--scan-batch=')) {
        $batchClasses = json_decode((string)base64_decode(substr($arg, 13)), true) ?: [];
        echo json_encode(scanClassesInline($root, $batchClasses));
        exit(0);
    }
}

$mode = $argv[1] ?? '';
if (!in_array($mode, ['snapshot', 'verify'], true)) {
    fwrite(STDERR, "Usage: check-dead-overrides.php <snapshot|verify>\n");
    exit(2);
}

function isVendorClass(string $fqcn): bool
{
    foreach (['Spryker\\', 'SprykerShop\\', 'SprykerEco\\', 'SprykerSdk\\'] as $prefix) {
        if (str_starts_with($fqcn, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string> candidate Pyz FQCNs, derived from file paths
 */
function collectPyzClasses(string $root): array
{
    $srcDir = $root . '/src/Pyz';
    $classes = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($srcDir) + 1, -4);
        $classes[] = 'Pyz\\' . str_replace('/', '\\', $relative);
    }
    sort($classes);

    return $classes;
}

/**
 * Scan one batch of classes in a CHILD process.
 *
 * Some breakage is a compile-time fatal that try/catch cannot trap — e.g. core adding a typed
 * class constant (`const string FACADE_X`) while the Pyz override leaves it untyped raises
 * "Type of ...::FACADE_X must be compatible with ...", which aborts the whole PHP process. Doing
 * the reflection in a child means one poisoned class costs that batch, not the entire run: the
 * batch is then re-scanned one class at a time to attribute the fatal precisely.
 *
 * @param list<string> $classes
 * @return array{ok: bool, map: array<string, mixed>, unloadable: array<string, string>}
 */
function scanBatch(string $root, array $classes): array
{
    $script = __FILE__;
    $payload = base64_encode(json_encode($classes));
    $cmd = sprintf(
        '%s %s --scan-batch=%s 2>/dev/null',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script),
        escapeshellarg($payload)
    );
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $decoded = json_decode(implode("\n", $output), true);

    if ($exitCode !== 0 || !is_array($decoded)) {
        return ['ok' => false, 'map' => [], 'unloadable' => []];
    }

    return ['ok' => true, 'map' => $decoded['map'], 'unloadable' => $decoded['unloadable']];
}

/**
 * @param list<string> $classes
 * @return array{map: array<string, mixed>, unloadable: array<string, string>}
 */
function scanClassesInline(string $root, array $classes): array
{
    $map = [];
    $unloadable = [];

    foreach ($classes as $fqcn) {
        try {
            if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
                continue;
            }
            $ref = new ReflectionClass($fqcn);
        } catch (Throwable $e) {
            $unloadable[$fqcn] = $e->getMessage();
            continue;
        }

        foreach ($ref->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            $parent = $ref->getParentClass();
            while ($parent) {
                if ($parent->hasMethod($method->getName())) {
                    $declaring = $parent->getMethod($method->getName())->getDeclaringClass()->getName();
                    if (isVendorClass($declaring)) {
                        $map[$fqcn . '::' . $method->getName()] = [
                            'class' => $fqcn,
                            'method' => $method->getName(),
                            'file' => str_replace($root . '/', '', (string)$method->getFileName()),
                            'vendorDeclaringClass' => $declaring,
                        ];
                    }
                    break;
                }
                $parent = $parent->getParentClass();
            }
        }
    }

    return ['map' => $map, 'unloadable' => $unloadable];
}

/**
 * Does the project class itself still declare this method?
 *
 * Runs in a child process because the class may be one of the poisoned ones that fatal on load.
 * A load failure answers "unknown", and the caller then treats the entry as a real conflict —
 * the safe direction.
 */
function projectStillDeclares(string $root, string $class, string $method): bool
{
    $cmd = sprintf(
        '%s -r %s 2>/dev/null',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(sprintf(
            'require %s; $r = new ReflectionClass(%s); '
            . 'echo $r->hasMethod(%s) && $r->getMethod(%s)->getDeclaringClass()->getName() === %s ? "YES" : "NO";',
            var_export($root . '/vendor/autoload.php', true),
            var_export($class, true),
            var_export($method, true),
            var_export($method, true),
            var_export($class, true)
        ))
    );
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        return true; // could not determine — report it rather than hide it
    }

    return trim(implode('', $output)) === 'YES';
}

/**
 * @return array{map: array<string, array<string, string>>, unloadable: array<string, string>}
 */
function buildOverrideMap(string $root): array
{
    $classes = collectPyzClasses($root);
    $map = [];
    $unloadable = [];

    // Batch for speed; on a fatal, fall back to one-by-one to pinpoint the offending class.
    foreach (array_chunk($classes, 100) as $batch) {
        $result = scanBatch($root, $batch);
        if ($result['ok']) {
            $map += $result['map'];
            $unloadable += $result['unloadable'];
            continue;
        }

        foreach ($batch as $fqcn) {
            $single = scanBatch($root, [$fqcn]);
            if ($single['ok']) {
                $map += $single['map'];
                $unloadable += $single['unloadable'];
                continue;
            }
            $unloadable[$fqcn] = 'FATAL while loading this class (incompatible declaration vs the '
                . 'vendor parent — e.g. a class constant that core now declares with a type). '
                . 'Run: php -r \'require "vendor/autoload.php"; new ReflectionClass("' . $fqcn . '");\'';
        }
    }

    ksort($map);
    ksort($unloadable);

    return ['map' => $map, 'unloadable' => $unloadable];
}

$result = buildOverrideMap($root);

if ($mode === 'snapshot') {
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0775, true);
    }
    file_put_contents($stateFile, json_encode([
        'createdAt' => date('c'),
        'overrides' => $result['map'],
        'unloadable' => $result['unloadable'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    printf(
        "Snapshot written: %s\n  %d vendor-method overrides recorded across src/Pyz\n  %d classes could not be loaded (see snapshot file)\n",
        str_replace($root . '/', '', $stateFile),
        count($result['map']),
        count($result['unloadable'])
    );
    exit(0);
}

// verify
if (!is_file($stateFile)) {
    fwrite(STDERR, "No snapshot found at $stateFile — run 'snapshot' before upgrading.\n");
    exit(2);
}

$snapshot = json_decode((string)file_get_contents($stateFile), true);
$conflicts = [];
$resolved = [];

foreach ($snapshot['overrides'] as $key => $entry) {
    if (isset($result['map'][$key])) {
        continue; // override still anchored to a vendor method
    }

    $projectFile = $root . '/' . $entry['file'];
    if (!is_file($projectFile)) {
        continue; // project removed the class itself — nothing to report
    }

    if (isset($result['unloadable'][$entry['class']])) {
        $conflicts[] = $entry + [
            'type' => 'CLASS_BROKEN',
            'detail' => 'Class no longer loads (parent class or interface removed?): '
                . $result['unloadable'][$entry['class']],
        ];
        continue;
    }

    // An entry can drop out of the map for two very different reasons: the vendor method was
    // removed (a real orphan), or the project stopped declaring the override (already resolved,
    // e.g. during this upgrade). Distinguish them, or resolved work keeps reporting as damage.
    if (!projectStillDeclares($root, $entry['class'], $entry['method'])) {
        $resolved[] = $entry + [
            'type' => 'OVERRIDE_REMOVED',
            'detail' => 'The project no longer declares this override — recorded as resolved, not damage.',
        ];

        continue;
    }

    $conflicts[] = $entry + [
        'type' => 'OVERRIDE_ORPHANED',
        'detail' => sprintf(
            'Method %s() no longer exists in the vendor parent chain (was declared in %s). '
            . 'The project logic in this method is likely no longer invoked.',
            $entry['method'],
            $entry['vendorDeclaringClass']
        ),
    ];
}

// New load failures that were fine at snapshot time are conflicts too.
foreach ($result['unloadable'] as $fqcn => $error) {
    if (!isset($snapshot['unloadable'][$fqcn]) && !array_key_exists($fqcn, array_column($conflicts, null, 'class'))) {
        $conflicts[] = [
            'class' => $fqcn,
            'method' => '-',
            'file' => '-',
            'vendorDeclaringClass' => '-',
            'type' => 'CLASS_BROKEN',
            'detail' => $error,
        ];
    }
}

file_put_contents($reportFile, json_encode([
    'createdAt' => date('c'),
    'conflicts' => $conflicts,
    'resolved' => $resolved,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

if ($resolved !== []) {
    printf("%d recorded override(s) are gone because the project removed them (resolved, not damage).\n", count($resolved));
}

if ($conflicts === []) {
    echo "OK: all " . count($snapshot['overrides']) . " recorded vendor-method overrides are still anchored.\n";
    exit(0);
}

printf("FOUND %d dead/broken override(s):\n\n", count($conflicts));
foreach ($conflicts as $c) {
    printf("[%s] %s::%s\n  file:   %s\n  vendor: %s\n  %s\n\n",
        $c['type'], $c['class'], $c['method'], $c['file'], $c['vendorDeclaringClass'], $c['detail']);
}
echo "Full report: " . str_replace($root . '/', '', $reportFile) . "\n";
exit(1);
