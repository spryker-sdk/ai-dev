<?php

declare(strict_types=1);

/** Zero-dependency test for the plugin hooks. */

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

/** The gate hook needs `validate.php`, which sits in the skills tree. */
$skipped = 0;
function check_gate(string $name, bool $ok): void
{
    global $skipped, $hasValidator;
    if (!$hasValidator) {
        $skipped++;
        echo "  skip {$name} (no validate.php beside the hooks)\n";

        return;
    }
    check($name, $ok);
}

$hooksDir = __DIR__;
$pluginRoot = dirname(__DIR__);
$hasValidator = is_file($pluginRoot . '/skills/spryker-import-tools/scripts/validate.php');
$php = PHP_BINARY;

// --- command classifier (unit) -------------------------------------------------------------
require_once $hooksDir . '/lib.php';
check('classify: heredoc body mentioning docker/sdk up is NOT a rebuild (a state-file edit is not a rebuild)',
    hook_classify_command("python3 - <<'PY'\ns=s.replace('boot running: docker/sdk up -t','x')\nPY") === null);
check('classify: quoted mention is not a rebuild', hook_classify_command('echo "then run docker/sdk up"') === null);
check('classify: script-wrapped reset is a rebuild', (hook_classify_command('script -q .ai-dev/reset.log docker/sdk reset')['verb'] ?? null) === 'reset');
check('classify: rm cache && reset is a rebuild', (hook_classify_command('rm -rf data/cache/Yves/docker.dev && script -q x docker/sdk reset')['verb'] ?? null) === 'reset');
check('classify: quoted cli data:import is an import', (hook_classify_command('docker/sdk cli "APPLICATION_STORE=DE vendor/bin/console data:import"')['kind'] ?? null) === 'import');
check('classify: `docker/sdk up --assets` is an asset build, not a rebuild', hook_classify_command('docker/sdk up --assets') === null);
check('classify: `docker/sdk up --build --assets` is still a rebuild', (hook_classify_command('docker/sdk up --build --assets')['kind'] ?? null) === 'rebuild');
check('polite request: "can you not delete that?" is a question', hook_is_polite_request('can you not delete that?') === false);
check('polite request: "could you walk me through what changed?" is a question', hook_is_polite_request('could you walk me through what changed?') === false);
check('polite request: "could you prepare the demo from brief.md?" is an instruction', hook_is_polite_request('could you prepare the demo from brief.md?') === true);
check('classify: data:import:glossary is an import', (hook_classify_command('docker/sdk console data:import:glossary')['kind'] ?? null) === 'import');
check('classify: heredoc file body with data:import is not an import', hook_classify_command("cat > x <<EOF\ndocker/sdk console data:import\nEOF") === null);

$ac = static fn (string $old, string $new, string $ext = 'php'): array => hook_added_comments($old, $new, $ext);
check('comments: a `//` explanation is added', $ac('$a = 1;', "// load the price first\n\$a = 1;") === ['load the price first']);
check('comments: a trailing `//` after code is added', $ac('$a = 1;', '$a = 1; // default store') === ['default store']);
check('comments: a docblock with only tags is not explanatory', $ac('', "/**\n * @param int \$a\n *\n * @return void\n */") === []);
check('comments: `{@inheritDoc}` and `@api` are not explanatory', $ac('', "/**\n * {@inheritDoc}\n *\n * @api\n */") === []);
check('comments: a Specification block is not explanatory', $ac('', "/**\n * Specification:\n * - Returns the price for the store.\n *\n * @api\n */") === []);
check('comments: an upgrade-debt docblock is kept', $ac('', "/**\n * upgrade-debt: re-boots application plugins until the vendor fix lands.\n */") === []);
check('comments: a class docblock sentence is added', $ac('', "/**\n * Builds the cart summary.\n */\nclass X {}") === ['Builds the cart summary.']);
check('comments: a PHP attribute is not a comment', $ac('', '#[\\ReturnTypeWillChange]') === []);
check('comments: a moved comment is not added', $ac("// keep\n\$a = 1;", "\$a = 1;\n// keep") === []);
check('comments: a URL in a string is not a comment', $ac('', "\$u = 'http://example.org';") === []);
check('comments: a twig comment is added', $ac('', '{# shows the promo only for guests #}', 'twig') === ['shows the promo only for guests']);
check('comments: a license header is kept', $ac('', "/**\n * This file is part of the Spryker Commerce OS.\n * For full license information, please view the LICENSE file that was distributed with this source code.\n */") === []);
check('comments: a `@phpstan-ignore` suppression is reported', $ac('', "// @phpstan-ignore-next-line\n\$a = 1;") === ['@phpstan-ignore-next-line']);
check('comments: a suppression tag inside a docblock is reported', $ac('', "/**\n * @return int\n * @psalm-suppress MixedReturn\n */") === ['@psalm-suppress MixedReturn']);
check('comments: an Edit starting mid-docblock is read as a docblock', $ac('', " * Returns the sum.\n */") === ['Returns the sum.']);

/** Run a hook script with a payload; returns [decision|null, reason, stderr, exit]. */
function run_hook(string $script, array $payload): array
{
    global $pluginRoot, $hooksDir, $php;
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$php, $hooksDir . '/' . $script], $desc, $pipes, null, ['CLAUDE_PLUGIN_ROOT' => $pluginRoot, 'PATH' => getenv('PATH')] + ($GLOBALS['hookEnforce'] ?? true ? ['SPRYKER_AI_DEV_ENFORCE' => '1'] : []));
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

// --- a minimal Spryker-shaped project: one store, one locale, a clean manifest ---
$proj = sys_get_temp_dir() . '/hooks_' . getmypid();
@mkdir($proj . '/data/import/local', 0777, true);
@mkdir($proj . '/data/import/acme/stores/EU', 0777, true);
@mkdir($proj . '/data/import/acme/stores/DE', 0777, true);
@mkdir($proj . '/.ai-dev', 0777, true);
@mkdir($proj . '/.claude/skills/project-data', 0777, true);
@mkdir($proj . '/.claude/skills/demo-runtime-smoke', 0777, true);
file_put_contents($proj . '/deploy.dev.yml', "version: '0.1'\nregions:\n    EU:\n        stores:\n            DE: {}\n");
file_put_contents($proj . '/data/import/acme/stores/EU/store.csv', "name\nDE\n");
file_put_contents($proj . '/data/import/acme/stores/DE/locale_store.csv', "locale_name,store_name\nde_DE,DE\n");
file_put_contents($proj . '/data/import/acme/stores/DE/cms_block_store.csv', "block_key,store_name\nb1,DE\n");
$manifest = $proj . '/data/import/local/full_EU.yml';
file_put_contents($manifest, "version: 0\nactions:\n  - data_entity: store\n    source: data/import/acme/stores/EU/store.csv\n  - data_entity: locale-store\n    source: data/import/acme/stores/DE/locale_store.csv\n  - data_entity: cms-block-store\n    source: data/import/acme/stores/DE/cms_block_store.csv\n");
$bash = static fn (string $cmd): array => ['tool_name' => 'Bash', 'tool_input' => ['command' => $cmd], 'cwd' => $proj, 'hook_event_name' => 'PreToolUse'];

// --- gate-docker-sdk: classification ---
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk console queue:worker:start --stop-when-empty'));
check_gate('gate hook: a non-mutating docker/sdk command → no opinion', $d === null);
[$d] = run_hook('gate-docker-sdk.php', $bash('php validate.php gate'));
check_gate('gate hook: unrelated command → no opinion', $d === null);
[$d] = run_hook('gate-docker-sdk.php', ['tool_name' => 'Bash', 'tool_input' => ['command' => 'docker/sdk reset'], 'cwd' => sys_get_temp_dir()]);
check_gate('gate hook: not a Spryker data project (no manifest) → no opinion', $d === null);

// --- gate-docker-sdk: clean data → ask, with the rebuild counter and blast radius ---
[$d, $r] = run_hook('gate-docker-sdk.php', $bash('script -q .ai-dev/reset.log docker/sdk reset'));
check_gate('gate hook: clean gate + reset (through script wrapper) → ask, in plain words', $d === 'ask' && str_contains($r, 'Rebuild #1') && str_contains($r, 'wipes the shop') && str_contains($r, 'say so in the chat'));
// a first-setup or demo clone has nothing to lose: the rebuild runs without a prompt
file_put_contents($proj . '/.ai-dev/demo-prep.md', "---\nanswers_source: brief\n---\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: demo clone, clean gate → rebuild runs without a prompt', $d === null);
@unlink($proj . '/.ai-dev/demo-prep.md');
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { purpose: project }\n---\n| # | step | status |\n|---|---|---|\n| 8 | boot-and-verify | pending |\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: first setup (boot-and-verify not done) → no prompt', $d === null);
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { purpose: project }\n---\n| # | step | status |\n|---|---|---|\n| 8 | boot-and-verify | done |\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: after the first boot is verified → asks again', $d === 'ask');
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { purpose: project }\nstanding_approval: { scope: rebuilds, granted: 2026-09-24T10:00:00Z }\n---\n| 8 | boot-and-verify | done |\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: a standing-approval line the person never said → still asks (the agent cannot self-grant)', $d === 'ask');
$grantT = $proj . '/grant.jsonl';
file_put_contents($grantT, json_encode(['type' => 'user', 'message' => ['content' => 'rebuilds are always OK on this project, no need to ask']]) . "\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset') + ['transcript_path' => $grantT]);
check_gate('gate hook: standing approval the person gave in their own words → no prompt', $d === null);
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { purpose: project }\nstanding_approval: { scope: rebuilds, granted: 2026-09-24T10:00:00Z } # never covers volume drops outside rebuilds\n---\n| 8 | boot-and-verify | done |\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset') + ['transcript_path' => $grantT]);
check_gate('gate hook: a `#` comment after the standing approval does not void it', $d === null);
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { purpose: project }\nstanding_approval: { scope: rebuilds, granted: 2026-09-24T10:00:00Z }\n---\n| 8 | boot-and-verify | done |\n");
foreach (['Never run rebuilds without asking me first.', 'I do not trust rebuilds on this box', 'Rebuilds must always ask me.'] as $no) {
    file_put_contents($grantT, json_encode(['type' => 'user', 'message' => ['content' => $no]]) . "\n");
    [$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset') + ['transcript_path' => $grantT]);
    check_gate("gate hook: a negated approval (\"{$no}\") → still asks", $d === 'ask');
}
file_put_contents($grantT, json_encode(['type' => 'user', 'message' => ['content' => 'rebuilds are always OK on this project, no need to ask']]) . "\n");
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { purpose: project }\nstanding_approval: none — rebuilds must always be confirmed\n---\n| 8 | boot-and-verify | done |\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset') + ['transcript_path' => $grantT]);
check_gate('gate hook: `standing_approval: none — rebuilds must always be confirmed` → still asks', $d === 'ask');
foreach (['scope: "rebuilds are always OK here"', '{ scope: [imports, rebuilds] }'] as $form) {
    file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { purpose: project }\nstanding_approval: {$form}\n---\n| 8 | boot-and-verify | done |\n");
    [$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset') + ['transcript_path' => $grantT]);
    check_gate("gate hook: standing approval written as {$form} → no prompt", $d === null);
}
file_put_contents($grantT, json_encode(['type' => 'user', 'message' => ['content' => 'Rebuilds without asking are not OK.']]) . "\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset') + ['transcript_path' => $grantT]);
check_gate('gate hook: a negation after the word "rebuilds" → still asks', $d === 'ask');
file_put_contents($grantT, json_encode(['type' => 'user', 'message' => ['content' => 'rebuilds are always OK on this project, no need to ask']]) . "\n");
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { purpose: project }\nstanding_approval: { scope: rebuilds, granted: 2026-09-24T10:00:00Z }\n---\n| 8 | boot-and-verify | done |\n");
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { purpose: demo }\n---\n| 8 | boot-and-verify | done |\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: purpose: demo in project-setup.md → no prompt even after the first boot', $d === null);
@unlink($proj . '/.ai-dev/project-setup.md');
check_gate('gate hook: writes .ai-dev/gate-last.json', is_file($proj . '/.ai-dev/gate-last.json'));
[$d, $r] = run_hook('gate-docker-sdk.php', $bash('docker/sdk up -t'));
check_gate('gate hook: clean gate + up → ask (rebuild)', $d === 'ask' && str_contains($r, 'docker/sdk up'));
[$d, $r] = run_hook('gate-docker-sdk.php', $bash('docker/sdk console data:import -c data/import/local/full_EU.yml'));
check_gate('gate hook: clean gate + data:import → allowed silently (rung 1 stays cheap)', $d === null);
[$d, $r] = run_hook('gate-docker-sdk.php', $bash('docker/sdk cli "vendor/bin/console data:import product-abstract"'));
check_gate('gate hook: data:import via docker/sdk cli console → allowed silently', $d === null);

// --- rebuild counter (PostToolUse) ---
$post = static fn (string $cmd): array => ['tool_name' => 'Bash', 'tool_input' => ['command' => $cmd], 'tool_response' => ['stdout' => ''], 'cwd' => $proj, 'hook_event_name' => 'PostToolUse'];
run_hook('count-rebuild.php', $post('docker/sdk console data:import'));
check('count hook: data:import does not count', !is_file($proj . '/.ai-dev/rebuild-count'));
run_hook('count-rebuild.php', $post('docker/sdk reset'));
run_hook('count-rebuild.php', $post('script -q .ai-dev/boot.log docker/sdk up -t'));
check('count hook: two rebuilds counted + logged', trim((string) file_get_contents($proj . '/.ai-dev/rebuild-count')) === '2' && substr_count((string) file_get_contents($proj . '/.ai-dev/rebuild-log'), "\n") === 2);
[$d, $r] = run_hook('gate-docker-sdk.php', $bash('docker/sdk clean-data'));
check_gate('gate hook: third rebuild → deny carries the "more than two" warning', $d === 'deny' && str_contains($r, 'Rebuild #3') && str_contains($r, 'already rebuilt 2 times') && str_contains($r, 'decision-log'));
file_put_contents($proj . '/.ai-dev/demo-prep.md', "---\nanswers_source: brief\n---\n");
[$d, $r] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: even on a demo clone, the third rebuild of a step is denied until justified', $d === 'deny' && str_contains($r, 'Rebuild #3'));
@unlink($proj . '/.ai-dev/demo-prep.md');

// --- gate-docker-sdk: broken data, no baseline → deny with findings; with baseline → deny ---
file_put_contents($proj . '/data/import/acme/stores/DE/cms_block_store.csv', "block_key,store_name\nb1,DE\nb2,UK\n");
[$d, $r] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: findings without baseline → deny, names the finding', $d === 'deny' && str_contains($r, 'no baseline') && str_contains($r, 'undeclaredStore'));
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk console data:import'));
check_gate('gate hook: findings without baseline → data:import still allowed', $d === null);
file_put_contents($proj . '/.ai-dev/preflight-baseline.json', '{"check":"preflight","urlDuplicates":[]}');
[$d, $r] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: findings with a baseline → DENY, names the finding and the re-run command', $d === 'deny' && str_contains($r, 'Blocked `') && str_contains($r, 'undeclaredStore') && str_contains($r, 'validate.php gate'));
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk console data:import'));
check_gate('gate hook: findings with a baseline → data:import denied too', $d === 'deny');
$gateReport = shell_exec($php . ' ' . escapeshellarg($pluginRoot . '/skills/spryker-import-tools/scripts/validate.php') . ' gate --base ' . escapeshellarg($proj));
file_put_contents($proj . '/.ai-dev/gate-baseline.json', (string) $gateReport);
[$d, $r] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: third rebuild with no justification written → deny, asks for a decision-log line', $d === 'deny' && str_contains($r, 'rebuild #3'));
file_put_contents($proj . '/.ai-dev/decision-log.md', "- rebuild #3: the removed categories need a DB drop (rung 3)\n");
[$d] = run_hook('gate-docker-sdk.php', $bash('docker/sdk reset'));
check_gate('gate hook: justified third rebuild on a project with real data (no demo, no first setup) → the one remaining question', $d === 'ask');
@unlink($proj . '/.ai-dev/decision-log.md');
@unlink($proj . '/.ai-dev/gate-baseline.json');

// --- guard-files: installed skill copies, counter, state file ---
$edit = static fn (string $path, string $new, string $tool = 'Edit', array $extra = []): array => ['tool_name' => $tool, 'tool_input' => array_merge(['file_path' => $path, 'old_string' => 'x', 'new_string' => $new, 'content' => $new], $extra), 'cwd' => $proj, 'hook_event_name' => 'PreToolUse'];
[$d, $r] = run_hook('guard-files.php', $edit('.claude/skills/project-data/SKILL.md', 'patched'));
check_gate('guard: installed plugin skill copy → deny, points at the skill-improvement log', $d === 'deny' && str_contains($r, 'skill-improvement-log'));
[$d] = run_hook('guard-files.php', $edit($proj . '/.claude/skills/project-data/references/generate.md', 'patched', 'Write'));
check_gate('guard: absolute path into an installed skill copy → deny', $d === 'deny');
[$d] = run_hook('guard-files.php', $edit('.claude/skills/demo-runtime-smoke/SKILL.md', 'ok'));
check('guard: a project-owned skill (not shipped by the plugin) → allowed', $d === null);
@mkdir($proj . '/src/Pyz/Zed', 0777, true);
file_put_contents($proj . '/src/Pyz/Zed/Foo.php', '<?php');
[$d] = run_hook('guard-files.php', $edit('src/Pyz/Zed/Foo.php', 'ok'));
check('guard: an existing source file → no opinion', $d === null);
[$d] = run_hook('guard-files.php', $edit('src/Pyz/Zed/Brandnew.php', '<?php', 'Write'));
check('guard: a NEW project PHP class → deny (work-class boundary)', $d === 'deny');
[$d] = run_hook('guard-files.php', $edit('.ai-dev/rebuild-count', '0', 'Write'));
check('guard: rebuild counter → deny', $d === 'deny');

$transcript = $proj . '/transcript.jsonl';
$ask = static fn (array $questions): string => json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'AskUserQuestion', 'input' => ['questions' => array_map(static fn ($q) => ['question' => $q, 'header' => 'x', 'options' => []], $questions)]]]]]) . "\n";
$userMsg = static fn (string $text): string => json_encode(['type' => 'user', 'message' => ['content' => $text]]) . "\n";
$state = "---\nversion: 1\nrun_mode: autonomous\nanswers_source: interview\n---\n## Steps\n| step | status | note |\n|---|---|---|\n| define-stores | pending | |\n";
$stateQ = str_replace('interview', 'questionnaire+interview', $state);
file_put_contents($transcript, $userMsg("We need to setup a project: Store United Kingdom (GB), English, EUR, brand red, replace the demo catalogue…") . $ask(['Run mode?', 'Hard-stops acknowledged?', 'Logo and brand red source?']));
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', $stateQ, 'Write') + ['transcript_path' => $transcript]);
check('guard: prose brief + 3 questions + `questionnaire+interview` claimed → deny (a brief is not a questionnaire)', $d === 'deny' && str_contains($r, 'not collected') && str_contains($r, 'confirm'));
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', $state, 'Write') + ['transcript_path' => $transcript]);
check('guard: same transcript claiming `interview` → deny too (the claim is irrelevant, the evidence counts)', $d === 'deny');
file_put_contents($transcript, $ask(['Project name?', 'Namespace?', 'Stores?']) . $ask(['Data mode?', 'CI plan?']));
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', $state, 'Write') + ['transcript_path' => $transcript]);
check('guard: 5 questions, no confirmation → deny, names only the confirmation', $d === 'deny' && !str_contains($r, 'not collected') && str_contains($r, 'confirmation'));
file_put_contents($transcript, $ask(['Project name?', 'Namespace?', 'Stores?']) . $ask(['Data mode?', 'CI plan?']) . $ask(['Please confirm the resolved answer set below:']));
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', $state, 'Write') + ['transcript_path' => $transcript]);
check('guard: 5 questions + confirmation → allowed', $d === null);
file_put_contents($transcript, $userMsg("here are my answers:\nP1: acme\nR1: autonomous\nR2: yes\nT1: GB") . $ask(['Confirm the resolved set (P1 acme, stores GB …)?']));
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', $stateQ, 'Write') + ['transcript_path' => $transcript]);
check('guard: filled questionnaire (ID-tagged lines) + confirmation → allowed', $d === null);
file_put_contents($transcript, $userMsg("Base directory for this skill: /x\nP1: name\nR1: mode\nR2: ack\nT1: stores") . $ask(['Confirm?']));
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', $stateQ, 'Write') + ['transcript_path' => $transcript]);
check('guard: question IDs inside a loaded skill body are not developer answers → deny', $d === 'deny');
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', $state, 'Write') + ['transcript_path' => $proj . '/none.jsonl']);
check('guard: no transcript at all → deny (no evidence)', $d === 'deny');
file_put_contents($proj . '/.ai-dev/project-setup.md', $state);

[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| define-stores | done | store CSVs written |'));
check_gate('guard: define-stores → done while the gate fails → deny with findings', $d === 'deny' && str_contains($r, 'undeclaredStore'));
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| define-stores | in-progress: stores pending | |'));
check('guard: in-progress status is never gated', $d === null);
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| project-ci-generator | done | 7 jobs kept |'));
check('guard: an ungated step → allowed', $d === null);
file_put_contents($proj . '/data/import/acme/stores/DE/cms_block_store.csv', "block_key,store_name\nb1,DE\n");
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| define-stores | done | store CSVs written |'));
check('guard: define-stores → done with a clean gate → allowed', $d === null);
file_put_contents($proj . '/data/import/acme/orphan.csv', "a\n1\n");
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| project-data | done | consolidated |'));
check_gate('guard: project-data → done is STRICT (an orphan file denies)', $d === 'deny' && str_contains($r, 'orphan-files'));
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| define-stores | done | |'));
check('guard: define-stores → done is not strict (orphan file tolerated)', $d === null);
@unlink($proj . '/data/import/acme/orphan.csv');
$verifierReport = "# Verifier report\n\n| AC | verdict | evidence |\n|---|---|---|\n| storefront per store | PASS | /EN/en 200, /DE/en 200 |\n| add to cart | PASS | quote persisted, 2 items |\n| back office | BLOCKED | no credentials in this session |\n| glue token | PASS | data.attributes.accessToken present |\n";
$verifierTranscript = $proj . '/vt.jsonl';
file_put_contents($verifierTranscript, json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Agent', 'input' => ['subagent_type' => 'spryker-verifier', 'prompt' => 'verify']]]]]) . "\n");
file_put_contents($proj . '/.ai-dev/verifier-report.md', $verifierReport);
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| boot-and-verify | done (browser ACs BLOCKED — /etc/hosts declined) | |') + ['transcript_path' => $verifierTranscript]);
check('guard: boot-and-verify → done with a clean strict gate → allowed', $d === null);
file_put_contents($proj . '/.ai-dev/project-setup.md', str_replace('| define-stores | pending | |', '| define-stores | done | |', $state));
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| define-stores | done | note updated |'));
check('guard: a row already done → editing its note is not a flip', $d === null);

check_gate('hooks.log: every ask/deny is recorded for the operator', is_file($proj . '/.ai-dev/hooks.log') && substr_count((string) file_get_contents($proj . '/.ai-dev/hooks.log'), ' deny — ') >= 5 && str_contains((string) file_get_contents($proj . '/.ai-dev/hooks.log'), 'gate-docker-sdk.php ask'));

// --- guard-bash: shell writes, scripts, git add sweeps ---
$bashT = static fn (string $cmd, ?string $transcript = null): array => ['tool_name' => 'Bash', 'tool_input' => ['command' => $cmd], 'cwd' => $proj, 'hook_event_name' => 'PreToolUse'] + ($transcript !== null ? ['transcript_path' => $transcript] : []);
file_put_contents($proj . '/composer.json', '{}');
[$d, $r] = run_hook('guard-bash.php', $bashT('php validate.php gate > .ai-dev/gate-baseline.json'));
check('bash guard: `>` redirect into a project file → deny, names --save', $d === 'deny' && str_contains($r, '--save'));
[$d] = run_hook('guard-bash.php', $bashT("cat > data/import/acme/x.csv <<'EOF'\na,b\nEOF"));
check('bash guard: heredoc into a project file → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('sed -i "" "s/a/b/" data/import/acme/stores/EU/store.csv'));
check('bash guard: sed -i on a project file → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('php x.php | tee .ai-dev/out.json'));
check('bash guard: tee into the project → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('docker/sdk console queue:worker:start 2>&1'));
check('bash guard: `2>&1` is not a file write → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('php validate.php gate > /dev/null'));
check('bash guard: redirect to /dev/null → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('php validate.php gate > /tmp/x.json'));
check('bash guard: redirect to a temp dir → allowed', $d === null);
[$d, $r] = run_hook('guard-bash.php', $bashT("python3 - <<'EOF'\nimport csv\nopen('out.csv','w').write('a')\nEOF"));
check('bash guard: a heredoc that WRITES a file → deny, names csv.php append/add-row', $d === 'deny' && str_contains($r, 'append'));
[$d] = run_hook('guard-bash.php', $bashT("python3 - <<'EOF'\nopen('/tmp/out.csv','w').write('a')\nEOF"));
check('bash guard: a heredoc that writes only outside the project (absolute /tmp path) → no prompt', $d === null);
[$d] = run_hook('guard-bash.php', $bashT("cd /private/tmp/scratchpad && python3 - <<'EOF'\nimport re,json\ns=open('pdp.html').read()\nopen('pdp.rsc','w').write(s)\nEOF"));
check('bash guard: cd to a scratchpad outside the project, then a script writing there → no prompt', $d === null);
[$d] = run_hook('guard-bash.php', $bashT("cd data && python3 - <<'EOF'\nopen('x.txt','w').write('a')\nEOF"));
check('bash guard: cd INTO the project, then a script writing → still deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT("python3 - <<'EOF'\np=sys.argv[1]\nopen(p,'w').write('a')\nEOF"));
check('bash guard: a write target that cannot be read literally → still deny', $d === 'deny');
[$d, $r] = run_hook('guard-bash.php', $bashT("python3 - <<'EOF'\np='.ai-dev/demo-prep.md'\ns=open(p).read()\nopen(p,'w').write(s.replace('| 6 | rehearsal | pending |','| 6 | rehearsal | done |'))\nEOF"));
check('bash guard: a script editing .ai-dev/demo-prep.md → deny (it would skip the done-gates)', $d === 'deny' && str_contains($r, 'demo-prep.md'));
[$d] = run_hook('guard-bash.php', $bashT("python3 - <<'EOF'\nopen('.ai-dev/rehearsal.md','w').write('pass')\nEOF"));
check('bash guard: a script writing .ai-dev/rehearsal.md evidence → deny', $d === 'deny');
// --- rm: routine deletions in the project go through; only what git cannot restore asks ---
$sessT = $proj . '/session.jsonl';
file_put_contents($sessT, json_encode(['type' => 'user', 'timestamp' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 60), 'message' => ['content' => 'prepare the demo']]) . "\n");
@mkdir($proj . '/data/import/common/AT', 0777, true);
file_put_contents($proj . '/data/import/common/AT/store.csv', "name\nAT\n");
[$d] = run_hook('guard-bash.php', $bashT('rm -rf data/import/common/AT data/import/local/full_US.yml', $sessT));
check('bash guard: rm of demo data this session created under data/import → allowed without a prompt', $d === 'allow');
file_put_contents($proj . '/data/import/customer_products.csv', "sku\n1\n");
touch($proj . '/data/import/customer_products.csv', time() - 86400);
[$d] = run_hook('guard-bash.php', $bashT('rm data/import/customer_products.csv', $sessT));
check('bash guard: rm of an untracked file that was there before the session → deny', $d === 'deny');
@unlink($proj . '/data/import/customer_products.csv');
[$d] = run_hook('guard-bash.php', $bashT('cd ~ && rm -rf Documents', $sessT));
check('bash guard: `cd ~ && rm` → the target resolves outside the project → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('cd .. && rm -rf other-project', $sessT));
check('bash guard: `cd .. && rm` → outside the project → deny', $d === 'deny');
[$d, $r] = run_hook('guard-bash.php', $bashT('rm -rf /etc/hosts.bak'));
check('bash guard: rm outside the project → denies', $d === 'deny' && str_contains($r, 'outside the project') && str_contains($r, 'final report'));
[$d, $r] = run_hook('guard-bash.php', $bashT('rm -rf src'));
check('bash guard: rm of a whole top-level folder → denies', $d === 'deny' && str_contains($r, 'top-level'));
[$d] = run_hook('guard-bash.php', $bashT('rm -rf .git'));
check('bash guard: rm of .git → denies', $d === 'deny');
file_put_contents($proj . '/my-notes.txt', 'mine');
[$d, $r] = run_hook('guard-bash.php', $bashT('rm my-notes.txt'));
check('bash guard: rm of a root file git does not track → denies', $d === 'deny' && str_contains($r, 'not in git'));
[$d] = run_hook('guard-bash.php', $bashT('rm -rf data/import/*'));
check('bash guard: rm with a wildcard → denies', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('rm -rf data/import/x && echo done'));
check('bash guard: compound command whose rm is recoverable → allowed', $d === 'allow');
[$d] = run_hook('guard-bash.php', $bashT('rm -rf data/import/x; git clean -fdx'));
check('bash guard: a recoverable rm does not approve the rest of the line (git clean) → no allow', $d !== 'allow');
[$d] = run_hook('guard-bash.php', $bashT('rm -rf $DIR'));
check('bash guard: rm of a shell variable → deny (the target cannot be read)', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('rm data/import/{a,b}.csv'));
check('bash guard: rm with a brace list → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('(cd data/import && rm old.csv)'));
check('bash guard: rm inside a subshell → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT("cd ..\nrm -rf other-project"));
check('bash guard: cd and rm on separate lines → the cd is followed → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('command rm -rf /etc/hosts.bak'));
check('bash guard: `command rm` outside the project → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('/bin/rm -rf /etc/hosts.bak'));
check('bash guard: `/bin/rm` outside the project → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('cd /private/tmp/claude-harvest && python3 harvest.py > harvest.log 2>&1; tail -5 harvest.log'));
check('bash guard: a redirect after `cd` to a folder outside the project → no opinion', $d === null);
@mkdir($proj . '/data/cache/Yves/twig-x', 0777, true);
[$d, $r] = run_hook('guard-bash.php', $bashT('rm -rf src/Generated/Yves/Twig/codeBucket && find data/cache/Yves -mindepth 1 -maxdepth 1 -type d'));
check('bash guard: a read-only `find` next to an rm is not a wildcard delete', $d !== 'deny' || !str_contains($r, 'wildcard'));
[$d, $r] = run_hook('guard-bash.php', $bashT('rm -rf data/cache/Yves/dockerdev'));
check('bash guard: deleting data/cache/Yves/<env> (the DI container) → deny, names the Twig codeBucket', $d === 'deny' && str_contains($r, 'codeBucket'));
[$d, $r] = run_hook('guard-bash.php', $bashT("sed -i '' 's/  \\.x-tiles {/&/' src/Pyz/Yves/x.scss"));
check('bash guard: sed -i names the FILE it edits, not a word of its script', $d === 'deny' && str_contains($r, 'src/Pyz/Yves/x.scss'));
[$d] = run_hook('guard-bash.php', $bashT("rm data/import/local/tmp.yml; docker/sdk cli \"mariadb -e 'SELECT c.\\`key\\` FROM t;'\" | tail -5"));
check('bash guard: backticks inside a quoted SQL query are not a command substitution', $d !== 'deny');
[$d] = run_hook('guard-bash.php', $bashT('ls data && rm -rf /tmp/../etc'));
check('bash guard: compound command hiding an rm outside the project → denies', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('find . -name "*.bak" -delete'));
check('bash guard: find -delete → denies (targets cannot be named)', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('git ls-files | xargs rm'));
check('bash guard: xargs rm → denies', $d === 'deny');
@unlink($proj . '/data/import/common/AT/store.csv');
@rmdir($proj . '/data/import/common/AT');
@unlink($proj . '/my-notes.txt');

// --- browser JavaScript: local pages need no prompt, external ones keep it ---
$nav = static fn (string $url, string $tab = ''): string => json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'mcp__claude-in-chrome__navigate', 'input' => ['url' => $url] + ($tab !== '' ? ['tabId' => $tab] : [])]]]]) . "\n";
$js = static fn (string $transcript, string $tab = ''): array => ['tool_name' => 'mcp__claude-in-chrome__javascript_tool', 'tool_input' => ['action' => 'javascript_exec', 'text' => 'document.title'] + ($tab !== '' ? ['tabId' => $tab] : []), 'cwd' => $proj, 'transcript_path' => $transcript, 'hook_event_name' => 'PreToolUse'];
$bt = $proj . '/browser.jsonl';
file_put_contents($bt, $nav('http://yves.eu.spryker.local/DE/de'));
[$d] = run_hook('guard-browser.php', $js($bt));
check('browser guard: JavaScript on the local demo shop → allowed without a prompt', $d === 'allow');
file_put_contents($bt, $nav('http://yves.eu.spryker.local/DE/de') . $nav('https://www.example.com/'));
[$d] = run_hook('guard-browser.php', $js($bt));
check('browser guard: JavaScript after navigating to an external site → no opinion (auto mode decides)', $d === null);
file_put_contents($bt, $nav('https://www.example.com/', 't1') . $nav('http://localhost:8080/', 't2'));
[$d] = run_hook('guard-browser.php', $js($bt, 't1'));
check('browser guard: the tab id decides — tab 1 is external → no opinion', $d === null);
[$d] = run_hook('guard-browser.php', $js($bt, 't2'));
check('browser guard: tab 2 is local → allowed', $d === 'allow');
[$d] = run_hook('guard-browser.php', $js(''));
check('browser guard: page unknown (no transcript) → no opinion', $d === null);
$bi = static fn (string $tool, array $input, string $transcript = ''): array => ['tool_name' => $tool, 'tool_input' => $input, 'cwd' => $proj, 'transcript_path' => $transcript, 'hook_event_name' => 'PreToolUse'];
[$d, $r] = run_hook('guard-browser.php', $bi('mcp__Claude_Browser__navigate', ['url' => 'http://yves.eu.spryker.local/DE/de']));
check('browser guard: built-in browser navigating to the local shop → deny, use Claude in Chrome', $d === 'deny' && str_contains($r, 'claude-in-chrome'));
$biNav = static fn (string $url): string => json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'mcp__Claude_Browser__preview_start', 'input' => ['url' => $url]]]]]) . "\n";
file_put_contents($bt, $biNav('http://yves.eu.spryker.local/DE/de'));
[$d] = run_hook('guard-browser.php', $bi('mcp__Claude_Browser__computer', ['action' => 'screenshot'], $bt));
check('browser guard: built-in browser acting on a tab that shows the local shop → deny', $d === 'deny');
file_put_contents($bt, $biNav('https://docs.spryker.com/') . $nav('http://yves.eu.spryker.local/DE/de'));
[$d] = run_hook('guard-browser.php', $bi('mcp__Claude_Browser__computer', ['action' => 'screenshot'], $bt));
check('browser guard: built-in browser on docs while Chrome shows the local shop → no opinion', $d === null);
[$d] = run_hook('guard-browser.php', $bi('mcp__Claude_Browser__browser_batch', ['actions' => [['name' => 'navigate', 'input' => ['url' => 'http://yves.eu.spryker.local/']]]]));
check('browser guard: built-in browser_batch navigating to the local shop → deny', $d === 'deny');
[$d] = run_hook('guard-browser.php', $bi('mcp__Claude_Browser__navigate', ['url' => 'https://www.example.org/']));
check('browser guard: built-in browser on an external site (the reference) → no opinion', $d === null);
[$d] = run_hook('guard-browser.php', $bi('mcp__claude-in-chrome__javascript_tool', ['action' => 'javascript_exec', 'text' => '1'], $bt));
check('browser guard: Chrome JavaScript on the local shop → allowed', $d === 'allow');
[$d] = run_hook('guard-bash.php', $bashT("python3 - <<'EOF'\nimport json,sys\nprint(json.load(open('report.json'))['count'])\nEOF"));
check('bash guard: a READ-ONLY heredoc → allowed, no prompt', $d === null);
[$d] = run_hook('guard-bash.php', $bashT("php -r 'echo count(json_decode(file_get_contents(\"x.json\"), true));'"));
check('bash guard: a read-only php -r → allowed, no prompt', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('php -r \'echo 1;\''));
check('bash guard: a read-only php -r → allowed (only writers are asked about)', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('php .claude/skills/spryker-import-tools/scripts/csv.php append a.csv --from b.csv --in-place'));
check('bash guard: the named php tools are not scripts → allowed', $d === null);
$userSays = static function (string $text) use ($proj): string {
    $path = $proj . '/stage-' . md5($text) . '.jsonl';
    file_put_contents($path, json_encode(['type' => 'user', 'message' => ['content' => $text]]) . "\n");

    return $path;
};
[$d, $r] = run_hook('guard-bash.php', $bashT('git add data/import src config', $userSays('Build the demo from brief.md.')));
check('bash guard: `git add` the person did not ask for → deny, staging is their decision', $d === 'deny' && str_contains($r, "person's decision"));
[$d] = run_hook('guard-bash.php', $bashT('git add -A', $userSays('Build the demo from brief.md.')));
check('bash guard: `git add -A` the person did not ask for → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('git add src/Pyz/Yves/x.twig'));
check('bash guard: `git add` with no transcript → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('git add .claude/skills .ai-dev/demo-prep.md', $userSays('Please stage .claude/skills and .ai-dev/demo-prep.md.')));
check('bash guard: staging `.claude/` and `.ai-dev/` the person asked for → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('git add -A', $userSays('Stage everything and commit it.')));
check('bash guard: `git add -A` the person asked for → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('git add src', $userSays('Fix the header, but do not stage anything.')));
check('bash guard: "do not stage anything" → `git add` denied', $d === 'deny');
[$d, $r] = run_hook('guard-bash.php', $bashT('git rm .github/workflows/old.yml', $userSays('Generate the project CI.')));
check('bash guard: `git rm` the person did not ask for → deny, points at `rm`', $d === 'deny' && str_contains($r, 'with `rm`'));
[$d] = run_hook('guard-bash.php', $bashT('git rm --cached .ai-dev/run.log', $userSays('Generate the project CI.')));
check('bash guard: `git rm --cached` the person did not ask for → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('git mv a.yml b.yml', $userSays('Generate the project CI.')));
check('bash guard: `git mv` the person did not ask for → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('git rm .github/workflows/old.yml', $userSays('Remove the old workflow with git rm.')));
check('bash guard: `git rm` the person asked for → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('git commit -m x', $userSays('Fix the header, but do not commit anything.')));
check('bash guard: "do not commit anything" → `git commit` denied', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('git checkout -b feature/x', $userSays('Stay on this branch and fix the header.')));
check('bash guard: "stay on this branch" → `git checkout -b` denied', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('git checkout -b feature/x', $userSays('Create a branch feature/x for this.')));
check('bash guard: a branch the person asked for → allowed', $d === null);
[$d, $r] = run_hook('guard-bash.php', $bashT('rm .ai-dev/stale.md'));
check('bash guard: rm inside `.ai-dev` → deny, labelled as a tooling folder, not a top-level folder', $d === 'deny' && str_contains($r, 'inside `.git`, `.claude` or `.ai-dev`') && !str_contains($r, 'whole top-level folder'));
$gateT = $proj . '/gate-yes.jsonl';
$asst = static fn (string $text): string => json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => $text]]]]) . "\n";
$usr = static fn (string $text): string => json_encode(['type' => 'user', 'message' => ['content' => $text]]) . "\n";
file_put_contents($gateT, $usr('Add the delivery badge.') . $asst('3 of 3 ACs green. Stage and commit these files?') . $usr('yes'));
[$d] = run_hook('guard-bash.php', $bashT('git add src/Pyz/Yves/a.twig', $gateT));
check('bash guard: "yes" to the agent\'s "Stage and commit these files?" → `git add` allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('git commit -m x', $gateT));
check('bash guard: "yes" to the commit gate → `git commit` allowed', $d === null);
file_put_contents($gateT, $usr('Add the delivery badge.') . $asst('3 of 3 ACs green. Stage and commit these files?') . $usr('no, leave them'));
[$d] = run_hook('guard-bash.php', $bashT('git add src/Pyz/Yves/a.twig', $gateT));
check('bash guard: "no" to the commit gate → `git add` denied', $d === 'deny');
$askUse = json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'AskUserQuestion', 'input' => ['questions' => [['question' => 'Stage and commit these files?', 'options' => [['label' => 'Yes'], ['label' => 'No']]]]]]]]]) . "\n";
$askRes = json_encode(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'User has answered your questions: "Stage and commit these files?"="Yes".']]]]) . "\n";
file_put_contents($gateT, $usr('Add the delivery badge.') . $askUse . $askRes);
[$d] = run_hook('guard-bash.php', $bashT('git add src/Pyz/Yves/a.twig', $gateT));
check('bash guard: AskUserQuestion answered with the yes option for staging → `git add` allowed', $d === null);
file_put_contents($gateT, $usr('Add the delivery badge.') . $asst('Stage and commit these files?') . $usr('yes') . $usr('Now fix the footer.'));
[$d] = run_hook('guard-bash.php', $bashT('git add src/Pyz/Yves/a.twig', $gateT));
check('bash guard: a later instruction ends the confirmation → `git add` denied', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('git commit -m x', $userSays('Commit the header fix.')));
check('bash guard: a commit the person asked for → allowed', $d === null);
[$d] = run_hook('guard-bash.php', ['tool_name' => 'Bash', 'tool_input' => ['command' => 'git add -A'], 'cwd' => sys_get_temp_dir()]);
check('bash guard: not a project dir → no opinion', $d === null);

// --- the question rule: the developer asked, the agent must answer, not act ---
$assistantMsg = static fn (string $text): string => json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => $text]]]]) . "\n";
$qt = $proj . '/q.jsonl';
$userMsg2 = static fn (string $text): string => json_encode(['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => $text]]]]) . "\n";
file_put_contents($qt, $userMsg2('please rename the shared dir') . $userMsg2('why is data/import/acme/shared named that way?'));
[$d, $r] = run_hook('guard-bash.php', $bashT('mv data/import/acme/shared data/import/acme/commerce', $qt));
check('bash guard: mutating command after a developer question → deny, quotes the question', $d === 'deny' && str_contains($r, 'named that way'));
[$d] = run_hook('guard-bash.php', $bashT('ls data/import/acme', $qt));
check('bash guard: read-only command after a question → allowed', $d === null);
[$d] = run_hook('guard-files.php', $edit('data/import/acme/shared/x.csv', 'a,b') + ['transcript_path' => $qt]);
check('files guard: edit after a developer question → deny', $d === 'deny');
file_put_contents($qt, $userMsg2('what does this directory name mean?') . $userMsg2('ok rename it to commerce'));
[$d] = run_hook('guard-bash.php', $bashT('mv data/import/acme/shared data/import/acme/commerce', $qt));
check('bash guard: an instruction after the question → allowed', $d === null);
file_put_contents($qt, $userMsg2('please rename the shared dir') . $userMsg2('why is data/import/acme/shared named that way?') . $assistantMsg('It is called shared because those sources are used by every store. I would not rename it.'));
[$d] = run_hook('guard-bash.php', $bashT('mv data/import/acme/shared data/import/acme/commerce', $qt));
check('bash guard: the question was answered in prose → no longer pending, allowed', $d === null);
[$d] = run_hook('guard-files.php', $edit('data/import/acme/shared/x.csv', 'a,b') + ['transcript_path' => $qt]);
check('files guard: same, on the file guard', $d === null);
[$d] = run_hook('guard-files.php', $edit('data/import/acme/shared/x.csv', 'a,b') + ['transcript_path' => $qt]);
check('files guard: edit after an instruction → allowed', $d === null);
file_put_contents($qt, $userMsg2('what does this directory name mean?') . json_encode(['type' => 'user', 'message' => ['content' => 'Base directory for this skill: /x\n# Skill\nDo you want to?']]) . "\n" . json_encode(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'ok?']]]]) . "\n");
[$d] = run_hook('guard-bash.php', $bashT('rm -rf data/import/acme/shared', $qt));
check('question rule: skill bodies and tool results are not the developer → the question still counts', $d === 'deny');

// --- boot-and-verify → done requires the verifier report ---
file_put_contents($proj . '/.ai-dev/project-setup.md', $state);
@unlink($proj . '/.ai-dev/verifier-report.md');
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| boot-and-verify | done | all green |'));
check('guard: boot-and-verify → done without .ai-dev/verifier-report.md → deny', $d === 'deny' && str_contains($r, 'verifier-report.md'));
file_put_contents($proj . '/.ai-dev/verifier-report.md', $verifierReport);
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| boot-and-verify | done | all green |') + ['transcript_path' => $verifierTranscript]);
check('guard: boot-and-verify → done with a real report and a dispatched verifier → allowed', $d === null);
file_put_contents($proj . '/.ai-dev/verifier-report.md', '');
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| boot-and-verify | done | all green |') + ['transcript_path' => $verifierTranscript]);
check('guard: a zero-byte verifier-report (a bare `touch`) → deny', $d === 'deny' && str_contains($r, 'it is empty'));
file_put_contents($proj . '/.ai-dev/verifier-report.md', $verifierReport);
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| boot-and-verify | done | all green |') + ['transcript_path' => '']);
check('guard: a real report but no spryker-verifier dispatch in the transcript → deny', $d === 'deny' && str_contains($r, 'dispatched'));
file_put_contents($proj . '/vt-plugin.jsonl', json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Agent', 'input' => ['subagent_type' => 'spryker-ai-dev-sdk:spryker-verifier', 'prompt' => 'verify']]]]]) . "\n");
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| boot-and-verify | done | all green |') + ['transcript_path' => $proj . '/vt-plugin.jsonl']);
check('guard: a plugin-prefixed verifier dispatch (`spryker-ai-dev-sdk:spryker-verifier`) counts', $d === null);
file_put_contents($proj . '/.ai-dev/rebuild-log', "2099-01-01 reset\n");
touch($proj . '/.ai-dev/rebuild-log', time() + 60);
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', '| boot-and-verify | done | all green |') + ['transcript_path' => $verifierTranscript]);
check('guard: a verifier report older than the last rebuild → deny (evidence expires)', $d === 'deny' && str_contains($r, 'predates the last rebuild'));
@unlink($proj . '/.ai-dev/rebuild-log');

// --- `>` inside quotes is data, not a redirect ---
[$d] = run_hook('guard-bash.php', $bashT("grep -oE 'href=\"/EN/en/[^\"]*-316[0-9]+\"' page.html"));
check('bash guard: `>`-free quoted regex with `\"` → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT("docker exec broker rabbitmqadmin list queues | awk -F'|' 'NF>2 && \$3+0>0 {print \$2}'"));
check('bash guard: `>` inside an awk program → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT("php validate.php gate | php -r '\$j=json_decode(stream_get_contents(STDIN),true); echo \$j[\"status\"];'"));
check('bash guard: `>`-shaped text inside a php -r payload → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('echo hi > "data/import/acme/with space.csv"'));
check('bash guard: a quoted redirect TARGET is still a write → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('docker/sdk cli "rm -rf /data/src/Generated/Yves/Twig/codeBucket"'));
check('bash guard: a container-side path is not judged by the host guard → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('docker/sdk cli "rm -rf /data/data/cache/Yves/dockerdev"'));
check('bash guard: deleting the DI container inside the container → deny too', $d === 'deny');

// --- appending to the run's own notes is what they are for ---
[$d] = run_hook('guard-bash.php', $bashT("cat >> .ai-dev/skill-improvement-log.md <<'EOF'\n## 1 — x\nEOF"));
check('bash guard: appending to the skill-improvement log → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('php x.php >> .ai-dev/run.log'));
check('bash guard: appending to the run log → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT("cat > .ai-dev/skill-improvement-log.md <<'EOF'\nx\nEOF"));
check('bash guard: TRUNCATING the skill-improvement log → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT("cat >> .ai-dev/project-setup.md <<'EOF'\n| x | done | |\nEOF"));
check('bash guard: appending to the guarded state file → deny', $d === 'deny');
[$d, $r] = run_hook('guard-bash.php', $bashT("python3 - <<'PY'\np='.ai-dev/project-setup.md'\ns=open(p).read()\nopen(p,'w').write(s)\nPY"));
check('bash guard: a script rewriting the state file → deny (it bypasses the Edit guard)', $d === 'deny' && str_contains($r, 'project-setup.md'));

// --- a redirect target built from a shell variable ---
[$d] = run_hook('guard-bash.php', $bashT('P=/private/tmp/scratch && curl -s -o $P/home.html http://yves.local/'));
check('bash guard: `$P/...` resolving to a temp dir → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('D=data/import/acme && printf "a,b\n" >> $D/product.csv'));
check('bash guard: `$D/...` resolving into the import tree → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('curl -s -o $UNRESOLVED/x.html http://yves.local/'));
check('bash guard: an unresolvable variable target → no opinion', $d === null);

// --- SQL and key-value writes, git commit/branch, scripts, cache paths, overwrites, baselines ---

[$d, $r] = run_hook('guard-bash.php', $bashT('docker/sdk cli "mariadb -u spryker -e \"UPDATE spy_payment_method SET is_active = 0\""'));
check('bash guard: UPDATE through mariadb → deny, names the read model', $d === 'deny' && str_contains($r, 'read model'));
[$d] = run_hook('guard-bash.php', $bashT('docker/sdk cli "mariadb -e \"DELETE FROM spy_product_configuration\""'));
check('bash guard: DELETE FROM through mariadb → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT('docker/sdk cli "mariadb -e \"SELECT COUNT(*) FROM spy_product\""'));
check('bash guard: SELECT through mariadb → allowed', $d === null);

[$d, $r] = run_hook('guard-bash.php', $bashT('docker/sdk cli "redis-cli -n 1 DEL kv:product_abstract:1"'));
check('bash guard: redis-cli DEL → deny, names publish:trigger-events', $d === 'deny' && str_contains($r, 'publish:trigger-events'));
[$d] = run_hook('guard-bash.php', $bashT('docker/sdk cli "redis-cli --scan --pattern \"kv:translation:*\""'));
check('bash guard: redis-cli scan → allowed', $d === null);

$ct = $proj . '/commit.jsonl';
file_put_contents($ct, $userMsg2('fix the navigation please'));
[$d, $r] = run_hook('guard-bash.php', $bashT('git commit -q -m "wip"', $ct));
check('bash guard: unrequested git commit → deny', $d === 'deny' && str_contains($r, 'person'));
file_put_contents($ct, $userMsg2('please commit what we have'));
[$d] = run_hook('guard-bash.php', $bashT('git commit -q -m "wip"', $ct));
check('bash guard: git commit the developer asked for → allowed', $d === null);
file_put_contents($ct, $userMsg2('carry on'));
[$d] = run_hook('guard-bash.php', $bashT('git checkout -b feature/x', $ct));
check('bash guard: unrequested branch → deny', $d === 'deny');
file_put_contents($ct, $userMsg2('create a branch please'));
[$d] = run_hook('guard-bash.php', $bashT('git checkout -b feature/x', $ct));
check('bash guard: branch the developer asked for → allowed', $d === null);
$custT = $proj . '/cust.jsonl';
file_put_contents($custT, json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Skill', 'input' => ['skill' => 'spryker-ai-dev-sdk:spryker-customization']]]]]) . "\n" . json_encode(['type' => 'user', 'message' => ['content' => 'add a quick view to the product tile']]) . "\n");
[$d] = run_hook('guard-bash.php', $bashT('git checkout -b ai-customize/quick-view', $custT));
check('bash guard: spryker-customization creating its own ai-customize/ branch → allowed', $d === null);
[$d] = run_hook('guard-bash.php', $bashT('git checkout -b feature/whatever', $custT));
check('bash guard: any other unrequested branch, even with the skill loaded → deny', $d === 'deny');

[$d, $r] = run_hook('guard-bash.php', $bashT("python3 - <<'PY'\nimport csv\nrows=list(csv.DictReader(open('data/import/acme/product.csv')))\nw=csv.DictWriter(open('data/import/acme/product.csv','w'),fieldnames=['a'])\nPY"));
check('bash guard: python heredoc writing data/import → deny, names csv.php', $d === 'deny' && str_contains($r, 'csv.php'));
[$d] = run_hook('guard-bash.php', $bashT("python3 - <<'PY'\nimport json,sys\nprint(json.load(sys.stdin))\nPY"));
check('bash guard: read-only python heredoc → allowed (only writers are asked about)', $d === null);
file_put_contents($proj . '/gen.py', "import csv\nf=open('data/import/acme/catalog.csv','w')\n");
[$d, $r] = run_hook('guard-bash.php', $bashT('python3 gen.py'));
check('bash guard: running a script FILE that writes project data → deny', $d === 'deny' && str_contains($r, 'data/import'));
file_put_contents($proj . '/read.py', "print(open('data/import/acme/catalog.csv').read())\n");
[$d] = run_hook('guard-bash.php', $bashT('python3 read.py'));
check('bash guard: running a read-only script file → allowed', $d === null);

@mkdir($proj . '/data/cache/Yves/dockerdev/pools', 0777, true);
[$d, $r] = run_hook('guard-bash.php', $bashT('rm -rf data/cache/Yves/dockerdev/twig'));
check('bash guard: rm of a cache dir that does not exist → deny, lists what does', $d === 'deny' && str_contains($r, 'pools'));
[$d] = run_hook('guard-bash.php', $bashT('rm -rf data/cache/Yves/dockerdev/pools'));
check('bash guard: rm of a cache dir that exists → allowed without a prompt', $d === 'allow');

@mkdir($proj . '/src/Pyz/Yves', 0777, true);
file_put_contents($proj . '/src/Pyz/Yves/a.twig', 'x');
file_put_contents($proj . '/src/Pyz/Yves/b.twig', 'y');
shell_exec('cd ' . escapeshellarg($proj) . ' && git init -q 2>/dev/null && git add src/Pyz/Yves/a.twig 2>/dev/null');
[$d, $r] = run_hook('guard-bash.php', $bashT('cp src/Pyz/Yves/b.twig src/Pyz/Yves/a.twig'));
check('bash guard: cp over a tracked file → deny', $d === 'deny' && str_contains($r, 'a.twig'));
file_put_contents($proj . '/.env.dist', "A=1\n");
shell_exec('cd ' . escapeshellarg($proj) . ' && git add .env.dist 2>/dev/null');
[$d] = run_hook('guard-bash.php', $bashT('cp src/Pyz/Yves/b.twig ./.env.dist'));
check('bash guard: cp over a tracked dot-file (`./.env.dist`) → deny', $d === 'deny');
@mkdir($proj . '/src/Pyz/Moved', 0777, true);
copy($proj . '/src/Pyz/Yves/a.twig', $proj . '/src/Pyz/Moved/a.twig');
[$d] = run_hook('guard-bash.php', $bashT('rm -rf src/Pyz/Moved'));
check('bash guard: rm of an untracked copy whose content git tracks (after `mv`/`cp -r`) → allowed', $d === 'allow');
file_put_contents($proj . '/src/Pyz/Moved/own.twig', 'only here');
[$d] = run_hook('guard-bash.php', $bashT('rm -rf src/Pyz/Moved'));
check('bash guard: the same folder with one file git does not know → deny', $d === 'deny');
@unlink($proj . '/src/Pyz/Moved/own.twig');
@unlink($proj . '/src/Pyz/Moved/a.twig');
@rmdir($proj . '/src/Pyz/Moved');
[$d] = run_hook('guard-bash.php', $bashT('cp src/Pyz/Yves/a.twig src/Pyz/Yves/c.twig'));
check('bash guard: cp to a new path → allowed', $d === null);

[$d, $r] = run_hook('guard-bash.php', $bashT('mv .ai-dev/gate-baseline.json .ai-dev/gate-baseline.step1.json'));
check('bash guard: moving the gate baseline → deny', $d === 'deny' && str_contains($r, 'before any change'));

[$d, $r] = run_hook('guard-bash.php', $bashT("php -r \"\\\$DI='data'.':'.'import'; echo \\\$DI;\""));
check('bash guard: a guarded token built by concatenation → deny', $d === 'deny' && str_contains($r, 'string pieces'));

$ot = $proj . '/own.jsonl';
file_put_contents($ot, $userMsg2('improve the description of the hero product') . $assistantMsg('It covers every product. If you would rather I limit it, say so — shall I apply the lot?'));
[$d, $r] = run_hook('guard-bash.php', $bashT('cp data/import/acme/x.csv data/import/acme/y.csv', $ot));
check('bash guard: mutation while the agent own question is unanswered → deny', $d === 'deny' && str_contains($r, 'unanswered'));
file_put_contents($ot, $userMsg2('improve the description of the hero product') . $assistantMsg('Shall I apply the lot?') . $userMsg2('yes, all of them'));
[$d] = run_hook('guard-bash.php', $bashT('cp data/import/acme/x.csv data/import/acme/y.csv', $ot));
check('bash guard: the question was answered → allowed', $d === null);
file_put_contents($proj . '/own2.jsonl', $userMsg2('go on') . $assistantMsg('Should I also rewrite the other locale?'));
[$d, $r] = run_hook('guard-files.php', $edit('data/import/acme/x.csv', 'a,b') + ['transcript_path' => $proj . '/own2.jsonl']);
check('files guard: edit while the agent own question is unanswered → deny', $d === 'deny' && str_contains($r, 'unanswered'));
file_put_contents($ot, $userMsg2('go on') . $assistantMsg('Store DE is configured. Does the hero render at phone width?'));
[$d] = run_hook('guard-bash.php', $bashT('cp data/import/acme/x.csv data/import/acme/y.csv', $ot));
check('bash guard: a rhetorical question to itself does not freeze the run', $d === null);
file_put_contents($ot, $userMsg2('Can you prepare the ACME demo from brief.md?'));
[$d] = run_hook('guard-bash.php', $bashT('cp data/import/acme/x.csv data/import/acme/y.csv', $ot));
check('bash guard: a request phrased as a question ("can you prepare…?") is an instruction', $d === null);
file_put_contents($ot, $userMsg2('Can you explain why the gate failed?'));
[$d] = run_hook('guard-bash.php', $bashT('cp data/import/acme/x.csv data/import/acme/y.csv', $ot));
check('bash guard: "can you explain…?" is still a question → deny', $d === 'deny');
[$d] = run_hook('guard-bash.php', $bashT("python3 - <<'EOF'\nimport json\nrows=open('data/import/acme/x.csv').read().splitlines()\njson.dump({'n':len(rows)}, open('/private/tmp/scratchpad/summary.json','w'))\nEOF"));
check('bash guard: a script that only READS project files and writes outside → no opinion', $d === null);
[$d] = run_hook('guard-bash.php', $bashT("python3 - <<'EOF'\nimport csv,sys,json\nw=csv.writer(sys.stdout)\nfor r in csv.reader(open('data/import/acme/x.csv')): w.writerow(r)\njson.dump({'ok':1}, sys.stdout)\nEOF"));
check('bash guard: a script writing only to stdout (csv.writer / json.dump on sys.stdout) → no opinion', $d === null);
file_put_contents($ot, $userMsg2('go on') . $assistantMsg('The installer stops at the prompt "Do you want to continue?"'));
[$d] = run_hook('guard-bash.php', $bashT('cp data/import/acme/x.csv data/import/acme/y.csv', $ot));
check('bash guard: a quoted tool prompt is not the agent asking the person', $d === null);

file_put_contents($proj . '/.gitignore', "public/Yves/assets/\n");
@mkdir($proj . '/public/Yves/assets', 0777, true);
file_put_contents($proj . '/public/Yves/assets/design-tokens.css', ':root{}');
[$d, $r] = run_hook('guard-files.php', $edit('public/Yves/assets/design-tokens.css', ':root{--x:1}'));
check('files guard: editing ignored build output → deny, says find the source', $d === 'deny' && str_contains($r, 'generated'));
[$d] = run_hook('guard-files.php', $edit('src/Pyz/Yves/a.twig', 'x2'));
check('files guard: editing a tracked source file → allowed', $d === null);

// --- guard-skill: a wizard step must not start on an unconfirmed answer set ---
$skillCall = static fn (string $name): array => ['tool_name' => 'Skill', 'tool_input' => ['skill' => $name], 'cwd' => $proj, 'hook_event_name' => 'PreToolUse'];
@unlink($proj . '/.ai-dev/project-setup.md');
[$d] = run_hook('guard-skill.php', $skillCall('project-ci-generator'));
check('skill guard: no state file → a standalone invocation is free', $d === null);
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { name: acme }\nanswers_source: interview\n---\n");
[$d, $r] = run_hook('guard-skill.php', $skillCall('project-ci-generator'));
check('skill guard: state file without a confirmation stamp → deny', $d === 'deny' && str_contains($r, 'interrupted interview'));
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { name: acme }\n---\n\n| step | status | notes |\n|---|---|---|\n| define-stores | done | ok |\n");
[$d] = run_hook('guard-skill.php', $skillCall('project-data'));
check('skill guard: a run already under way (a step is done) → allowed', $d === null);
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { name: acme }\n---\n\n| # | step | status | notes |\n|---|---|---|---|\n| 0 | wizard:interview | done | confirmed |\n| 1 | project-ci-generator | pending | |\n");
[$d] = run_hook('guard-skill.php', $skillCall('project-ci-generator'));
check('skill guard: only the interview row is done and no confirmation stamp → deny', $d === 'deny');
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { name: acme }\n---\n\n| step | status | notes |\n|---|---|---|\n| define-stores | done | ok |\n");
[$d] = run_hook('guard-skill.php', $skillCall('spryker-import-tools'));
check('skill guard: a non-step skill → no opinion', $d === null);
file_put_contents($proj . '/.ai-dev/project-setup.md', "---\nproject: { name: acme }\nanswers_confirmed_at: 2026-09-21T10:00:00Z\n---\n");
[$d] = run_hook('guard-skill.php', $skillCall('define-stores'));
check('skill guard: confirmed answer set → allowed', $d === null);

// --- the demo-prep wizard's state file carries the same contract ---
@unlink($proj . '/.ai-dev/project-setup.md');
file_put_contents($proj . '/.ai-dev/demo-prep.md', "---\ncustomer: acme\nanswers_source: brief\n---\n");
[$d, $r] = run_hook('guard-skill.php', $skillCall('project-data'));
check('skill guard: demo-prep.md without a confirmation stamp → deny', $d === 'deny' && str_contains($r, 'demo-prep.md'));
file_put_contents($proj . '/.ai-dev/demo-prep.md', "---\ncustomer: acme\nanswers_confirmed_at: 2026-09-22T09:00:00Z\n---\n");
[$d] = run_hook('guard-skill.php', $skillCall('project-data'));
check('skill guard: demo-prep.md confirmed → allowed', $d === null);
@unlink($proj . '/.ai-dev/demo-prep.md');
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/demo-prep.md', "---\nanswers_source: brief\n---\n", 'Write') + ['transcript_path' => $proj . '/none.jsonl']);
check('files guard: first write of demo-prep.md without a confirmed routing table → deny', $d === 'deny' && str_contains($r, 'routing table'));
$rt = $proj . '/routing.jsonl';
file_put_contents($rt, json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'AskUserQuestion', 'input' => ['questions' => [['question' => 'Confirm the routing table?', 'header' => 'Routing', 'options' => [['label' => 'Yes'], ['label' => 'Change']]]]]]]]]) . "\n");
[$d] = run_hook('guard-files.php', $edit('.ai-dev/demo-prep.md', "---\nanswers_source: brief\n---\n", 'Write') + ['transcript_path' => $rt]);
check('files guard: demo-prep.md after ONE routing confirmation → allowed (no interview threshold)', $d === null);
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', "---\nanswers_source: interview\n---\n", 'Write') + ['transcript_path' => $rt]);
check('files guard: project-setup.md still needs the interview, one confirmation is not enough', $d === 'deny');
file_put_contents($proj . '/.ai-dev/demo-prep.md', "---\nanswers_source: brief\nanswers_confirmed_at: 2026-09-24T10:00:00Z\nup_front:\n  shop_name: acme\n---\n");
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', "---\nanswers_source: demo-prep\nproject: { purpose: demo }\n---\n", 'Write') + ['transcript_path' => $proj . '/none.jsonl']);
check('files guard: routing confirmed but the one sitting not yet confirmed → deny', $d === 'deny');
file_put_contents($proj . '/.ai-dev/demo-prep.md', "---\nanswers_source: brief\nanswers_confirmed_at: 2026-09-24T10:00:00Z\nup_front_confirmed_at: 2026-09-24T10:05:00Z\nup_front:\n  shop_name: acme\n---\n");
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', "---\nanswers_source: demo-prep\nproject: { purpose: demo }\n---\n", 'Write') + ['transcript_path' => $proj . '/none.jsonl']);
check('files guard: project-setup.md from a confirmed demo-prep one sitting → allowed without re-asking', $d === null);
file_put_contents($proj . '/.ai-dev/demo-prep.md', "---\nanswers_source: brief\n---\n");
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', "---\nanswers_source: demo-prep\n---\n", 'Write') + ['transcript_path' => $proj . '/none.jsonl']);
check('files guard: answers_source: demo-prep without a confirmed demo-prep.md → deny', $d === 'deny');
@unlink($proj . '/.ai-dev/demo-prep.md');

// --- the demo gates: rehearsal needs a walked record, the run sheet needs the sheet ---
@unlink($proj . '/.ai-dev/rehearsal.md');
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/demo-prep.md', '| 6 | rehearsal | done | walked |'));
check('files guard: rehearsal → done with no .ai-dev/rehearsal.md → deny', $d === 'deny' && str_contains($r, 'rehearsal.md'));
file_put_contents($proj . '/.ai-dev/rehearsal.md', "# rehearsal walk — demo for acme\n\nbeat 1 \"open the homepage and show the seasonal story\" — done\nbeat 2 \"browse the catalogue and filter by size\" — done\nbeat 3 \"add to basket and check out\" — done, looked fine\n");
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/demo-prep.md', '| 6 | rehearsal | done | walked |'));
check('files guard: rehearsal record with no pass/gap verdicts → deny', $d === 'deny' && str_contains($r, 'rehearsal.md'));
file_put_contents($proj . '/.ai-dev/rehearsal.md', "# rehearsal walk — demo for acme\n\nbeat 1 \"open the homepage and show the seasonal story\" — pass (browser, buyer persona)\nbeat 2 \"browse the catalogue and filter by size\" — gap: the size facet narrows to an empty result\nbeat 3 \"add to basket and check out\" — pass (delegated to spryker-verifier, logged in)\n");
[$d] = run_hook('guard-files.php', $edit('.ai-dev/demo-prep.md', '| 6 | rehearsal | done | walked |'));
check('files guard: rehearsal → done with a walked pass/gap record → allowed', $d === null);
file_put_contents($proj . '/.ai-dev/rebuild-log', gmdate('c') . " reset\n");
touch($proj . '/.ai-dev/rehearsal.md', time() - 600);
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/demo-prep.md', '| 6 | rehearsal | done | walked |'));
check('files guard: rehearsal record older than the last rebuild → deny', $d === 'deny' && str_contains($r, 'rebuild'));
@unlink($proj . '/.ai-dev/rebuild-log');
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/demo-prep.md', '| 7 | demo-run-sheet | done | sent |'));
check('files guard: demo-run-sheet → done with no sheet on disk → deny', $d === 'deny' && str_contains($r, 'demo-run-sheet'));
file_put_contents($proj . '/.ai-dev/demo-run-sheet.md', "# run sheet — demo for acme\n\n1. open https://acme.local/ — the seasonal story, say the campaign line\n2. browse the catalogue, filter by size, land on the hero product\n3. sign in as buyer@example.com, add to basket, place the order\n");
[$d] = run_hook('guard-files.php', $edit('.ai-dev/demo-prep.md', '| 7 | demo-run-sheet | done | sent |'));
check('files guard: demo-run-sheet → done with the sheet on disk → allowed', $d === null);
@unlink($proj . '/.ai-dev/demo-run-sheet.md');
file_put_contents($proj . '/.ai-dev/demo-run-sheet.txt', "run sheet — demo for acme\n\n1. open the homepage — the seasonal story, say the campaign line\n2. browse the catalogue, filter by size, land on the hero product\n3. add to basket and check out as a guest shopper, no account needed\n");
[$d] = run_hook('guard-files.php', $edit('.ai-dev/demo-prep.md', '| 7 | demo-run-sheet | done | pasted in chat |'));
check('files guard: demo-run-sheet → done with a plain-text copy on disk → allowed', $d === null);
@unlink($proj . '/.ai-dev/demo-run-sheet.txt');
@unlink($proj . '/.ai-dev/rehearsal.md');

// --- the work-class boundary — new behaviour is customization ---
$ct2 = $proj . '/class.jsonl';
file_put_contents($ct2, $userMsg2('make the top image bigger please'));
[$d, $r] = run_hook('guard-files.php', $edit('src/Demo/Zed/Cart/Plugin/RemoveMerchantReferencePlugin.php', '<?php class X {}', 'Write') + ['transcript_path' => $ct2]);
check('files guard: new project PHP with no customization skill loaded → deny, names the class', $d === 'deny' && str_contains($r, 'how the shop behaves'));
[$d, $r] = run_hook('guard-bash.php', $bashT("cat > src/Demo/Zed/Cart/Plugin/NewPlugin.php <<'PHP'\n<?php\nPHP", $ct2));
check('bash guard: a heredoc creating project PHP → deny, names the work class', $d === 'deny' && str_contains($r, 'how the shop behaves'));
[$d, $r] = run_hook('guard-bash.php', $bashT("cat > src/Pyz/Yves/ShopUi/Theme/default/x.scss <<'S'\n.x{}\nS", $ct2));
check('bash guard: a heredoc creating a theme file → the ordinary shell-write deny, not the class one', $d === 'deny' && !str_contains($r, 'how the shop behaves'));
[$d, $r] = run_hook('guard-files.php', $edit('src/Pyz/Yves/ShopUi/Theme/default/components/molecules/hero/hero.scss', '.hero{}', 'Write') + ['transcript_path' => $ct2]);
check('files guard: a theme stylesheet is DESIGN, not behaviour — so the customization rule stays quiet and the design rule speaks',
    $d === 'deny' && !str_contains($r, 'how the shop behaves') && str_contains($r, 'yves-atomic-frontend'), (string) $r);
[$d] = run_hook('guard-files.php', $edit('data/import/acme/x.csv', 'a,b', 'Write') + ['transcript_path' => $ct2]);
check('files guard: an import row is data, not behaviour → allowed', $d === null);
$skillMsg = static fn (string $name): string => json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Skill', 'input' => ['skill' => $name]]]]]) . "\n";
file_put_contents($ct2, $userMsg2('build the B2C guest checkout') . $skillMsg('spryker-customization'));
[$d] = run_hook('guard-files.php', $edit('src/Demo/Zed/Cart/Plugin/RemoveMerchantReferencePlugin.php', '<?php class X {}', 'Write') + ['transcript_path' => $ct2]);
check('files guard: the same file after spryker-customization was loaded → allowed', $d === null);
@mkdir($proj . '/src/Demo/Zed/Cart/Plugin', 0777, true);
file_put_contents($proj . '/src/Demo/Zed/Cart/Plugin/Existing.php', '<?php');
file_put_contents($ct2, $userMsg2('fix the typo in that plugin'));
[$d] = run_hook('guard-files.php', $edit('src/Demo/Zed/Cart/Plugin/Existing.php', '<?php // fixed') + ['transcript_path' => $ct2]);
check('files guard: editing an EXISTING project PHP file is not a new behaviour class → allowed', $d === null);

// --- a keep-shipped namespace the codebase does not carry ---
@mkdir($proj . '/config/Shared', 0777, true);
file_put_contents($proj . '/config/Shared/config_default.php', "<?php\n\$config[KernelConstants::PROJECT_NAMESPACES] = [\n    'Demo',\n    'Pyz',\n];\n");
check('lib: project namespaces read in precedence order', hook_project_namespaces($proj) === ['Demo', 'Pyz']);
file_put_contents($proj . '/.ai-dev/project-setup.md', $state);
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', 'namespace: { mode: keep-shipped, name: Pyz }'));
check('files guard: keep-shipped Pyz on a clone that ships Demo first → deny, names the precedence', $d === 'deny' && str_contains($r, 'Demo, Pyz') && str_contains($r, 'not the one that resolves first'));
[$d, $r] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', 'namespace: { mode: keep-shipped, name: Ghost }'));
check('files guard: keep-shipped a namespace the clone does not ship at all → deny', $d === 'deny' && str_contains($r, 'does not ship'));
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', 'namespace: { mode: keep-shipped, name: Demo }'));
check('files guard: keep-shipped Demo on that clone → allowed', $d === null);
[$d] = run_hook('guard-files.php', $edit('.ai-dev/project-setup.md', 'namespace: { mode: custom, name: Acme }'));
check('files guard: an explicit custom namespace is the developer\'s choice → allowed', $d === null);

// --- guard-stop: an autonomous run does not end its turn with work outstanding ---
$stopPayload = static fn (string $msg, array $extra = []): array => array_merge(['hook_event_name' => 'Stop', 'cwd' => $proj, 'last_assistant_message' => $msg], $extra);
$stopDecision = static function (array $payload) use ($proj): array {
    [$d, $r, , ] = run_hook('guard-stop.php', $payload);
    return [$d, $r];
};
@unlink($proj . '/.ai-dev/stop-blocks.json');
@unlink($proj . '/.ai-dev/demo-prep.md');
@unlink($proj . '/.ai-dev/hooks.log');
$savedState = (string) @file_get_contents($proj . '/.ai-dev/project-setup.md');
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

// --- the steps-table row parser: numbered rows and qualified statuses ----------------
check('steps table: numbered row with a qualified status counts as done',
    hook_step_is_done('| 6 | project-data | done (pre-boot half; import proven at step 8) | x |', 'project-data'));
check('steps table: a parenthetical suffix on the step name still matches',
    hook_step_is_done('| 4 | match-reference-design (spec) | done | y |', 'match-reference-design'));
check('steps table: the two-column shape still matches', hook_step_is_done('| define-stores | done |', 'define-stores'));
check('steps table: in-progress is not done', !hook_step_is_done('| 8 | boot-and-verify | in-progress | z |', 'boot-and-verify'));
check('steps table: skipped counts as settled', hook_step_is_done('| 1 | project-ci-generator | skipped | purpose=demo |', 'project-ci-generator'));
check('steps table: a step not in the table is not done', !hook_step_is_done('| 1 | other | done |', 'project-data'));
check('steps table: a status that merely mentions "done" in the NOTE does not count',
    !hook_step_is_done('| 3 | project-data | pending | blocked until step 2 is done |', 'project-data'));
check('steps table: newly-done is done-now-and-not-before',
    hook_step_newly_done('| 6 | project-data | done |', '| 6 | project-data | pending |', 'project-data')
    && !hook_step_newly_done('| 6 | project-data | done |', '| 6 | project-data | done |', 'project-data'));

// --- sub-agent dispatch guard ------------------------------------------------------
$agent = static fn (string $type, string $prompt, string $desc = 'w'): array => [
    'hook_event_name' => 'PreToolUse', 'cwd' => $proj, 'tool_name' => 'Agent',
    'tool_input' => ['subagent_type' => $type, 'prompt' => $prompt, 'description' => $desc],
];
[$d, $r] = run_hook('guard-agent.php', $agent('general-purpose', 'Write a CSV mapping file with the Write tool, RFC4180 quoted.'));
check('agent guard: a worker told to write CSV without csv.php → deny', $d === 'deny' && str_contains($r, 'csv.php'));
[$d] = run_hook('guard-agent.php', $agent('general-purpose', 'Polish the checkout template spacing per yves-atomic-frontend; use the yves-atomic-frontend skill.'));
check('agent guard: "polish" the verb is not translation work', $d === null);
[$d] = run_hook('guard-agent.php', $agent('general-purpose', 'Write the Polish glossary rows for pl_PL.'));
check('agent guard: Polish the language is translation work → deny', $d === 'deny');
[$d] = run_hook('guard-agent.php', $agent('general-purpose', 'Write the rows with `php scripts/csv.php add-row` and verify with `csv.php count`.'));
check('agent guard: naming csv.php satisfies it', $d === null);
[$d, $r] = run_hook('guard-agent.php', $agent('spryker-verifier', 'Verify the project. Observe and report only — do NOT fix anything. Read data/import/ for expected counts.'));
check('agent guard: a read-only verifier that only READS data/import is not a CSV producer', $d === null, (string) $r);
[$d, $r] = run_hook('guard-agent.php', $agent('general-purpose', 'You translate English Spryker UI strings into POLISH (pl_PL). Return the mapping.'));
check('agent guard: translation dispatched to general-purpose → deny, naming translate-content', $d === 'deny' && str_contains($r, 'translate-content'));
[$d] = run_hook('guard-agent.php', $agent('general-purpose', 'Load the translate-content skill first, then translate these pl_PL strings.'));
check('agent guard: naming the owning skill satisfies it', $d === null);
[$d, $r] = run_hook('guard-agent.php', $agent('general-purpose', 'Modify config/Shared/config_default.php so the counter is higher.'));
check('agent guard: a project-writing worker with no work class named → deny', $d === 'deny' && str_contains($r, 'setup, data, design or customization'), (string) $r);
[$d] = run_hook('guard-agent.php', $agent('general-purpose', 'This is setup work. Modify config/Shared/config_default.php so the counter is higher.'));
check('agent guard: naming the work class satisfies it', $d === null);
[$d, $r] = run_hook('guard-agent.php', $agent('general-purpose', 'Edit src/Pyz/Yves/ShopUi/Theme/default/x.twig to fix the header.'));
check('agent guard: a twig dispatch is claimed by the design skill rule first', $d === 'deny' && str_contains($r, 'yves-atomic-frontend'));
[$d] = run_hook('guard-agent.php', $agent('general-purpose', 'Research how the demo payment methods are configured: read data/import payment_method.csv and payment_method_store.csv and report back.'));
check('agent guard: a research worker that reads CSV → no opinion', $d === null);
[$d] = run_hook('guard-agent.php', $agent('Explore', 'Find every CSV under data/import that mentions PayPal.'));
check('agent guard: an Explore agent (cannot write) → no opinion', $d === null);
[$d] = run_hook('guard-agent.php', $agent('general-purpose', 'Research the payment methods, then add rows to data/import payment_method.csv for BLIK.'));
check('agent guard: research that also writes CSV rows → deny, names csv.php', $d === 'deny');
[$d] = run_hook('guard-agent.php', $agent('general-purpose', 'Summarise the last three commits.'));
check('agent guard: an ordinary read-only dispatch is untouched', $d === null);

// --- design work needs a design skill and an evidenced sweep -----------------------
check('frontend: a theme twig is design work', hook_is_frontend_file('src/Pyz/Yves/ShopUi/Theme/default/components/molecules/x/x.twig'));
check('frontend: a theme scss is design work', hook_is_frontend_file('src/Demo/Yves/ProductWidget/Theme/default/a.scss'));
check('frontend: a Zed PHP class is not design work', !hook_is_frontend_file('src/Pyz/Zed/Cart/CartConfig.php'));
check('frontend: a data CSV is not design work', !hook_is_frontend_file('data/import/x.csv'));
@mkdir($proj . '/src/Pyz/Yves/ShopUi/Theme/default', 0777, true);
[$d, $r] = run_hook('guard-files.php', ['hook_event_name' => 'PreToolUse', 'cwd' => $proj, 'tool_name' => 'Edit', 'transcript_path' => '',
    'tool_input' => ['file_path' => $proj . '/src/Pyz/Yves/ShopUi/Theme/default/hero.twig', 'old_string' => 'a', 'new_string' => 'b']]);
check('guard: a theme twig with no design skill loaded → deny', $d === 'deny' && str_contains($r, 'yves-atomic-frontend'));

check('design gaps: an empty sweep is all surfaces missing', hook_design_gaps('') === hook_design_surfaces());
$sweepEvidence = ['header' => 'logo measured 40x40', 'dropdown' => 'every dropdown opened, `mega-menu.twig`',
    'hero' => '16/9, 760px', 'homepage' => '8 blocks at /EN/en', 'plp' => '/EN/en/category 200',
    'pdp' => '/EN/en/product-1 200', 'cart' => '/EN/en/cart 200, 2 lines', 'checkout' => '/EN/en/checkout 302 to login',
    'search' => '/EN/en/search?q=example 200, 12 hits', 'footer' => 'brand logo #293043', 'mobile' => '390px, no h-scroll'];
$sweep = '';
foreach (hook_design_surfaces() as $sfc) {
    $sweep .= '| ' . $sfc . ' | ' . ($sweepEvidence[$sfc] ?? ($sfc . ' measured 100x100')) . " |\n";
}
check('design gaps: an evidenced sweep of every surface has no gaps', hook_design_gaps($sweep) === [], implode(',', hook_design_gaps($sweep)));
check('design gaps: "checked" without evidence does not count', in_array('footer', hook_design_gaps(str_replace('brand logo #293043', 'checked', $sweep)), true));
$backticks = '';
foreach (hook_design_surfaces() as $sfc) { $backticks .= "| {$sfc} | `ok` |\n"; }
check('design gaps: a row of `ok` per surface is not a sweep', hook_design_gaps($backticks) === hook_design_surfaces());
check('design gaps: a sweep that says NOT checked is not a sweep', hook_design_gaps(str_replace('`ok`', 'NOT checked, `broken`', $backticks)) === hook_design_surfaces());
check('design gaps: every surface on one line with one token is not a sweep', hook_design_gaps(implode(' ', hook_design_surfaces()) . ' `x`') === hook_design_surfaces());
check('design gaps: the SAME evidence repeated on every row is not a sweep', count(hook_design_gaps(preg_replace('~\| [^|]+ \|$~m', '| 100x100 |', $sweep) ?? '')) > 8);
check('frontend: a Shared CmsBlock template is design work',
    hook_is_frontend_file('src/Pyz/Shared/CmsBlock/Theme/default/template/jumbotron_block.twig'));
check('frontend: the shop_ui configuration carries the palette and logo, so it is design work',
    hook_is_frontend_file('data/configuration/shop_ui.configuration.yml'));

$fe = $proj . '/fe.jsonl';
file_put_contents($fe, json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Edit',
    'input' => ['file_path' => $proj . '/src/Pyz/Shared/CmsBlock/Theme/default/template/jumbotron_block.twig']]]]]) . "\n");
$designDone = static fn (string $step): array => ['hook_event_name' => 'PreToolUse', 'cwd' => $proj, 'tool_name' => 'Write', 'transcript_path' => $fe,
    'tool_input' => ['file_path' => $proj . '/.ai-dev/project-setup.md', 'content' => "run_mode: autonomous\n\n| # | step | status |\n|---|---|---|\n| 8 | {$step} | done |\n"]];
@unlink($proj . '/.ai-dev/design-acceptance.md');
[$d, $r] = run_hook('guard-files.php', $designDone('boot-and-verify'));
check('guard: closing `boot-and-verify` after frontend edits, no sweep → deny',
    $d === 'deny' && str_contains($r, 'design-acceptance.md'), (string) $r);
[$d] = run_hook('guard-files.php', $designDone('match-reference-design'));
check('guard: the named design step is gated too', $d === 'deny');
file_put_contents($proj . '/.ai-dev/design-acceptance.md', $sweep);
[$d] = run_hook('guard-files.php', $designDone('match-reference-design'));
check('guard: a full evidenced sweep passes the design gate', $d !== 'deny', (string) $d);
[$d, $r] = run_hook('guard-files.php', ['hook_event_name' => 'PreToolUse', 'cwd' => $proj, 'tool_name' => 'Write', 'transcript_path' => '',
    'tool_input' => ['file_path' => $proj . '/.ai-dev/project-setup.md', 'content' => "run_mode: autonomous\n\n| # | step | status |\n|---|---|---|\n| 8 | match-reference-design | done |\n"]]);
check('guard: no frontend edit this session → the design gate stays out of the way', $d !== 'deny' || !str_contains((string) $r, 'design-acceptance'));
@unlink($proj . '/.ai-dev/design-acceptance.md');
@unlink($fe);

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

[$d, , , $exit] = run_hook('guard-files.php', ['tool_name' => 'Edit']);
check('guard: malformed payload → exit 0, no decision', $d === null && $exit === 0);
[$d, , , $exit] = run_hook('gate-docker-sdk.php', []);
check_gate('gate hook: empty payload → exit 0, no decision', $d === null && $exit === 0);

// --- outside a wizard run the hooks keep only the rules that protect every project -------------
$dev = sys_get_temp_dir() . '/hooks_dev_' . getmypid();
@mkdir($dev . '/data/import', 0777, true);
file_put_contents($dev . '/composer.json', '{}');
$GLOBALS['hookEnforce'] = false;
$devBash = static fn (string $cmd): array => ['tool_name' => 'Bash', 'tool_input' => ['command' => $cmd], 'cwd' => $dev, 'hook_event_name' => 'PreToolUse'];
[$d] = run_hook('guard-bash.php', $devBash('git commit -m "wip"'));
check('outside a run: an ordinary `git commit` → no opinion', $d === null);
[$d] = run_hook('guard-bash.php', $devBash('git add -A'));
check('outside a run: `git add -A` → no opinion', $d === null);
[$d] = run_hook('guard-bash.php', $devBash("sed -i '' 's/a/b/' src/Pyz/Yves/x.twig"));
check('outside a run: `sed -i` on a project file → no opinion', $d === null);
[$d] = run_hook('guard-bash.php', $devBash('rm -rf /etc/hosts.bak'));
check('outside a run: an rm git cannot undo is still denied', $d === 'deny');
[$d] = run_hook('guard-bash.php', $devBash('rm -rf data/cache/Yves/dockerdev'));
check('outside a run: deleting the DI container is still denied', $d === 'deny');
$devQ = $dev . '/q.jsonl';
file_put_contents($devQ, json_encode(['type' => 'user', 'message' => ['content' => 'why does the cart show 0 items?']]) . "\n");
[$d] = run_hook('guard-files.php', ['tool_name' => 'Edit', 'tool_input' => ['file_path' => $dev . '/src/Pyz/Yves/x.twig', 'old_string' => 'a', 'new_string' => 'b'], 'cwd' => $dev, 'transcript_path' => $devQ, 'hook_event_name' => 'PreToolUse']);
check('outside a run: an edit after a question, with no design skill loaded → no opinion', $d === null);
[$d] = run_hook('guard-agent.php', ['tool_name' => 'Agent', 'tool_input' => ['subagent_type' => 'general-purpose', 'prompt' => 'Translate the de_DE glossary rows into French and write the csv.'], 'cwd' => $dev, 'hook_event_name' => 'PreToolUse']);
check('outside a run: a sub-agent dispatch → no opinion', $d === null);
[$d] = run_hook('gate-docker-sdk.php', ['tool_name' => 'Bash', 'tool_input' => ['command' => 'docker/sdk reset'], 'cwd' => $dev, 'hook_event_name' => 'PreToolUse']);
check('outside a run: `docker/sdk reset` → no opinion', $d === null);
@mkdir($dev . '/.ai-dev', 0777, true);
file_put_contents($dev . '/.ai-dev/demo-prep.md', "---\nanswers_source: brief\n---\n");
[$d] = run_hook('guard-bash.php', $devBash('git commit -m "wip"'));
check('inside a run (a state file exists): an unrequested `git commit` → deny', $d === 'deny');
$commentEdit = static fn (string $path): array => ['tool_name' => 'Edit', 'tool_input' => ['file_path' => $dev . '/' . $path, 'old_string' => '$a = 1;', 'new_string' => "// use the default store\n\$a = 1;"], 'cwd' => $dev, 'hook_event_name' => 'PreToolUse'];
[$d, $r] = run_hook('guard-files.php', $commentEdit('src/Pyz/Zed/Cart/Business/CartReader.php'));
check('inside a run: an edit adding an explanatory comment → deny, points at naming', $d === 'deny' && str_contains($r, 'rename'));
unlink($dev . '/.ai-dev/demo-prep.md');
[$d] = run_hook('guard-files.php', $commentEdit('src/Pyz/Zed/Cart/Business/CartReader.php'));
check('outside a run: an edit adding a comment → no opinion', $d === null);
@mkdir($dev . '/.spryker-upgrade/state', 0777, true);
[$d] = run_hook('guard-files.php', $commentEdit('src/Pyz/Zed/Cart/Business/CartReader.php'));
check('during an upgrade: an edit adding an explanatory comment → deny', $d === 'deny');
[$d] = run_hook('guard-files.php', $commentEdit('src/Generated/Shared/Transfer/CartTransfer.php'));
check('during an upgrade: generated code → no opinion', $d === null);
[$d] = run_hook('guard-files.php', $commentEdit('docs/notes.md'));
check('during an upgrade: a markdown file → no opinion', $d === null);
[$d] = run_hook('guard-files.php', ['tool_name' => 'Edit', 'tool_input' => ['file_path' => $dev . '/src/Pyz/Zed/Cart/CartConfig.php', 'old_string' => 'x', 'new_string' => "/**\n * @return int\n */"], 'cwd' => $dev, 'hook_event_name' => 'PreToolUse']);
check('during an upgrade: a tag-only docblock → no opinion', $d === null);
$GLOBALS['hookEnforce'] = true;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dev, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($dev);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($proj, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $entry) {
    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($proj);

echo "\n{$count} checks, {$failures} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n";
exit($failures === 0 ? 0 : 1);
