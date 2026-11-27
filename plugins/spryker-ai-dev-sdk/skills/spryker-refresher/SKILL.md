---
name: spryker-refresher
description: "Runs the console commands that make changed code, schema, frontend or config take effect, in dependency order. Use after edits: 'regenerate transfers', 'rebuild the frontend'."
---

# Spryker Refresher

When invoked, this skill runs the post-change command chain for a list of touched files. The main session executes the commands directly — no sub-agent spawn.

This skill doesn't edit code. It doesn't decide whether changes are *correct*. It makes them *active*: codegen runs, caches clear, frontends rebuild, autoloaders refresh, the right warmups run.

**Examples of jobs this skill handles:**

- *"I just touched a transfer XML — refresh."*
- *"I edited a schema XML — refresh."*
- *"I touched some Twig templates and a Yves controller — refresh."*
- *"I added a new plugin registration — refresh."*
- *"After this build, run the full post-change chain for everything we touched."*

**Locations.** `$RF` is `<skill-dir>/scripts`, where `<skill-dir>` is the path reported as `Base directory for this skill:` when this skill loaded. Substitute it inline as a literal path; never set a shell variable and never `cd`. Run the script from the project root: it writes only under `.ai-dev/refresher/` in the project.

## The two calls

1. **Plan.** `php $RF/refresh.php plan [<file>...]`
   - Files: the caller's list is best. Name each one as a path, or with its status: `A:<path>`, `M:<path>`, `D:<path>`, `R:<old>-><new>`. Without files the script takes the working tree and untracked files against `HEAD` (`--base <ref>` for another base, for example `--base HEAD~1` after a commit). When neither gives the list, ask.
   - Status rule: a bare path is `A` when the base commit does not have it, `D` when it is not on disk, `M` when it differs from the base commit. A bare path that is in the base commit and has no diff gets no command: the plan names it under "Needs a decision" (status unknown). After a commit, name each file with its status, or plan with `--base <the commit before the change>`.
   - `--symptom <name>` without files plans the symptom steps only; it does not read git.
   - It prints the commands in run order, each with the file and the mapping row that caused it, and writes `.ai-dev/refresher/plan.json`. `php $RF/refresh.php table` prints the mapping.
2. **Run.** `php $RF/refresh.php run` runs the plan. It prints the plan's creation time, file count and step count first, then one line per command (number, command, exit code, log file under `.ai-dev/refresher/logs/`). It stops at the first non-zero exit and prints the last 20 lines of that log. It runs only the commands of the mapping. At its end it repeats the plan's open decisions (an open decision means its step did not run) and the count of the manual items.
   - `propel:install` and the frontend builds can run longer than the default timeout of a shell call. Run `run` in the background, or with a long timeout (10 minutes), or step by step with `--only <n>`.
   - `--from <n>` resumes at step `n`; `--only <n>[,<n>]` and `--skip <n>[,<n>]` select steps. A step number outside the plan gives exit 2.
   - With `--from` or `--only`, a first step whose log has no `exit` line stops the run with exit 2: that step may still run. Wait for it to end. Add `--force` only when it no longer runs.

## Reading the plan

- **Needs a decision** — the plan holds these steps back until a flag gives the fact. Plan again with it:
  - a file with status unknown, and a `composer.lock` with `0 package(s) changed`: name the status, or plan with `--base <ref>`;
  - the stores of a DMS project (`--store <STORE>`, once per store). If the caller has not given the store or region, ask — a store-less queue or publish command in DMS is a silent no-op (banner, exit 0, nothing processed), not a default;
  - the applications a route belongs to (`--router zed|backoffice|backend-gateway|merchant-portal`);
  - a changed module's `package.json`: name the changed `vendor/<package>/**/package.json` in the file list, so `frontend:project:install-dependencies` runs first;
  - a missing deploy file, a Glue application the deploy file does not declare, or API Platform resources without a resolved Glue application (`--deploy-file <active deploy file>`);
  - a CSV the script has no diff for, and a CSV whose file name is no plain entity (run its importer by hand).
- **Manual** — the skill never runs these. Put each into the report's caveats.
- **Skipped** — a recipe step for a Glue application the project does not have: report it as `skipped (no such Glue application)`.
- **Not found** — a path that is neither on disk nor in the base commit. Nothing is planned for it: check the path, or name a deleted file as `D:<path>`.
- **No row matches** — the file needs no command. If a command not in the table seems needed, confirm it exists in `docker/sdk console list` first, and run it by hand as `docker/sdk console <command>` (Glue: `docker/sdk cli "vendor/bin/glue <command>"`; on macOS wrap a non-interactive call in `script -q /dev/null …`).

## What needs judgement

- **Install recipes** under `config/install/*.yml` are authoritative for this project's command spelling, flags and order. Read them. If a recipe orders the commands differently from the plan, run the plan's steps in the recipe's order with `--only <n>`. If a recipe spells a command or its flags differently, skip that step with `--skip <n>` and run the recipe's form by hand: `docker/sdk console <command> <flags>` (Glue: `docker/sdk cli "vendor/bin/glue <command>"`). Several data imports run in the order of the `data/import/**/*.yml` file that lists the most of them, else in the order of the files given; the plan names the source. Exception: the recipe never decides which Glue applications exist — `config/install/docker.yml` lists steps for `GLUE_STOREFRONT`, `GLUE_BACKEND` and the default application regardless of what the project runs. The script resolves them from `public/Glue*/index.php` and the deploy file.
- **`docker/sdk console list`** is the live source of what commands exist; `searchAlgoliaDocumentation` (Spryker MCP) or `https://docs.spryker.com/` via the web fetch tool is the fallback when a command's purpose is not obvious.
- **A stale template symptom.** A Yves template fix "does nothing" — the `.twig` is correct on disk AND in the container (`docker/sdk cli "cat /data/<path>"` shows the new markup), yet the page is the old one: plan with `--symptom yves-twig-stale` (and the file, when it is a new override) BEFORE concluding the change had no effect and writing a second fix; `up --assets` does not clear it. A Back Office template still stale after a clean refresh: `--symptom zed-twig-stale`.
- **Never delete `data/cache/Yves/<env>`** — that is the Symfony DI container, not the Twig cache; removing it mid-request gives transient 500s ("Failed opening required …/Container…"). The one case for clearing it is a stale container after generated-code changes (`../boot-and-verify/references/verify-gates.md`, "Class not found" triage).
- **Failure signature:** `ERR_MODULE_NOT_FOUND: Cannot find package '<x>'` from `vendor/spryker-shop/shop-ui/.../FrontendBuilder` during a frontend build is stale or incomplete frontend dependencies, not a code defect. Report it as a **pre-existing environment issue**. Fix: `frontend:project:install-dependencies`; if the project's `package-lock.json` lacks the package, propose a lock update for the user to approve. Do not edit source. The same applies when the run's frontend dependency check fails.
- **Failure signature:** phpstan reports `Call to an undefined method …LocatorLocatorInterface::<newModule>()` for a module that exists — the locator stub is stale, not a code defect. The new-module row regenerates it (`dev:ide-auto-completion:generate`, the project's install recipe runs it too — see `config/install/development.yml`) and clears the phpstan result cache, which keeps reporting the stale error after the stub is fixed.
- **Glossary import** (`data:import:glossary`) updates the Yves storefront translations only, not the Zed Back Office labels: those come from the translator CSV and `translator:generate-cache`.
- **A Yves build** ends with `webpack compiled successfully` in its log. Look for this line in the log of the build step.
- **DMS queue drain.** In DMS, `queue:worker:start`, `queue:task:start` and `publish:trigger-events` take the store as a prefix: `docker/sdk cli "APPLICATION_STORE=<STORE> vendor/bin/console <command>"`, once per store. A worker that exits at once with only the env banner while the queues stay full is a missing `APPLICATION_STORE`, not a broken publisher. Assert the drain, don't infer it: find the broker container with `docker ps --format '{{.Names}}'` (filter for `broker`; it is `<namespace>_broker_1` — never hardcode `spryker_broker_1`) and confirm `docker exec <broker> rabbitmqctl list_queues -p /<project vhost> name messages` fell to 0.
- **Data import manual items.** A removed row, or a changed value on `product-price`, `product-price-schedule`, `discount-amount` or `cms-block`, is not re-imported: those importers look rows up by value, so a re-import duplicates the row or fails on a unique key, and no importer deletes. They follow `project-data`'s reset ladder (`boot-and-verify` §3b) — out of scope here, since this skill runs no `reset`; report the file and hand it over.
- **`propel:install` after a `composer.lock` change** changes the database schema: say so in the report.
- **A failed step:** the caller decides retry vs hand-off to `spryker-issue-diagnoser`.

## Constraints

- Read source files only — never edit them.
- Destructive commands (anything from `destructive*.yml`, anything that drops, deletes, truncates or wipes data, schemas, indices, queues or storage, `docker/sdk reset`) are out of scope.
- Run only the commands the plan holds for the actual file list — never the full install chain.
- Use relative paths from the project root, and do not prepend `cd /absolute/path && …` to a command: the harness already runs in the project root, and a `cd` shifts the command to another allowlist pattern and causes permission prompts.

## Report to the caller

```
## Refresh Report
- Files touched: <list> (or "derived from git against <base>")
- Change classes detected: <the mapping rows of the plan, for example "transfer", "schema", "yves-assets">
- Plan: <the plan lines, or "see the run lines">
- Run: <the run script's lines, as printed>
- Failure (if any): the failing command, its exit code, the 20-line tail the run printed; suggested next step: hand to `spryker-issue-diagnoser`
- Decisions left open: <the plan's "Needs a decision" items not resolved>
- Caveats: the plan's manual items, for example: queue workers need a restart (publisher plugin changed); the storefront may need a browser hard refresh; after a DependencyProvider, `ApplicationServices.php` or other DI/container wiring change, a clean exit code does NOT prove the application still serves — run the wiring smoke check (one Back Office route + one backend-gateway route) before continuing: a stateful-plugin DI mistake exits 0 here and 500s every route at runtime.
```
