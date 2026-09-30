<?php

declare(strict_types=1);

/** Stop — an autonomous run does not end its turn while steps in its state file are still open. */

require __DIR__ . '/lib.php';

const STOP_MAX_BLOCKS = 3;
const STOP_STATE_FILES = ['.ai-dev/demo-prep.md', '.ai-dev/project-setup.md'];
const STOP_DONE_STATUSES = ['done', 'skipped', 'n/a', 'not needed'];

/** Emit the Stop-hook decision and exit. */
function stop_decide(string $decision, string $reason, string $cwd): never
{
    hook_trace($decision);
    if ($decision === 'block' && $cwd !== '' && is_dir($cwd . '/.ai-dev')) {
        @file_put_contents($cwd . '/.ai-dev/hooks.log', date('Y-m-d H:i:s') . ' guard-stop.php block — ' . mb_substr(strtok($reason, "\n") ?: $reason, 0, 200) . "\n", FILE_APPEND);
    }
    if ($decision !== 'block') {
        exit(0);
    }
    echo json_encode([
        'hookSpecificOutput' => [
            'hookEventName' => 'Stop',
            'decision' => 'block',
            'reason' => $reason,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    fwrite(STDERR, $reason . "\n");
    exit(2);
}

/** Record that the guard stopped blocking, so a silent hand-back is still diagnosable. */
function stop_give_up(string $cwd, string $why): void
{
    if ($cwd === '' || !is_dir($cwd . '/.ai-dev')) {
        return;
    }
    @file_put_contents($cwd . '/.ai-dev/hooks.log', date('Y-m-d H:i:s') . ' guard-stop.php gave-up — ' . $why . ", the turn is allowed to end with work still open\n", FILE_APPEND);
}

/**
 * Steps a state file still owes, as "<file>: <step>" strings.
 *
 * @return list<string>
 */
function stop_unfinished_steps(string $cwd): array
{
    $open = [];
    foreach (STOP_STATE_FILES as $rel) {
        $path = $cwd . '/' . $rel;
        if (!is_file($path)) {
            continue;
        }
        $body = (string) file_get_contents($path);
        if (preg_match('~^\s*run_mode\s*:\s*autonomous~mi', $body) !== 1) {
            continue;
        }
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (!str_starts_with(ltrim($line), '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, " \t|")));
            if (count($cells) < 2 || str_starts_with($cells[0], '---')) {
                continue;
            }
            $status = null;
            $stepIdx = null;
            foreach ($cells as $i => $cell) {
                $c = strtolower(strip_tags(preg_replace('~[*`]~', '', $cell) ?? $cell));
                if (preg_match('~^(done|skipped|n/a|not needed|pending|in-progress|in progress|blocked|todo)\b~', $c, $m) === 1) {
                    $status = $m[1];
                    $stepIdx = $i;
                    break;
                }
            }
            if ($status === null || in_array($status, STOP_DONE_STATUSES, true)) {
                continue;
            }
            $name = '';
            for ($i = ($stepIdx ?? 1) - 1; $i >= 0; $i--) {
                $candidate = trim(preg_replace('~[*`]~', '', $cells[$i]) ?? $cells[$i]);
                if ($candidate !== '' && !ctype_digit($candidate)) {
                    $name = $candidate;
                    break;
                }
            }
            if ($name !== '' && strtolower($name) !== 'step' && strtolower($name) !== 'phase') {
                $open[] = basename($rel) . ': ' . $name . ' (' . $status . ')';
            }
        }
    }

    return array_values(array_unique($open));
}

/** Why this stop may pass despite unfinished steps, or null when it is blocked. */
function stop_is_blocked(string $cwd, string $lastMessage, string $transcriptPath): ?string
{
    $tail = rtrim(strip_tags($lastMessage), " \t\n\"'”’)*_`");
    if ($tail !== '' && str_ends_with($tail, '?')) {
        $sentences = preg_split('/(?<=[.!?\n])\s+/', $lastMessage) ?: [];
        $question = '';
        foreach ($sentences as $sentence) {
            if (str_contains($sentence, '?')) {
                $question = $sentence;
            }
        }
        $hollow = preg_match('~\b(continue|proceed|carry on|go ahead|keep going|go on|move on|anything else|ready to start|shall i begin)\b~i', $question) === 1
            || preg_match('~^\W*(ok|okay|good|sound good|make sense|all good|right)\s*\?\W*$~i', trim($question)) === 1;
        if (!$hollow) {
            return 'the turn ends on a question only the person can answer';
        }
    }
    if (preg_match('~NEEDS YOU:|ACTION NEEDED|awaiting your|waiting for you|your call on~i', $lastMessage) === 1) {
        return 'the turn is explicitly waiting on the person';
    }
    $log = $cwd . '/.ai-dev/hooks.log';
    if (is_file($log) && time() - (int) filemtime($log) < 180) {
        $lines = preg_split('/\R/', trim((string) file_get_contents($log))) ?: [];
        $last = (string) end($lines);
        if (preg_match('~\bask\b~', $last) === 1 && !str_contains($last, 'guard-stop.php')) {
            return 'a guard asked within the last few minutes, so the person has a prompt in front of them';
        }
    }
    if ($transcriptPath !== '' && is_file($transcriptPath)) {
        $size = filesize($transcriptPath) ?: 0;
        $fh = fopen($transcriptPath, 'rb');
        if ($fh !== false) {
            fseek($fh, max(0, $size - 200000));
            $tailText = (string) stream_get_contents($fh);
            fclose($fh);
            $lastUser = strrpos($tailText, '"type":"user"');
            if ($lastUser !== false && str_contains(substr($tailText, $lastUser), 'AskUserQuestion')) {
                return 'the turn asked the person a question through AskUserQuestion';
            }
        }
    }

    return null;
}

try {
    $payload = hook_payload();
    $cwd = hook_cwd($payload);
    $lastMessage = (string) ($payload['last_assistant_message'] ?? '');
    $counterFile = $cwd . '/.ai-dev/stop-blocks.json';
    $counter = is_file($counterFile) ? (array) json_decode((string) file_get_contents($counterFile), true) : [];
    $reset = static function () use ($counterFile): void {
        @unlink($counterFile);
    };

    $open = stop_unfinished_steps($cwd);
    if ($open === []) {
        $reset();
        exit(0);
    }
    $claimsCancelled = hook_claims_user_cancelled_work($lastMessage);
    $blocked = $claimsCancelled ? null : stop_is_blocked($cwd, $lastMessage, (string) ($payload['transcript_path'] ?? ''));
    if ($blocked !== null) {
        $reset();
        exit(0);
    }
    $hash = sha1($lastMessage);
    $fingerprint = sha1(implode('|', $open));
    $count = (int) ($counter['count'] ?? 0);
    $sameWork = ($counter['fingerprint'] ?? '') === $fingerprint;

    if (($counter['hash'] ?? '') === $hash) {
        stop_give_up($cwd, 'the closing message repeated verbatim');
        $reset();
        exit(0);
    }
    if ($count >= STOP_MAX_BLOCKS) {
        stop_give_up($cwd, 'reached ' . STOP_MAX_BLOCKS . ' consecutive blocks' . ($sameWork ? ' with the steps table unchanged throughout' : ''));
        $reset();
        exit(0);
    }
    @file_put_contents($counterFile, json_encode(['count' => $count + 1, 'hash' => $hash, 'fingerprint' => $fingerprint, 'ts' => time()]));

    if ($claimsCancelled) {
        stop_decide('block', "Stop blocked: this turn reports that the person stopped or cancelled the work, and steps are still open. A sub-agent whose tool call is denied is reported as `Agent \"…\" was stopped by user`; that message does not mean the person withdrew the instruction.\n\nRe-dispatch the work with allowed tools (`csv.php`, the Write tool, `docker/sdk cli` instead of shell assembly), or record the blocked step in the report and continue with the rest. Next open step: {$open[0]}.", $cwd);
    }

    if (hook_stops_on_permissions($lastMessage)) {
        stop_decide('block', "Stop blocked: this turn ends on a permission or allowlist limit, and {$open[0]} is still open. Record a blocked command in the report and continue:\n  1. Finish every part of the task the allowed tools cover.\n  2. Write the rest to a report (`.ai-dev/verification-report.md` or the step's own report) as a table: what is needed, the exact command a person runs, and why it is blocked.\n  3. State in one line what is unverified, as distinct from what failed.\n\nIf the blocked command was in a sub-agent, first re-dispatch it with allowed tools (the SDK scripts and the Write tool instead of shell assembly).", $cwd);
    }

    $next = $open[0];
    $rest = count($open) - 1;
    $also = $rest > 0 ? " ({$rest} more behind it: " . implode('; ', array_slice($open, 1, 4)) . ')' : '';

    $escalation = $sameWork && $count >= 1
        ? "\n\nThis is block #" . ($count + 1) . " on the same set of open steps; the steps table has not changed since the last block. One of these applies:\n  1. The table is out of date: it does not record work you have done. Update the row, with the evidence `done` requires.\n  2. The first open step has a blocker you have not named. Name it in one line and end the turn on that question; this hook lets that through."
        : '';

    stop_decide('block', "Stop blocked: the run mode is `autonomous` and the next unfinished step is **{$next}**{$also}. An autonomous run continues without the person until every step is finished; a progress report does not end the turn.\n\nContinue with {$next} now.{$escalation}\n\nEnd the turn only when every row is `done` or `skipped`, or when you are blocked by something only the person can resolve: end the turn on a question only they can answer, or on a line that starts with `NEEDS YOU:` and names the blocker.", $cwd);
} catch (Throwable $e) {
    fwrite(STDERR, 'spryker-ai-dev-sdk stop guard: ' . $e->getMessage() . "\n");
    exit(0);
}
