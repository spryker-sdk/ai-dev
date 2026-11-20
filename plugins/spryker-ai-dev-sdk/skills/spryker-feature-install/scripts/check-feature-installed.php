<?php

/**
 * Installed-state check: which parts of a feature the project already has, and whether the wiring
 * the checklist decided on is in the project's DependencyProviders.
 *
 * A project often has part of a feature already — a prerequisite feature, a plugin a sibling
 * feature wired, a module pulled in earlier. Applying such a step again duplicates a plugin in a
 * stack. Before the install this script finds those rows; after the install it proves the wiring.
 *
 * Usage:
 *   php check-feature-installed.php <checklist.json> [--mark]
 *   php check-feature-installed.php <checklist.json> --wiring
 *
 *   (default)  modules of each guide's module table: installed or missing; wiring rows: present,
 *              partial or absent in the project
 *   --mark     also sets decision `already-present` on undecided wiring rows that are fully present
 *   --wiring   gate after the install: every wiring row that is not skipped must be fully present
 *
 * The guide's `src/Pyz/` paths are matched against every project namespace in composer.json. A
 * plugin whose class is not in `vendor/` is reported as not installed: its module is not part of
 * the project, so the plugin is not a step (a marketplace plugin in a B2C project).
 *
 * Exit codes: 0 report (default, --mark) or all wiring present (--wiring); 1 --wiring found missing
 * plugins; 2 usage or unreadable checklist.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

const CFI_WIRING_KINDS = ['plugin', 'route', 'widget', 'queue'];

/**
 * @return list<string> project namespaces, e.g. ['Pyz', 'Demo']
 */
function cfi_project_namespaces(string $root): array
{
    $composer = json_decode((string)@file_get_contents($root . '/composer.json'), true);
    $namespaces = [];
    foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $dirs) {
        foreach ((array)$dirs as $dir) {
            if (preg_match('#^src/([A-Z]\w*)/?$#', rtrim((string)$dir, '/') . '/', $m) && !in_array($m[1], ['Generated', 'Orm'], true)) {
                $namespaces[] = $m[1];
            }
        }
        if (preg_match('/^([A-Z]\w*)\\\\$/', (string)$prefix, $m) && is_dir($root . '/src/' . $m[1]) && !in_array($m[1], ['Generated', 'Orm'], true)) {
            $namespaces[] = $m[1];
        }
    }
    if ($namespaces === [] && is_dir($root . '/src/Pyz')) {
        $namespaces[] = 'Pyz';
    }

    return array_values(array_unique($namespaces));
}

/**
 * @return list<string> existing project files a guide path maps to
 */
function cfi_project_files(string $root, string $guidePath, array $namespaces): array
{
    $files = [];
    foreach ($namespaces as $namespace) {
        $candidate = (string)preg_replace('#^src/Pyz/#', 'src/' . $namespace . '/', $guidePath);
        if (is_file($root . '/' . $candidate)) {
            $files[] = $candidate;
        }
    }

    return array_values(array_unique($files));
}

/**
 * @return list<string> every DependencyProvider under the project namespaces
 */
function cfi_dependency_providers(string $root, array $namespaces): array
{
    $files = [];
    foreach ($namespaces as $namespace) {
        $base = $root . '/src/' . $namespace;
        if (!is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), 'DependencyProvider.php')) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($files);

    return $files;
}

/**
 * The source wires the plugin: its short name is used, and no `use` statement imports a class of
 * that name from another namespace. A deprecated class and its replacement often share the name.
 */
function cfi_mentions(string $source, string $plugin): bool
{
    $short = fi_short_name($plugin);
    if (!preg_match('/\b' . preg_quote($short, '/') . '\b/', $source)) {
        return false;
    }
    if (!str_contains($plugin, '\\') || str_contains($source, $plugin)) {
        return true;
    }

    return !preg_match('/^use\s+[A-Za-z0-9_\\\\]+\\\\' . preg_quote($short, '/') . '\s*;/m', $source);
}

/**
 * A vendor class is available when its file is in a package's `src/` under `vendor/`. A plugin of a
 * module the project does not install (a marketplace or company-account plugin in a B2C project)
 * cannot be wired. Without a `vendor/` directory nothing can be said, so every class counts as
 * available.
 */
function cfi_class_available(string $root, string $class): bool
{
    if (!str_contains($class, '\\') || !is_dir($root . '/vendor')) {
        return true;
    }

    return glob($root . '/vendor/*/*/src/' . str_replace('\\', '/', $class) . '.php') !== [];
}

/**
 * @return array{state: string, files: list<string>, missing: list<string>, unavailable: list<string>}
 */
function cfi_wiring_state(string $root, array $row, array $namespaces, array $providers): array
{
    $plugins = $row['plugins'] ?? [];
    $target = (string)($row['target'] ?? '');
    $unavailable = array_values(array_filter($plugins, static fn (string $plugin): bool => !cfi_class_available($root, $plugin)));
    $files = str_contains($target, '/') ? cfi_project_files($root, $target, $namespaces) : $providers;
    if ($files === []) {
        return ['state' => 'absent', 'files' => [], 'missing' => $plugins, 'unavailable' => $unavailable];
    }
    $source = '';
    foreach ($files as $file) {
        $source .= (string)file_get_contents($root . '/' . $file) . "\n";
    }
    $missing = array_values(array_filter($plugins, static fn (string $plugin): bool => !cfi_mentions($source, $plugin)));
    $state = match (true) {
        $plugins === [] => 'file-present',
        $missing === [] => 'present',
        count($missing) === count($plugins) => 'absent',
        default => 'partial',
    };
    if (!str_contains($target, '/')) {
        $files = array_values(array_filter($files, static fn (string $file): bool => cfi_mentions((string)file_get_contents($root . '/' . $file), $plugins[0] ?? '')));
    }

    return ['state' => $state, 'files' => $files, 'missing' => $missing, 'unavailable' => $unavailable];
}

function cfi_main(array $argv): int
{
    $path = null;
    $mark = false;
    $wiring = false;
    for ($i = 1; $i < count($argv); $i++) {
        match ($argv[$i]) {
            '--mark' => $mark = true,
            '--wiring' => $wiring = true,
            default => $path = $argv[$i],
        };
    }
    $checklist = $path !== null ? fi_load_checklist($path) : null;
    if ($checklist === null) {
        fwrite(STDERR, "usage: php check-feature-installed.php <checklist.json> [--mark | --wiring]\n");

        return 2;
    }
    $root = fi_project_root();
    $namespaces = cfi_project_namespaces($root);
    $providers = cfi_dependency_providers($root, $namespaces);

    if (!$wiring) {
        foreach ($checklist['guides'] as $guide) {
            $modules = $guide['modules'] ?? [];
            $missing = array_filter($modules, static fn (array $m): bool => $m['directory'] === '' || !is_dir($root . '/' . trim($m['directory'], '/')));
            fwrite(STDOUT, sprintf("%s: %d of %d modules installed\n", $guide['file'], count($modules) - count($missing), count($modules)));
            foreach ($missing as $module) {
                fwrite(STDOUT, sprintf("  missing  %-40s %s\n", $module['module'], $module['directory']));
            }
        }
    }

    $gaps = 0;
    $marked = 0;
    fwrite(STDOUT, sprintf("\nWiring rows (project namespaces: %s):\n", implode(', ', $namespaces)));
    foreach ($checklist['rows'] as $index => $row) {
        if (!in_array($row['kind'] ?? '', CFI_WIRING_KINDS, true)) {
            continue;
        }
        $decision = $row['decision'] ?? null;
        if ($wiring && ($decision === null || fi_is_skip($decision))) {
            continue;
        }
        $state = cfi_wiring_state($root, $row, $namespaces, $providers);
        if ($wiring && $state['state'] !== 'present' && $state['state'] !== 'file-present') {
            $gaps++;
        }
        if ($mark && $decision === null && $state['state'] === 'present') {
            $checklist['rows'][$index]['decision'] = 'already-present';
            $marked++;
        }
        fwrite(STDOUT, sprintf("  %-5s %-12s %s%s%s\n", $row['id'], $state['state'], $row['target'], $state['missing'] !== [] && $state['state'] === 'partial'
            ? ' — missing: ' . implode(', ', array_map('fi_short_name', $state['missing']))
            : '', $state['unavailable'] !== []
            ? ' — not in vendor/ (module not installed): ' . implode(', ', array_map('fi_short_name', $state['unavailable']))
            : ''));
    }
    if ($mark) {
        fi_save_checklist((string)$path, $checklist);
        fwrite(STDOUT, "\n$marked row(s) marked already-present.\n");
    }
    if ($wiring) {
        fwrite(STDOUT, $gaps === 0 ? "\nOK: every decided wiring row is present.\n" : "\n$gaps decided wiring row(s) not fully present.\n");

        return $gaps === 0 ? 0 : 1;
    }

    return 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(cfi_main($argv));
}
