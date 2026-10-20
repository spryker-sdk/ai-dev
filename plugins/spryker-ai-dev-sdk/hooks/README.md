# Hooks

The plugin ships two hooks: `scripts/sync-rules.sh` on session start, and `guard-stop.php` on the
end-of-turn event.

| hook | event | what it does |
|---|---|---|
| `scripts/sync-rules.sh` | `SessionStart` / `sessionStart` | Offers, once per project, to install the Spryker coding rules as path-scoped rule files. It writes no rule files itself: it states the offer, stamps `.ai-dev/rules.suggested` so the offer is never repeated, and leaves the decision to the person. Installing is a separate call — `sync-rules.sh --target <project> --harness <claude\|copilot>`, which the agent runs on a yes, or the `ai-dev-setup` rules-only flow. Silent once the rules are installed, outside a Spryker project, in CI, and when `.ai-dev/no-rules-sync` exists. |
| `guard-stop.php` | `Stop` / `agentStop` | An **autonomous** run does not end its turn with work outstanding. When a state file says `run_mode: autonomous` and its steps table still has a row that is not `done` or `skipped`, the stop is **refused** and the reason names the open steps, starting with the next one. Stops that pass: a turn that ends on a question only the person can answer (not "continue?" or "OK?"), a line starting with `NEEDS YOU:` or another wait-on-the-person marker, or a structured question. Neither harness has loop protection for this event, so the hook carries its own — at most three consecutive refusals, and it lets go at once if the closing message repeats. |

It acts only while a wizard state file (`.ai-dev/project-setup.md` or `.ai-dev/demo-prep.md`) says
`run_mode: autonomous`; in every other project and run mode it has no opinion. Each block and each
release is appended to `.ai-dev/hooks.log`. It needs host `php`; without it the hook exits 0 silently.

## Two hook files, because the harnesses resolve different ones

| file | read by | format |
|---|---|---|
| `hooks/hooks.json` | Claude Code | `Stop` and `SessionStart`, `command` key, `timeout` in seconds |
| `hooks.json` (plugin root) | GitHub Copilot | `version: 1`, `agentStop` and `sessionStart`, `bash` key, `timeoutSec` |

Copilot looks for `plugin.json`'s own `hooks` field, then the plugin root's `hooks.json`, then
`hooks/hooks.json`, and the first file that exists **and parses** wins — so the root file shadows
`hooks/hooks.json` for Copilot entirely and must carry every hook. Claude Code reads only
`hooks/hooks.json`. Keep the two in step: a hook added to one belongs in the other.

`guard-stop.php` handles both. It reads the harness off the payload's key casing (Claude Code sends
`session_id` / `transcript_path`, Copilot `sessionId` / `transcriptPath`), reads the closing message
from the payload under Claude Code and from the transcript under Copilot (which does not send one),
and emits the decision in both shapes. The exit code is the one real difference: Claude Code treats
exit 2 as the block, while Copilot discards the output of any hook that exits non-zero, so a Copilot
block exits 0.

**Windows.** Both hooks run through `bash`. Spryker on Windows runs in WSL2, where that is
available; native Windows needs Git Bash. There is deliberately no `powershell` entry: Copilot
prefers it over `bash` on Windows, so it would replace the working path rather than add one.

## Plugin install

Nothing to do — both hook files are picked up with the plugin. The `permissions` block of
`settings.example.json` is still worth merging into the project's harness settings file (Claude Code:
`.claude/settings.json`): it has no
`ask` rules and denies `sudo` / `docker volume rm` / `docker system prune` / `git push`. Do not add a
blanket `Bash(docker/sdk:*)` rule.

## Setup install (skills copied into the harness's project skills folder, no plugin)

Copy `guard-stop.php` and `lib.php` into the harness's project hooks folder under a
`spryker-ai-dev-sdk/` subfolder (Claude Code: `.claude/hooks/spryker-ai-dev-sdk/`) and merge the
`hooks` and `permissions` blocks of `settings.example.json` into the harness settings file
(Claude Code: `.claude/settings.json`).

## Test

```bash
php hooks/hooks.test.php
```
