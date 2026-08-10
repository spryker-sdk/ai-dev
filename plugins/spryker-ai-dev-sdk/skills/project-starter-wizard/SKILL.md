---
name: project-starter-wizard
description: "Use at the very start of a new customer project, on a fresh un-booted clone of a Spryker b2b/b2b-marketplace demoshop — 'turn this demoshop into our project', 'start the project setup', 'run the project starter'. Also the resume entry when a prior run left .ai-dev/project-setup.md. The entry point that owns the developer interview and orchestrates the other project-start skills."
---

# Project Starter — Wizard

You turn a **fresh, un-booted** clone of a Spryker b2b / b2b-marketplace demoshop into the customer's project. You own the conversation and the flow; the transformation work is done by the other skills (which drive the `spryker-import-tools` tools with parameters — never hardcode file lists).

Work only from real files and real command output. Never assume Spryker specifics from memory — inspect the clone.

## Communication (applies to EVERY message — the developer will not read walls of text)

Keep output **short**. Nobody reads long status dumps, so a long message is a *failed* message — anything important in it is lost. Three hard rules:

1. **A required human action is the FIRST thing in the message, alone, unmissable.** Lead with a single `⚠ ACTION NEEDED:` line and the exact command — before any status, table, or summary. A prerequisite buried under a validation table or a "✅ N of M complete" header reads as status, not a request, and the run stalls on an unread ask. Never phrase a prerequisite as "whenever" or "I'll tell you when I get there."
2. **Issues/blockers are HIGHLIGHTED, not narrated.** Surface a problem as its own short, flagged line (`⚠`/`❌`) — never mixed into a paragraph of prose. One glance should tell the developer: action needed? issue? or just progress?
3. **Default to terse.** Report the outcome, not the play-by-play. A finished step = one line. Save detail for when it's asked, or for the improvement log (which is dev scaffolding, not something the developer reads for actions). Verbose reasoning belongs in your own thinking, not the output.

This is not cosmetic — it is the difference between an unattended run that completes and one that silently waits on an unread ask.

## 0. Pre-flight (stop if any fails)

- **Flavor:** confirm this is b2b or b2b-marketplace (check `composer.json` name/packages). Reject anything else.
- **Fresh:** no **tracked** modifications in `git status --porcelain` (the ` M`/` D` lines) — untracked files are tolerated when they're plainly tooling (`.claude/`, `.cursor/`, `.windsurf*`, `.github/agents|skills/`, `.mcp.json`, `AGENTS.md`, `CLAUDE.md`, editor configs); an untracked pile of data/CSV/patch files is NOT tooling — ask, don't guess. **If `.ai-dev/project-setup.md` already exists, this is a prior run — do NOT stop; go straight to the Resume section** (skip the interview, continue from the first not-`done` step). Only a dirty/booted clone with no state file is a hard stop — and the stop must hand over the **"Return to fresh" recipe (§ below)**, never a bare refusal.
- **Un-booted:** `.env` empty/absent and no `*_dev_*` containers running (`docker ps`). One `docker ps` conflates two different findings — classify, don't guess:
  - *Containers running* → not fresh → **stop**.
  - *Daemon down* (`Cannot connect to the Docker daemon`) → fine right now (nothing before boot needs Docker), but a **developer prerequisite for boot-and-verify** (~40 min away). Do NOT stop, and do NOT silently note-and-continue — make it an explicit up-front ask (see below). A daemon-down that only surfaces as a wall-stop 40 min later is the failure to avoid.
- **No Docker VOLUME collision on the chosen namespace (BLOCKER).** Run `docker volume ls`; on **any** `<namespace>_*` hit, **refuse and require a different project name/namespace before booting** (the deploy `namespace:` derives from the project name, `<project>` → `<project>_dev`; collisions are the norm on a shared dev machine — ~30 project namespaces are typical). The namespace is chosen in the interview — re-run this check once it is known, and again in the execution pre-flight before boot.
  - *Why a blocker:* `docker/sdk up` drops and recreates the database but **REUSES existing named volumes** — a "fresh" first boot silently inherits a PRIOR project's KV / search / broker read models (foreign store ids, stale indices, reversed store→id maps, an extra RabbitMQ vhost) while the freshly-written DB reads correct. Every signal reads green; the environment is contaminated. This is the one gap `docker ps` doesn't cover.
  - *Fix hierarchy — offer the developer three ranked options, never force a project rename:* (1) **the developer removes their own confirmed-stale volumes** (`docker volume rm <namespace>_*` — theirs to run, per the never-touch-their-docker rule; right when the collision is a leftover experiment); (2) **override only the deploy `namespace:` token** independent of the project name (the name drives branding; the namespace is one YAML key — right when the colliding volumes must survive); (3) a different project name. All three beat `clean-data`/`reset`, which destroy data while fixing nothing about the collision.
- **Docker present:** `docker -v`. **PHP:** `php -v` (else note the docker fallback for lib scripts).
- **Boot-environment probes — the minute-0 and minute-40 killers the daemon check doesn't cover.** Each failing probe joins the same developer-must-do-before-boot bucket as "start Docker" (an up-front ask, not a wall-stop mid-boot):
  - **Ports:** the boot binds 80/443 (+ service UIs) — read `docker ps` for OTHER projects' containers publishing `:80->`/`:443->` and ask the developer to stop them before boot (never stop them yourself).
  - **Composer auth/connectivity:** the in-container `composer install` at ~minute 40 dies on a GitHub rate limit or a private-package 401 exactly like a stopped daemon would. Check `~/.composer/auth.json` / `COMPOSER_AUTH` for a `github-oauth` token and `curl -s https://api.github.com/rate_limit` for headroom; missing/exhausted → up-front ask for a token (never handle the token value yourself — the developer adds it).
  - **Disk:** a first boot pulls multi-GB images + volumes; read `docker system df` and flag a nearly-full disk (recommend the developer free space or prune — never prune yourself).
- **Platform:** supported hands-off hosts are **macOS (OrbStack/Docker Desktop, mutagen mounts) and Linux (native mounts, no mutagen block)**. On **Windows**, the run must live inside **WSL2** (Linux rules apply; the `/etc/hosts` equivalent is the Windows-side hosts file — say so in the close); native Windows shells are unsupported for the boot — the developer runs `docker/sdk up` themselves.
- **Git remote:** read `git remote -v`. If `origin` still points at the demoshop upstream (`spryker-shop/*`), record it — the close summary must flag "origin still points at the demoshop; point it at the customer's repository before pushing" (never change the remote yourself).
- **Docker SDK present, at the pinned version:** the clone must have a working `docker/sdk`. **Read `.git.docker` FIRST** (the project's SDK pin). It holds **either a tag (`1.76.0`) or a 40-char commit SHA** — and `git clone --branch` accepts a tag but **not** a SHA, so don't assume the tag form. If `docker/sdk` is absent (some clones ship without the submodule), fetch it at the pin with the clone-then-fetch-then-checkout approach the repo's CI uses (works for a tag OR a SHA). **Read `.git.docker` with the Read tool** to get the literal pin `<PIN>`, then run three separate single commands (do NOT chain with `&&` or use `$(cat …)`):
  1. `git clone https://github.com/spryker/docker-sdk.git docker`
  2. `git -C docker fetch --depth 1 origin <PIN>`
  3. `git -C docker checkout <PIN>`
  Never `master` (cloning master then having to `rm -rf docker` and re-clone at the pin). This is an **accepted exception** to the one-simple-command discipline — `git -C` isn't allowlistable, so these prompt; it's a one-time SDK setup the developer approves once. If `docker/sdk` is present instead, verify its checkout matches `.git.docker`. If `.git.docker` itself is **absent**: derive the pin from an existing `docker/` checkout's tag if present, else ask the developer which SDK version the project targets — never silently take `master` (per the same regression). Don't defer to the developer and don't boot without it.
- **Classify every pre-flight finding into three buckets** — **stop** (not fresh/wrong flavor), **fix-myself** (clone the SDK, php-docker fallback), or **developer-must-do-before-step-N** (start Docker/OrbStack; add the allowlist). Anything in the third bucket becomes a single up-front ask at the top of the interview, per the **Communication** rules (§ top) — surfaced once, unmissable, not a parenthetical.
- **Permission allowlist — recommend, never install (affects only hands-off-ness):**
  - **Do NOT create, copy, or modify the developer's `.claude/settings*.json`** — they may already have their own, and their settings are theirs. If commands start prompting, **recommend** they add the shipped template (`${plugin root}/recommended-permissions.settings.json`) to `.claude/settings.local.json` and restart the session — their choice. Without it the run still works; it just prompts per command.
  - **Never claim the run is unattended if the allowlist isn't in place.** Unattended behaviour depends on the session allowlist granting the project-starter commands (`php`, `docker/sdk`, the `script … docker/sdk up` boot, `git clone` of the SDK, `curl`, read-only helpers) and denying `sudo`/foreign interpreters.
- **Inventory (needed for collision checks and the CI section of the interview):** list the shipped regions and stores — scan `deploy.*.yml` (region keys), `data/import/local/full_*.yml`, `config/install/*/`. Record what already exists (the demoshop ships an EU region **and** a dormant US region). Also scan `.github/workflows/` for the CI jobs/suites actually present — interview §8 offers only what was found.

If not fresh/un-booted **and there is no `.ai-dev/project-setup.md`**, **stop and hand over the "Return to fresh" recipe** (§ Abort, change an answer, return to fresh) — do not half-apply, and do not leave the developer with a bare refusal. (An existing state file → Resume, per above.)

## 1. Interview

Conduct the interview per **`references/interview.md`** — read it BEFORE asking the first question. It carries the AskUserQuestion mechanics (structured options with the default first, pre-computed candidates for free-text values, batched sections — what makes this a wizard, not a form) and the **nine-section decision catalog**: identity, namespace, services/applications, stores + region, demo-data mode, catalog scope, localization, CI, and run mode (autonomous/supervised). Ask section by section; always show the demoshop default; accepting every default is a valid outcome.

## Two rules that bind EVERY edit of the run (interview through execution)

**Surgical edits only — never tidy adjacent config.** When editing a shipped file (deploy.dev.yml, config, YAML, recipes), change **only** the exact keys the current step owns; do not reorganise, reformat, "clean up", or move neighbouring blocks you were not asked to touch. Everything else stays byte-for-byte as shipped. (For example, an agent rewriting `docker.testing.region` also deleted the adjacent `docker.mount.mutagen` block and moved macOS to `native` mounts — crippling the Mac dev env. The region token was in scope; the mount block was not.)

**Environment limits are NOT project defects.** A failure caused by *your run environment* — no TTY for an interactive command, the 10-minute tool timeout, shell word-splitting (zsh vs bash), a detached/background process, `/dev/null` stdin — is a limit of how *you* are running, not a bug in the project. **Never diagnose a sandbox limit as a project problem, and never edit project files to accommodate it.** When a command needs a real terminal or exceeds the tool cap (notably `docker/sdk up`), hand it to the developer to run — do not fake a TTY, background it to dodge the cap, or "fix" the project to make it run headless. If unsure whether a failure is the project or the environment, assume the environment and surface it, rather than mutating the project.

## 2. Confirm & write state

Summarise every answer. Get explicit confirmation. Then write `.ai-dev/project-setup.md` **exactly per the template at the end of `references/interview.md`** — the frontmatter (project / namespace / services / stores / data / reduce_catalog / localize) plus the **Steps table** that §3 and Resume operate on.

In the same pass, create `.ai-dev/run.log` with the interview summary as its first line (see §3 → Run logging). Every step from here on appends to it.

## 3. Run the steps in order

**Run mode — chosen in the interview (`run_mode: autonomous | supervised` in the state file).** It governs only how control is handed back *between* decisions; it changes nothing about WHAT each step does, and it never relaxes the hard-stops below.

- **Autonomous** — run steps 1–9 as **one continuous pass**; after a non-gated step completes, proceed to the next in the same turn (status update + one-line report happen inline, mid-run — don't end the turn to announce "step done — continue?"). At any **reversible, in-project decision point** — which value to pick, how to resolve a dangling reference, a strategy fork the interview didn't pin down — choose the best option, **record it in the decision log** (below), and keep going. The default is continue.
- **Supervised** — do **not** silently stop, and do **not** run straight through. At each **step boundary**, end the turn with a one-line result and an explicit **"Continue to `<next step>`?"**, and wait. At any **decision point**, surface it as a question with the options and your recommendation (`AskUserQuestion`) and wait — never decide silently, never halt without a question. Record the answers in the decision log too, so a resume keeps the rationale.

**Hard-stops — control returns to the developer in BOTH modes** (autonomy never overrides these; supervised asks anyway):
- **(a) a `⚠ ACTION NEEDED` prerequisite** — something only the developer can do (start Docker/OrbStack, add the `/etc/hosts` line, supply a token). Surface it per the Communication rules and wait.
- **(b) a deletion or data-destroying action — gated in BOTH modes, no exceptions.** Any of: deleting files/dirs (`rm`, `git clean`); removing rows/columns in place (`csv delete` / `filter --in-place` / `drop-columns --in-place` — these do **NOT** prompt at the OS level, so the gate is this rule, not a shell prompt); any DB/volume drop (`reset`/`clean-data`); `sudo`; or publishing outside the clone. Present the concrete blast radius (the file list, the row/column count, or what the drop wipes) and get an explicit go-ahead first. **This includes the routine happy-path deletions, not only recovery paths** — the `project-ci-generator` CI/deploy/install wipe (step 1), `define-stores`' removal of the non-canonical demo dirs + dangling manifests, and `project-data`'s strip passes. **Autonomous performs and logs reversible EDITS on its own** (value transforms, added columns, file writes — all git-revertable in a fresh clone) **but never a deletion, data wipe, or `sudo` unattended.**
- **(c) a step failure** — stop with guidance, per the failure rule below.

**Decision log (`.ai-dev/decision-log.md`) — the autonomous run's audit trail (and supervised's answer record).** Append one terse entry per decision: the step, what was decided, why (the evidence), the alternatives rejected, and **how to reverse it** (the git path, or the specific file/rows). Like the improvement log it is dev scaffolding, not developer-facing status — its purpose is that a reversible-but-wrong autonomous call costs a review-and-revert, not a silent broken shop. The developer reads it to audit or override the run's choices after the fact.

### Run logging — what, when, how, where

A run spans nine steps, several sub-skills, a boot, and can be interrupted and resumed hours later. The
three `.ai-dev/` files already cover *configuration* (the state file), *rationale* (the decision log),
and *maintainer feedback* (the improvement log) — what is missing is the **timeline**: what actually
ran, in what order, and how each step ended. `.ai-dev/run.log` is that file. It **records** the steps
below; it changes nothing about what any step does, which gate fires, or when control returns.

**Where.** Alongside the other run artifacts, in the clone's own tree — `.ai-dev/run.log`, a
project-relative path like every other `.ai-dev/` file. The run is self-contained: never write run
files outside this clone. It is created by the run and removed by the "Return to fresh" recipe, which
already deletes `.ai-dev/project-setup.md` **(+ run logs)**.

The run's four files sit flat at `.ai-dev/` because the state file's path is load-bearing: pre-flight
detects a prior run by it, Resume reads it, and "Return to fresh" deletes it.

**When.** Create it in **§2 (Confirm & write state)**, in the same pass that writes
`.ai-dev/project-setup.md` — the interview answers are the first thing worth recording, and every step
from §3 onward appends to it. On **Resume**, do not start a new file: append a `RESUME` line and keep
going, so one run reads as one continuous timeline across interruptions.

**How.** Write it with the built-in **Write / Edit / Read** tools, not the shell. This is a direct
application of the Tooling discipline below: `printf … >> file` is a redirect, and redirects (like
pipes, `&&`, and subshells) prompt regardless of the allowlist. Appending a line with `Edit` costs no
prompt and keeps the run hands-off. Keep entries terse — one line per event:

```
[2026-08-10 14:02:11] INTERVIEW — 9 sections answered · run_mode=autonomous · data.mode=adapt
[2026-08-10 14:02:40] STEP 1 project-ci-generator | START
[2026-08-10 14:11:05] STEP 1 project-ci-generator | END done — 1 workflow kept, 14 files wiped (approved)
[2026-08-10 14:11:20] STEP 5 define-stores | SKIPPED (data.mode=leave)
[2026-08-10 14:48:02] STEP 8 boot-and-verify | ⚠ ACTION NEEDED /etc/hosts — waiting
[2026-08-10 15:03:55] RESUME — continuing from step 8 (in-progress: browser ACs pending)
[2026-08-10 15:12:31] STEP 8 boot-and-verify | END done (browser ACs BLOCKED — /etc/hosts declined)
```

**What.** One line per step boundary (`| START`, `| END <one-line outcome>`), plus every event a later
reader would need to explain the run:

- The interview summary: `run_mode`, and the answers that decide whether steps run (`data.mode`,
  `reduce_catalog`, `localize`, the `ci:` plan).
- Each step's START/END with its terminal status, and each **conditional skip with its reason**
  (`SKIPPED (data.mode=leave)`) — a skip is a result, and it explains a "missing" step later.
- Every **hard-stop** as it happens: a `⚠ ACTION NEEDED` prerequisite and how it resolved, each
  **destructive-op gate** with the blast radius presented and the developer's answer, and any step
  failure with the signature you matched in `references/pitfalls.md`.
- Each sub-skill handoff (which skill, for what) and the post-boot passes in step 8 — brand theming,
  the codeception seed, the `cy:run` smoke — with their individual outcomes.
- The `spryker-verifier` verdict at the step-8 gate, per store.
- Every `RESUME`, so an interrupted run's real elapsed shape stays visible.

Three rules:

- **Mirror the one-liner, keep the detail in its own file.** The decision log holds the *why*, the run
  log holds the *when* — when you record a CRITICAL DECISION, add a one-line pointer here rather than
  duplicating the rationale. Bulk output (boot logs, verifier transcripts) stays where it already lives;
  reference it, don't paste it.
- **Never log a step green that wasn't.** `done (browser ACs BLOCKED — /etc/hosts declined)` is the
  honest terminal state and is exactly what belongs in the log — not `done`. A skipped, blocked, or
  partially-completed step is recorded as precisely that. This is the same honesty rule the state
  file's status column already carries.
- **Write it as you go, never reconstruct it at the end.** The log's whole value is surviving an
  interruption; a timeline assembled from memory after the fact is the one thing it cannot be.

The closing summary points at `.ai-dev/run.log` so the developer can audit what ran without re-reading
the conversation.

Re-check the execution pre-flight (still fresh/un-booted) before writing anything. Then run, updating each step's status in the state file as you go. **Long steps record intra-step progress in the `note` column** — set the step to `in-progress` and update the note after each major pass (e.g. `in-progress: locales done, currencies pending, strip not started`), so an interrupted run can resume precisely instead of blindly re-running (most passes are idempotent; the deletion passes are NOT — a blind re-run of those is the thing the note prevents).

1. **project-ci-generator** — turn the repo's inherited product/vendor CI (`.github/workflows/ci.yml`) into a single lean project pipeline.
   - Runs **first and pre-boot** (pure CI-file work — no boot, namespace, or data needed), so it's **unmissable** rather than an end-of-run afterthought.
   - **The decisions were already collected in interview §8 (the `ci:` block)** — this step executes that plan against the discovered CI; it does NOT re-run the questionnaire. **Outward-facing** (it deletes CI files): the one thing still confirmed at execution time is the destructive wipe itself — present the concrete file list derived from the plan and get the go-ahead. The developer can decline and keep everything, but the wizard never silently skips the step. If there's no CI to transform, say so and move on.
   - The `ci.keep_suites` decision doubles as the robot/acceptance-fixture lane decision other steps read (define-stores' dangling-manifest sweep, later fixture adaptation).
   - **Owns removing a dropped test-suite's whole footprint** — not just the CI jobs but the `.github/deploy/*.yml`, `config/install/*.yml` install-pipeline configs, and fixture dirs those jobs alone referenced. `cypress-migration` (step 7) no longer does this; it keeps only the Composer-package removal + vendoring. Keeping one owner avoids two steps re-editing the same CI/install files.
   - **Green CI on a non-`DE`/`AT`/`US` store — the bare-runner store trap (verify after the trim; ci-generator owns these `.github/workflows` files, but it's vendored so the obligation is recorded here).** The kept static-analysis / `transfer:generate` jobs run `vendor/bin/console` **directly on the runner** — no docker, no deploy file. Retargeting their `env:` store/region tokens to the project's (`APPLICATION_STORE`, `SPRYKER_CURRENT_REGION`) is **necessary but NOT sufficient**: on a DMS project whose stores aren't `DE`/`AT`/`US`, the first console call fatals `Missing setup for store: <STORE>` — the legacy `Store` path is taken (because `config/Shared/stores.php` still ships, so DMS reads as OFF, and its fallback defines only `DE`/`AT`/`US`, while `SPRYKER_ACTIVE_STORES` — which docker-sdk would supply from the deploy file — is absent on a bare runner). **Fix: add `SPRYKER_DYNAMIC_STORE_MODE: true` to those jobs' `env:`** — `defineLocale()` short-circuits, `stores.php` is never consulted, the region resolves from `SPRYKER_CURRENT_REGION` (and it matches the already-DMS functional job). **Pre-flightable without pushing:** export the job's env and run its own commands — `APPLICATION_ENV=ci.mysql APPLICATION_STORE=<STORE> SPRYKER_CURRENT_REGION=<REGION> SPRYKER_DYNAMIC_STORE_MODE=true php -d memory_limit=-1 vendor/bin/console transfer:generate` → expect exit 0 (pass `-d memory_limit=-1`; the host default 128M dies inside `transfer:generate`. `code:sniff:style` can't be verified this way — it spawns `phpcs` as a subprocess that doesn't inherit the flag). This is the sibling of `configure-codebase`'s codeception store-helper trap — both are "the shipped tests/CI assume store `DE`."
2. **configure-codebase** — namespace registration + FE/test wiring, incl. the **PHP/codeception** test infrastructure (skip if `keep-pyz`).
3. **brand-project (identity only)** — composer/README/docker-namespace/domain, and drop the logo asset into place. Its **theming half is post-boot** (colours, logo wiring, BO/MP ymls, `configuration:sync`, rendered verification) — it needs `vendor/` + an initialized DB, so it runs in step 8, same split as configure-codebase.
4. **configure-services** — engines + optional services + app enable/disable into `deploy.dev.yml`.
5. **define-stores** — region, store-definition CSVs + the store-definition manifest (`store_<REGION>.yml`), literal sweep. The catalog import-config assembly belongs to `project-data` (step 6), not here. **Skip if `data.mode = leave`** (demo stores kept unchanged → nothing to redefine).
6. **project-data** — the data step; it picks the strategy by `data.mode` (each assembles its own import config):
   - **adapt** — reshape the demo catalog to the project stores/locales/currencies.
   - **clean** — build the minimal bootable import (no demo catalog).
   - **generate** — author a themed catalog in the project's vertical.
   - **`leave`** — skip entirely; the shipped demo data stands (rebrand-only path).
   Then, **adapt only** and only if `reduce_catalog` ≠ `keep: all`, run the **reduce** strategy to trim the demo catalog to the project's subset **pre-boot** (no reset), before boot. N/A to clean/generate/leave.
7. **cypress-migration** — the **E2E (Cypress) half of test infrastructure**: removes the inherited Spryker demo suites' **Composer packages** (`cypress-tests`/`robotframework-suite-tests`), vendors the proven `tests/cypress-boilerplate/`, wires Cypress CI, and generates the companion `cypress-tests` skill. (The old suites' CI jobs + deploy/install-pipeline/fixture configs are removed by `project-ci-generator` at step 1, not here — see step 1.) Always run it (the orchestration boundary lives here, not in it). **Tooling note:** it predates this plugin's command discipline and still uses its own conventions (`python3 -c "import yaml…"`, `rsync`, `grep … | wc -l`, `cd … && npm ci`) that don't follow the one-simple-command rule — so **expect its commands to prompt**. That's a deferred conformance item, not a blocker (the commands work); bring it into line when convenient (e.g. the grep-pre-boot / `symfony/yaml`-post-boot YAML route ci-generator §5 uses). **Runs last of the pre-boot steps — deliberately, because it depends on the output of the ones before it:**
   - **After `brand-project`(3) + `define-stores`(5)** — its Cypress `.env` (`STOREFRONT_URL`/`BACK_OFFICE_URL`/`GLUE_URL`/`MP_URL`) is built from the project's **final** region + domain; those hostnames don't exist until brand (domain) and define-stores (region token) have run.
   - **After `configure-services`(4)** — read `services.applications_disabled` and **prune the Cypress specs for every disabled app** (drop the merchant-portal specs if `merchant-portal` is off, the Glue/API specs if `glue` is off, etc.) so the suite never asserts against an endpoint the project removed. A removed service must take its E2E coverage with it.
   - **After `project-data`(6)** — retarget its fixtures against the **final** catalog/seed (its Step 7). Works uniformly across `adapt`/`clean`/`generate`: the smoke tests run on the static test fixtures/seed (removed only at go-live), not the dynamic catalog, so `clean` still has data to test.
   - **After `project-ci-generator`(1)** — ci-generator already trimmed the CI jobs before any `cypress-*` job exists, so it can't clobber the jobs this step adds. cypress-migration therefore **defers its own CI-job removal (its Step 3) to ci-generator** (verify via grep, don't re-run the banner deletion), and **owns what ci-generator doesn't touch:** composer removal (Step 2), deploy-config + robot-fixture removal (Step 4, **gated on the same `ci.keep_suites` fixture-lane decision** so it never deletes fixtures a kept acceptance suite needs), `.gitignore` (Step 5).
   - **Pre-boot vs post-boot** (same split as configure-codebase's seed): the file work (composer.json edit, removals, vendoring, CI + skill) is pre-boot; the tooling proofs (`composer install`/lock sync, `npm ci`, `cy:run` smoke) need the boot's dependency install → they run **post-boot** as a first-class verification-checklist item in step 8.
   - **Destructive / outward-facing:** it deletes composer packages and CI/deploy files → apply the destructive-op gate (present the concrete file list, get the go-ahead) even under always-run. **Honesty flag:** the skill's own *removal half (its Steps 2–5) has never been run end-to-end* — treat those as a careful plan, verify each deletion with the greps it gives; only the additive half is proven.
> **Ordering gate — before starting step 8, every one of steps 1–7 must be `done` or explicitly `skipped` in the state-file Steps table.** Read the table and confirm it; if any pre-boot step is still `pending`/`in-progress`, finish it first. Note `cypress-migration` (7) in particular: its proof (`cy:run`) is post-boot, but its file work (composer removal, vendoring, CI, companion skill) is pre-boot, so it must complete before the boot. This is what keeps the §3 continuous-run directive from turning "keep going" into "skip ahead".

8. **boot-and-verify** — validate everything, first boot, per-store verification, and the **post-boot passes (mandatory, on the checklist alongside the endpoint checks):** brand-project's theming half (colours, logo wiring, BO/MP ymls, `configuration:sync`, rendered colour/logo gate), the codeception seed's green `codecept run` (configure-codebase), and cypress-migration's `cy:run` smoke. The authoritative PASS/FAIL comes from the independent `spryker-verifier` sub-agent (shipped in this plugin's `agents/`, driven by the bundled `spryker-qa-coverage`/`spryker-runtime` skills), not the executor's self-assessment (boot-and-verify §4c). Its browser ACs need `/etc/hosts`, surfaced as a `⚠ ACTION NEEDED` prerequisite before the gate; if declined, the server-side checks (`curl --resolve`) still stand and the step completes in a **terminal** state — `done (browser ACs BLOCKED — /etc/hosts declined)` — so the run finishes rather than parking forever (never a permanent `in-progress`). This is the one prerequisite whose decline is terminal, not a hard-stop wait — spell that out so an autonomous run doesn't stall on it.
9. **translate-content** (optional — only if `localize.locales` is non-empty) — translate the chosen locale(s) after a green boot; default is skip (English copies stand). Never blocks setup.

On any step failure: **stop with guidance**, leave the state file recording where it stopped, and log the failure line in `.ai-dev/run.log` (the step, the signature, the matched `pitfalls.md` entry if there is one) before you hand back — a run that stops is exactly the run whose timeline gets read. Do not proceed past a failed step.

**Triage reference — the Known-traps catalog (`references/pitfalls.md`).** It collects every known failure signature across the whole run (cross-cutting + per-step: volume/namespace collision, mutagen-down, boot aborts, "green but empty" post-boot, read-model recovery) as *signature → cause → fix*. On any failure or suspicious-but-green signal, match it there before diagnosing from scratch. Each step-skill also carries the one-line trigger inline for standalone use; this catalog is the shared depth and the single home for the cross-cutting traps.

When blocked on a human action, apply the **Communication** rules at the top: lead with the single `⚠ ACTION NEEDED:` line and exact command, alone, above any status; never bury it, never say "whenever".

## Resume

If invoked with an existing `.ai-dev/project-setup.md`, skip the interview and continue from the first step whose status is not `done` or `skipped` (covers the `/etc/hosts` and boot interruptions). **Append a `RESUME` line to `.ai-dev/run.log`** (never start a new log — one run, one timeline) recording which step you are resuming from and the progress note you resumed against. **Re-read `run_mode` from the state file first** — a resumed run honours the mode chosen at interview time (autonomous vs supervised), and any `## Required follow-ups` recorded there. **If that step is `in-progress` with a progress note, resume from the point the note records** — verify the completed passes' outcomes are actually on disk (a quick `columns`/`distinct` spot-check), then continue with the pending passes; never blindly re-run a deletion pass the note says already ran. (Mark conditionally-skipped steps `skipped` with the reason at state-write time — e.g. `define-stores: skipped (data.mode=leave)` — so Resume never lands on a step that must not run.)

## Abort, change an answer, return to fresh

The run's only verbs are not "continue" and "stop" — these three recovery paths exist, and a stop must always hand the applicable one over:

- **Change an answer BEFORE boot** (the common "I picked the wrong stores" case): everything pre-boot is file edits on a fresh clone, so git is the undo. Revert the affected files (`git checkout -- <paths>` + delete created files/dirs the diff shows), update the answer in `.ai-dev/project-setup.md`, reset the affected steps' statuses to `pending`, and re-run from the earliest reverted step. Cheap for stores/data answers; identity/namespace answers touch many files — when in doubt, prefer the full return-to-fresh below over a partial revert you can't fully enumerate.
- **Change an answer AFTER boot:** file-level revert as above, plus the data/DB consequences follow `boot-and-verify` §3b (a store/catalog answer change = deletions = a DB drop via `reset`). Announce the blast radius per the destructive-op gate first.
- **Return to fresh** (a booted-once clone, an abandoned run, a hopeless half-state): (1) `git checkout -- .` + `git clean` of the run's created files — surface the `git clean` file list and get a yes first (it deletes untracked files; scope it to the paths the run created, never the developer's tooling); (2) delete `.ai-dev/project-setup.md` (+ run logs); (3) the developer stops/removes this project's containers and — their call — the `<namespace>_*` volumes (`docker/sdk down`; `docker volume rm` is theirs to run); (4) re-run pre-flight. A re-clone plus a new namespace is the equally valid lazy path — offer both.

## Tooling discipline (applies to every step)

Work through a **closed set of capabilities** — never improvise in the shell:

1. **Data / CSV / validation** → the `spryker-import-tools` php tools (`csv.php` + `validate.php`). All CSV reading, inspection (`distinct`, `columns --plain`), transformation, and validation go through them.
2. **Reading / searching / editing files** (configs, PHP, YAML, deploy files, import manifests) → Claude's built-in **Read / Grep / Glob / Edit** tools. Config and YAML edits are **anchored, format-preserving Edit calls** — never regenerate a file with `python`/`ruby`/`sed`, and never pre-validate YAML with `python`/`ruby` (its validity is proven by `docker/sdk bootstrap`).
3. **State changes** → `docker/sdk` (developer approves; long boots run in the background per `boot-and-verify`).
4. **HTTP checks** → `curl`. **Staging** → `git add`. **Filesystem moves** (in-place dir renames) → `mv`/`cp`/`mkdir`/`rm`. **Run `git` plainly from the project cwd** — `git status --porcelain`, `git add <relative-path>`, `git diff` — **never `git -C <path>` or an absolute path**: the session cwd is already the project, and a leading `-C <path>` makes the command miss the `git status`/`git add` allowlist prefix (a needless prompt) and breaks the no-absolute-paths rule.

**Read and write only inside this project's own tree — the run must be self-contained.** Pre-boot, this clone has no `vendor/` yet (composer installs it in-container during the boot), so a pre-boot step needing a vendor/core reference (a facade signature, a vendor `*.configuration.yml`, an importer's expected columns) has nothing to read locally. Do not reach into a sibling checkout on the same machine to get it — that clone may not exist on the developer's machine. Instead: defer the sub-task to post-boot when this clone's own `vendor/` exists (most vendor-reference needs are verification, which is post-boot anyway); or take it from the plugin's bundled material if it ships one; or, if genuinely needed pre-boot, ask the developer. A neighbouring clone's `vendor/` or `src/` is never a dependency.

**One Bash call = one simple command.** Compound one-liners (pipes, `;`/`&&`, `for`/`while` loops, `$( … )` subshells, `>` redirects, leading `VAR=…` assignments) prompt regardless of the allowlist, because prefix rules like `Bash(grep:*)` match a lone command, not a pipeline whose segments must each also match — so command shape, not tool choice, drives most avoidable prompts. Issue each command on its own and read output with the built-in Read/Grep tools; prefer `Read`/`Grep`/`Glob` over `cat`/`find`/`ls` pipelines. (`rm` and other destructive ops prompt by design.)

**Reach for the already-available tools first — a general interpreter is a last resort, not a first move.** Nothing here is prohibited (except `sudo`); `python`/`ruby`/`perl`/`node`/`awk`/`sed`/`jq` will simply *prompt* (they're not pre-allowed) — and that prompt is your cue to stop and ask: *does an allowed capability already do this?* Almost always yes:
- parse a tool's JSON by **reading it** (or `--plain`/`--quiet` + exit code) — not `… | python -c` / `jq`;
- inspect/transform/count/translate CSVs with the **php tools** — not `awk`/`sed`/a hand-written script;
- give the boot its TTY with **`script`** (sanctioned) — not a `python pty.spawn` fake-TTY (that one actually hung on `/dev/null` stdin);
- read/search/edit files with the **built-in Read/Grep/Edit**.

Using a foreign interpreter *instead of* an available tool is the thing to avoid — it's slower, more error-prone, and prompts. If you genuinely need one and nothing available fits, go ahead (it'll prompt); just don't reach for it out of habit when the allowed path already does the job.

> **`script` is the sanctioned exception** — the one allowed pty, used *only* for the `docker/sdk up` boot (mechanics owned by boot-and-verify). The `pty.spawn` prohibition above is about `python`/`ruby` fake-TTYs, not `script`.

**Literal tool paths + one simple command per Bash call** — the authoritative rules are in **`spryker-import-tools` → "Invocation & command discipline"**, and they bind every step of this run: invoke tools by their literal path from the project cwd (never `cd`, never a shell variable — the `ROOT`/`CSV`/`VALIDATE` names in each skill's intro are resolved once *in reasoning*, not set in shell); never chain/pipe/loop/redirect (`&&`/`||`/`;`/`|`/`$( )`/`for` always prompt, regardless of the allowlist); read files with the built-in Read tool (`.env`, configs, logs), search with the built-in Grep tool, inspect CSVs with `csv.php columns --plain`/`distinct`. Run-level applications of the same rules:
- A diagnostic that would be a pipe (`docker ps | grep dev`) → run the plain command (`docker ps`) and read its output yourself.
- A long command (boot) → `run_in_background`, which captures output with no `>` redirection.
- Run pre-flight checks as separate single commands (`docker -v`, then `php -v`, then `docker ps`), not one `&&`-joined line.

## Rules

- Stage changed/created files (`git add`); never branch or commit — that's the developer's decision, including whether to commit the state file.
- **No further *configuration* questions after the interview.** All configuration decisions are collected up front (§1); every step then runs without new ones — including the boot, which runs in a detached pseudo-terminal (`script … docker/sdk up` in background; see boot-and-verify). Two honesty rules about what "hands-off" means:
  - **Hands-off only if the developer's allowlist already grants these commands.** Without it the same steps run but each command prompts — still correct, just not hands-off; say so rather than implying silence (recommend the template, don't install it).
  - **Do not over-promise silence.** A real run still consults the developer on **destructive operations and on any data defect the boot surfaces** — in practice several times (a store-keyed money decision, a destructive-trim gate, a namespace collision, an empty-homepage or missing-entity defect the boot reveals). Frame it as "no further configuration questions; expect to be consulted on destructive operations and on any data defect the boot surfaces," never as an unattended run that never speaks again.
  - Human touch-points that remain regardless: (a) the `/etc/hosts` `sudo` line at the end — the server-side verification uses `curl --resolve` so it is **not a gate on the run completing**; only the browser-based verifier ACs need it, and declining it is a **terminal** `done (browser ACs BLOCKED — /etc/hosts declined)`, never a stall — never run `sudo` yourself; (b) **deletions** (`rm` of leftover region dirs / stale manifests) are deliberately NOT allowlisted, so they prompt — a safety choice; (c) a genuine boot environment limit → surface the plain `docker/sdk up` for the developer to run, never edit project config to force it. On Spryker Cloud, infra changes needing a support case are the only other external gate.
- New lifecycle steps register a new state-file section + step; never edit the interview catalog (`references/interview.md`) per addition.

## ⚠ Development scaffolding — REMOVE BEFORE RELEASE

**This section is dev-time only** — it exists to harvest issues while building and testing the wizard. It has no value in a customer's released run. **Strip this section, and any `.ai-dev/skill-improvement-log.md` writes, before shipping** (tracked in `docs/plans/pre-release-cleanup.md`).

While the wizard is still in development/testing: maintain **`.ai-dev/skill-improvement-log.md`**. Whenever any step hits a gap, surprise, workaround, or a place a tool/skill fell short — you had to fall back to a foreign interpreter, an enumerated list missed a file, a boot- or runtime-blocker, an inherited demo-data bug, a manual fix the skill didn't cover — append an entry: **symptom → root cause → which skill → suggested change**. This is the feedback trail we mine to improve the skills (it's how the current correctness/tooling fixes were found). Keep it honest and specific; it is *for the maintainer*, not the end user.
