<?php

/**
 * Reference change finder: the pull request that brought a feature into the reference demo shop,
 * and the files it changed that no guide row names.
 *
 * A guide names the wiring and the config, but the reference change also carries templates,
 * DependencyProviders of neighbouring modules, OMS processes for its own payment methods, and data
 * files. Those files reach the checklist only through the reference change. The finder needs no
 * title search: the first commit that added the feature package to composer.json leads to the
 * merge that brought it into the branch.
 *
 * Usage:
 *   php find-feature-change.php --repo <reference clone with history> --package <spryker-feature/x>
 *       [--branch master] [--checklist <checklist.json> --add-rows]
 *
 *   --repo       a full clone (a bare clone is enough): git clone --bare https://github.com/spryker-shop/b2b-demo-marketplace.git
 *   --add-rows   adds a `reference-only` row for each changed file that no checklist row names
 *
 * Exit codes: 0 change found; 1 the branch never required the package, or no merge was found;
 * 2 usage or git error.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/parse-integration-guide.php';

/** Files a reference change carries that are never a step for the project. */
const FFC_NOT_A_STEP = '#^(tests/|src/Orm/|src/Generated/|composer\.(json|lock)$|package-lock\.json$|\.github/|codeception|phpstan|architecture-baseline)#';

/**
 * @return array{0: int, 1: string}
 */
function ffc_git(string $repo, array $args): array
{
    $command = 'git -C ' . escapeshellarg($repo);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $output = [];
    exec($command . ' 2>&1', $output, $exit);

    return [$exit, implode("\n", $output)];
}

/**
 * @return array{pr: ?int, merge: string, subject: string, via: string}|null
 */
function ffc_find_merge(string $repo, string $first, string $branch): ?array
{
    [, $firstParent] = ffc_git($repo, ['rev-list', '--first-parent', $branch]);
    $onBranch = array_flip(array_filter(explode("\n", $firstParent)));
    $subjectOf = static fn (string $sha): string => ffc_git($repo, ['log', '-1', '--format=%s', $sha])[1];

    if (isset($onBranch[$first])) {
        $subject = $subjectOf($first);

        return ['pr' => preg_match('/\(#(\d+)\)\s*$/', $subject, $m) ? (int)$m[1] : null, 'merge' => $first, 'subject' => $subject, 'via' => 'commit on the branch'];
    }
    [, $merges] = ffc_git($repo, ['rev-list', '--ancestry-path', '--merges', '--reverse', $first . '..' . $branch]);
    $fallback = null;
    foreach (array_filter(explode("\n", $merges)) as $sha) {
        $subject = $subjectOf($sha);
        if (!preg_match('/^Merge pull request #(\d+)/', $subject, $m)) {
            continue;
        }
        if (isset($onBranch[$sha])) {
            return ['pr' => (int)$m[1], 'merge' => $sha, 'subject' => $subject, 'via' => 'merge into ' . $branch];
        }
        $fallback ??= ['pr' => (int)$m[1], 'merge' => $sha, 'subject' => $subject, 'via' => 'first pull request after the commit; it merged into another branch'];
    }

    return $fallback;
}

/**
 * @return list<string>
 */
function ffc_changed_files(string $repo, array $merge): array
{
    $parents = array_filter(explode(' ', ffc_git($repo, ['log', '-1', '--format=%P', $merge['merge']])[1]));
    $base = count($parents) > 0 ? $parents[0] : $merge['merge'] . '^';
    [, $files] = ffc_git($repo, ['diff', '--name-only', $base, $merge['merge']]);

    return array_values(array_filter(explode("\n", $files)));
}

/**
 * @return array{added: list<string>, covered: list<string>, not_a_step: list<string>}
 */
function ffc_add_rows(array &$checklist, array $files, array $merge): array
{
    $named = [];
    foreach ($checklist['rows'] as $row) {
        $named[(string)($row['target'] ?? '')] = true;
    }
    $result = ['added' => [], 'covered' => [], 'not_a_step' => []];
    $next = count($checklist['rows']) + 1;
    foreach ($files as $file) {
        if (preg_match(FFC_NOT_A_STEP, $file)) {
            $result['not_a_step'][] = $file;
            continue;
        }
        if (isset($named[$file])) {
            $result['covered'][] = $file;
            continue;
        }
        $kind = pig_classify_path($file, '');
        $row = [
            'id' => sprintf('R%03d', $next++),
            'guide' => null,
            'section' => 'reference change' . ($merge['pr'] !== null ? ' #' . $merge['pr'] : ''),
            'lines' => [0, 0],
            'kind' => $kind,
            'target' => $file,
            'tag' => 'required',
            'source' => 'reference-only',
            'reference' => 'changed: ' . substr($merge['merge'], 0, 10),
            'decision' => null,
            'status' => 'open',
        ];
        if (in_array($kind, FI_DATA_KINDS, true)) {
            $row['data_class'] = null;
        }
        $checklist['rows'][] = $row;
        $result['added'][] = $file;
    }

    return $result;
}

function ffc_main(array $argv): int
{
    $options = ['repo' => null, 'package' => null, 'branch' => 'master', 'checklist' => null, 'add-rows' => false];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--add-rows') {
            $options['add-rows'] = true;
        } elseif (in_array($arg, ['--repo', '--package', '--branch', '--checklist'], true)) {
            $options[substr($arg, 2)] = $argv[++$i] ?? null;
        } else {
            fwrite(STDERR, "unknown argument $arg\n");

            return 2;
        }
    }
    if ($options['repo'] === null || $options['package'] === null || ($options['add-rows'] && $options['checklist'] === null)) {
        fwrite(STDERR, "usage: php find-feature-change.php --repo <reference clone> --package <spryker-feature/x> [--branch master] [--checklist <checklist.json> --add-rows]\n");

        return 2;
    }
    [$exit, $first] = ffc_git($options['repo'], ['log', '--reverse', '--format=%H', '-S"' . $options['package'] . '"', $options['branch'], '--', 'composer.json']);
    if ($exit !== 0) {
        fwrite(STDERR, "git failed: $first\n");

        return 2;
    }
    $first = strtok($first, "\n") ?: '';
    if ($first === '') {
        fwrite(STDOUT, "{$options['branch']} never required {$options['package']} in composer.json.\n");

        return 1;
    }
    $merge = ffc_find_merge($options['repo'], $first, $options['branch']);
    if ($merge === null) {
        fwrite(STDOUT, sprintf("First commit with %s: %s. No merge into %s found.\n", $options['package'], substr($first, 0, 10), $options['branch']));

        return 1;
    }
    $files = ffc_changed_files($options['repo'], $merge);
    fwrite(STDOUT, sprintf("%s: %s (%s), %d file(s) changed.\n", $options['package'], $merge['subject'], $merge['via'], count($files)));

    if ($options['add-rows']) {
        $checklist = fi_load_checklist((string)$options['checklist']);
        if ($checklist === null) {
            fwrite(STDERR, "{$options['checklist']} is not a readable checklist\n");

            return 2;
        }
        $result = ffc_add_rows($checklist, $files, $merge);
        $checklist['reference_change'] = ['package' => $options['package'], 'pr' => $merge['pr'], 'merge' => $merge['merge'], 'via' => $merge['via']];
        fi_save_checklist((string)$options['checklist'], $checklist);
        fwrite(STDOUT, sprintf("  %d named by a row already, %d not a step (tests, generated code, lock files), %d reference-only row(s) added.\n", count($result['covered']), count($result['not_a_step']), count($result['added'])));
        foreach ($result['added'] as $file) {
            fwrite(STDOUT, "  + $file\n");
        }
    } else {
        foreach ($files as $file) {
            fwrite(STDOUT, "  $file\n");
        }
    }

    return 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(ffc_main($argv));
}
