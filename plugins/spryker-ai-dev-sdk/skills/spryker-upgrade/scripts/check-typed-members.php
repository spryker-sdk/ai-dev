<?php

/**
 * Typed-member detector (upgrade use case: core adopts PHP 8.3 typed class constants or typed
 * properties, and an untyped override of one becomes a FATAL at class load).
 *
 * When the target release's core adopts PHP 8.3 typing, this is a frequent class of damage.
 * "Type of Foo::BAR must be compatible with Parent::BAR of type int" is a compile-time error, so it is not catchable and it aborts whatever was running — including
 * `vendor/bin/console`, which means transfer:generate and every other command dies on startup.
 *
 * Why a static scan rather than loading classes:
 *   PHP reports only the first incompatible member per class, so a class with two offending
 *   constants hides the second until the first is fixed. Loading also cannot see past a class
 *   that fatals. This compares declarations in the source text against the typed members found up
 *   the parent chain, so every offender in every class is reported in one pass.
 *
 * Usage:
 *   php $UP/check-typed-members.php [<scanDir> ...] [--baseline] [--limit N]
 *
 * --baseline (Phase 0) also keeps the result in typed-members-baseline.json; a later run marks each
 * finding inBaseline and lists only the new ones. --limit sets the findings printed (default 10,
 * 0 = counts only).
 *
 * Defaults to scanning src/Pyz. Pass extra directories to cover packages that ship project-owned
 * code too, e.g. a merge-plugin bundle tree:
 *   php $UP/check-typed-members.php src/Pyz vendor/spryker/spryker-demo/Bundles
 *
 * Exit code 1 when any untyped member shadows a typed parent member.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/bootstrap.php';

$options = spryker_upgrade_output_options($argv, true);
$root = spryker_upgrade_project_root();
require $root . '/vendor/autoload.php';

$stateDir = spryker_upgrade_state_dir($root);
$reportFile = $stateDir . '/typed-members-report.json';

$dirs = array_slice($argv, 1);
if ($dirs === []) {
    $dirs = ['src/Pyz'];
}

/**
 * Resolve the parent FQCN from the source text — never by loading the child, because the child is
 * exactly the class that would fatal. Handles `extends Foo` plus the matching `use ... [as Foo];`.
 */
function resolveParentFromSource(string $src): ?string
{
    if (!preg_match('/^(?:final\s+|abstract\s+)?class\s+\w+\s+extends\s+([\w\\\\]+)/m', $src, $m)) {
        return null;
    }
    $parent = $m[1];
    if (str_starts_with($parent, '\\')) {
        return ltrim($parent, '\\');
    }
    if (preg_match('/^use\s+([\w\\\\]+)\s+as\s+' . preg_quote($parent, '/') . '\s*;/m', $src, $u)) {
        return $u[1];
    }
    if (preg_match('/^use\s+([\w\\\\]*\\\\' . preg_quote($parent, '/') . ')\s*;/m', $src, $u)) {
        return $u[1];
    }
    if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) {
        return trim($ns[1]) . '\\' . $parent; // same-namespace parent
    }

    return null;
}

/**
 * Typed constants and typed properties declared anywhere up the parent chain.
 *
 * The parent is core, so reflecting on it is safe — it is the child that fatals.
 *
 * @return array{constants: array<string, string>, properties: array<string, string>}
 */
function typedParentMembers(?string $parentClass): array
{
    $typed = ['constants' => [], 'properties' => []];
    if ($parentClass === null) {
        return $typed;
    }
    try {
        $ref = new ReflectionClass($parentClass);
    } catch (Throwable) {
        return $typed;
    }

    $scalar = 'int|string|float|bool|array|iterable|mixed|object|callable';
    for ($parent = $ref; $parent; $parent = $parent->getParentClass()) {
        $file = (string)$parent->getFileName();
        $src = $file !== '' ? @file_get_contents($file) : false;
        if ($src === false) {
            continue;
        }
        if (preg_match_all(
            '/(?:public|protected|private)\s+const\s+(' . $scalar . ')\s+([A-Z_][A-Z0-9_]*)/',
            $src,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $hit) {
                $typed['constants'][$hit[2]] ??= $hit[1];
            }
        }
        // Typed properties, including constructor-promoted ones.
        if (preg_match_all(
            '/(?:public|protected|private)\s+(?:readonly\s+)?\??([A-Z][\w\\\\]*|' . $scalar . ')\s+\$(\w+)/',
            $src,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $hit) {
                $typed['properties'][$hit[2]] ??= $hit[1];
            }
        }
    }

    return $typed;
}

$problems = [];
$scannedClasses = 0;

foreach ($dirs as $dir) {
    $abs = str_starts_with($dir, '/') ? $dir : $root . '/' . $dir;
    if (!is_dir($abs)) {
        fwrite(STDERR, "Skipping missing directory: $dir\n");
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $src = (string)file_get_contents($file->getPathname());
        if (!preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) {
            continue;
        }
        if (!preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cn)) {
            continue;
        }
        $class = trim($ns[1]) . '\\' . $cn[1];
        $parentClass = resolveParentFromSource($src);
        if ($parentClass === null) {
            continue;
        }

        $ownUntypedConstants = [];
        if (preg_match_all('/(?:public|protected|private)\s+const\s+([A-Z_][A-Z0-9_]*)\s*=/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $ownUntypedConstants[] = $hit[1];
            }
        }
        $ownUntypedProperties = [];
        if (preg_match_all('/(?:public|protected|private)\s+\$(\w+)\s*(?:=|;)/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $ownUntypedProperties[] = $hit[1];
            }
        }
        if ($ownUntypedConstants === [] && $ownUntypedProperties === []) {
            continue;
        }

        $scannedClasses++;
        $typed = typedParentMembers($parentClass);

        foreach ($ownUntypedConstants as $name) {
            if (isset($typed['constants'][$name])) {
                $problems[] = [
                    'kind' => 'CONSTANT',
                    'class' => $class,
                    'member' => $name,
                    'requiredType' => $typed['constants'][$name],
                    'parent' => $parentClass,
                    'file' => str_replace($root . '/', '', $file->getPathname()),
                    'fix' => sprintf('declare it as `const %s %s`', $typed['constants'][$name], $name),
                ];
            }
        }
        foreach ($ownUntypedProperties as $name) {
            if (isset($typed['properties'][$name])) {
                $problems[] = [
                    'kind' => 'PROPERTY',
                    'class' => $class,
                    'member' => '$' . $name,
                    'requiredType' => $typed['properties'][$name],
                    'parent' => $parentClass,
                    'file' => str_replace($root . '/', '', $file->getPathname()),
                    'fix' => sprintf(
                        'the parent declares $%s as %s — usually the redeclaration exists only to '
                        . 'narrow the docblock type and should be deleted, narrowing locally at the '
                        . 'usage site instead (core may promote it as a constructor property, which '
                        . 'cannot be redeclared with a narrower type)',
                        $name,
                        $typed['properties'][$name]
                    ),
                ];
            }
        }
    }
}

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}
$report = [
    'createdAt' => date('c'),
    'scannedDirs' => $dirs,
    'classesWithUntypedMembers' => $scannedClasses,
    'problems' => $problems,
];
$report = spryker_upgrade_compare_with_baseline(
    $stateDir,
    'typed-members',
    $report,
    ['problems'],
    static fn (string $list, array $entry): string => implode(' ', [$entry['kind'] ?? '', $entry['class'] ?? '', $entry['member'] ?? '']),
    $options['baseline']
) ?? $report;
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

spryker_upgrade_print_summary(
    'check-typed-members',
    ['typed-member fatals' => count($problems)],
    array_map(
        static fn (array $p): array => spryker_upgrade_finding($p['kind'], sprintf('%s::%s (parent %s declares %s)', $p['class'], $p['member'], $p['parent'], $p['requiredType']), $p),
        $report['problems']
    ),
    $reportFile,
    $root,
    $options['limit'],
    array_values(array_filter([spryker_upgrade_baseline_note($stateDir, 'typed-members', $options['baseline'], $problems !== [])]))
);
exit($problems === [] ? 0 : 1);
