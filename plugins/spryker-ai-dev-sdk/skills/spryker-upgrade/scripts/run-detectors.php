<?php

/**
 * Runs the detectors of one phase in the order of the skill text and prints the summary of each:
 * one verdict line, at most --limit findings, the report path.
 *
 * Usage (from the project root):
 *   php run-detectors.php phase0 [--limit N]
 *   php run-detectors.php phase3 [--limit N] [--typed-members-dir <dir>]...
 *
 * phase0: check-platform-alignment, check-dead-overrides snapshot, twig-shadow-map snapshot,
 *         check-plugin-usage --baseline, check-config-constants --baseline,
 *         check-typed-members --baseline, check-constraint-style, check-test-coverage,
 *         check-vendor-class-replacement, check-constant-overrides --snapshot.
 * phase3: check-typed-members (first: its fatals abort the console), list-major-bumps,
 *         check-dead-overrides verify, twig-shadow-map diff, check-plugin-usage,
 *         check-config-constants, check-constant-overrides, check-performance.
 *
 * --typed-members-dir adds a directory to the check-typed-members scan (default src/Pyz), for
 * example a composer-merge-plugin bundle tree. Each detector stays callable alone.
 *
 * check-test-coverage.php prints its own output; the runner shows its first --limit lines.
 *
 * Exit codes: 0 no detector found anything; 1 a detector exited 1 (findings, also when Phase 0 had
 * them all); 2 a detector did not run (another exit code), or usage error.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const RD_USAGE = "Usage: php run-detectors.php phase0|phase3 [--limit N] [--typed-members-dir <dir>]...\n";

/**
 * The detector calls of a phase: script and arguments, in order.
 *
 * @param list<string> $typedMembersDirs
 *
 * @return list<array{0: string, 1: list<string>}>
 */
function rd_plan(string $phase, array $typedMembersDirs): array
{
    $typedDirs = $typedMembersDirs === [] ? [] : array_merge(['src/Pyz'], $typedMembersDirs);
    if ($phase === 'phase0') {
        return [
            ['check-platform-alignment.php', []],
            ['check-dead-overrides.php', ['snapshot']],
            ['twig-shadow-map.php', ['snapshot']],
            ['check-plugin-usage.php', ['--baseline']],
            ['check-config-constants.php', ['--baseline']],
            ['check-typed-members.php', array_merge($typedDirs, ['--baseline'])],
            ['check-constraint-style.php', []],
            ['check-test-coverage.php', []],
            ['check-vendor-class-replacement.php', []],
            ['check-constant-overrides.php', ['--snapshot']],
        ];
    }

    return [
        ['check-typed-members.php', $typedDirs === [] ? ['src/Pyz'] : $typedDirs],
        ['list-major-bumps.php', []],
        ['check-dead-overrides.php', ['verify']],
        ['twig-shadow-map.php', ['diff']],
        ['check-plugin-usage.php', []],
        ['check-config-constants.php', []],
        ['check-constant-overrides.php', []],
        ['check-performance.php', []],
    ];
}

/**
 * Run one detector as its own process, so one that fails does not stop the others.
 *
 * @param list<string> $args
 *
 * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
 */
function rd_run(string $script, array $args, string $root): array
{
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/' . $script], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        return [127, '', 'could not start ' . $script];
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * The first $limit lines of an output, then how many more there are.
 */
function rd_head(string $output, int $limit): string
{
    $lines = explode("\n", rtrim($output, "\n"));
    $head = implode("\n", array_slice($lines, 0, max(1, $limit)));

    return $head . "\n" . (count($lines) > max(1, $limit) ? sprintf("  (%d more lines: run the script alone)\n", count($lines) - max(1, $limit)) : '');
}

function rd_main(array $argv): int
{
    $options = spryker_upgrade_output_options($argv);
    $phase = null;
    $typedMembersDirs = [];
    $args = array_slice($argv, 1);
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--typed-members-dir' && isset($args[$i + 1])) {
            $typedMembersDirs[] = $args[++$i];
        } elseif (str_starts_with($args[$i], '--typed-members-dir=')) {
            $typedMembersDirs[] = substr($args[$i], 20);
        } elseif ($phase === null && in_array($args[$i], ['phase0', 'phase3'], true)) {
            $phase = $args[$i];
        } else {
            fwrite(STDERR, "Unknown argument: {$args[$i]}\n" . RD_USAGE);

            return 2;
        }
    }
    if ($phase === null) {
        fwrite(STDERR, RD_USAGE);

        return 2;
    }
    $root = spryker_upgrade_project_root();

    $found = false;
    $notRun = [];
    foreach (rd_plan($phase, $typedMembersDirs) as [$script, $scriptArgs]) {
        $summarised = $script !== 'check-test-coverage.php';
        [$code, $stdout, $stderr] = rd_run($script, $summarised ? array_merge($scriptArgs, ['--limit', (string)$options['limit']]) : $scriptArgs, $root);
        if ($code !== 0 && $code !== 1) {
            $reason = trim(strtok(trim($stderr) !== '' ? trim($stderr) : trim($stdout), "\n") ?: '');
            fwrite(STDOUT, sprintf("%s: DID NOT RUN (exit %d)%s\n", basename($script, '.php'), $code, $reason === '' ? '' : ': ' . $reason));
            $notRun[] = basename($script, '.php');
            continue;
        }
        $found = $found || $code === 1;
        fwrite(STDOUT, $summarised ? $stdout : rd_head($stdout, $options['limit'] + 1));
    }
    if ($notRun !== []) {
        fwrite(STDOUT, sprintf("Did not run: %s. Run each alone to see the full error.\n", implode(', ', $notRun)));

        return 2;
    }

    return $found ? 1 : 0;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(rd_main($argv));
}
