<?php

declare(strict_types=1);

/** Shared helpers for the plugin's hooks, under Claude Code and GitHub Copilot. */

/** @return array<string,mixed> */
function hook_payload(): array
{
    $raw = stream_get_contents(STDIN);
    $data = $raw === false || trim($raw) === '' ? null : json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/** The project root the tool call runs in (remembered for the decision log). */
function hook_cwd(array $payload): string
{
    $cwd = (string) ($payload['cwd'] ?? '');
    if ($cwd === '' || !is_dir($cwd)) {
        $cwd = (string) (getenv('CLAUDE_PROJECT_DIR') ?: getcwd());
    }
    $GLOBALS['hook_cwd'] = rtrim($cwd, '/');

    return $GLOBALS['hook_cwd'];
}

/**
 * One row per harness: the payload keys it is known by, then the environment it exports.
 * Adding a tool is adding a row; nothing matching returns 'unknown' rather than a guess.
 */
const HOOK_HARNESS_SIGNALS = [
    'claude' => ['keys' => ['session_id', 'hook_event_name'], 'env' => ['CLAUDECODE', 'CLAUDE_PLUGIN_ROOT']],
    'copilot' => ['keys' => ['sessionId', 'transcriptPath'], 'env' => ['COPILOT_HOME']],
];

/** Which harness invoked this hook, or 'unknown'. */
function hook_harness(array $payload): string
{
    foreach (HOOK_HARNESS_SIGNALS as $harness => $signals) {
        foreach ($signals['keys'] as $key) {
            if (array_key_exists($key, $payload)) {
                return $harness;
            }
        }
    }
    foreach (HOOK_HARNESS_SIGNALS as $harness => $signals) {
        foreach ($signals['env'] as $variable) {
            if (getenv($variable) !== false) {
                return $harness;
            }
        }
    }

    return 'unknown';
}

/** The session transcript, under whichever key the harness used. */
function hook_transcript_path(array $payload): string
{
    return (string) ($payload['transcript_path'] ?? $payload['transcriptPath'] ?? '');
}

/** The text of the turn that is ending; Copilot sends none, so it is read off the transcript. */
function hook_last_assistant_message(array $payload): string
{
    $direct = (string) ($payload['last_assistant_message'] ?? '');
    if ($direct !== '') {
        return $direct;
    }
    $path = hook_transcript_path($payload);
    if ($path === '' || !is_file($path)) {
        return '';
    }
    $size = filesize($path) ?: 0;
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    fseek($fh, max(0, $size - 400000));
    $tail = (string) stream_get_contents($fh);
    fclose($fh);

    $message = '';
    foreach (preg_split('/\R/', $tail) ?: [] as $line) {
        if (!str_contains($line, '"assistant.message"')) {
            continue;
        }
        $event = json_decode($line, true);
        $content = is_array($event) ? ($event['data']['content'] ?? null) : null;
        if (is_string($content) && $content !== '') {
            $message = $content;
        }
    }

    return $message;
}

/** Record which rule decided, when `AIDEV_HOOK_TRACE` names a file. */
function hook_trace(string $decision): void
{
    $dest = (string) getenv('AIDEV_HOOK_TRACE');
    if ($dest === '') {
        return;
    }
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
        if (!in_array((string) ($frame['function'] ?? ''), ['stop_decide'], true)) {
            continue;
        }
        @file_put_contents($dest, basename((string) ($frame['file'] ?? '?')) . ':' . (int) ($frame['line'] ?? 0) . ' ' . $decision . "\n", FILE_APPEND);

        return;
    }
}

/**
 * Does the last assistant message assert that the user cancelled or killed the run's own work?
 */
function hook_claims_user_cancelled_work(string $lastMessage): bool
{
    return preg_match('~\b(you (?:keep|kept) (?:stopping|killing)|you(?:\'ve| have) (?:now )?(?:killed|stopped)|work you keep stopping|killed (?:a|the|my) (?:sub-?agent|agent|translator|verifier))~i', $lastMessage) === 1;
}

/** Does the last assistant message end the turn because of a permission or allowlist limit? */
function hook_stops_on_permissions(string $lastMessage): bool
{
    if (preg_match('~(report (?:is )?written|written to [`\[]?\.ai-dev|recorded (?:the rest|them|it)|listed under \*?\*?blocked|rather than stopping|worked entirely within the allow)~i', $lastMessage) === 1) {
        return false;
    }
    foreach (preg_split('/(?<=[.!?\n])\s+/', $lastMessage) ?: [] as $sentence) {
        if (preg_match('~\b(allow-?list|allowlisted|permission prompt|permissions?)\b~i', $sentence) !== 1) {
            continue;
        }
        if (preg_match('~\b(so I (?:am |\'m )?stopp|stopping here|cannot (?:continue|proceed|go further)|can(?:not|\'t) do (?:any|this) more|blocked (?:on|by) (?:this|that|it|permission)|(?:this|the) step is blocked|handing back|until you (?:allow|approve|add))~i', $sentence) === 1) {
            return true;
        }
    }

    return false;
}
