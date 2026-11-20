<?php

/**
 * Data-manifest check: does every data file the install changed reach an environment?
 *
 * A row in `data/import/**` takes effect only when an import manifest that a deploy recipe runs
 * names its file. Projects keep several trees (`common/`, `local/`, `production/`, per region or
 * store), and a row written into a file that no recipe's manifest reads imports nowhere, while
 * every check on the local stack can still pass after a manual import.
 *
 * Manifests are found from the recipes: every `--config=<file>.yml` in `config/install/**`, with
 * `${SPRYKER_REGION}` and similar variables matched against the files that exist. Each manifest's
 * `source:` entries are the files it imports.
 *
 * Usage (from the project root, or set SPRYKER_PROJECT_ROOT):
 *   php check-data-manifests.php [<data file>...]
 *
 *   without files: the CSV files under data/import that differ from HEAD (changed or new)
 *
 * Exit codes: 0 every file is imported by at least one recipe's manifest; 1 a file no recipe
 * imports (listed); 2 usage, or no manifest found.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/**
 * @return list<string> manifest paths relative to the project root, from the install recipes
 */
function cdm_manifests(string $root): array
{
    $manifests = [];
    $recipes = array_merge(glob($root . '/config/install/*.yml') ?: [], glob($root . '/config/install/*/*.yml') ?: []);
    foreach ($recipes as $recipe) {
        if (!preg_match_all('/--config[= ]([^\s\'"]+\.ya?ml)/', (string)file_get_contents($recipe), $m)) {
            continue;
        }
        foreach ($m[1] as $config) {
            $pattern = (string)preg_replace('/\$\{?[A-Z_]+\}?/', '*', $config);
            foreach (glob($root . '/' . ltrim($pattern, '/')) ?: [] as $file) {
                $manifests[] = substr($file, strlen($root) + 1);
            }
        }
    }
    $manifests = array_values(array_unique($manifests));
    sort($manifests);

    return $manifests;
}

/**
 * @return list<string> the files a manifest imports, relative to the project root
 */
function cdm_sources(string $root, string $manifest): array
{
    $sources = [];
    if (!preg_match_all('/^\s*-?\s*source:\s*[\'"]?([^\s\'"#]+)/m', (string)@file_get_contents($root . '/' . $manifest), $m)) {
        return [];
    }
    foreach ($m[1] as $source) {
        $source = ltrim((string)preg_replace('#^\./#', '', $source), '/');
        $sources[] = is_file($root . '/' . $source) || !is_file($root . '/' . dirname($manifest) . '/' . $source)
            ? $source
            : dirname($manifest) . '/' . $source;
    }

    return $sources;
}

/**
 * @return list<string>
 */
function cdm_changed_files(string $root): array
{
    $files = [];
    foreach ([['diff', '--name-only', 'HEAD', '--', 'data/import'], ['ls-files', '--others', '--exclude-standard', '--', 'data/import']] as $args) {
        $command = 'git -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
        foreach (explode("\n", (string)shell_exec($command)) as $file) {
            if (str_ends_with($file, '.csv') && is_file($root . '/' . $file)) {
                $files[] = $file;
            }
        }
    }

    return array_values(array_unique($files));
}

/**
 * @return array<string, list<string>> data file => manifests that import it (empty: none)
 */
function cdm_evaluate(string $root, array $files): array
{
    $byFile = array_fill_keys($files, []);
    foreach (cdm_manifests($root) as $manifest) {
        $sources = cdm_sources($root, $manifest);
        foreach ($files as $file) {
            if (in_array($file, $sources, true)) {
                $byFile[$file][] = $manifest;
            }
        }
    }

    return $byFile;
}

function cdm_main(array $argv): int
{
    $root = fi_project_root();
    $files = array_slice($argv, 1);
    if (array_filter($files, static fn (string $f): bool => str_starts_with($f, '-')) !== []) {
        fwrite(STDERR, "usage: php check-data-manifests.php [<data file>...]\n");

        return 2;
    }
    $manifests = cdm_manifests($root);
    if ($manifests === []) {
        fwrite(STDERR, "No import manifest found: no recipe in config/install runs data:import --config=<manifest>.\n");

        return 2;
    }
    $files = $files !== [] ? $files : cdm_changed_files($root);
    $byFile = cdm_evaluate($root, $files);
    $orphans = array_keys(array_filter($byFile, static fn (array $m): bool => $m === []));

    fwrite(STDOUT, sprintf("%d data file(s) checked against %d manifest(s) the recipes run.\n", count($files), count($manifests)));
    foreach ($byFile as $file => $importedBy) {
        fwrite(STDOUT, sprintf("  %-4s %s%s\n", $importedBy === [] ? 'NONE' : 'ok', $file, $importedBy === [] ? '   imported by no manifest a recipe runs' : '   ' . implode(', ', $importedBy)));
    }

    return $orphans === [] ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(cdm_main($argv));
}
