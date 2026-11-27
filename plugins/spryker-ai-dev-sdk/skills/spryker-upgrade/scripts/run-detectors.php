<?php

/**
 * Runs the detectors of one phase in the order of the skill text and prints the summary of each:
 * one verdict line, at most --limit findings, the report path.
 *
 * Usage (from the project root):
 *   php run-detectors.php phase0 [--limit N] [--force]
 *   php run-detectors.php phase3 [--limit N] [--typed-members-dir <dir>]...
 *
 * phase0: check-platform-alignment, check-dead-overrides snapshot, twig-shadow-map snapshot,
 *         check-plugin-usage --baseline, check-config-constants --baseline,
 *         check-typed-members --baseline, check-constraint-style,
 *         check-vendor-class-replacement, check-constant-overrides --snapshot.
 *         Phase 0.5 runs check-test-coverage. --force goes to the --baseline detectors: they
 *         record their Phase 0 result again also when composer.lock differs from composer.lock.before.
 * phase3: check-typed-members (first: its fatals abort the console), list-major-bumps,
 *         check-dead-overrides verify, twig-shadow-map diff, check-plugin-usage,
 *         check-config-constants, check-constant-overrides, check-performance.
 *
 * --typed-members-dir adds a directory to the check-typed-members scan (default src/Pyz), for
 * example a composer-merge-plugin bundle tree. Each detector stays callable alone.
 *
 * Each detector must print its verdict line (`<name>...: OK|FOUND`); exit 0 or 1 without one, or
 * another exit code, is DID NOT RUN. The first line a detector writes to stderr is printed under its
 * output.
 *
 * Exit codes: 0 no detector found anything; 1 a detector exited 1 (findings, also when Phase 0 had
 * them all); 2 a detector did not run, or usage error.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const RD_USAGE = "Usage: php run-detectors.php phase0|phase3 [--limit N] [--force] [--typed-members-dir <dir>]...\n";

/**
 * The detector calls of a phase: script and arguments, in order.
 *
 * @param list<string> $typedMembersDirs
 *
 * @return list<array{0: string, 1: list<string>}>
 */
function rd_plan(string $phase, array $typedMembersDirs, bool $force = false): array
{
    $typedDirs = $typedMembersDirs === [] ? [] : array_merge(['src/Pyz'], $typedMembersDirs);
    $baseline = $force ? ['--baseline', '--force'] : ['--baseline'];
    if ($phase === 'phase0') {
        return [
            ['check-platform-alignment.php', []],
            ['check-dead-overrides.php', ['snapshot']],
            ['twig-shadow-map.php', ['snapshot']],
            ['check-plugin-usage.php', $baseline],
            ['check-config-constants.php', $baseline],
            ['check-typed-members.php', array_merge($typedDirs, $baseline)],
            ['check-constraint-style.php', []],
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
 * Does the output hold the verdict line of the detector (`<name>[ mode (...)]: OK|FOUND`)?
 */
function rd_has_verdict(string $detector, string $stdout): bool
{
    return preg_match('/^' . preg_quote($detector, '/') . '\b[^\n]*?: (OK|FOUND)\b/m', $stdout) === 1;
}

/**
 * The first line that is not empty, or ''.
 */
function rd_first_line(string $text): string
{
    foreach (explode("\n", $text) as $line) {
        if (trim($line) !== '') {
            return trim($line);
        }
    }

    return '';
}

function rd_main(array $argv): int
{
    $options = spryker_upgrade_output_options($argv);
    $phase = null;
    $force = false;
    $typedMembersDirs = [];
    $args = array_slice($argv, 1);
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--typed-members-dir' && isset($args[$i + 1])) {
            $typedMembersDirs[] = $args[++$i];
        } elseif (str_starts_with($args[$i], '--typed-members-dir=')) {
            $typedMembersDirs[] = substr($args[$i], 20);
        } elseif ($args[$i] === '--force') {
            $force = true;
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
    foreach (rd_plan($phase, $typedMembersDirs, $force) as [$script, $scriptArgs]) {
        $detector = basename($script, '.php');
        [$code, $stdout, $stderr] = rd_run($script, array_merge($scriptArgs, ['--limit', (string)$options['limit']]), $root);
        $error = rd_first_line($stderr);
        if (($code !== 0 && $code !== 1) || !rd_has_verdict($detector, $stdout)) {
            $reason = $error !== '' ? $error : rd_first_line($stdout);
            if ($reason === '' && ($code === 0 || $code === 1)) {
                $reason = 'no verdict line';
            }
            fwrite(STDOUT, sprintf("%s: DID NOT RUN (exit %d)%s\n", $detector, $code, $reason === '' ? '' : ': ' . $reason));
            $notRun[] = $detector;
            continue;
        }
        $found = $found || $code === 1;
        fwrite(STDOUT, $stdout . ($error === '' ? '' : '  stderr: ' . $error . "\n"));
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
