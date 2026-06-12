---
name: spryker-refresher
description: >
  Use this skill whenever code, schema, frontend, or config changes have just
  been applied and the system needs to be refreshed so the changes take effect.
  Triggers - "run post-change commands", "refresh after edits", "regenerate
  transfers", "rebuild the frontend", "clear caches after this change",
  "warm up the caches", "what do I need to run after changing X", "make
  these changes take effect", "post-change orchestration". Owns the
  file-pattern to Spryker console command mapping drawn from the project's
  install recipes. Runs the commands in dependency order and reports
  results. Never edits source code.
---

# Spryker Refresher

When invoked, this skill walks through the post-change command chain for a list of touched files. The main session executes the commands directly — no sub-agent spawn.

This skill doesn't edit code. It doesn't decide whether changes are *correct*. It makes them *active*: codegen runs, caches clear, frontends rebuild, autoloaders refresh, the right warmups run. That's it.

**Examples of jobs this skill handles:**

- *"I just touched a transfer XML — refresh."*
- *"I edited a schema XML — refresh."*
- *"I touched some Twig templates and a Yves controller — refresh."*
- *"I added a new plugin registration — refresh."*
- *"After this build, run the full post-change chain for everything we touched."*

## Knowledge Sources — discover, don't assume

- **Install recipes** under `config/install/*.yml` (and per-region subdirectories) — the canonical sequences of commands the project actually runs. Treat them as the source of truth for command spelling, flags, and ordering. Read them before improvising.
- **`docker/sdk console list`** — live source of truth for what commands actually exist in the running stack. Use when an install recipe references something but you need to confirm spelling/flags, or when the recipe doesn't cover the command you need.
- **`searchAlgoliaDocumentation`** (Spryker MCP) or `https://docs.spryker.com/` via WebFetch — fallback when a command's purpose isn't obvious.
- **Project state** — `Read` / `Grep`, plus `git status` and `git diff --name-only HEAD~1`, to derive the touched-files list when the caller didn't enumerate it.

Claude runs on the host, not in the container. **Use these two invocation forms — and only these:**

- **Spryker console commands → `docker/sdk console <command>`** (no `cli`, no `vendor/bin/console`). Example: `docker/sdk console cache:empty-all`. **Never** `docker/sdk cli console <cmd>` (wrong — that's a malformed concatenation, not a valid docker/sdk syntax) and **never** bare `vendor/bin/console <cmd>` (won't reach the container from the host).
- **Composer → `docker/sdk cli composer <args>`** (runs composer inside the container). Example: `docker/sdk cli composer dumpautoload --apcu`. **Never** bare `composer <args>` (assumes composer is installed on the host, which it may not be).

If you find yourself typing `docker/sdk cli console …`, `vendor/bin/console …`, or bare `composer …`, **stop** — you've picked the wrong form.

## Touched-file → command mapping

The commands listed below are verified to exist in this project (per `docker/sdk console list`). Always cross-check against the relevant install recipe for the **ordering and flags** this project actually uses — recipes are authoritative; the table just narrows the search.

| Touched files | Commands (in order) |
|---|---|
| `*.transfer.xml` | `transfer:generate` |
| `*.schema.xml` (project layer) | `propel:install` → `propel:diff` → `propel:migrate` |
| **Any** `.php` file change under a project namespace directory in `src/` (new OR edited — controllers, factories, plugins, models, overrides of Spryker core classes, etc.) | `docker/sdk cli composer dumpautoload --apcu` → `docker/sdk console cache:class-resolver:build` (rebuilds the resolver map so Spryker resolves to the project-layer class, not the vendor original). **Mandatory for overrides** — without this command, Spryker continues to load the vendor class and the override never takes effect. |
| Plugin registration changes in `*DependencyProvider.php` | (covered by the row above) **plus** `docker/sdk console cache:empty-all` (the DI container caches the plugin chain — must clear to pick up new registrations) |
| `config_default*.php` or other `config/` PHP | `cache:empty-all` |
| Yves Twig / JS / frontend assets | `frontend:yves:build` → `twig:cache:warmer` |
| Zed Twig | `twig:cache:warmer` |
| `navigation.xml` | `navigation:cache:remove` → `navigation:build-cache` |
| Glue / SAPI / BAPI route or `*RestApi*` plugin | `rest-api:remove-validation-cache` → `rest-api:build-request-validation-cache` |
| Router-related (controllers, route files) | `router:cache:warm-up` (Zed) and/or `router:cache:warm-up:backoffice`, `router:cache:warm-up:backend-gateway`, `router:cache:warm-up:merchant-portal` |
| OMS XML (`config/Zed/oms/*.xml`) | `oms:process-cache:warm-up` (and `cache:empty-all` if other state cleared) |
| Glossary CSV | `data:import:glossary` |
| Search mapping / source changes | `search:source-map:remove` → `search:setup:source-map` → `search:setup:sources` (queue worker restart may also be needed) |
| Publisher plugin / queue config | queue worker restart — project-specific; document, don't auto-run |
| Data import CSVs (entity-specific) | `data:import:<entity>` (verify the entity importer exists in `docker/sdk console list`) |
| Stale Zed Twig (rare; symptom: BO still shows old template after a clean refresh) | `rm -f src/Generated/Zed/Twig/codeBucket/.pathCache` → `twig:cache:warmer` |

If the AC requires running a command not listed above, verify it exists via `docker/sdk console list` before invoking. **Never invent commands.**

## Approach

1. **Receive change context** — explicit file list from the caller is best. If only a verbal description, derive the list via `git diff --name-only HEAD~1` or `git status`. If you can't figure out what changed, stop and ask the caller.
2. **Classify each touched file** against the mapping above. Group commands by what they target (codegen, schema, autoload, cache clear, cache warmup, frontend build, data import).
3. **Determine order:**
   - `docker/sdk cli composer dumpautoload --apcu` first if any `.php` was added/moved.
   - Codegen next (transfers, scope-collection, etc.).
   - Schema (propel) next.
   - Cache clears (only if needed — `cache:empty-all` is heavy).
   - Frontend builds.
   - Cache warmups last.
4. **Run each command separately via Bash** so you can capture exit code and tail of output per step. Use `docker/sdk console <command>` for everything Spryker, `docker/sdk cli composer …` for autoload regeneration.
5. **Stop on the first non-zero exit code.** Don't continue past a failure. Report what ran, what failed, and the tail of the failed command's output. The caller decides whether to retry or hand off to `spryker-issue-diagnoser`.
6. **Report concisely.** No log dump — just exit codes, brief output highlights, and any caveats (e.g. *"queue workers not restarted — caller should run that manually."*).

## Output Format

```
## Refresh Report

### Inputs
- Files touched: <list>  (or "<derived from git status>")
- Change classes detected: <e.g. "transfer", "schema", "frontend">

### Plan
1. <command 1> — <why>
2. <command 2> — <why>

### Execution
| # | Command | Exit | Notes |
|---|---------|------|-------|
| 1 | `docker/sdk cli composer dumpautoload --apcu` | 0 | regenerated classmap (in container) |
| 2 | `docker/sdk console <discovered command from install recipe>` | 0 | |
| 3 | `docker/sdk console <next discovered command>` | 0 | |

### Failures (if any)
- Command: <full command>
- Exit: <code>
- Tail of output:
  ```
  <last ~20 lines>
  ```
- Suggested next step: hand to `spryker-issue-diagnoser`

### Caveats (manual steps the caller should consider)
- Queue workers may need restart if publisher plugins changed.
- Storefront cache may need browser hard-refresh.
```

## What you do NOT do

- Do not edit source files. Read-only on code, write-only on caches/builds via console commands.
- Do not run destructive commands — anything from a `destructive*.yml` recipe, or any command that drops, deletes, truncates, or wipes data, schemas, indices, queues, or storage. Full demoshop resets (e.g. `docker/sdk reset`) are out of scope.
- Do not run `docker/sdk reset` itself.
- Do not skip dependents after a failure. Stop, report, let the caller decide.
- Do not run the entire install chain when only a subset is needed. Be surgical.
- Do not run commands across multiple stores / regions without the caller specifying which one.
- Do not skip the install-recipe read. The mapping in this prompt is a starting point; the recipes are the source of truth for this project's command ordering and flags.
- Do not prepend `cd /absolute/path && ...` to any `Bash` command. The harness already runs every `Bash` invocation in the project root — `cd` shifts the command to a different allowlist pattern and causes permission prompts.
- **Do not omit `cache:class-resolver:build` after any `.php` change under a project namespace directory in `src/`.** This is the most common refresh defect: the override file lands in the project layer, but Spryker keeps resolving to the vendor class because the resolver map wasn't rebuilt. Self-correction signal: if your file list contains any `src/<Namespace>/**/*.php` (new or edited) and your command plan doesn't include `docker/sdk console cache:class-resolver:build`, stop and add it before reporting.
