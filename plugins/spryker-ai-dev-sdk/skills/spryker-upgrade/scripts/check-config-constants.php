<?php

/**
 * Config-constants detector (upgrade use case: configuration development strategy —
 * config/Shared/config_*.php sets values keyed by vendor *Constants interfaces; an upgrade
 * can remove/rename an interface or a constant, breaking bootstrap of every application).
 *
 * Scans all PHP files under config/ for references to vendor classes/interfaces
 * (via `use` imports and inline FQCNs) and `Alias::CONSTANT` usages, then verifies through
 * reflection that the type and the constant still exist.
 *
 * Usage:
 *   php $UP/check-config-constants.php   # any time — exit 1 on missing
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
require $root . '/vendor/autoload.php';

$stateDir = spryker_upgrade_state_dir($root);
$reportFile = $stateDir . '/config-constants-report.json';

function isVendorClass(string $fqcn): bool
{
    foreach (['Spryker\\', 'SprykerShop\\', 'SprykerEco\\', 'SprykerSdk\\', 'SprykerFeature\\', 'Generated\\'] as $prefix) {
        if (str_starts_with($fqcn, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * Generated\ classes (transfer objects) do not exist until `transfer:generate` has run, so
 * their absence is not upgrade damage. They are reported separately: after regeneration a
 * still-missing transfer DOES mean the definition was dropped upstream.
 */
function isGeneratedClass(string $fqcn): bool
{
    return str_starts_with($fqcn, 'Generated\\');
}

$problems = [];
$generatedMissing = [];
$checkedTypes = 0;
$checkedConstants = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/config', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $relFile = str_replace($root . '/', '', $file->getPathname());
    $code = (string)file_get_contents($file->getPathname());

    // Build alias => FQCN map from use statements.
    $aliases = [];
    if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+?)(?:\s+as\s+(\w+))?;/m', $code, $m, PREG_SET_ORDER)) {
        foreach ($m as $u) {
            $fqcn = $u[1];
            $alias = $u[2] ?? substr($fqcn, (int)strrpos($fqcn, '\\') + 1);
            $aliases[$alias] = $fqcn;
        }
    }

    // 1. Verify every imported vendor type still exists.
    foreach ($aliases as $alias => $fqcn) {
        if (!isVendorClass($fqcn)) {
            continue;
        }
        $checkedTypes++;
        $exists = false;
        try {
            $exists = class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn);
        } catch (Throwable) {
        }
        if (!$exists) {
            if (isGeneratedClass($fqcn)) {
                $generatedMissing[] = ['file' => $relFile, 'subject' => $fqcn];
            } else {
                $problems[] = [
                    'type' => 'TYPE_MISSING',
                    'file' => $relFile,
                    'subject' => $fqcn,
                    'detail' => 'Imported vendor class/interface no longer exists — config file will fatal on bootstrap.',
                ];
            }
            unset($aliases[$alias]); // constant checks below would be redundant noise
        }
    }

    // 2. Verify every Alias::CONSTANT / \Fqcn::CONSTANT reference.
    if (preg_match_all('/(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::([A-Z][A-Z0-9_]+)\b/', $code, $m, PREG_SET_ORDER)) {
        $seen = [];
        foreach ($m as $ref) {
            [$full, $typeRef, $constant] = $ref;
            if (in_array($constant, ['CLASS', 'PHP_EOL'], true) || str_ends_with($typeRef, '::')) {
                continue;
            }
            $fqcn = str_starts_with($typeRef, '\\')
                ? substr($typeRef, 1)
                : ($aliases[$typeRef] ?? null);
            if ($fqcn === null || !isVendorClass($fqcn) || isset($seen[$fqcn . '::' . $constant])) {
                continue;
            }
            $seen[$fqcn . '::' . $constant] = true;
            $checkedConstants++;

            try {
                if (!class_exists($fqcn) && !interface_exists($fqcn) && !enum_exists($fqcn)) {
                    continue; // already reported as TYPE_MISSING above (or non-loadable inline FQCN)
                }
                if (!(new ReflectionClass($fqcn))->hasConstant($constant)) {
                    $problems[] = [
                        'type' => 'CONSTANT_MISSING',
                        'file' => $relFile,
                        'subject' => $fqcn . '::' . $constant,
                        'detail' => 'Vendor constant referenced by project config no longer exists — '
                            . 'config will fatal, or a renamed setting silently stops applying.',
                    ];
                }
            } catch (Throwable) {
                // unloadable type on an inline FQCN
                $problems[] = [
                    'type' => 'TYPE_MISSING',
                    'file' => $relFile,
                    'subject' => $fqcn,
                    'detail' => 'Vendor type referenced inline no longer loads.',
                ];
            }
        }
    }
}

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}
file_put_contents($reportFile, json_encode([
    'createdAt' => date('c'),
    'checkedTypes' => $checkedTypes,
    'checkedConstants' => $checkedConstants,
    'problems' => $problems,
    'generatedMissing' => $generatedMissing,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf("Checked %d vendor type imports and %d vendor constant references across config/.\n", $checkedTypes, $checkedConstants);

if ($generatedMissing !== []) {
    printf(
        "\nNOTE: %d Generated\\ transfer class(es) referenced by config are absent. Run\n"
        . "  vendor/bin/console transfer:generate\n"
        . "and re-run this check — any that are still missing then were dropped upstream.\n",
        count($generatedMissing)
    );
}

if ($problems === []) {
    echo "\nOK: all vendor types and constants used by project config still exist.\n";
    exit(0);
}

printf("\nFOUND %d problem(s):\n\n", count($problems));
foreach ($problems as $p) {
    printf("[%s] %s\n  file: %s\n  %s\n\n", $p['type'], $p['subject'], $p['file'], $p['detail']);
}
echo "Full report: " . str_replace($root . '/', '', $reportFile) . "\n";
exit(1);
