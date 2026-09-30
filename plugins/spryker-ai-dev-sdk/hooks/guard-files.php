<?php

declare(strict_types=1);

/** PreToolUse / Edit|Write|MultiEdit — the artifacts an agent must not write freely. */

require __DIR__ . '/lib.php';

const GUARD_MIN_INTERVIEW_QUESTIONS = 5;
const GUARD_MIN_QUESTIONNAIRE_LINES = 3;

/**
 * What the transcript proves about how the answers were collected.
 *
 * @return array{questionsAsked:int, questionnaireLines:int, confirmationAsked:bool}
 */
function guard_collection_evidence(string $transcriptPath): array
{
    $out = ['questionsAsked' => 0, 'questionnaireLines' => 0, 'confirmationAsked' => false];
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return $out;
    }
    $fh = fopen($transcriptPath, 'rb');
    if ($fh === false) {
        return $out;
    }
    while (($line = fgets($fh)) !== false) {
        $d = json_decode($line, true);
        if (!is_array($d)) {
            continue;
        }
        $content = $d['message']['content'] ?? null;
        if (($d['type'] ?? '') === 'assistant' && is_array($content)) {
            foreach ($content as $c) {
                if (($c['type'] ?? '') !== 'tool_use' || ($c['name'] ?? '') !== 'AskUserQuestion') {
                    continue;
                }
                $qs = (array) ($c['input']['questions'] ?? []);
                $out['questionsAsked'] += max(1, count($qs));
                foreach ($qs as $q) {
                    if (preg_match('/confirm/i', (string) ($q['question'] ?? '') . ' ' . (string) ($q['header'] ?? '')) === 1) {
                        $out['confirmationAsked'] = true;
                    }
                }
            }
        }
        if (($d['type'] ?? '') === 'user') {
            $texts = [];
            if (is_string($content)) {
                $texts[] = $content;
            } elseif (is_array($content)) {
                foreach ($content as $c) {
                    if (($c['type'] ?? '') === 'text') {
                        $texts[] = (string) ($c['text'] ?? '');
                    }
                }
            }
            foreach ($texts as $t) {
                if (str_starts_with(ltrim($t), 'Base directory for this skill:')) {
                    continue;
                }
                $out['questionnaireLines'] += preg_match_all('/^\s*[PNSTDCLQR]\d+\s*[:.]\s*\S/m', $t);
            }
        }
    }
    fclose($fh);

    return $out;
}

try {
    $payload = hook_payload();
    $tool = (string) ($payload['tool_name'] ?? '');
    if (!in_array($tool, ['Edit', 'Write', 'MultiEdit'], true)) {
        exit(0);
    }
    $input = (array) ($payload['tool_input'] ?? []);
    $path = (string) ($input['file_path'] ?? '');
    if ($path === '') {
        exit(0);
    }
    $cwd = hook_cwd($payload);
    $abs = $path[0] === '/' ? $path : $cwd . '/' . $path;
    $rel = str_starts_with($abs, $cwd . '/') ? substr($abs, strlen($cwd) + 1) : $path;

    if (preg_match('~^(?:\.claude|\.cursor|\.windsurf|\.agents|\.github|\.opencode)/skills/([^/]+)/~', $rel, $m) === 1
        && in_array($m[1], hook_plugin_skills(), true)) {
        hook_decide('deny', "Blocked: `{$rel}` is an installed copy of the plugin skill `{$m[1]}` and is read-only; the next install overwrites it. Record the proposed change in `.ai-dev/skill-improvement-log.md` as symptom → root cause → skill → suggested change.");
    }

    if ($rel === '.ai-dev/rebuild-count' || $rel === '.ai-dev/rebuild-log') {
        hook_decide('deny', 'Blocked: `.ai-dev/rebuild-count` and `.ai-dev/rebuild-log` are written by the plugin hook after each `docker/sdk reset|clean-data|up` and are read-only. Quote the count in the step report.');
    }

    if (hook_is_commented_code_file($rel) && (hook_run_active($cwd) || hook_upgrade_active($cwd))) {
        $ext = pathinfo($rel, PATHINFO_EXTENSION);
        $pairs = match ($tool) {
            'Write' => [[is_file($abs) ? (string) file_get_contents($abs) : '', (string) ($input['content'] ?? '')]],
            'MultiEdit' => array_map(static fn ($e): array => [(string) ($e['old_string'] ?? ''), (string) ($e['new_string'] ?? '')], (array) ($input['edits'] ?? [])),
            default => [[(string) ($input['old_string'] ?? ''), (string) ($input['new_string'] ?? '')]],
        };
        $added = [];
        foreach ($pairs as [$old, $new]) {
            array_push($added, ...hook_added_comments($old, $new, $ext));
        }
        if ($added !== []) {
            $quoted = implode('", "', array_map(static fn (string $c): string => mb_substr($c, 0, 80), array_slice($added, 0, 3)));
            hook_decide('deny', "Blocked: this edit adds explanatory comments to `{$rel}`: \"{$quoted}\". To make the comment unnecessary, rename the method, variable or constant, or extract a well-named method. Allowed: docblock tags (`@param`, `@return`, …), `{@inheritDoc}`, `Specification:` blocks, the license header, and an `upgrade-debt` docblock on a temporary shim. Suppressions (`@phpstan-ignore`, `phpcs:ignore`, `eslint-disable`, …) are blocked too: fix the finding. Put the reason for a change in the commit or PR description.");
        }
    }

    // Everything below is run discipline: it applies while a wizard run is in progress, not to everyday work.
    if (!hook_run_active($cwd) && !in_array($rel, ['.ai-dev/project-setup.md', '.ai-dev/demo-prep.md'], true)) {
        exit(0);
    }


    $question = hook_last_user_question((string) ($payload['transcript_path'] ?? ''));
    if ($question !== null) {
        hook_decide('deny', "Blocked: the person's last message is a question: \"" . mb_substr($question, 0, 160) . "\". Answer it before editing.");
    }

    $ownQuestion = hook_unanswered_agent_question((string) ($payload['transcript_path'] ?? ''));
    if ($ownQuestion !== null && $rel !== '.ai-dev/decision-log.md') {
        hook_decide('deny', 'Blocked: your question to the person is unanswered: "' . mb_substr($ownQuestion, 0, 160) . '". Wait for the answer before editing. If the question was not meant for the person, decide it yourself: state the decision in one line in the chat (not as a question), record it in `.ai-dev/decision-log.md`, and retry.');
    }

    if (hook_is_new_customization_file($cwd, $rel) && !hook_skill_loaded((string) ($payload['transcript_path'] ?? ''), hook_customization_skills())) {
        hook_decide('deny', "Blocked: `{$rel}` is a new PHP class in the project namespace, which changes how the shop behaves (customization work), and no customization skill is loaded. If the request is design or data work, change a template, stylesheet or import file instead. If it is customization, load `spryker-customization` with the Skill tool, then retry.");
    }

    if (hook_is_frontend_file($rel) && !hook_skill_loaded((string) ($payload['transcript_path'] ?? ''), hook_design_skills())) {
        hook_decide('deny', "Blocked: `{$rel}` is a storefront template or stylesheet (design work), and no design skill is loaded. Load `yves-atomic-frontend` with the Skill tool (or `match-reference-design` for a comparison with a reference design), then retry.");
    }

    if (preg_match('~^\.(?:ai-dev|claude)/~', $rel) !== 1 && hook_git_ignored($cwd, $abs)) {
        hook_decide('deny', "Blocked: `{$rel}` is matched by the project's ignore rules, which usually means it is generated output that the next build overwrites. Edit the source file it is built from.");
    }

    if (str_starts_with($rel, 'src/') && hook_rebuild_running($cwd)) {
        hook_decide('deny', 'Blocked: a rebuild of this project is running and may be compiling this source file; editing it now can abort the rebuild. Wait for it to finish, then retry.');
    }

    $stateFiles = ['.ai-dev/project-setup.md' => 'project-starter-wizard', '.ai-dev/demo-prep.md' => 'demo-prep-wizard'];
    if (!isset($stateFiles[$rel])) {
        exit(0);
    }
    $ownerSkill = $stateFiles[$rel];
    $newText = match ($tool) {
        'Write' => (string) ($input['content'] ?? ''),
        'Edit' => (string) ($input['new_string'] ?? ''),
        default => implode("\n", array_map(static fn ($e) => (string) ($e['new_string'] ?? ''), (array) ($input['edits'] ?? []))),
    };
    $oldText = is_file($abs) ? (string) file_get_contents($abs) : '';

    if ($oldText === '' && preg_match('/^answers_source:/m', $newText) === 1) {
        $ev = guard_collection_evidence((string) ($payload['transcript_path'] ?? ''));
        $isDemo = str_ends_with($rel, 'demo-prep.md');
        // A demo's project answers were asked and confirmed in demo-prep-wizard's one sitting.
        $demoPrep = $cwd . '/.ai-dev/demo-prep.md';
        if (!$isDemo && preg_match('/^answers_source:\s*demo-prep\b/m', $newText) === 1
            && is_file($demoPrep) && preg_match('/^up_front_confirmed_at:\s*\S/m', (string) file_get_contents($demoPrep)) === 1) {
            $isDemo = true;
            $ev['confirmationAsked'] = true;
        }
        if ($isDemo && !$ev['confirmationAsked']) {
            hook_decide('deny', "Cannot write `{$rel}` yet: the routing table is not confirmed. Show it (each briefing item: in the demo, as a small working version, or left out, with your recommendation), ask the person to confirm it in one AskUserQuestion whose text contains \"confirm\", then write the file. Ask only about the routing, not about the demo content.");
        }
        $collected = $isDemo || $ev['questionnaireLines'] >= GUARD_MIN_QUESTIONNAIRE_LINES || $ev['questionsAsked'] >= GUARD_MIN_INTERVIEW_QUESTIONS;
        if (!$collected || !$ev['confirmationAsked']) {
            $what = [];
            if (!$collected) {
                $what[] = "the answers were not collected: {$ev['questionsAsked']} question(s) asked via AskUserQuestion (interview needs ≥ " . GUARD_MIN_INTERVIEW_QUESTIONS . ") and {$ev['questionnaireLines']} ID-tagged questionnaire line(s) from the person (a filled questionnaire needs ≥ " . GUARD_MIN_QUESTIONNAIRE_LINES . ", e.g. `P1: acme`)";
            }
            if (!$ev['confirmationAsked']) {
                $what[] = 'no confirmation question was asked (an AskUserQuestion whose text contains "confirm", listing every resolved value)';
            }
            hook_decide('deny', "Cannot write `{$rel}` yet: " . implode('; and ', $what) . ". Use a prose brief to pre-fill suggested answers, ask every section in batched AskUserQuestion calls ({$ownerSkill}), then ask the person to confirm the full answer set. Ask about every value the person did not state.");
        }
    }

    if (preg_match('~namespace:\s*\{[^}]*mode:\s*keep-shipped[^}]*name:\s*([A-Za-z0-9_]+)~', $newText, $ns) === 1) {
        $have = hook_project_namespaces($cwd);
        if ($have !== [] && $ns[1] !== $have[0]) {
            $list = implode(', ', $have);
            $why = in_array($ns[1], $have, true)
                ? "`{$ns[1]}` is a project namespace, but not the one that resolves first"
                : "the project does not ship `{$ns[1]}`";
            hook_decide('deny', "Blocked `namespace: { mode: keep-shipped, name: {$ns[1]} }`: {$why}. Project namespaces in precedence order: {$list}. `keep-shipped` means the first one, `{$have[0]}`. Check `PROJECT_NAMESPACES` in `config/Shared/config_default.php`, ask again with every shipped namespace named in the options, and record a lower-precedence choice as `mode: custom`.");
        }
    }

    $designClosers = ['match-reference-design', 'design', 'brand-project', 'boot-and-verify', 'build', 'rehearsal'];
    $closing = null;
    foreach ($designClosers as $step) {
        if (hook_step_newly_done($newText, $oldText, $step)) {
            $closing = $step;
            break;
        }
    }
    if ($closing !== null && hook_frontend_edited((string) ($payload['transcript_path'] ?? ''), $cwd)) {
        $acceptance = $cwd . '/.ai-dev/design-acceptance.md';
        $body = is_file($acceptance) ? (string) file_get_contents($acceptance) : '';
        $gaps = $body === '' ? hook_design_surfaces() : hook_design_gaps($body);
        $surfaces = implode(', ', hook_design_surfaces());
        if ($gaps !== []) {
            $why = $body === ''
                ? '`.ai-dev/design-acceptance.md` does not exist'
                : 'no evidenced line for: ' . implode(', ', $gaps);
            hook_decide('deny', "Cannot mark `{$closing}` done: this session edited storefront templates or styles, and {$why}.\n\nAfter the last change, check every surface and write `.ai-dev/design-acceptance.md`: one row per surface per store, each with evidence — a measurement (`hero 16/9, 760px`), a rendered token (`#293043`), a fetched URL (`/EN/en/category`) or a template path. The word \"checked\" alone, or the same token on every row, is not accepted as evidence.\n\nSurfaces: {$surfaces}. `dropdown` means every dropdown opened, `footer` scrolled to, `mobile` at phone width.");
        }
        $swept = (int) @filemtime($acceptance);
        if (hook_evidence_stale($cwd, $acceptance)) {
            hook_decide('deny', "Cannot mark `{$closing}` done: `.ai-dev/design-acceptance.md` is older than the last rebuild (`.ai-dev/rebuild-log`). Check the surfaces again and rewrite it.");
        }
        $newestEdit = hook_frontend_mtime($cwd);
        if ($newestEdit > 0 && $swept > 0 && $swept < $newestEdit) {
            hook_decide('deny', "Cannot mark `{$closing}` done: `.ai-dev/design-acceptance.md` is older than the newest template or style change. Check every surface again after the last change and rewrite the file.");
        }
    }

    $gatedSteps = ['define-stores' => false, 'project-data' => true, 'boot-and-verify' => true, 'rehearsal' => true, 'demo-run-sheet' => false];
    $flipped = [];
    foreach ($gatedSteps as $step => $strict) {
        if (hook_step_newly_done($newText, $oldText, $step)) {
            $flipped[$step] = $strict;
        }
    }
    if ($flipped === []) {
        exit(0);
    }
    if (isset($flipped['boot-and-verify'])) {
        $report = $cwd . '/.ai-dev/verifier-report.md';
        $missing = hook_evidence_missing($report);
        if ($missing !== null) {
            hook_decide('deny', "Cannot mark `boot-and-verify` done: `.ai-dev/verifier-report.md` — {$missing}. Dispatch the `spryker-verifier` agent; it saves its PASS/FAIL/BLOCKED report to that file. Then mark the step done.");
        }
        if (!hook_verifier_dispatched((string) ($payload['transcript_path'] ?? ''))) {
            hook_decide('deny', 'Cannot mark `boot-and-verify` done: no `spryker-verifier` agent was dispatched in this session, so `.ai-dev/verifier-report.md` did not come from the independent verifier. Dispatch `spryker-verifier` and let it write the file.');
        }
        if (hook_evidence_stale($cwd, $report)) {
            hook_decide('deny', 'Cannot mark `boot-and-verify` done: `.ai-dev/verifier-report.md` predates the last rebuild in `.ai-dev/rebuild-log`, so it describes an earlier state of the stack. Run the verifier again.');
        }
    }
    if (isset($flipped['rehearsal'])) {
        $walk = $cwd . '/.ai-dev/rehearsal.md';
        $missing = hook_evidence_missing($walk, '~\b(pass|gap|fail)\b~i');
        if ($missing !== null) {
            hook_decide('deny', "Cannot mark `rehearsal` done: `.ai-dev/rehearsal.md` — {$missing}. The file needs a pass or gap line for every beat of the demo, recorded while walking it.");
        }
        if (hook_evidence_stale($cwd, $walk)) {
            hook_decide('deny', 'Cannot mark `rehearsal` done: `.ai-dev/rehearsal.md` predates the last rebuild in `.ai-dev/rebuild-log`. Walk the demo again and rewrite the file.');
        }
    }
    if (isset($flipped['demo-run-sheet'])) {
        $sheet = null;
        foreach (['demo-run-sheet.html', 'demo-run-sheet.md', 'demo-run-sheet.txt'] as $cand) {
            if (is_file($cwd . '/.ai-dev/' . $cand)) {
                $sheet = $cwd . '/.ai-dev/' . $cand;
                break;
            }
        }
        if ($sheet === null || hook_evidence_missing($sheet, '') !== null) {
            hook_decide('deny', 'Cannot mark `demo-run-sheet` done: no `.ai-dev/demo-run-sheet.html` (or `.md` / `.txt`) with content exists. Save a local copy of the run sheet there, whatever format it was delivered in, then mark the step done.');
        }
    }

    $strict = in_array(true, $flipped, true);
    $gate = hook_run_gate($cwd, $strict);
    if ($gate === null) {
        exit(0);
    }
    if (($gate['status'] ?? '') === 'error') {
        $steps = implode(', ', array_keys($flipped));
        hook_decide('deny', "Cannot mark `{$steps}` done: the data gate fails" . ($strict ? ' (strict: orphan-files also gates)' : '') . ".\n" . hook_gate_digest($gate) . "\nA step is `done` when `php " . (hook_validator($cwd) ?? 'validate.php') . " gate" . ($strict ? ' --strict' : '') . "` exits 0 and its output is in the step report. Fix the findings, or record the step as `in-progress (<what is left>)`.");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'spryker-ai-dev-sdk guard hook: ' . $e->getMessage() . "\n");
    exit(0);
}
