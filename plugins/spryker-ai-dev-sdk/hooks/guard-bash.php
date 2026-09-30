<?php

declare(strict_types=1);

/** PreToolUse / Bash — shell writes, git staging and commits, database and key-value writes, and deletions. */

require __DIR__ . '/lib.php';

/** The deletion rules. They apply in every project, inside a wizard run or not. */
function guard_bash_deletions(string $command, string $cwd, array $payload): void
{
    if (preg_match('~\brm\s+(?:-\S+\s+)*[^;&|\n]*?\bdata/cache/(?:Yves|Zed|Glue|Backoffice|MerchantPortal)/[A-Za-z0-9_.-]+/?(?=[\s"\';&|)]|$)~', $command) === 1) {
        hook_decide('deny', 'Blocked: `data/cache/<Application>/<env>` holds the Symfony DI container, not compiled templates; deleting it causes 500 errors until the container is rebuilt. Compiled Twig templates are in `src/Generated/Yves/Twig/codeBucket` (Zed: `src/Generated/Zed/Twig/codeBucket`); delete that with `docker/sdk cli "rm -rf <path>"`, and run `docker/sdk console twig:cache:warmer` after adding a template override. The one case for clearing the compiled container is a stale container after generated-code changes (`boot-and-verify` verify-gates, "Class not found" triage).');
    }

    // A deletion git can restore runs without a prompt; one it cannot restore is denied.
    $rmTargets = hook_rm_targets($command);
    if ($rmTargets !== null && $rmTargets !== []) {
        $bad = hook_rm_unrecoverable($rmTargets, $cwd, hook_session_start((string) ($payload['transcript_path'] ?? '')));
        if ($bad !== []) {
            hook_decide('deny', 'Blocked `rm`: git cannot restore ' . implode(', ', array_slice($bad, 0, 5)) . '. Leave it in place and list it in the final report for the person to remove. Delete only files git tracks, or files this session created under `data/import`, `config`, `src`, `frontend`, `public` or `tests`.');
        }
        if (hook_command_only_deletes($command)) {
            hook_decide('allow', 'Deletes only project files that git can restore, or that this session created.');
        }
    }
}

try {
    $payload = hook_payload();
    if (($payload['tool_name'] ?? '') !== 'Bash') {
        exit(0);
    }
    $command = (string) ($payload['tool_input']['command'] ?? '');
    if (trim($command) === '') {
        exit(0);
    }
    $cwd = hook_cwd($payload);
    $isProject = is_dir($cwd . '/data/import') || is_file($cwd . '/composer.json');
    if (!$isProject) {
        exit(0);
    }
    if (!hook_run_active($cwd)) {
        guard_bash_deletions($command, $cwd, $payload);
        exit(0);
    }

    $sql = hook_sql_write($command);
    if ($sql !== null) {
        hook_decide('deny', "Blocked `{$sql}` on the project database: SQL is read-only here (`SELECT`, `SHOW`, `DESCRIBE` are allowed). A direct write skips the entity's events, so the read model (Redis, search) keeps the old value, and the change is not in the repository. Change data through `data/import/**` and `data:import <entity>`, `config/` or `src/`; to remove rows, use the reset ladder.");
    }

    $kv = hook_kv_write($command);
    if ($kv !== null) {
        hook_decide('deny', "Blocked `{$kv}` on the key-value store: editing the read model by hand leaves it out of sync with the database. Republish instead with `publish:trigger-events -r <resource>` per store, then let the queue workers process the events.");
    }

    $lastText = hook_last_user_text((string) ($payload['transcript_path'] ?? ''));
    if (preg_match('~\bgit\s+(?:-C\s+\S+\s+|--git-dir[= ]\S+\s+|--work-tree[= ]\S+\s+)*(add|rm|mv)\b~', $command, $stageVerb) === 1 && !hook_person_asked_to_stage($lastText) && !hook_person_confirmed_stage((string) ($payload['transcript_path'] ?? ''))) {
        $how = $stageVerb[1] === 'add' ? 'Leave the changes unstaged' : ($stageVerb[1] === 'rm' ? 'Delete tracked files with `rm` and leave the deletions unstaged' : 'Move the file with `mv` and leave the change unstaged');
        hook_decide('deny', "Blocked `git {$stageVerb[1]}`: it stages the change, and what to stage is the person's decision. {$how}, and list the changed files in the report.");
    }
    if (preg_match('~\bgit\s+(?:-C\s+\S+\s+|--git-dir[= ]\S+\s+|--work-tree[= ]\S+\s+)*commit\b~', $command) === 1 && !hook_person_asked_for($lastText, 'commit') && !hook_person_confirmed_stage((string) ($payload['transcript_path'] ?? ''))) {
        hook_decide('deny', 'Blocked `git commit`: the person did not ask for a commit. Leave committing to the person: report the changed files and say the work is ready to commit.');
    }
    if (preg_match('~\bgit\s+(?:-C\s+\S+\s+|--git-dir[= ]\S+\s+|--work-tree[= ]\S+\s+)*checkout\s+-b\b|\bgit\s+(?:-C\s+\S+\s+|--git-dir[= ]\S+\s+|--work-tree[= ]\S+\s+)*switch\s+-c\b~', $command) === 1 && !hook_person_asked_for($lastText, 'branch')
        && !(preg_match('~\s(?:-b|-c)\s+ai-customize/[\w.-]+~', $command) === 1 && hook_skill_loaded((string) ($payload['transcript_path'] ?? ''), ['spryker-customization']))) {
        hook_decide('deny', 'Blocked: the person did not ask for a new branch. Keep the work on the current branch and say it is ready.');
    }

    if (hook_touches_baseline($command)) {
        hook_decide('deny', 'Blocked: the gate baseline records the findings the project had before any change, so only new findings gate. Moving, deleting or recapturing it would record your own findings as pre-existing. If the baseline is wrong, say so in the final report and leave it in place.');
    }

    if (hook_disguised_token($command)) {
        hook_decide('deny', 'Blocked: this command builds a command name from string pieces, which the guards cannot read. Write the command in plain form, or skip the step and note it in the final report.');
    }


    $cache = hook_missing_cache_target($command, $cwd);
    if ($cache !== null) {
        $have = $cache['siblings'] === [] ? '(nothing)' : implode(', ', array_slice($cache['siblings'], 0, 8));
        hook_decide('deny', "Blocked: `{$cache['target']}` does not exist, so this `rm` removes nothing and still exits 0. `" . basename($cache['parent']) . "` contains: {$have}. Target a path that exists. Compiled Twig templates are in `src/Generated/Yves/Twig/codeBucket`; `console cache:empty-all` clears the application caches.");
    }

    $overwrite = hook_overwrite_target($cwd, $command);
    if ($overwrite !== null) {
        hook_decide('deny', "Blocked: this `cp`/`mv` overwrites `{$overwrite}`, a file tracked by git, without a reviewable diff. Read the file and change it with the Edit tool, or with Write if it is replaced entirely.");
    }

    $target = hook_shell_write_target($command);
    if ($target !== null && (hook_runs_outside_project($command, $cwd) || (str_starts_with($target, '/') && !str_starts_with($target, rtrim($cwd, '/') . '/')))) {
        $target = null; // the write lands outside the project
    }
    if ($target !== null) {
        $relTarget = str_starts_with($target, $cwd . '/') ? substr($target, strlen($cwd) + 1) : preg_replace('~^(?:\./)+~', '', $target);
        if (hook_is_new_customization_file($cwd, $relTarget) && !hook_skill_loaded((string) ($payload['transcript_path'] ?? ''), hook_customization_skills())) {
            hook_decide('deny', "Blocked: `{$relTarget}` is a new PHP class in the project namespace, which changes how the shop behaves (customization work), and no customization skill is loaded. If the request is design or data work, change a template, stylesheet or import file instead. If it is customization, load `spryker-customization` and write the class with the Write tool, not the shell.");
        }
        hook_decide('deny', "Blocked: this command writes `{$target}` through the shell (`>`, `>>`, `tee` or `sed -i`). Change project files with the Edit/Write tools or `csv.php`; the file guards do not see shell writes, and `>` empties the target before the command runs. For a gate baseline use `validate.php gate --save <path>`; to keep command output, write it outside the project.");
    }

    $scriptWrite = hook_script_project_write($command, $cwd);
    if ($scriptWrite !== null && hook_guarded_state_file($scriptWrite)) {
        hook_decide('deny', "Blocked: this script writes the run state file `{$scriptWrite}`. Change it with the Edit tool (Write for a new file); its checks, such as the evidence needed to mark a step done, run only on Edit and Write.");
    }
    if ($scriptWrite !== null) {
        hook_decide('deny', "Blocked: this script writes `{$scriptWrite}`. Change CSV rows with `csv.php` (`append --from <rows.csv> --in-place`, `add-row --set col=value --in-place`, `set|replace|filter|delete --in-place`) and other project files with Edit/Write. If no tool fits, for example when converting a JSON dataset, write the result with the Write tool and list it under `## Deviations` in the step report.");
    }

    if (!hook_script_writes_outside_project($command, $cwd) && preg_match('~\b(python3?|php|perl|ruby|node)\b~', $command) === 1 && (str_contains($command, '<<') || preg_match('~\s-(c|r|e)\s~', $command) === 1)
        && preg_match('~validate\.php|csv\.php|hooks\.test|\.test\.php~', $command) !== 1
        && preg_match('~(open\s*\([^)]*[\'"][wa]\+?[\'"]|write_text\s*\(|file_put_contents\s*\(|shutil\.(?:copy|move)|os\.(?:rename|replace|remove|unlink)|\.to_csv\s*\(|fopen\s*\([^)]*[\'"][wa])~', hook_script_text($command, $cwd)) === 1) {
        hook_decide('deny', 'Blocked: an inline script that writes into the project. Use the Edit/Write tools for files, `csv.php add-row|append|set|replace|filter|delete --in-place` for CSV rows, and `validate.php gate --save` for a baseline. Scripts may write outside the project: `cd` there first, or use absolute paths outside it.');
    }

    if (hook_bash_mutates($command)) {
        $question = hook_last_user_question((string) ($payload['transcript_path'] ?? ''));
        if ($question !== null) {
            hook_decide('deny', "Blocked: the person's last message is a question: \"" . mb_substr($question, 0, 160) . "\". Answer it before changing anything.");
        }

        if (hook_rebuild_running($cwd)) {
            hook_decide('deny', 'Blocked: a rebuild of this project is running, and changing files now can break it. Wait for it to finish, then retry this command without asking the person.');
        }

        $own = hook_unanswered_agent_question((string) ($payload['transcript_path'] ?? ''));
        if ($own !== null) {
            hook_decide('deny', 'Blocked: your question to the person is unanswered: "' . mb_substr($own, 0, 160) . '". Wait for the answer before changing anything. If the question was not meant for the person, decide it yourself: state the decision in one line in the chat (not as a question), record it in `.ai-dev/decision-log.md`, and retry.');
        }
    }

    guard_bash_deletions($command, $cwd, $payload);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'spryker-ai-dev-sdk bash guard: ' . $e->getMessage() . "\n");
    exit(0);
}
