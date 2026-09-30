<?php

declare(strict_types=1);

/** PreToolUse / Skill — a wizard step must not start on an unconfirmed answer set. */

require __DIR__ . '/lib.php';

/**
 * The wizard's step skills — the ones that change the project on the strength of the answer set.
 */
const GUARD_STEP_SKILLS = [
    'project-ci-generator',
    'configure-codebase',
    'configure-services',
    'brand-project',
    'define-stores',
    'project-data',
    'cypress-migration',
    'boot-and-verify',
    'translate-content',
];

try {
    $payload = hook_payload();
    if (($payload['tool_name'] ?? '') !== 'Skill') {
        exit(0);
    }
    $skill = (string) ($payload['tool_input']['skill'] ?? '');
    $skill = str_contains($skill, ':') ? substr($skill, (int) strrpos($skill, ':') + 1) : $skill;
    if (!in_array($skill, GUARD_STEP_SKILLS, true)) {
        exit(0);
    }
    $cwd = hook_cwd($payload);
    $states = ['.ai-dev/project-setup.md', '.ai-dev/demo-prep.md'];
    $unconfirmed = null;
    foreach ($states as $rel) {
        $state = $cwd . '/' . $rel;
        if (!is_file($state)) {
            continue;
        }
        $body = (string) file_get_contents($state);
        if (preg_match('~^\s*answers_confirmed_at\s*:\s*\S~mi', $body) === 1) {
            continue;
        }
        if (hook_any_step_started($body)) {
            continue;
        }
        $unconfirmed = $rel;
        break;
    }
    if ($unconfirmed === null) {
        exit(0);
    }
    hook_decide('deny', "Blocked `{$skill}`: `{$unconfirmed}` has no `answers_confirmed_at`, so its answers were never confirmed. Treat it as an interrupted interview: use the recorded values as pre-filled suggestions, ask the remaining questions, have the person confirm the full answer set, write `answers_confirmed_at`, then retry.");
} catch (Throwable $e) {
    fwrite(STDERR, 'spryker-ai-dev-sdk skill guard: ' . $e->getMessage() . "\n");
    exit(0);
}
