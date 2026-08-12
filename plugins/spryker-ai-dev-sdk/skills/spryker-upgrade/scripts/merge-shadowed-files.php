<?php

/**
 * Batch three-way merge for shadowed frontend/presentation files (Lane 2).
 *
 * twig-shadow-map.php reports every project file that shadows a vendor file the upgrade changed.
 * Each one needs the vendor change ported into the project override, and the merge base is the
 * pre-upgrade vendor copy captured in state/vendor-baseline/. That is a mechanical
 * `git merge-file` per file — this script runs them all and sorts the outcomes.
 *
 * Usage:
 *   php $UP/merge-shadowed-files.php --dry-run   # classify only, touch nothing
 *   php $UP/merge-shadowed-files.php --apply     # write the clean merges
 *
 * Outcomes:
 *   CLEAN      - merged without conflict markers; written when --apply
 *   CONFLICTED - needs a human; the project file is left untouched and the conflicted merge is
 *                written next to it as <file>.merge-conflict for review
 *   IDENTICAL  - project override matched the old vendor file exactly, so it was a pointless
 *                copy: the vendor version is adopted wholesale (still reported, since the
 *                override itself is now a candidate for deletion)
 *   NO_BASE    - no baseline copy (snapshot missing this file); skipped
 *
 * Never touches VENDOR_FILE_REMOVED entries — a vanished vendor template is a semantic decision,
 * not a merge.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = spryker_upgrade_project_root();
$stateDir = spryker_upgrade_state_dir($root);
$reportFile = $stateDir . '/twig-conflicts-report.json';
$baselineDir = $stateDir . '/vendor-baseline';
$outFile = $stateDir . '/merge-results.json';

$apply = in_array('--apply', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);
if ($apply === $dryRun) {
    fwrite(STDERR, "Pass exactly one of --dry-run or --apply.\n");
    exit(2);
}

if (!is_file($reportFile)) {
    fwrite(STDERR, "No conflict report — run twig-shadow-map.php diff first.\n");
    exit(2);
}

$report = json_decode((string)file_get_contents($reportFile), true);
$results = ['clean' => [], 'conflicted' => [], 'identical' => [], 'noBase' => [], 'removed' => []];

foreach ($report['conflicts'] ?? [] as $conflict) {
    $project = $conflict['project'];
    $vendor = $conflict['vendor'];

    if (($conflict['type'] ?? '') === 'VENDOR_FILE_REMOVED') {
        $results['removed'][] = $project;

        continue;
    }

    $base = $baselineDir . '/' . $vendor;
    $projectAbs = $root . '/' . $project;
    $vendorAbs = $root . '/' . $vendor;

    if (!is_file($base) || !is_file($projectAbs) || !is_file($vendorAbs)) {
        $results['noBase'][] = $project;

        continue;
    }

    // A project override byte-identical to the OLD vendor file carried no customisation at all.
    if (md5_file($projectAbs) === md5_file($base)) {
        if ($apply) {
            copy($vendorAbs, $projectAbs);
        }
        $results['identical'][] = $project;

        continue;
    }

    $cmd = sprintf(
        'git merge-file -p -L project -L vendor-before -L vendor-after %s %s %s 2>/dev/null',
        escapeshellarg($projectAbs),
        escapeshellarg($base),
        escapeshellarg($vendorAbs)
    );
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $merged = implode("\n", $output) . "\n";

    // git merge-file exits >0 with the number of conflicts; also guard on markers directly.
    $hasConflict = $exitCode !== 0 || str_contains($merged, '<<<<<<<');

    if ($hasConflict) {
        if ($apply) {
            file_put_contents($projectAbs . '.merge-conflict', $merged);
        }
        $results['conflicted'][] = $project;

        continue;
    }

    if ($apply) {
        file_put_contents($projectAbs, $merged);
    }
    $results['clean'][] = $project;
}

file_put_contents($outFile, json_encode([
    'createdAt' => date('c'),
    'applied' => $apply,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf(
    "%s\n  CLEAN      %d\n  IDENTICAL  %d (override carried no customisation — candidates for deletion)\n"
    . "  CONFLICTED %d (need manual resolution)\n  NO_BASE    %d\n  REMOVED    %d (vendor template gone — semantic decision)\n",
    $apply ? 'Applied merges:' : 'Dry run (nothing written):',
    count($results['clean']),
    count($results['identical']),
    count($results['conflicted']),
    count($results['noBase']),
    count($results['removed'])
);

if ($results['conflicted'] !== []) {
    echo "\nConflicted files:\n";
    foreach ($results['conflicted'] as $file) {
        echo "  $file\n";
    }
    if ($apply) {
        echo "\nEach conflicted merge was written to <file>.merge-conflict; the project file is untouched.\n";
    }
}

printf("\nFull results: %s\n", str_replace($root . '/', '', $outFile));
exit($results['conflicted'] === [] ? 0 : 1);
