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
  results. Never edits source code. Boundary: a change to data/import/**
  rows takes effect through project-data's reset ladder (boot-and-verify
  §3b), not here — this skill only re-runs an importer for added rows.
---

# Spryker Refresher

When invoked, this skill walks through the post-change command chain for a list of touched files. The main session executes the commands directly — no sub-agent spawn.

This skill doesn't edit code. It doesn't decide whether changes are *correct*. It makes them *active*: codegen runs, caches clear, frontends rebuild, autoloaders refresh, the right warmups run.

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

Claude runs on the host, not in the container. Use these invocation forms:

- Spryker console commands → `docker/sdk console <command>`
- Composer → `docker/sdk cli composer <args>`

## Touched-file → command mapping

Commands below exist in this project (verified via `docker/sdk console list`). Install recipes under `config/install/*.yml` are authoritative for this project's ordering and flags — read them before improvising. The table just narrows the search.

Each row's trigger is independent. Apply every row whose trigger matches a file in your change set.

| Trigger | Commands |
|---|---|
| `*.transfer.xml` changed | `transfer:generate` |
| `*.schema.xml` changed (project layer) | `propel:install` |
| A `.php` file is **added, renamed, moved, or deleted** under a project namespace in `src/` | `composer dumpautoload --apcu` |
| A `.php` file under a project namespace is added or deleted whose **fully-qualified class name matches an existing vendor class** (the file is a project-layer override — same FQCN minus the `Spryker\` vs project root) | `cache:class-resolver:build` |
| A **new module directory** appears under a project namespace (`src/<Ns>/{Shared,Zed,Client,Yves,Glue,Service}/<NewModule>/`) | `dev:ide-auto-completion:generate` → `vendor/bin/phpstan clear-result-cache` (via `docker/sdk cli`). The first regenerates the locator stub so `$this->getLocator()-><newModule>()` exists — without it the app runs fine but **phpstan fails with `Call to an undefined method …LocatorLocatorInterface::<newModule>()`**, surfacing two steps later as a phantom code defect (the project's own install recipe runs it — see `config/install/development.yml`). The second is its unconditional companion: phpstan's result cache keeps reporting the stale locator error after the stub is fixed. |
| `*DependencyProvider.php` changed (plugin chain edit, body or new file) | `cache:empty-all` |
| `config/*.php` or `config_default*.php` changed | `cache:empty-all` |
| Yves **Twig** changed | Edited template: `docker/sdk cli "rm -rf src/Generated/Yves/Twig/codeBucket"` (the compiled templates and their `.pathCache`), then reload. **New** override template (a file that did not exist): also `twig:cache:warmer`, which rebuilds the template path map — until then the old path is served. **Never delete `data/cache/Yves/<env>`** — that is the Symfony DI container, not the Twig cache; removing it mid-request gives transient 500s ("Failed opening required …/Container…"); the one case for clearing it is a stale container after generated-code changes (`../boot-and-verify/references/verify-gates.md`, "Class not found" triage). `cache:empty-all` also empties the `codeBucket` directories. |
| Yves `Theme/**` **JS / TS / SCSS** changed | `docker/sdk cli "ls -d /data/node_modules"` decides: **present** → `frontend:yves:build` (runs `npm run yves`; seconds; expect `webpack compiled successfully`); **absent** → `docker/sdk up --assets` (minutes; re-runs the whole `build-static*` section). Clones typically ship `/data/node_modules` in the `cli` container — run the `ls`, don't assume. |
| Back Office (Zed) `assets/Zed/**` JS / SCSS changed | `frontend:zed:build` (runs `npm run zed`) |
| Merchant Portal `Presentation/Components/**` changed | `frontend:mp:build` (runs `npm run mp:build`) |
| Twig file **added, moved, or deleted** in Zed or MP (a new override) | `twig:cache:warmer` (the template path cache otherwise keeps resolving the old file). |
| `navigation.xml` changed | `navigation:cache:remove` → `navigation:build-cache` |
| Glue / SAPI / BAPI route or `*RestApi*` plugin changed | `rest-api:remove-validation-cache` → `rest-api:build-request-validation-cache` |
| `RouteProvider` plugin or route configuration file added / changed / deleted, or a **new Zed controller / action** (a 404 on the new URL) — NOT controller body edits | `router:cache:warm-up` (Zed) and/or the per-application variant — `router:cache:warm-up:backoffice`, `router:cache:warm-up:backend-gateway`, `router:cache:warm-up:merchant-portal` — for the applications the route belongs to |
| OMS XML (`config/Zed/oms/*.xml`) changed | `oms:process-cache:warm-up` |
| Glossary CSV changed (e.g. `data/import/**/glossary*.csv`) | `data:import:glossary` (Yves storefront translations only — does NOT cover Zed BO labels) |
| Zed translator CSV changed (`src/<Namespace>/Zed/Translator/data/<Module>/*.csv`) | `translator:generate-cache` (Zed BO labels — separate pipeline from glossary; verify the command via `docker/sdk console list`) |
| Search schema / index-map file changed (project-layer JSON under `src/<Namespace>/Shared/Search/Schema/*.json`) | `search:source-map:remove` → `search:setup:source-map` → `search:setup:sources` |
| Data import CSV changed (entity-specific) — **rows ADDED only** | `data:import:<entity>` (verify the entity importer exists in `docker/sdk console list`). **Not for a changed VALUE on `product-price`, `product-price-schedule`, `discount-amount` or `cms-block`, and not for a removed row:** those importers look rows up by value, so a re-import duplicates the row or fails on a unique key, and no importer deletes. They follow `project-data`'s reset ladder (`boot-and-verify` §3b) — out of scope here, since this skill runs no `reset`; report the file and hand it over. |
| Publisher plugin / queue config changed | Queue workers need a manual restart — project-specific. Document in the report; don't auto-run. |
| A data import or `publish:trigger-events` ran and the KV/search read model must catch up, in a **DMS (dynamic multi-store)** project | `docker/sdk cli "APPLICATION_STORE=<STORE> vendor/bin/console queue:worker:start --stop-when-empty"` — **once per store** (same `APPLICATION_STORE=<STORE>` prefix on `queue:task:start` and `publish:trigger-events`). In DMS these commands resolve the store from `APPLICATION_STORE`; the bare `docker/sdk console queue:worker:start --stop-when-empty` prints the env banner, exits 0 and processes **nothing**. Assert the drain, don't infer it: discover the broker container via `docker ps --format '{{.Names}}'` (filter for `broker`; it is `<namespace>_broker_1` — never hardcode `spryker_broker_1`) and confirm `docker exec <broker> rabbitmqctl list_queues -p /<project vhost> name messages` fell to 0. **Signature:** worker exits immediately with only the env banner while the queues stay full → missing `APPLICATION_STORE`, not a broken publisher. |
| BO showing stale template after a clean refresh (rare) | `rm -f src/Generated/Zed/Twig/codeBucket/.pathCache` → `twig:cache:warmer` |
| **Stale Yves Twig cache** — a template fix "does nothing": the `.twig` is correct on disk AND in the container (`docker/sdk cli "cat /data/<path>"` shows the new markup), yet the rendered page is the old one | `docker/sdk cli "rm -rf src/Generated/Yves/Twig/codeBucket"` (+ `twig:cache:warmer` for a new override file). Clear it BEFORE concluding "the change had no effect" and writing a second fix — `up --assets` does not clear it. Never `data/cache/Yves/<env>` (the DI container). |

If a command not in the table seems needed, verify it exists in `docker/sdk console list` first.

## Approach

1. **Get the file list.** Caller-supplied is best. Otherwise: `git status` / `git diff --name-only HEAD~1`. If you can't figure it out, ask.

2. **Match each file to table rows.** Skip rows whose trigger isn't in your list.

3. **Order the resulting commands** (this matches the pattern in `config/install/development.yml` and similar recipes — clear-then-codegen-then-build-then-resolve-then-import-then-frontend):

   1. **Cache removes** — `cache:empty-all`, `navigation:cache:remove`, `rest-api:remove-validation-cache`, `search:source-map:remove`, `docker/sdk cli "rm -rf src/Generated/Yves/Twig/codeBucket"` (Twig — never `data/cache/Yves/<env>`, which is the DI container). Clear old cached state before new state is written.
   2. **Codegen** — `transfer:generate`, `propel:install`.
   3. **Autoload** — `composer dumpautoload --apcu`.
   4. **IDE/locator stubs** — `dev:ide-auto-completion:generate`. After autoload (the stub generator reads the class map), before the class-resolver build.
   5. **Cache builds / warmups** — `twig:cache:warmer`, `navigation:build-cache`, `rest-api:build-request-validation-cache`, `search:setup:source-map`, `search:setup:sources`, `oms:process-cache:warm-up`, `router:cache:warm-up*`.
   6. **Class resolver build** — `cache:class-resolver:build`. Runs after the class layout AND caches are in their final state.
   7. **Data imports** — `data:import:glossary`, `data:import:<entity>`. Spryker translations are looked up at runtime, so glossary import can run after twig warmup safely. In a DMS project, follow an entity import with the **per-store** drain from the table (`APPLICATION_STORE=<STORE> … queue:worker:start --stop-when-empty`) and the broker depth check — the bare worker is a silent no-op there.
   8. **Frontend builds** — `frontend:yves:build`, `frontend:zed:build`, `frontend:mp:build`. Last.
   9. **Stale-analysis cache clear** — `vendor/bin/phpstan clear-result-cache` (via `docker/sdk cli`), whenever step 4 ran. Last, so no later step repopulates a stale entry.

   Each step runs only if its commands appeared in your matched set from step 2. Install recipes (`config/install/*.yml`) are authoritative — if a recipe orders commands differently for this project, defer to the recipe.

4. **Run each command separately via Bash** so you capture exit code and tail of output per step.

5. **Stop on the first non-zero exit.** Report what ran, what failed, and the tail. Caller decides retry vs hand-off to `spryker-issue-diagnoser`.

6. **Report concisely** — exit codes, brief output highlights, and caveats (e.g. *"queue workers need a manual restart — publisher plugin changed"*).

## Constraints

- Read source files only — never edit them.
- Destructive commands (anything from `destructive*.yml`, anything that drops/truncates/wipes data or storage, `docker/sdk reset`) are out of scope.
- Run only the commands needed for the actual file list — never the full install chain.
- Use relative paths from the project root for any Bash invocation.
- If the caller hasn't specified a store / region for a multi-region command, ask. In DMS, queue and publish commands take the store as `APPLICATION_STORE=<STORE>` (via `docker/sdk cli "APPLICATION_STORE=<STORE> vendor/bin/console …"`) and run once per store — a store-less run is a silent no-op (banner, exit 0, nothing processed), not a default.

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
| 1 | `docker/sdk console transfer:generate` | 0 | regenerated transfer classes |
| 2 | `docker/sdk cli composer dumpautoload --apcu` | 0 | classmap refreshed |
| 3 | `docker/sdk console frontend:yves:build` | 0 | yves assets rebuilt |

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
- **If the file list touched a DependencyProvider, `ApplicationServices.php`, or other DI/container wiring:** a clean exit code does NOT prove the application still serves — remind the caller to run the wiring smoke check (one Back Office route + one backend-gateway route) before continuing. A stateful-plugin DI mistake exits 0 here and 500s every route at runtime.
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
- **Do not omit `cache:class-resolver:build` after any `.php` change under a project namespace directory in `src/`.** Without it, the override file lands in the project layer, but Spryker keeps resolving to the vendor class because the resolver map wasn't rebuilt. Self-correction signal: if your file list contains any `src/<Namespace>/**/*.php` (new or edited) and your command plan doesn't include `docker/sdk console cache:class-resolver:build`, stop and add it before reporting.
