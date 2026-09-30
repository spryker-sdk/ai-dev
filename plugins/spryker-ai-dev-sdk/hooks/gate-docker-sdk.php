<?php

declare(strict_types=1);

/**
 * PreToolUse / Bash — the gate in front of every expensive or DB-mutating docker/sdk command.
 */

require __DIR__ . '/lib.php';

try {
    $payload = hook_payload();
    if (($payload['tool_name'] ?? '') !== 'Bash') {
        exit(0);
    }
    $command = (string) ($payload['tool_input']['command'] ?? '');
    $class = hook_classify_command($command);
    if ($class === null) {
        exit(0);
    }
    $cwd = hook_cwd($payload);
    if (!hook_run_active($cwd)) {
        exit(0);
    }
    $gate = hook_run_gate($cwd, false);
    if ($gate === null) {
        exit(0);
    }
    $count = hook_rebuild_count($cwd);
    $hasBaseline = ($gate['baseline'] ?? null) !== null;
    $validatorHint = 'Fix the findings, then re-run `php ' . (hook_validator($cwd) ?? 'validate.php') . ' gate`. Full report: .ai-dev/gate-last.json.';

    if (($gate['status'] ?? '') === 'error') {
        $digest = hook_gate_digest($gate);
        if ($hasBaseline) {
            hook_decide('deny', "Blocked `{$class['verb']}`: the data gate reports new findings, so the " . ($class['kind'] === 'rebuild' ? 'rebuild would run on data the gate already rejects' : 'import would load data with known errors') . ".\n{$digest}\n{$validatorHint}");
        }
        if ($class['kind'] === 'import') {
            exit(0);
        }
        hook_decide('deny', "Blocked `{$class['verb']}`: the data gate has findings and there is no baseline (.ai-dev/gate-baseline.json), so pre-existing findings cannot be told apart from new ones. Fix the findings your changes caused, or, if the project is still unchanged, capture the baseline with `php " . (hook_validator($cwd) ?? 'validate.php') . " gate --save .ai-dev/gate-baseline.json`, then retry.\n{$digest}" . ($class['kind'] === 'rebuild' ? "\nThis would be rebuild #" . ($count + 1) . '.' : ''));
    }

    if ($class['kind'] === 'rebuild') {
        $what = match ($class['verb']) {
            'reset' => 'wipes the shop\'s database and reloads it from the project files (roughly 10–30 minutes). Your project files are not touched',
            'clean-data' => 'removes the shop\'s containers and stored data, then everything is rebuilt from the project files (30–60 minutes). Your project files are not touched',
            default => 'rebuilds the shop\'s containers and frontend (30–60 minutes). Stored data is kept',
        };
        $trusted = hook_rebuild_trusted($cwd, (string) ($payload['transcript_path'] ?? ''));
        if ($trusted !== null && $count < 2) {
            exit(0);
        }
        if ($count >= 2) {
            $next = $count + 1;
            $logs = '';
            foreach (['decision-log.md', 'demo-prep.md', 'project-setup.md'] as $f) {
                $logs .= is_file($cwd . '/.ai-dev/' . $f) ? (string) file_get_contents($cwd . '/.ai-dev/' . $f) : '';
            }
            if (preg_match('~rebuild\s*#?\s*' . $next . '\b~i', $logs) !== 1) {
                hook_decide('deny', "Rebuild #{$next} blocked: `docker/sdk {$class['verb']}` {$what}. This project was already rebuilt {$count} times; from the third rebuild on, each one needs a reason in the decision log. Where `data:import -c <config>` can show the result, use that instead. If a rebuild is needed, add the decision-log line \"rebuild #{$next}: <reason>\" to `.ai-dev/decision-log.md`, then retry.");
            }
            if ($trusted !== null) {
                exit(0);
            }
        }
        hook_decide('ask', "Rebuild #" . ($count + 1) . ": `docker/sdk {$class['verb']}` {$what}. Data check: " . implode(' · ', (array) $gate['summary']) . ". If you are happy for rebuilds to run without asking on this project, say so in the chat (for example \"rebuilds are always OK here\") and it will be recorded.");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'spryker-ai-dev-sdk gate hook: ' . $e->getMessage() . "\n");
    exit(0);
}
