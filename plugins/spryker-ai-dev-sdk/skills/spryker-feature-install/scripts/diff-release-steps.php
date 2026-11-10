<?php

/**
 * Release-step candidates: what the checklist ran locally that the deploy recipes do not run.
 *
 * A guide is written for a local install: it runs imports, installers and republishes by hand. An
 * existing environment gets only what its deploy recipe runs. Every candidate this script lists is
 * either already covered by a recipe, or a release step to prove (references/release-steps.md).
 *
 * Usage:
 *   php diff-release-steps.php <checklist.json> [--recipe <file>[:<section>,<section>]]...
 *
 *   --recipe   an install recipe and, optionally, the sections of it that run on a deploy.
 *              Default: config/install/production.yml (all sections) and
 *              config/install/docker.yml:build,build-static.
 *
 * Candidates: console rows; imports of feature-data and project-content rows; a republish per new
 * publisher plugin; queue:setup for new queues; new environment variables; propel:migrate for
 * schema rows. Rows whose decision is skip:<reason> or already-present are not candidates.
 *
 * Exit codes: 0 every candidate is covered by a recipe; 1 candidates to prove (listed); 2 usage.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/**
 * Commands that only prepare a local stack (code generation is part of the image build, which the
 * recipes' build sections cover) or only read state.
 */
const DRS_LOCAL_ONLY = ['frontend:yves:build', 'frontend:zed:build', 'frontend:mp:build', 'frontend:project:install-dependencies', 'cache:empty-all', 'dev:ide-auto-completion:generate'];

/** A local command and the command a deploy runs for the same effect. */
const DRS_DEPLOY_EQUIVALENT = ['propel:install' => 'propel:migrate'];

/**
 * @return list<string> command signatures a recipe runs, from `command:` lines of the chosen sections
 */
function drs_recipe_commands(string $file, ?array $sections): array
{
    if (!is_file($file)) {
        return [];
    }
    $commands = [];
    $current = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^    ([A-Za-z0-9_\-]+):\s*$/', $line, $m)) {
            $current = $m[1];
            continue;
        }
        if (preg_match("/^\s+command:\s*['\"]?(.+?)['\"]?\s*$/", $line, $m) && ($sections === null || in_array($current, $sections, true))) {
            $commands[] = fi_command_signature($m[1]);
        }
    }

    return $commands;
}

function drs_covered(string $signature, array $recipeCommands): bool
{
    foreach ($recipeCommands as $recipeCommand) {
        if ($recipeCommand === $signature || str_starts_with($signature, $recipeCommand . ' ') || fi_command_name($recipeCommand) === $signature) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<array{step: string, why: string, rows: list<string>}>
 */
function drs_candidates(array $checklist): array
{
    $candidates = [];
    $add = static function (string $step, string $why, string $rowId) use (&$candidates): void {
        $step = DRS_DEPLOY_EQUIVALENT[$step] ?? $step;
        $candidates[$step] ??= ['step' => $step, 'why' => $why, 'rows' => []];
        if (!in_array($rowId, $candidates[$step]['rows'], true)) {
            $candidates[$step]['rows'][] = $rowId;
        }
    };
    foreach ($checklist['rows'] as $row) {
        $decision = $row['decision'] ?? null;
        if (fi_is_skip($decision) || $decision === 'already-present') {
            continue;
        }
        $id = (string)$row['id'];
        $kind = $row['kind'] ?? '';
        $target = (string)($row['target'] ?? '');
        if ($kind === 'console') {
            $signature = fi_command_signature($target);
            if (!in_array(fi_command_name($target), DRS_LOCAL_ONLY, true)) {
                $add($signature, 'the guide runs it', $id);
            }
        }
        if (in_array($kind, FI_DATA_KINDS, true) && in_array($row['data_class'] ?? null, ['feature-data', 'project-content'], true)) {
            $add($kind === 'glossary' ? 'data:import glossary' : 'data:import <entity of ' . basename($target) . '>', ($row['data_class']) . ' rows the feature needs', $id);
        }
        if (in_array($kind, ['schema', 'event'], true)) {
            $add('propel:migrate', 'schema change', $id);
        }
        if ($kind === 'queue') {
            $add('queue:setup', 'new queue', $id);
        }
        foreach ($row['plugins'] ?? [] as $plugin) {
            if (str_contains($plugin, '\\Plugin\\Publisher\\')) {
                $add('publish:trigger-events -r <resource of ' . fi_short_name($plugin) . '>', 'new publisher: existing rows are not published', $id);
            }
        }
        foreach ($row['env'] ?? [] as $variable) {
            $add('env ' . $variable, 'environment variable the code reads', $id);
        }
    }

    return array_values($candidates);
}

function drs_main(array $argv): int
{
    $path = null;
    $recipes = [];
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--recipe') {
            $recipes[] = (string)($argv[++$i] ?? '');
        } else {
            $path = $argv[$i];
        }
    }
    $checklist = $path !== null ? fi_load_checklist($path) : null;
    if ($checklist === null) {
        fwrite(STDERR, "usage: php diff-release-steps.php <checklist.json> [--recipe <file>[:<section>,<section>]]...\n");

        return 2;
    }
    $root = fi_project_root();
    if ($recipes === []) {
        $recipes = ['config/install/production.yml', 'config/install/docker.yml:build,build-static'];
    }
    $recipeCommands = [];
    foreach ($recipes as $recipe) {
        [$file, $sectionList] = array_pad(explode(':', $recipe, 2), 2, null);
        $file = str_starts_with((string)$file, '/') ? (string)$file : $root . '/' . $file;
        if (!is_file($file)) {
            fwrite(STDOUT, "note: recipe $recipe not found — its commands count as not covered\n");
        }
        array_push($recipeCommands, ...drs_recipe_commands($file, $sectionList !== null ? explode(',', $sectionList) : null));
    }

    $open = 0;
    fwrite(STDOUT, sprintf("| %-60s | %-9s | %-45s | %s |\n", 'step', 'recipe', 'why', 'rows'));
    fwrite(STDOUT, '|' . str_repeat('-', 62) . '|' . str_repeat('-', 11) . '|' . str_repeat('-', 47) . "|------|\n");
    foreach (drs_candidates($checklist) as $candidate) {
        $isEnv = str_starts_with($candidate['step'], 'env ');
        $covered = !$isEnv && drs_covered($candidate['step'], $recipeCommands);
        $open += $covered ? 0 : 1;
        fwrite(STDOUT, sprintf("| %-60s | %-9s | %-45s | %s |\n", $candidate['step'], $covered ? 'covered' : ($isEnv ? 'set it' : 'to prove'), $candidate['why'], implode(' ', $candidate['rows'])));
    }
    fwrite(STDOUT, $open === 0 ? "\nOK: every candidate is covered by a recipe.\n" : "\n$open candidate(s) not covered: add each to the release-steps log and prove it.\n");

    return $open === 0 ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(drs_main($argv));
}
