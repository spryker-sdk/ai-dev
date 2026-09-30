<?php

declare(strict_types=1);

/** PreToolUse / Agent|Task — a writing sub-agent's prompt names its tooling, the owning skill and the work class. */

require __DIR__ . '/lib.php';

/** Skill that owns a kind of work, keyed by a pattern that recognises the dispatch. */
const AGENT_OWNED_WORK = [
    '~\b(?i:translat\w+|glossar\w+|locali[sz]\w+)\b|\b[a-z]{2}_[A-Z]{2}\b|\b(?i:into|to|in)\s+(?:Polish|German|Spanish|Czech|Ukrainian|Italian|French|Dutch)\b|\b(?:Polish|German|Spanish|Czech|Ukrainian|Italian|French|Dutch)\s+(?i:glossary|translations?|copy|texts?|content|version|locale)\b~' => 'translate-content',
    '~\b(import(er|ing)?\s+(data|csv)|data:import|demo data|product_abstract|product_concrete)\b~i' => 'project-data',
    '~\b(twig|scss|atomic|molecule|organism|storefront (design|template))\b~i' => 'yves-atomic-frontend',
];

try {
    $payload = hook_payload();
    if (!in_array((string) ($payload['tool_name'] ?? ''), ['Agent', 'Task'], true)) {
        exit(0);
    }
    $d = hook_agent_dispatch($payload);
    $prompt = $d['prompt'];
    $text = $prompt . "\n" . $d['description'];
    if (trim($prompt) === '') {
        exit(0);
    }
    $cwd = hook_cwd($payload);
    if (!hook_run_active($cwd)) {
        exit(0);
    }
    $validator = hook_validator($cwd);
    $csv = $validator !== null ? str_replace('validate.php', 'csv.php', $validator) : 'csv.php';

    // A worker that only reads needs none of these rules. Research intent counts unless the prompt also
    // tells it to write something.
    $writeIntent = preg_match('~\b(write|create|add|append|update|edit|modify|generate|produce|save|replace|delete|remove|import)\b[^.\n]{0,40}\b(files?|csv|rows?|columns?|data/import|templates?|config|src/)~i', $prompt) === 1;
    $readOnly = in_array($d['type'], ['Explore', 'Plan', 'claude-code-guide'], true)
        || preg_match('~\b(do\s*NOT\s+fix|never\s+fix|observe\s+and\s+report|report\s+only|read-only)\b~i', $prompt) === 1
        || (!$writeIntent && preg_match('~\b(research|investigate|look\s+up|find\s+out|explore|survey|report\s+back|summari[sz]e|audit|review|do\s*n[o\']t\s+(?:edit|change|modify|write)|change\s+nothing|no\s+(?:changes|edits))\b~i', $text) === 1);
    if ($readOnly) {
        exit(0);
    }

    $makesCsv = !$readOnly && preg_match('~\b(csv|rfc\s*4180|comma-separated)\b|data/import/~i', $text) === 1;
    if ($makesCsv && preg_match('~csv\.php~', $prompt) !== 1) {
        hook_decide('deny', "Blocked: this sub-agent is told to produce CSV, and its prompt does not name `csv.php`. A sub-agent does not inherit this session's conventions. Add the commands it needs to the prompt, then re-dispatch:\n  add a row    `php {$csv} add-row <file> --set col=value --in-place`\n  append rows  `php {$csv} append <file> --from <rows.csv> --in-place`\n  transform    `php {$csv} set|replace|filter|delete --in-place`\n  verify       `php {$csv} count <file> --plain` and `php {$csv} columns <file> --plain`\nIf the sub-agent only reads, say so in its prompt (for example \"read-only, change nothing\").");
    }

    if (!in_array($d['type'], hook_skill_aware_agents(), true)) {
        foreach (AGENT_OWNED_WORK as $pattern => $skill) {
            if (preg_match($pattern, $text) !== 1) {
                continue;
            }
            $q = preg_quote($skill, '~');
            $instructed = preg_match('~\b(?:load|loads|loading|use|uses|using|follow|follows|read|reads|invoke|apply|per|Skill\()[^.\n]{0,24}?' . $q . '~i', $prompt) === 1;
            $negated = preg_match('~\b(?:do\s*n[o\']t|don\'t|never|without|skip|ignore|no\s+need\s+to)\b[^.\n]{0,40}?' . $q . '~i', $prompt) === 1;
            if ($instructed && !$negated) {
                continue;
            }
            hook_decide('deny', "Blocked: this is `{$skill}` work dispatched to a `{$d['type']}` sub-agent, and the prompt does not tell it to use `{$skill}`. A sub-agent loads no skills on its own. Tell it to load `{$skill}` first, or copy the rules it needs into the prompt marked \"per `{$skill}`\", then re-dispatch.");
        }
    }

    $writesProject = !$readOnly
        && preg_match('~\b(write|edit|create|modify|patch|apply)\b~i', $prompt) === 1
        && preg_match('~(\bsrc/|\bdata/import/|\bconfig/|\.twig\b|\.scss\b|(?<!csv)(?<!validate)\.php\b)~', $prompt) === 1;
    if ($writesProject && preg_match('~\b(setup|data|design|customi[sz]ation)\b~i', $prompt) !== 1) {
        hook_decide('deny', "Blocked: this sub-agent is told to write project files, and its prompt does not name the class of work: setup, data, design or customization. Each class has its own owning skill and file scope. Name the class and the files it may change in the prompt, then re-dispatch.");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'spryker-ai-dev-sdk agent guard: ' . $e->getMessage() . "\n");
    exit(0);
}
