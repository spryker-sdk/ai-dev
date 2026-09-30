# Hooks — the enforcement layer

These hooks enforce the plugin's run rules at the tool call that would break them. A call that breaks a
rule is denied, and the reason says what to do instead.

**When they apply.** The run discipline below applies while a wizard state file —
`.ai-dev/project-setup.md` or `.ai-dev/demo-prep.md` — exists in the project, including after the run finishes (or `SPRYKER_AI_DEV_ENFORCE=1`
is set). The first write of a state file is checked too, because it starts the run. Outside a run,
everyday work is left alone and only these rules stay on everywhere: the deletion guard (an `rm` git
cannot undo, and deleting the DI container under `data/cache/<Application>/<env>`), installed plugin
skill copies and `.ai-dev/rebuild-count` / `.ai-dev/rebuild-log` are read-only, and the built-in browser
is steered to Claude in Chrome for local hosts. The comment guard applies during a wizard run and during a
`spryker-upgrade` run (the project has `.spryker-upgrade/state/`).

| hook | event | what it does |
|---|---|---|
| `gate-docker-sdk.php` | `PreToolUse` / Bash | Before `docker/sdk reset\|clean-data\|up` or `console data:import`: runs `validate.php gate`. Gating finding + a baseline on file → **deny** (the findings are in the reason and in `.ai-dev/gate-last.json`). Rebuilds: findings without a baseline → **deny**, telling the agent to fix its own findings or capture the baseline on the untouched clone; clean → **no prompt** during first setup (the `boot-and-verify` step not yet done), on a demo clone, or under a `standing_approval: { scope: rebuilds }` that the person gave in their own words (the state-file line alone is ignored, because the agent can write it; a negated sentence such as "never rebuild without asking" grants nothing). On a project that already carries real data it **asks**, in plain words with the rebuild number and what it wipes — the only question any hook puts to the person. From the third rebuild on a project it **denies** until the decision log carries a `rebuild #N: <why>` line. `docker/sdk up --assets` (without `--build`/`--data`) is an asset build and is neither gated nor counted. `data:import` (and its `data:import:<type>` forms) is rung 1 of the reset ladder and is never asked — only denied when the gate fails against a baseline. |
| `guard-files.php` | `PreToolUse` / Edit, Write, MultiEdit | Denies edits to installed plugin skill copies (`.claude/skills/<plugin skill>/…` and the other harness dirs) and to `.ai-dev/rebuild-count` / `.ai-dev/rebuild-log`. On a wizard state file (`.ai-dev/project-setup.md`, or `.ai-dev/demo-prep.md` for `demo-prep-wizard` — same contract): denies flipping `define-stores` / `project-data` / `boot-and-verify` / `rehearsal` / `demo-run-sheet` to `done` while the gate fails (`--strict` for project-data, boot-and-verify and rehearsal); denies the first write of the state file (its `answers_source:` line) unless the transcript shows a filled questionnaire (ID-tagged answers) or the interview (≥5 questions asked) plus a confirmation question — whatever `answers_source` claims. For `.ai-dev/demo-prep.md` the evidence is the confirmed routing table alone: the demo itself is never asked about. A demo's `.ai-dev/project-setup.md` written with `answers_source: demo-prep` is accepted when `demo-prep.md` carries `up_front_confirmed_at`, because the demo-prep wizard asked and confirmed those answers in the same sitting. `demo-run-sheet` → `done` needs `.ai-dev/demo-run-sheet.html`, `.md` or `.txt` on disk. |
| `guard-bash.php` | `PreToolUse` / Bash | **Denies** shell writes into project files (`> path`, `>> path`, `tee path`, `sed -i path` — the Edit/Write guard cannot see them, and a `>` empties its target before the command runs), and any `git add`, `git rm` or `git mv` (each stages a change) unless the person's last message asks for staging or a commit, or answers yes to the agent's own stage/commit question (what to stage is their decision; once they ask, the form they ask for passes, `-A` and `.claude/` included). **Denies**, with instructions to use the Edit tool or `csv.php`, a heredoc- or inline-fed interpreter that writes **inside the project** (or to a path it cannot read); a script that writes only outside the project, such as to a scratchpad, runs without a prompt. |
| both PreToolUse guards | Bash, Edit, Write, MultiEdit | **Deny** any mutation while the person's last message is a question (ends with `?`) — it is answered first. A request phrased as a question ("can you prepare…?", "please…?") is an instruction and passes. |
| `guard-files.php` (comments) | `PreToolUse` / Edit, Write, MultiEdit | **Denies** an edit that adds explanatory comments to the project's own code and tests (`src/`, `config/`, `tests/`; `.php`, `.js`, `.ts`, `.twig`; not `src/Generated` or `src/Orm`) and tells the agent to rename or extract instead. Allowed: docblock tags, `{@inheritDoc}`, `Specification:` blocks, the license header, and an `upgrade-debt` docblock on a temporary shim. Suppressions (`@phpstan-ignore`, `phpcs:ignore`, `eslint-disable`, …) are denied too, and the reason says to fix the finding. Comments the file already had, or that an edit only moves, pass. |
| `guard-files.php` (verifier report) | `PreToolUse` / Edit, Write | `boot-and-verify → done` requires `.ai-dev/verifier-report.md` with a verdict line, written after the last rebuild, and a `spryker-verifier` dispatch in the session. |
| `guard-bash.php` | `PreToolUse` / Bash | **Denies** write-SQL through `mariadb`/`mysql`/`psql` and destructive `redis-cli` commands (reads stay free) — a direct write leaves the read model stale; `git commit` / `git checkout -b` the person did not ask for (a negated request such as "do not commit" counts as not asking) (the one exception: the `ai-customize/<slug>` branch the loaded `spryker-customization` skill creates for itself); an ad-hoc script whose write calls target project files or the guarded state files (reading project files and writing outside the project is allowed); **allows** a line of only `rm` / `cd` (plus harmless prints) whose every target git can restore, is ignored build output or `data/cache`, lies in the session scratchpad, or is a file under `data/import`, `config`, `src`, `frontend`, `public` or `tests` created during this session; **denies** a target it cannot (outside the project — `cd`, `command rm`, `/bin/rm` and newline-separated lines are followed —, anything inside `.git`, `.claude` or `.ai-dev`, a whole top-level folder, an untracked file that predates the session, a wildcard, a variable or brace list, a subshell, `find -delete`, `xargs rm`), telling the agent to leave it and list it in the final report. An `rm` inside a longer line approves nothing else in it. The permission settings allow `rm`, so this is the one gate. Also denied: `rm` of `data/cache/<Application>/<env>` (the Symfony DI container, not the Twig cache — the reason names `src/Generated/Yves/Twig/codeBucket`); `rm -rf data/cache/<x>` when `<x>` does not exist (it removes nothing and exits 0); `cp`/`mv` over a tracked file; moving or deleting a gate baseline; a guarded command assembled from string pieces. **Denies** (the agent waits, then retries) while a rebuild of this project is running, and while the agent's own question to the person (one that addresses them: "you", "shall I", "OK?") is unanswered. |
| `guard-files.php` | `PreToolUse` / Edit, Write | **Denies**, telling the agent what to do instead, when the file is matched by the project's ignore rules (generated output, which the next build overwrites), when a rebuild is running and the target is under `src/`, and when the agent's own question is unanswered. |
| `guard-skill.php` | `PreToolUse` / Skill | Denies a wizard **step** skill (`project-ci-generator`, `configure-codebase`, `configure-services`, `brand-project`, `define-stores`, `project-data`, `cypress-migration`, `boot-and-verify`, `translate-content`) while `.ai-dev/project-setup.md` or `.ai-dev/demo-prep.md` exists without an `answers_confirmed_at` stamp and no step has started yet; the recorded values count as suggestions until the person confirms them. Without a state file the skill runs freely (a standalone invocation, not a wizard run). |
| `guard-files.php` | `PreToolUse` / Edit, Write | **Denies** `rehearsal` → `done` without a walked `.ai-dev/rehearsal.md`, `demo-run-sheet` → `done` without the sheet on disk (`.html`, `.md` or `.txt`), and a design-closing step → `done`, when this session edited a storefront template or style, without an evidenced `.ai-dev/design-acceptance.md` (or with one older than the last rebuild or the newest template edit). **Denies** a `keep-shipped` namespace that is not the first one the codebase resolves. **Denies** a new project-namespace PHP class, or a theme template/stylesheet edit, until the owning skill is loaded — the agent loads it and retries. |
| `guard-agent.php` | `PreToolUse` / `Agent\|Task` | A writing sub-agent's prompt must name the tooling (`csv.php`), the SDK skill that owns the work, and which of the four work classes it is in — otherwise **deny**, and the agent re-dispatches with them. Read-only and research sub-agents (`Explore`, `Plan`, `claude-code-guide`, or a prompt that only reads) pass. Agent types are matched with or without the plugin prefix. |
| `guard-browser.php` | `PreToolUse` / browser tools | **Denies** the built-in browser (a `browser_batch` included) for a local host (`localhost`, loopback, `.local`, `.localhost` or `.test`) and sends the agent to Claude in Chrome — the built-in browser asks the person on every action for local hosts, a Claude app limitation. **Allows** Claude in Chrome's JavaScript tool on local hosts. External sites: no opinion. |
| `guard-stop.php` | `Stop` | An **autonomous** run does not end its turn with work outstanding. When a state file says `run_mode: autonomous` and its steps table still has a row that is not `done` or `skipped`, the stop is **refused** and the reason names the open steps, starting with the next one. Stops that pass: a turn that ends on a question only the person can answer (not "continue?" or "OK?"), a line starting with `NEEDS YOU:` or another wait-on-the-person marker, an `AskUserQuestion`, or a guard that asked in the last few minutes. Claude Code has no loop protection for Stop hooks, so this one carries its own — at most three consecutive refusals, and it lets go at once if the model repeats its closing message. |
| `count-rebuild.php` | `PostToolUse` / Bash | Increments `.ai-dev/rebuild-count` and appends `.ai-dev/rebuild-log` after every rebuild that ran. The count covers the whole project; the step report quotes it, and from the third rebuild the gate requires a decision-log line. |

All of them need host `php` (already a requirement of the CSV tools). Without it they exit 0 silently, so a
missing `php` never blocks a tool call. They locate `validate.php` via `CLAUDE_PLUGIN_ROOT`,
then relative to this directory, then `.claude/skills/spryker-import-tools/scripts/` (setup install).

## Plugin install

Nothing to do — `hooks.json` is picked up with the plugin.

## Setup install (skills copied into `.claude/skills`, no plugin)

Copy `*.php` to `.claude/hooks/spryker-ai-dev-sdk/` and merge the `hooks` and `permissions` blocks of
`settings.example.json` into the project's `.claude/settings.json`. **Also write
`.claude/hooks/spryker-ai-dev-sdk/plugin-skills.txt`** — one skill directory name per line. Without
it `hook_plugin_skills()` returns an empty list and the read-only-installed-skill rule matches
nothing. The `permissions` block is useful even with the plugin: it has no `ask` rules (a settings `ask`
outranks a hook `allow`, so the hooks decide instead), and it denies `sudo` / `docker volume rm` /
`docker system prune` / `git push`. Do not add a blanket `Bash(docker/sdk:*)` rule.

## Baseline

Capture once, on the untouched clone (the wizard does this in its pre-flight):

```bash
php <plugin>/skills/spryker-import-tools/scripts/validate.php gate --save .ai-dev/gate-baseline.json
```

`--save` writes the report after the run and refuses when the report could not run its checks.
Do not use `gate > file`: the shell empties the target before the gate runs, so the gate reads an empty
baseline and the saved report records errors instead of findings. The gate treats a baseline that
recorded errors as corrupt and reports a gating error that names the recapture command. Once a baseline exists, only new findings gate, across every check. Without a baseline a rebuild
on a failing gate is denied until the baseline is captured. Every ask/deny is appended to
`.ai-dev/hooks.log`.

## Test

```bash
php hooks/hooks.test.php
```
