<?php

declare(strict_types=1);

/** Zero-dependency test for the plugin's Stop hook. */

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

$hooksDir = __DIR__;
$pluginRoot = dirname(__DIR__);
$php = PHP_BINARY;
require_once $hooksDir . '/lib.php';

/** Run a hook script with a payload; returns [decision|null, reason, stderr, exit]. */
function run_hook(string $script, array $payload): array
{
    global $pluginRoot, $hooksDir, $php;
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$php, $hooksDir . '/' . $script], $desc, $pipes, null, ['CLAUDE_PLUGIN_ROOT' => $pluginRoot, 'PATH' => getenv('PATH')]);
    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    $json = json_decode((string) $out, true);
    $decision = is_array($json) ? ($json['hookSpecificOutput']['permissionDecision'] ?? ($json['hookSpecificOutput']['decision'] ?? null)) : null;
    $reason = is_array($json) ? (string) ($json['hookSpecificOutput']['permissionDecisionReason'] ?? ($json['hookSpecificOutput']['reason'] ?? '')) : '';

    return [$decision, $reason, (string) $err, $exit];
}

$proj = sys_get_temp_dir() . '/hooks_' . getmypid();
@mkdir($proj . '/.ai-dev', 0777, true);

// --- guard-stop: an autonomous run does not end its turn with work outstanding ---
$stopPayload = static fn (string $msg, array $extra = []): array => array_merge(['hook_event_name' => 'Stop', 'cwd' => $proj, 'last_assistant_message' => $msg], $extra);
$stopDecision = static function (array $payload) use ($proj): array {
    [$d, $r, , ] = run_hook('guard-stop.php', $payload);
    return [$d, $r];
};
@unlink($proj . '/.ai-dev/stop-blocks.json');
@unlink($proj . '/.ai-dev/demo-prep.md');
@unlink($proj . '/.ai-dev/hooks.log');
$savedState = "---\nrun_mode: autonomous\nanswers_confirmed_at: 2026-09-21T10:00:00Z\n---\n\n| # | step | status | notes |\n|---|---|---|---|\n| 2 | define-stores | in-progress | |\n| 6 | project-data | pending | |\n";
@unlink($proj . '/.ai-dev/project-setup.md');

[$d] = run_hook('guard-stop.php', $stopPayload('all done'));
check('stop guard: no autonomous state file → no opinion', $d === null);

file_put_contents($proj . '/.ai-dev/demo-prep.md', "---\nrun_mode: collaborative\n---\n\n| # | phase | status | notes |\n|---|---|---|---|\n| 6 | build | in-progress | x |\n");
[$d] = run_hook('guard-stop.php', $stopPayload('Step 6 is in progress, not done.'));
check('stop guard: collaborative run → no opinion', $d === null);

$auto = "---\nrun_mode: autonomous\nanswers_confirmed_at: 2026-09-22T10:45:31Z\n---\n\n| # | phase | status | notes |\n|---|---|---|---|\n| 5 | harvest | done | ok |\n| 6 | build | in-progress | catalogue written |\n| 7 | rehearsal | pending | |\n";
file_put_contents($proj . '/.ai-dev/demo-prep.md', $auto);
[$d, $r] = $stopDecision($stopPayload('Step 6 is **in progress, not done** — real progress, and a precise resume point.'));
check('stop guard: autonomous run stopping mid-work → block, names the open steps', $d === 'block' && str_contains($r, 'build') && str_contains($r, 'rehearsal'));
[, $rr, $ee, $xx] = run_hook('guard-stop.php', $stopPayload('Step 6 is in progress, not done — resuming later.'));
check('stop guard: a block EXITS 2 (exit 0 is silently ignored by the harness) and puts the reason on stderr', $xx === 2 && str_contains($ee, 'autonomous'));
check('stop guard: block is logged', str_contains((string) @file_get_contents($proj . '/.ai-dev/hooks.log'), 'guard-stop.php block'));

@unlink($proj . '/.ai-dev/stop-blocks.json');
@unlink($proj . '/.ai-dev/hooks.log');
[$d] = run_hook('guard-stop.php', $stopPayload('Two product lines have no colour data. Should I drop them or keep them single-colour?'));
check('stop guard: the turn ends on a question → allowed', $d === null);
[$d] = run_hook('guard-stop.php', $stopPayload("⚠ NEEDS YOU: approve the reset prompt and I'll carry on."));
check('stop guard: an explicit wait-on-developer marker → allowed', $d === null);

file_put_contents($proj . '/.ai-dev/demo-prep.md', "---\nrun_mode: autonomous\n---\n\n| # | phase | status |\n|---|---|---|\n| 6 | build | done |\n| 7 | rehearsal | skipped |\n");
[$d] = run_hook('guard-stop.php', $stopPayload('Everything is finished.'));
check('stop guard: every step done or skipped → allowed', $d === null);

file_put_contents($proj . '/.ai-dev/demo-prep.md', $auto);
@unlink($proj . '/.ai-dev/stop-blocks.json');
$seq = [];
foreach (['first stop.', 'second stop.', 'third stop.', 'fourth stop.'] as $msg) {
    [$d] = run_hook('guard-stop.php', $stopPayload($msg));
    $seq[] = $d ?? 'allow';
}
check('stop guard: blocks at most three times in a row, then lets go', $seq === ['block', 'block', 'block', 'allow'], implode(',', $seq));
@unlink($proj . '/.ai-dev/stop-blocks.json');
[$d] = run_hook('guard-stop.php', $stopPayload('same closing message.'));
[$d2] = run_hook('guard-stop.php', $stopPayload('same closing message.'));
check('stop guard: the model repeating its closing message → let go immediately', $d === 'block' && $d2 === null);
@unlink($proj . '/.ai-dev/demo-prep.md');
@unlink($proj . '/.ai-dev/stop-blocks.json');

file_put_contents($proj . '/.ai-dev/project-setup.md', $savedState);
[$d, $r] = run_hook('guard-stop.php', $stopPayload('Namespace resolved; moving on.'));
check('stop guard: the wizard state file drives it too, not only demo-prep', $d === 'block' && str_contains($r, 'define-stores'));
@unlink($proj . '/.ai-dev/stop-blocks.json');


// --- a denied sub-agent tool call reported as a cancellation --------------------------
check('cancel-claim: "you keep stopping" is a misread of a denial', hook_claims_user_cancelled_work("I'm not going to keep re-dispatching work you keep stopping."));
check('cancel-claim: "you have killed it twice" is a misread', hook_claims_user_cancelled_work("You've now killed a translator agent twice (chunk 02 just now)."));
check('cancel-claim: an ordinary report is not', !hook_claims_user_cancelled_work('Both fixed and verified. Moving to step 9.'));
file_put_contents($proj . '/.ai-dev/project-setup.md', $savedState);
@unlink($proj . '/.ai-dev/stop-blocks.json');
@unlink($proj . '/.ai-dev/hooks.log');
[$d, $r] = run_hook('guard-stop.php', $stopPayload("You've now killed a translator agent twice, so I'm not re-dispatching."));
check('stop guard: claiming the user cancelled the work → block with the correction', $d === 'block' && str_contains($r, 'withdrew the instruction'));
@unlink($proj . '/.ai-dev/stop-blocks.json');

// --- ending the turn on a permission or allowlist limit ------------------------------
check('permission-stop: a real hand-back on the allowlist fires',
    hook_stops_on_permissions('`docker restart` is outside your allowlist, so I am stopping here.'));
check('permission-stop: EXPLAINING the allowlist when asked is an answer, not a stop',
    !hook_stops_on_permissions("The prompts you've been getting come from commands outside your allowlist. Your `.claude/settings.json` allows only php, curl and read-only git. Everything else asks."));
check('permission-stop: having already reported the blocked commands is not blocked',
    !hook_stops_on_permissions('Report written to `.ai-dev/verification-report.md`. Worked entirely within the allowlist and recorded the rest rather than stopping.'));
check('permission-stop: delegating a login-gated check is not a permission stop',
    !hook_stops_on_permissions("Both jobs running. Verifier — the login-gated criteria I can't do myself, since I do not type credentials."));
check('permission-stop: "not allowlisted" + blocked', hook_stops_on_permissions('`rm -rf` is not allowlisted, so this step is blocked.'));
check('permission-stop: merely explaining the allowlist is not stopping on it',
    !hook_stops_on_permissions('Added the allowlist entries; continuing with step 9.'));
check('permission-stop: an ordinary report is untouched', !hook_stops_on_permissions('Both fixed and verified. Moving on.'));
@unlink($proj . '/.ai-dev/stop-blocks.json');
@unlink($proj . '/.ai-dev/hooks.log');
[$d, $r] = run_hook('guard-stop.php', $stopPayload('I cannot run that — it is outside your allowlist, so I am stopping here.'));
check('stop guard: ending a turn on a permission limit → block, told to report it instead',
    $d === 'block' && str_contains($r, 'Record a blocked command in the report'));
@unlink($proj . '/.ai-dev/stop-blocks.json');
@unlink($proj . '/.ai-dev/hooks.log');

// --- the block names the NEXT step and escalates when nothing moves -----------------
@unlink($proj . '/.ai-dev/hooks.log');
[$d, $r] = run_hook('guard-stop.php', $stopPayload('Progress report one.'));
check('stop guard: the block names the single next step, not a list dump', $d === 'block' && str_contains($r, 'the next unfinished step is'));
[$d, $r2] = run_hook('guard-stop.php', $stopPayload('Progress report two.'));
check('stop guard: a second block on the SAME open steps escalates', $d === 'block' && str_contains($r2, 'block #2 on the same set of open steps'));
check('stop guard: the escalation names the two options', str_contains($r2, 'out of date') && str_contains($r2, 'blocker'));
@unlink($proj . '/.ai-dev/stop-blocks.json');

[$d, , , $exit] = run_hook('guard-stop.php', []);
check('stop guard: empty payload → exit 0, no decision', $d === null && $exit === 0);


$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($proj, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $entry) {
    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($proj);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
