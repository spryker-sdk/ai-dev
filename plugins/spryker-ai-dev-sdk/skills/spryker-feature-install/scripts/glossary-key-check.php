<?php

/**
 * Glossary key check: which translation keys a feature uses or ships that the project's glossary
 * does not define, per project locale.
 *
 * Install guides rarely have a glossary step, so a freshly installed page or mail shows raw keys
 * (`wishlist.wishlist`) until rows exist. This collects:
 *   - every `'key' | trans` and `"key"|trans` key in the Twig of the given modules or packages, in
 *     vendor/ and the project's overrides in src/;
 *   - keys PHP passes to a translation: `setSubject(…)`, `setSubjectTranslationKey(…)`,
 *     `translate(…)`, `add{Success,Error,Info}Message(…)`, as a literal or as a class constant of the
 *     same file, and every dotted class constant whose name says SUBJECT, GLOSSARY, TRANSLATION or
 *     MESSAGE (a mail subject is usually set from such a constant through a variable);
 *   - every key a package ships in its own glossary CSV, so each project locale gets a row even when
 *     the package ships other locales only;
 * and compares them with the `key,translation,locale` rows of every data/import/** CSV whose name
 * contains "glossary". Keys built at runtime (concatenation, variables) cannot be seen.
 *
 * Locales: --locales, else config/Shared/stores.php, else the locales found in the glossary files.
 *
 * Usage (from the project root, or set SPRYKER_PROJECT_ROOT):
 *   php glossary-key-check.php --module=WishlistWidget,WishlistPage
 *   php glossary-key-check.php --package=spryker-shop/wishlist-widget [--locales=fr_FR,en_US]
 *
 * Output: one JSON object (modules, locales, keys, missing per locale) and a one-line summary.
 * Exit code: 0 when no key is missing, 1 when keys are missing, 2 on a usage or project error.
 */

declare(strict_types=1);

function glossary_check_project_root(): string
{
    $explicit = getenv('SPRYKER_PROJECT_ROOT');
    if (is_string($explicit) && $explicit !== '') {
        $real = realpath($explicit);
        if ($real === false || !is_file($real . '/composer.json')) {
            fwrite(STDERR, "SPRYKER_PROJECT_ROOT does not point at a composer project: $explicit\n");
            exit(2);
        }

        return $real;
    }

    $dir = (string)getcwd();
    while (true) {
        if (is_file($dir . '/composer.json') && is_dir($dir . '/src') && is_dir($dir . '/vendor')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            fwrite(STDERR, "Could not find a Spryker project root (composer.json, src/, vendor/) above the current directory.\n");
            exit(2);
        }
        $dir = $parent;
    }
}

/**
 * @return list<string>
 */
function glossary_check_files(string $dir, callable $accept): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $accept($file->getPathname())) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * Directories holding the Yves code of a module: vendor packages (any vendor) and the project layer.
 *
 * @param list<string> $modules
 * @param list<string> $packages
 *
 * @return array<string, list<string>> module or package => directories
 */
function glossary_check_module_dirs(string $root, array $modules, array $packages): array
{
    $dirs = [];
    foreach ($packages as $package) {
        $dir = $root . '/vendor/' . $package;
        if (is_dir($dir)) {
            $dirs[$package][] = $dir;
        }
    }
    foreach ($modules as $module) {
        foreach (glob($root . '/vendor/*/*/src/*/Yves/' . $module) ?: [] as $dir) {
            $dirs[$module][] = $dir;
        }
        foreach (glob($root . '/src/*/Yves/' . $module) ?: [] as $dir) {
            $dirs[$module][] = $dir;
        }
    }

    return $dirs;
}

/**
 * @return list<string>
 */
function glossary_check_keys_in_twig(string $file): array
{
    $source = (string)file_get_contents($file);
    $source = (string)preg_replace('/\{#.*?#\}/s', '', $source);
    preg_match_all('/([\'"])([A-Za-z0-9_.\-]+)\1\s*\|\s*trans\b/', $source, $matches);

    return array_values(array_unique(array_filter($matches[2], fn (string $key): bool => str_contains($key, '.'))));
}

const GLOSSARY_CHECK_PHP_CALLS = 'setSubject|setSubjectTranslationKey|translate|addSuccessMessage|addErrorMessage|addInfoMessage';

/**
 * Keys a PHP file passes to a translating call, as a string literal or as a constant of the file.
 *
 * @return list<string>
 */
function glossary_check_keys_in_php(string $file): array
{
    $source = (string)file_get_contents($file);
    $constants = [];
    $keys = [];
    if (preg_match_all('/const\s+(?:[\w\\\\|?]+\s+)?([A-Z][A-Z0-9_]*)\s*=\s*([\'"])([A-Za-z0-9_.\-]+)\2\s*;/', $source, $m, PREG_SET_ORDER)) {
        foreach ($m as $constant) {
            $constants[$constant[1]] = $constant[3];
            if (preg_match('/SUBJECT|GLOSSARY|TRANSLATION|MESSAGE/', $constant[1]) && str_contains($constant[3], '.')) {
                $keys[] = $constant[3];
            }
        }
    }
    if (preg_match_all('/->(?:' . GLOSSARY_CHECK_PHP_CALLS . ')\(\s*(?:([\'"])([A-Za-z0-9_.\-]+)\1|(?:static|self)::(\w+))/', $source, $m, PREG_SET_ORDER)) {
        foreach ($m as $call) {
            $key = ($call[2] ?? '') !== '' ? $call[2] : ($constants[$call[3] ?? ''] ?? '');
            if (str_contains($key, '.')) {
                $keys[] = $key;
            }
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Keys a package ships in its own glossary CSV files (any `*glossary*.csv` below the package).
 *
 * @return list<string>
 */
function glossary_check_shipped_keys(string $packageDir): array
{
    $keys = [];
    foreach (glossary_check_files($packageDir, static fn (string $path): bool => (bool)preg_match('/glossary[^\/]*\.csv$/i', basename($path))) as $file) {
        foreach (array_keys(glossary_check_read_csv($file)) as $key) {
            $keys[] = $key;
        }
    }

    return array_values(array_unique($keys));
}

/**
 * @return array<string, array<string, true>> key => locale => true
 */
function glossary_check_read_csv(string $file): array
{
    $rows = [];
    $handle = fopen($file, 'r');
    if ($handle === false) {
        return [];
    }
    fgetcsv($handle, 0, ',', '"', '');
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if (isset($row[0], $row[2]) && $row[0] !== '') {
            $rows[$row[0]][trim($row[2])] = true;
        }
    }
    fclose($handle);

    return $rows;
}

/**
 * The package directory (vendor/<vendor>/<name>) a module directory belongs to.
 */
function glossary_check_package_dir(string $dir): ?string
{
    return preg_match('#^(.*/vendor/[^/]+/[^/]+)(/|$)#', $dir, $m) ? $m[1] : null;
}

/**
 * @return list<string>
 */
function glossary_check_locales(string $root, array $glossary): array
{
    $stores = $root . '/config/Shared/stores.php';
    if (is_file($stores) && preg_match_all('/[\'"][a-z]{2}[\'"]\s*=>\s*[\'"]([a-z]{2}_[A-Z]{2})[\'"]/', (string)file_get_contents($stores), $matches)) {
        return array_values(array_unique($matches[1]));
    }
    $locales = [];
    foreach ($glossary as $byLocale) {
        foreach (array_keys($byLocale) as $locale) {
            $locales[$locale] = true;
        }
    }

    return array_keys($locales);
}

/**
 * @return array<string, array<string, true>> key => locale => true
 */
function glossary_check_load_glossary(string $root): array
{
    $glossary = [];
    $files = glossary_check_files($root . '/data/import', fn (string $path): bool => (bool)preg_match('/glossary[^\/]*\.csv$/i', basename($path)));
    foreach ($files as $file) {
        foreach (glossary_check_read_csv($file) as $key => $locales) {
            $glossary[$key] = ($glossary[$key] ?? []) + $locales;
        }
    }

    return $glossary;
}

function glossary_check_main(array $argv): int
{
    $modules = [];
    $packages = [];
    $locales = [];
    $usage = "Usage: glossary-key-check.php [--module=Name,...] [--package=vendor/name,...] [--locales=xx_XX,...]\n";
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--module=')) {
            array_push($modules, ...array_filter(explode(',', substr($argument, 9))));
        } elseif (str_starts_with($argument, '--package=')) {
            array_push($packages, ...array_filter(explode(',', substr($argument, 10))));
        } elseif (str_starts_with($argument, '--locales=')) {
            array_push($locales, ...array_filter(explode(',', substr($argument, 10))));
        } else {
            fwrite(STDERR, $usage);

            return 2;
        }
    }
    if ($modules === [] && $packages === []) {
        fwrite(STDERR, $usage);

        return 2;
    }

    $root = glossary_check_project_root();
    $dirs = glossary_check_module_dirs($root, $modules, $packages);
    $keys = [];
    $sources = ['twig' => 0, 'php' => 0, 'shipped' => 0];
    $packageDirs = [];
    foreach ($dirs as $directories) {
        foreach ($directories as $dir) {
            foreach (glossary_check_files($dir, fn (string $path): bool => str_ends_with($path, '.twig') || str_ends_with($path, '.php')) as $file) {
                $isTwig = str_ends_with($file, '.twig');
                foreach ($isTwig ? glossary_check_keys_in_twig($file) : glossary_check_keys_in_php($file) as $key) {
                    $sources[$isTwig ? 'twig' : 'php'] += isset($keys[$key]) ? 0 : 1;
                    $keys[$key] = true;
                }
            }
            $packageDir = glossary_check_package_dir($dir);
            if ($packageDir !== null) {
                $packageDirs[$packageDir] = true;
            }
        }
    }
    foreach (array_keys($packageDirs) as $packageDir) {
        foreach (glossary_check_shipped_keys($packageDir) as $key) {
            $sources['shipped'] += isset($keys[$key]) ? 0 : 1;
            $keys[$key] = true;
        }
    }
    $keys = array_keys($keys);
    sort($keys);

    $glossary = glossary_check_load_glossary($root);
    $locales = $locales !== [] ? $locales : glossary_check_locales($root, $glossary);
    $missing = array_fill_keys($locales, []);
    foreach ($keys as $key) {
        foreach ($locales as $locale) {
            if (!isset($glossary[$key][$locale])) {
                $missing[$locale][] = $key;
            }
        }
    }
    $total = array_sum(array_map('count', $missing));
    $notFound = array_values(array_diff(array_merge($modules, $packages), array_keys($dirs)));

    echo json_encode([
        'modules' => array_keys($dirs),
        'modules_not_found' => $notFound,
        'locales' => $locales,
        'keys' => count($keys),
        'sources' => $sources,
        'missing' => $missing,
    ], JSON_UNESCAPED_SLASHES), "\n";
    printf("%d key(s) in %d module(s), %d missing locale row(s) across %s\n", count($keys), count($dirs), $total, implode(', ', $locales) ?: 'no locales');

    return $total > 0 ? 1 : 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(glossary_check_main($argv));
}
