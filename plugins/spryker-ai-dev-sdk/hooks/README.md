# Hooks

The plugin ships one hook, `guard-stop.php`, on the `Stop` event.

| hook | event | what it does |
|---|---|---|
| `guard-stop.php` | `Stop` | An **autonomous** run does not end its turn with work outstanding. When a state file says `run_mode: autonomous` and its steps table still has a row that is not `done` or `skipped`, the stop is **refused** and the reason names the open steps, starting with the next one. Stops that pass: a turn that ends on a question only the person can answer (not "continue?" or "OK?"), a line starting with `NEEDS YOU:` or another wait-on-the-person marker, or an `AskUserQuestion`. Claude Code has no loop protection for Stop hooks, so this one carries its own — at most three consecutive refusals, and it lets go at once if the model repeats its closing message. |

It acts only while a wizard state file (`.ai-dev/project-setup.md` or `.ai-dev/demo-prep.md`) says
`run_mode: autonomous`; in every other project and run mode it has no opinion. Each block and each
release is appended to `.ai-dev/hooks.log`. It needs host `php`; without it the hook exits 0 silently.

## Plugin install

Nothing to do — `hooks.json` is picked up with the plugin. The `permissions` block of
`settings.example.json` is still worth merging into the project's `.claude/settings.json`: it has no
`ask` rules and denies `sudo` / `docker volume rm` / `docker system prune` / `git push`. Do not add a
blanket `Bash(docker/sdk:*)` rule.

## Setup install (skills copied into `.claude/skills`, no plugin)

Copy `guard-stop.php` and `lib.php` to `.claude/hooks/spryker-ai-dev-sdk/` and merge the `hooks` and
`permissions` blocks of `settings.example.json` into the project's `.claude/settings.json`.

## Test

```bash
php hooks/hooks.test.php
```
