---
name: spryker-customization
description: >
  Use whenever the user wants to implement a Spryker customization from a PRD
  or acceptance criteria. Triggers include "build this", "implement this",
  "here is a PRD - build it", "add X for the demo", "build this PoC",
  "production-quality build". Drives the full workflow from intake to commit,
  choosing a quality bar (PoC or MVP) at the start and delegating focused
  work (research, verification, debugging, test-data setup, refresh,
  Cypress E2E coverage, demo-artifact capture) to the spryker-* subagents
  and skills. Never auto-commits; the user always confirms.
---

# Spryker Customization Workflow

Take a PRD or acceptance criteria and walk it to a committed branch. Invoke focused subagents at the points called out below, using whichever subagent-spawning tool this harness exposes (commonly `Agent` — resolve it via `ToolSearch` first). User-facing interactions are limited to the consolidated planning gate and the commit gate.

## Run logging — what, when, how, where

A build spans many steps, several subagents, and a self-correct loop that can revisit the same AC more
than once. The run keeps a written trail so the commit gate is judged on evidence rather than memory,
and so a resumed or compacted run can re-orient. This **records** the workflow below — it does not
change any step's behavior, gates, or ordering.

**Where.** One per-run folder, anchored to the project root Claude Code loaded (`$CLAUDE_PROJECT_DIR`,
with a `$(pwd)` fallback) so it is stable regardless of the current working directory:

```
${CLAUDE_PROJECT_DIR:-$(pwd)}/.ai-dev/spryker-customization/<feature-slug>/
```

`<feature-slug>` is the same slug used for the `ai-customize/<slug>` branch. Keep **all** run files
inside `$BUILD_DIR` — never scatter them elsewhere. The folder survives across self-correct iterations,
which is what lets iteration N read what iteration N−1 already tried.

Three files, three distinct roles — keep them separate:

| File | Role |
|---|---|
| `run.log` | The append-only **timeline** — what happened, when. One line per step boundary. |
| `decisions.md` | The **rationale** — why each non-obvious fork was resolved the way it was. |
| `<stage>-<n>.log` | **Bulk output** from a subagent or gate (verifier runs, review findings, static-validation reports). |

**When.** Create the folder and the log at the **end of Step 0**, once the quality bar and phase list
are settled (those answers are the first thing worth recording) and before Step 0c / Intake. Write to
it continuously from that point on — never reconstruct the log at the end.

Because this skill does file work with **native tools, not `Bash`** (see "What you do NOT do"), create
and update these files with `Write` / `Edit` / `Read`, using project-relative paths. The shape of a
`run.log` line:

```
[2026-08-10 14:02:11] STEP 4 — edit | START
[2026-08-10 14:31:47] STEP 4 — edit | END 6 files touched (3 new, 3 modified)
[2026-08-10 14:52:03] STEP 6 — verification | END AC 1,2,4 green · AC 3 red → see verify-1.log
[2026-08-10 15:07:20] STEP 7 — self-correct iter=1 AC=3 | END still red → iter=2
```

**What.** Log one line per step boundary (`| START`, `| END <one-line outcome>`), plus every result a
later reader would need:

- The Step 0 answers: quality bar, and which phases are ON/OFF (an OFF phase explains a missing step later).
- The resolved PRD source from Step 0c, and the AC checklist count from Step 1.
- The branch cut in Step 2.
- Each subagent invocation: which agent, for what, and its compact verdict — plus the file holding its raw output.
- **Every self-correct iteration** in Step 7: the AC, the iteration number, what was changed, and the
  outcome. This is the highest-value part of the log — it is what makes a stuck loop visible as a
  pattern instead of a surprise, and it feeds the "stuck signals" judgement the step already makes.
- Each gate verdict: refresh, verification, Cypress E2E (or its logged skip reason), static validation, code review — `pass|fail` and the output file.
- The final AC tally and the user's commit-gate answer.

Record in `decisions.md` every fork you resolved without asking: the choice, the alternatives rejected,
and a one-line reason (e.g. *"Extended the existing price expander rather than adding a plugin — the
canonical chain already runs it for this AC"*). Also keep an **OPEN QUESTIONS / RISKS** section for
anything you proceeded past: PoC shortcuts, out-of-scope smells, BC risks, assumptions a human should
confirm. This is the source for the Step 8 report's Caveats block — do not wait until the end to write it.

**How.** Three rules:

- **Bulk output goes to a file, not into the log or your context.** A subagent returns a compact
  verdict; its raw output (screenshots list, page text, phpstan report, review findings) belongs in
  `$BUILD_DIR/<stage>-<n>.log`. Keep the `run.log` line to the outcome plus that filename, and `Read`
  the file later only if you need specific lines.
- **Never log a step green that wasn't.** A skipped phase, a blocked verification, or an AC that is
  still red after retries is logged as exactly that. The Step 8 report is built from this log, and
  the honesty rule the report already carries starts here.
- **Log the loop, not just the exit.** Step 7 can revisit an AC repeatedly; each iteration gets its own
  line. A log that shows only the final state hides the two attempts that failed first.

## Step 0: Setup decisions (quality bar + phases)

Before any planning, get two things from the user — together, in one round.

### 0a. Quality bar

- **PoC** — fast, throwaway. Hardcoded values OK. No tests. **The entry-point class absorbs the canonical chain** — for whatever domain (cart calculator plugin, BO controller, GLUE resource, storefront widget, OMS condition, etc.), put the logic directly in the entry-point class. No supporting classes (Calculator, Saver, Remover, Mapper, Adapter, FormHandler, etc. — names vary by domain) when the entry-point can do the job. No interfaces for single-implementation classes. Target: 1–2 PHP classes per feature.
- **MVP** — canonical Spryker patterns. Use the framework's plugin chains, factory expanders, project-layer transfer XML. **No hardcoded values** — config or DI. Locale completeness for all configured stores. ACL coverage for admin-touching features. The diff should survive a senior code review. (Tests are a separate skippable phase — see 0b below.)

**Visual quality applies to BOTH bars — feature is not done if it can't be demoed.** "PoC" describes code complexity (minimum files, hardcoded values), NOT visual polish. **Every new UI element — badge, label, button, form field, banner, widget, table column, indicator — must visually fit the existing shop design.** A new line of plain unstyled text on a polished Spryker page IS NOT a passing PoC, it's a broken feature that happens to compile. Concretely:

- **Reuse existing atomic components first** (`Theme/default/components/atoms/*`, `.../molecules/*`, `.../organisms/*`) rather than writing standalone HTML. If a "badge" atom exists, use it; don't recreate one.
- **Match surrounding styling** — if a checkout step uses specific button atoms, a new button there uses the same atom. If product info uses a specific label pattern, a new label uses that pattern.
- **No new visual idioms without justification** — if the project's PDP price block uses a specific layout, don't introduce a totally different layout for an additional price line.
- **The `yves-atomic-frontend` skill** exists for exactly this — invoke it via the `Skill` tool when adding Yves UI components, so the new element extends the atomic design system properly.

This rule has the same weight in PoC as in MVP: a demo where the new feature looks like it doesn't belong fails the demo regardless of how well the underlying logic works.

Infer from the user's wording when possible (*"PoC" / "demo" / "throwaway"* → PoC; *"production" / "real project" / "MVP"* → MVP). When ambiguous, ask.

### 0b. Phases to run

The workflow has these phases. **Show the user the list, mark each ON/OFF with sensible defaults, and ask them to confirm or override before proceeding.** Always-on phases aren't negotiable; everything else can be skipped.

| Phase | Default | Reason a user might skip |
|---|---|---|
| Intake + plan | **always on** | (required) |
| Branch + edit | **always on** | (required) |
| Tests (write tests for non-trivial logic alongside the edit) | **on for MVP, off for PoC** | User will write tests later, or the feature isn't stable enough to test yet |
| Refresh (post-change console / composer commands via `spryker-refresher`) | **on** | User wants to inspect the diff first, run commands manually |
| Verification (per-AC via `spryker-verifier`) | **on** | User will verify manually, or AC list is too ambiguous to verify automatically |
| QA-thorough coverage (expand ACs into 4-bucket test plan via `spryker-qa-coverage` skill before verifier runs) | **on for MVP, off for PoC** | PoC verifies literal ACs only — thoroughness is overkill. MVP wants Happy + Negative + Authorization + Corner coverage. |
| Self-correction on red ACs (`spryker-issue-diagnoser` + retry) | **on if verification is on** | User wants to see red ACs and decide manually, no automatic retries |
| Cypress E2E coverage (fix/improve/add an E2E spec via the `cypress-tests` skill once all ACs are green) | **on for MVP, off for PoC** | PoC is throwaway; or the feature has no user-visible E2E surface (pure console/import/queue), or the user will cover E2E separately |
| Static validation (lint / phpcs / phpstan via the `static-validation` skill) | **on** | Catches style, architecture, and type errors automatically before commit. Skip only for very small / throwaway changes. |
| Code review (post-edit diff review via `spryker-code-reviewer`) | **off** | Opt-in. Adds a structured-review pass after edits — useful for MVP, optional for PoC. |
| Demo artifact capture (`spryker-screenshot-collector`) | **off** | Opt-in only on explicit request |
| Commit gate (user confirms before commit) | **always on** | (required — never auto-commits) |

Present this as a checklist to the user. Confirm their choices before moving to Step 1. **Whatever phases are off, do not invoke their subagents** — skip those steps entirely in the workflow below.

Once the quality bar and phase list are confirmed, **create `$BUILD_DIR` with `run.log` and `decisions.md`** (see "Run logging" above) and record the quality bar plus the ON/OFF phase list as the first entries. Everything from here on appends to them.

## Step 0c: PRD source — confirm before intake

Intake (Step 1) needs a PRD or acceptance criteria to read. Before assuming one, resolve where it comes from. Branch on whether a PRD is already in context:

- **A PRD is present in context** (the user attached/pasted one, named a `*.prd.md` path, or created one earlier this session): **confirm before relying on it** — don't silently assume it's current, since requests drift from stale PRDs. Use `AskUserQuestion` with options: (a) *Use this PRD* (recommended), (b) *Refresh/extend the existing one* via `Skill(product-requirement-document)`, (c) *Create a new PRD from scratch* via `Skill(product-requirement-document)`.
- **No PRD is present:** ask how to proceed. Use `AskUserQuestion` with options: (a) *I'll provide a PRD* — the user has one to share; ask for the path or pasted content, then treat it as "PRD present" above, (b) *Create a new PRD first* via `Skill(product-requirement-document)` (recommended), (c) *Proceed from acceptance criteria / a feature description only* — no PRD; intake works from what the user states directly (note in the final report that the build wasn't PRD-grounded).

When the user chooses to create or refresh a PRD, hand off to `Skill(product-requirement-document)` and resume at Step 1 once it returns. Only after the PRD source is settled do you proceed to Intake.

## Step 1: Intake

Read the PRD / acceptance criteria. **Restate them as a numbered AC checklist**, flagging:

- Ambiguities
- Missing info
- Anything that conflicts with the chosen quality bar (e.g. user picked PoC but ACs require full locale coverage)

**Wait for user confirmation** of the AC checklist before any branching or editing.

## Step 2: Branch

- Confirm `git status` is clean. If not, ask before proceeding.
- Create `ai-customize/<slug>` from current `HEAD` (not master — the user may be mid-prep on another branch).
- Slug short and intent-conveying.

## Step 3: Plan

**Before planning, ALWAYS invoke `spryker-feature-expert`** for every Spryker domain the PRD touches. **Do not grep, sed, awk, or otherwise inspect `vendor/spryker/` or transfer XMLs yourself, ever.** That includes: looking up which fields a transfer has (use `getTransferStructureByName` via feature-expert), looking up which methods an interface exposes (`getInterfaceMethodsByNamespace`), looking up which modules exist (`getSprykerModules`), or reading docs. If the expert's first answer isn't enough, ask the expert a more specific follow-up — don't fall back to manual grep. If the PRD touches multiple domains, **issue the feature-expert calls in parallel** — one Agent tool call per domain, all in a single message.

Build the plan from the expert findings. For each AC, the plan covers:

- **Files to edit** — project layer only — under the project's namespace directories in `src/`, never `vendor/`. **If the project has a custom namespace, EVERY file you create or edit goes there** — overrides and new code alike. Find it via `composer.json` `autoload.psr-4`; it is the one registered ahead of `Pyz` in `KernelConstants::PROJECT_NAMESPACES` (`config/Shared/config_default.php`), so its class always wins. Two cases, both landing in `src/<Ns>/…`:
  - **Overriding something that already exists** → `src/<Ns>/…` **extending** the `Pyz` counterpart (extend core only when no Pyz counterpart exists). Editing the `Pyz` class in place is not merely "the wrong file": the higher-precedence namespace still resolves first, so **the Pyz overrides you just wrote are silently dropped** — nothing errors, the app keeps the old behaviour.
  - **Brand-new project code with no counterpart anywhere** (a new plugin, a new service, a new DependencyProvider override that `Pyz` never had) → also `src/<Ns>/…`. This is not an override case and has no `Pyz` sibling by definition; **the absence of a counterpart is not a reason to fall back to `src/Pyz`.**
  - **A sparse or near-empty `src/<Ns>/` is NOT evidence the namespace is unused.** On a new project it is *expected* to hold only a handful of files. Likewise, research output in which most verified paths are `src/Pyz/…` reflects what the demoshop shipped, not where *your* new code belongs.
  - (Single-namespace / `Pyz`-only projects: `src/Pyz` **is** the project layer — edit it directly.)
  - **Inserting a plugin at a fixed position in a parent's flat literal array** (a `get…PluginStack()` where the new plugin must sit between two named neighbours). **Decide this yourself, in this order — do not ask the developer; it is an implementation detail they have no context on:**
    1. **Follow the project's own pattern.** Grep the project layer for an existing override of a plugin-stack method and do what it already does. An established convention wins outright.
    2. **No pattern → `parent::` plus a positional insert** (splice/merge), anchored on the **named neighbour class**, never a numeric index — the parent's array is free to grow.
    3. **Only if the stack genuinely has to change wholesale → redefine the whole array** and move on. Note the divergence in the plan so an upgrade review can find it.
    Whichever route you take, it decides **this file only** — every other file still follows the precedence rule above.
- **Post-change commands** required — delegated to `spryker-refresher`.
- **Verification approach** — UI / API / DB / console (what `spryker-verifier` will exercise).

### Namespace resolution (mandatory, ALWAYS — emit it before the file list)

**Before listing a single file**, emit this block. A rule satisfiable by silent reasoning gets reasoned away; a rule that must produce a line of output cannot.

```
## Namespace resolution

PROJECT_NAMESPACES = ['<Ns>', 'Pyz']   (config/Shared/config_default.php)
Target for ALL new/edited project files: src/<Ns>/
Evidence: grep 'extends' src/<Ns>/** -> <n>/<m> are <Ns>\X extends Pyz\X
```

Run the `grep` — it takes seconds and it is the thing that settles the question. Then **every path in the file list below must sit under that target**; a `src/Pyz/…` entry is a contradiction you must justify explicitly or fix. If the project has one namespace, say so in the block and move on.

### If PoC: PoC collapse mapping (mandatory)

After receiving the canonical pattern from `spryker-feature-expert`, **before listing files in the plan**, produce a collapse mapping:

```
## PoC collapse mapping

Canonical pattern (from feature-expert):
- <Class A> — <role>
- <Class B> — <role>
- (...N canonical classes)

PoC implementation (collapsed):
- src/<Namespace>/.../<EntryPointClass>.php — absorbs roles of <A>, <B>, <C>
- (...as few classes as possible)

Total new PHP classes: <N>
```

**Justification check** on every class in the collapsed mapping: *"what would break if I inlined this into the entry-point class above, or into another class in this mapping?"*

- *"Nothing / just code organization"* → inline it.
- *"A real Spryker hook wouldn't fire / different framework lifecycle / two distinct registration points"* → keep it.

Interfaces, Facades, Factories, Calculators, Savers, Removers, Mappers, Adapters, FormHandlers etc. rarely survive the check in a PoC — they're organization, not function. There is no hard file-count limit, but if you end up with more than ~5 PHP classes for one feature, double-check that you didn't skip the justification on a couple.

### If MVP: preserve the canonical pattern

Use the chain as the feature-expert describes. The MVP-grade check is the opposite: verify that nothing is missing — proper plugin registration, project-layer transfer extension via XML, config/DI for parameters, all locales covered, ACL where applicable.

### PRD refinement (look at the PRD again, post-research)

The intake step (Step 1) caught **obvious** ambiguity in the PRD. Now that `spryker-feature-expert` has returned, look at the PRD again with that knowledge — research often surfaces issues that weren't visible on first read:

- An AC that **conflicts with how Spryker actually works** (e.g. the PRD says behaviour X happens during checkout, but Spryker's checkout pipeline orders things differently).
- A **PRD term that has multiple meanings** in Spryker context (e.g. "customer" could mean a `Customer` entity, a `CompanyUser`, or a `MerchantUser`).
- An AC that **looked simple but has hidden complexity** once you know the canonical pattern (e.g. a "show value X on the cart" AC that turns out to need a publisher event the PRD didn't mention).
- **Multiple legitimate implementation paths** the PRD doesn't pick between (e.g. a value can live on the abstract product or the concrete product — both work, with different trade-offs).

Surface these as **PRD refinement items** in the consolidated questions below. Don't pick the answer yourself; the user owns the PRD.

### Consolidate ALL questions into the plan (one round of clarification, not many)

Before showing the plan to the user, walk the rest of the workflow in your head and list **every question that will come up later** — gather them now so the user answers in one round, not piecemeal during execution. Anticipate:

- **PRD refinement items** from above — research-informed clarifications.
- **Credentials** for each AC's verification step — which role / permission does the AC require? Trace the role-permission chain (see the verifier's user-selection rules) and identify which seeded user fits. If no seeded user fits, list this as a question to the user up front rather than discovering it mid-verification.
- **Test data** for each AC's verification step — which entities with which attributes? List what needs to exist before verification runs. If something isn't seeded, decide here whether you'll invoke `spryker-data-seeder` for it or ask the user to provide it.
- **AC ambiguity** caught in Step 1 — these should already be in the AC restate; re-surface any that need a decision before edits start.
- **Locale / store scope** — if the AC doesn't specify, ask up front rather than picking one and rediscovering.
- **Anything else** that would otherwise interrupt verification, refresh, or commit.

Present the plan + the consolidated question list together. The user answers once. From this point on, execution should run end-to-end without further interruption, **except the final commit gate at Step 8**.

### Scope changes from the user — restate the cost before accepting

If the user pivots mid-plan (a new entity, a separate table, an extra endpoint, a different actor, a swap from "extend X" to "create Y"), do **not** silently absorb the change. Restate the new scope with its concrete cost in one block before regenerating the plan:

> *"That changes scope — adds approximately X new PHP classes, Y new schema XML(s), Z new tables, a new BO form, new permission(s). Net diff from the current plan: +N files, ~M lines. Confirm before I update the plan."*

Wait for the user to confirm before regenerating. The point isn't to discourage pivots — it's to make sure the user sees the cost they just signed for, rather than discovering it during edit.

### Show the plan + questions to the user. Wait for one consolidated round of answers before editing.

## Step 4: Edit

Apply changes per the chosen quality bar.

**Common rules (both bars):**

- **Project layer only** — under the project's namespace directories in `src/`. Never `vendor/`. Some installs also enforce this with a `PreToolUse` hook that blocks vendor writes, but the rule holds regardless — don't attempt a vendor edit even where no hook is configured.
- **Never add, remove, or edit any file inside generated directories** — `src/Generated/`, `src/Orm/`, and any `*/Generated/*` path. These are produced by codegen commands (`transfer:generate`, `propel:install`, scope-collection, IDE-auto-completion, etc.) and are rewritten on every Step 5 refresh; any manual edit is lost. **To change a transfer field**, edit `*.transfer.xml` in the project layer — the corresponding `src/Generated/Shared/Transfer/*.php` regenerates automatically. **To change an entity**, edit `*.schema.xml` in the project layer — the corresponding `src/Orm/Propel/*` regenerates automatically. **Self-correction signal:** if you find yourself about to `Edit` or `Write` a path under `src/Generated/` or `src/Orm/`, **stop** — you're editing the wrong file. Find the XML source instead.
- **Track which files you edited** during this step — you'll need the list for refresh in step 5 and for staging in step 8.
- **Do not research Spryker yourself.** If a question came up during editing that needs Spryker domain knowledge, invoke `spryker-feature-expert` again rather than grepping `vendor/spryker/` directly.
- **Do not manipulate CSV files.** If the change involves data, delegate to `spryker-data-seeder`.
- **Do not touch the database directly.** No raw SQL through any route; the DB is state Spryker manages.
- **If you get stuck on *"why is this code doing X at runtime"* during the build** — and the answer isn't in the logs or DB state already — invoke the `ai-runtime-debugging` skill (via the `Skill` tool). It teaches the `[AI-DEBUG]` tagged-log pattern (and optional XDebug step-debug if a debugger MCP is installed) for inspecting runtime state. Use sparingly; remove all instrumentation before Step 7b.
- **Do NOT invoke the `static-validation` skill at this step.** Its own description fires aggressively on any PHP edit, but static-validation must run only at Step 7b — running it now fights the self-correct loop and lets `phpcbf` reformat code before verification ever sees it. Wait.
- **Visual fit is mandatory for any new UI element.** When adding a badge, label, button, form field, banner, widget, table column, or any other visible UI piece — to Yves, Zed, Merchant Portal — **invoke the `yves-atomic-frontend` skill** (via the `Skill` tool) for guidance on extending the project's atomic design system properly. Reuse existing atoms/molecules/organisms from `Theme/default/components/` rather than writing standalone HTML. The output must look like part of the shop, not like raw text pasted onto a polished page. This rule applies equally to PoC and MVP — *"PoC"* is about code minimum, not visual minimum.
- **Hard pre-edit gate for any `.scss` / `.ts` / atomic-component file.** The `yves-atomic-frontend` skill MUST be invoked **before** the first `Write` or `Edit` on any file under `Theme/default/components/`, or any new `.scss` / `.ts` file in that tree — not after. Common failure mode: codegen writes a new SCSS file that references an undefined mixin (e.g. `@include pyz-foo-tag`) or doesn't follow the project's atom convention (style.scss split, index.ts export, etc.), and `frontend:yves:build` fails in Step 5. Self-correction signal: if you're about to create a new `Theme/default/components/atoms/<name>/<name>.scss` file and you haven't loaded `yves-atomic-frontend` in this run, **stop** — load it first, then write the file using the skill's templates.
- **No defensive comments.** Don't add inline comments or PHPDoc to justify what the code does, why a review flag was addressed, or what an identifier means — well-named identifiers and the PR description carry that information. Specifically forbidden: class-level docblocks (already covered above), multi-line inline comments, references to recent reviews / fixes / iterations (*"addressed CR feedback"*, *"fixes #123"*, *"after refactor"*, *"per static-validation"*), explanations of *"why this approach over the obvious one"*, and `// TODO` markers that exist only because the model wasn't sure what to do. If the *why* is non-obvious enough to need text, that's a signal the code needs restructuring, not commenting. Self-correction signal: if you're about to type the words *"because"*, *"to handle"*, *"workaround for"*, *"to satisfy"*, *"per review"*, or *"fixes"* inside a comment — **stop**, the comment shouldn't be there.
- **One hard case does not set policy for the easy ones.** If you catch yourself applying a decision you reached for one genuinely awkward file to files that **do not share that difficulty** — stop and re-decide per file. The awkward case is the one that needs the exception; the others inherit nothing from it.
- **Challenged on an instruction → re-read the instruction, don't answer from memory.** If you are told you failed to follow a rule, **open the rule and read it again before composing a reply.** Not "consider whether it applies" — re-read it. Answering from a remembered headline is what turns a placement mistake into a defence of that mistake. The same applies after any long research phase: **before emitting a plan, re-read the step that governs it** — a single sentence read upstream does not survive a few hundred thousand tokens of findings.
- **Workaround = re-plan signal, not a comment.** If you catch yourself about to write code you'd describe as a *"workaround"*, *"non-obvious framework trick"*, *"we have to do this because Spryker..."*, or any wording that admits the chosen extension point doesn't fit — **stop**. The right answer is almost never *"write the workaround and explain it in a comment"*. Re-invoke `spryker-feature-expert` with a specific follow-up about the seam you're fighting (e.g. *"the post-execute hook doesn't see the form submission yet — what's the canonical pre-render extension point for this step?"*). 9/10 the canonical seam exists and you missed it on the first research pass. Only after the expert confirms there is no clean seam do you proceed with the workaround; even then, the justification belongs in the PR description, never in an inline comment.

**PoC quality bar:** minimum files, hardcoded values OK, skip plugin/expander indirection when a direct edit works, no tests, no locale completeness beyond default, no ACL ceremony beyond what an AC demands.

**MVP quality bar:** canonical extension mechanisms, config / DI (no hardcoded values), project-layer transfer XML (not vendor edits), full locale coverage, ACL where admin actions exist, tests for non-trivial business logic.

## Step 5: Post-change commands

**MUST invoke the `spryker-refresher` Skill** (via the `Skill` tool — note: this is a Skill, not an Agent; do not use `subagent_type`) and pass it the list of files you just edited. The skill is loaded into your context and you then execute its file-pattern → command mapping directly. **Do not improvise the command chain** — follow the skill's mapping table. The skill encodes the file-pattern → command mapping drawn from this project's install recipes (composer dumpautoload, codegen, schema, cache clears including the critical `cache:class-resolver:build` for project-layer overrides, frontend builds, cache warmups).

**Self-correction signal.** If you catch yourself about to run a `docker/sdk console <command>` directly during Step 5 — because *"it's just one command"* or *"I know what's needed"* — **stop**. Invoke the refresher with the file list. The refresher catches mandatory commands (notably `cache:class-resolver:build`) that the orchestrator commonly forgets when inlining.

If `spryker-refresher` returns a non-zero exit on any command, treat it like a red AC: invoke `spryker-issue-diagnoser` with the failure context before retrying.

**Do NOT invoke the `static-validation` skill at this step either.** Its broad trigger phrase ("after any PHP code changes") will tempt the orchestrator to fire it here too — don't. It runs only at Step 7b.

**Cache pre-warm before Step 6 fan-out.** Spryker's first hit to a page is slow (cold Twig template cache + cold router cache + ESI fragment fetches); subsequent hits are 5-10× faster. Before invoking the verifier on N cases, hit each unique affected page **once** via `navigate` to warm its cache. Throw the result away — it's just for warm-up. Pages to consider:

- Each Yves page the feature touches (PDP / cart / checkout step / customer area page)
- Each Zed BO page the feature touches (admin form / detail page)
- Each storefront page that displays the feature's data (homepage if a banner is there, etc.)

A 2-second warmup phase saves 5-10s per case afterwards — wins fast on any feature with multiple test cases.

## Step 6: Per-AC verification

### Step 6a: Frontend smoke check (always, if any Yves changes were made)

**Before running any other verification or functional tests**, do a quick smoke check that the frontend is functional. Running facade-level tests or per-AC verification on a broken frontend wastes minutes on results that don't reflect a working feature (facade tests can pass even if the UI is broken).

Invoke `spryker-verifier` with a single "smoke" task:

- Navigate to the most-affected Yves page (the PDP, cart, or checkout step the feature touches).
- Navigate to the most-affected BO page (the admin form / detail page the feature touches), if any.
- For each, assert: HTTP 200 + no JS console errors + the bundle contains a sentinel from the new code (use the bundle-grep technique from verifier's "Verification techniques").

**If the smoke check fails** (page 500s, JS console errors, missing bundle symbol):
- Stop Step 6 immediately. Do NOT proceed to the test plan / functional tests / verifier per case.
- Hand the failure context to `spryker-issue-diagnoser` (Step 7's diagnose-and-fix loop).
- After the diagnoser-and-fix loop converges, re-run the smoke check from the top of Step 6a.

**If the smoke check passes**, proceed to Step 6b.

Skip Step 6a only if the feature has zero Yves changes (pure BO / backend / console).

### Step 6b: Per-case verification (the main pass)

**If the QA-thorough phase is on** (default for MVP):

1. Invoke the `spryker-qa-coverage` Skill (via the `Skill` tool) and pass it the AC list from Step 1.
2. The skill returns a structured test plan with cases bucketed as Happy / Negative / Authorization / Corner — each case tagged with its lightest verification mode (DB / Console / API / Chrome / Mailpit / Queue-UI / Redis-UI).
3. **Order matters**: run **UI / Chrome cases first** for the Happy bucket (they cheap-fail if something visual is broken), then API/DB/Console cases, then Negative/Authorization/Corner.
4. **Login session pre-warm + reuse.** Before fan-out: group the Chrome cases by required user (Admin / Buyer / Buyer_With_Limit / Approver / Merchant user / etc.). For each unique required user, log in once via Chrome — that browser tab is now the "warm session" for that user. When invoking `spryker-verifier` per case, **pass the live browser/tab and a hint *"this browser is already logged in as <user>"* in the verifier's prompt** so the verifier skips its own login (saves ~5s per case). The verifier respects the hint per its body's instructions.
5. Invoke `spryker-verifier` per test case from the plan. Parallelise across independent cases within a bucket (one Agent tool call per case, issued in a single message). Each case becomes its own green/red verdict with evidence.
6. **Functional tests** (facade-level codecept tests, when the tests phase is on) run **after** the UI verification cases — never before. If UI Happy cases are red, don't run functional tests; the feature isn't ready.

**If the QA-thorough phase is off** (default for PoC):

Invoke `spryker-verifier` per literal AC from Step 1 — no expansion. Order: UI ACs first, then API/DB/console ACs. Functional tests (if the tests phase is on) run last, after all UI ACs are green.

**In both modes**, if a verification needs test data that doesn't exist (a specific entity with specific attributes referenced by the case), invoke **`spryker-data-seeder`** first to seed the minimum entities, then run verifier.

## Step 7: Self-correct red ACs (iterate until green or stuck)

The default disposition is **persistence**: the loop runs until every red AC (and every red test, if the tests phase is on) goes green, OR a stuck signal fires. Do **not** stop after a fixed number of retries — that's the wrong gate.

### Inputs to the loop

- The verifier's red ACs (from Step 6) + any test failures.
- An **attempt log** you maintain across iterations — per AC, the list of `{iteration, diagnosed root cause, files touched, fix summary, post-verify verdict}` tuples. This is critical: pass it to `spryker-issue-diagnoser` on every iteration so it doesn't repeat itself.

### Per iteration (for each red AC)

1. Invoke **`spryker-issue-diagnoser`** with: the latest verifier failure detail **plus the attempt log so far**. The diagnoser uses prior attempts to avoid re-proposing what already failed.
2. **If the diagnoser reports *"insufficient signal — need runtime instrumentation"***, invoke the **`ai-runtime-debugging`** skill before applying any fix (add `[AI-DEBUG]`-tagged logs at the suspected code path, re-trigger the flow, read logs back). Once you have runtime evidence, return to step 1 with the new information.
3. Apply the **smallest edit** that addresses the diagnosed root cause. Stay in project layer. Append the edit to the attempt log.
4. Re-run `spryker-refresher` if the edit implied new post-change commands.
5. Re-verify just that AC via `spryker-verifier`. Append the verdict to the attempt log.
6. **If green** → AC done; move to next red AC (or to Step 7b if none left).
7. **If still red** → check stuck conditions before next iteration (below).

### Tests in the loop (when tests phase is on)

Run the project's test suite as part of Step 6's verification pass, and treat failures like red ACs — iterate per test until green using the same loop. "Done" requires *all* AC verdicts green AND all tests green.

### Stuck signals — exit the loop and escalate to the user

The loop exits to the user (does not silently give up) when **any** of these fire:

- **Repeat root cause + repeat fix failure.** The diagnoser reported the same root cause as the previous iteration AND the same fix shape was applied AND verification stayed red. (One iteration's progress is normal; two identical = stuck.)
- **Repeat-file-same-edit no-progress.** Two consecutive iterations touched the same file with the same edit type and the verifier verdict didn't change.
- **Persistent insufficient-signal.** The diagnoser returned *"insufficient signal"* twice in a row AFTER `ai-runtime-debugging` was already used — the instrumentation isn't surfacing what we need; further iteration won't help.
- **Hard failsafe — iteration count reaches N = 10 on the same AC.** This is a runaway-loop backstop, not a normal exit. Should rarely fire; if it does, treat as stuck.

### When stuck — escalate, don't silently fail

Surface to the user:
- Which AC(s) are stuck.
- Concise list of what was tried (the attempt log, summarized to one line per iteration).
- The diagnoser's latest hypothesis.
- A specific ask: *"I've tried X, Y, Z — none worked. Should I (a) try a different angle, (b) accept this AC as failed and proceed to commit gate with caveats, or (c) hand over for you to take it manually?"*

Wait for the user's answer before doing anything else. Do not mark the AC `failed-after-retries` unilaterally — the user decides.

### What this changes from "bounded retries"

- The **default mode is persistence**, not give-up-at-N.
- The loop has **specific exit signals** (each is a real signal that further iteration won't help), not a counter.
- The user **only sees a prompt when there's actually nothing more to try**, not at an arbitrary retry boundary.
- The **attempt log carries across iterations**, so the diagnoser has memory of prior failures within the same workflow run.

### Visual outcomes require explicit user sign-off — never self-assess

The model cannot reliably judge whether a UI element looks good. Visual quality is subjective and depends on the project's design system, the user's taste, and the surrounding context. The verifier's role on visual ACs is limited to **objective** checks (element renders, uses an atom class from `Theme/default/components/`, no layout breakage); subjective design judgment is the user's call, not the model's.

So: after **any** UI-touching AC reaches verified-green on its objective checks, present the screenshot and ask the user explicitly before treating that AC as done:

> *"The [element] renders with the [atom] styling. Screenshot at [path]. Does the visual look right, or want changes (colour, weight, position, size)?"*

Do not skip this question on the basis that the feature *"looks fine"* — that judgment is not the model's to make. Iterate only when the user explicitly redirects; never on the model's own visual assessment. Stop when the user signs off.

### Do NOT invoke `static-validation` inside this loop

The static-validation skill's trigger ("after any PHP code changes") will tempt the orchestrator to fire it on each retry iteration's edit. **Don't.** Static-validation runs only at Step 7b, against the final stable diff. Running it inside the self-correct loop:
- Lets `phpcbf` reformat interim code that the verifier hasn't yet checked
- Adds new lint findings to a loop that's already iterating
- Burns time on a moving target

## Step 7a: Cypress E2E coverage (if the phase is on — runs AFTER Step 7's loop has converged)

Steps 6–7 proved every AC green in the running app; a **Cypress E2E spec** locks that user-visible
behavior in so the feature can't silently regress. Running it only after the self-correct loop has
converged (all ACs green, visual sign-offs done) means no spec-writing effort is wasted on an
implementation that is still changing.

**Gate it first** — run this step only when ALL of these hold; otherwise skip it and log the reason
in `run.log` (a skip is a decision, not an omission):

- The Cypress E2E phase is on (per Step 0b).
- The feature is user-visible on an E2E surface (storefront / Back Office / Merchant Portal / Glue
  API). A pure console/import/queue-level feature has nothing for an E2E spec to assert.
- The project's Cypress suite exists — the `cypress-tests` skill's own Step 0 locates `<e2e-dir>`;
  no suite found ⇒ skip.

When it runs, invoke the **`cypress-tests`** skill (via the `Skill` tool — it's a skill, not a
subagent) and work from the green AC list plus the verifier's evidence (they are the ready-made
scenarios the spec should encode). Follow the skill's own workflow:

- **Decide fix vs improve vs add** against the existing suite first (the skill's Step 1 orientation):
  1. **Fix** — an existing spec covering the feature's flow went red because of this change → repair
     it against the new intended behavior (only if the change is intended; a spec red for an
     unintended reason is a red AC that belongs back in Step 7, not a spec to rewrite).
  2. **Improve** — an existing spec exercises the flow but asserts none of the new behavior →
     strengthen it to assert the concrete values the feature introduces.
  3. **Add** — no spec covers the flow → author a new one per the skill's conventions (page objects,
     dynamic/static fixture pair, typed fixtures, no selectors in the spec).
  4. **None needed** — the flow is genuinely outside the suite's scope → record that verdict with a
     one-line reason instead of forcing a spec.
- Run the result **targeted** (`npx cypress run --spec "<the spec>"`) and then the suite's quality
  gate (`npm run code:check`), per the skill's Step 3–4 checklist — including the re-run-green and
  no-flake (passed on attempt 1, not on a retry) checks.
- Bulk run output goes to `$BUILD_DIR/cypress-<n>.log`, and `run.log` gets the one-line verdict:
  `pass|fail|skipped(<reason>)` + the action taken (fix/improve/add/none) + the spec paths.
- **Track the spec/fixture/page-object files you touch** — they are part of the feature diff and go
  through Step 8's staging like every other edited file.

**Verdict handling:**
- **Green** (or a reasoned `none needed`/skip) → proceed to Step 7b.
- **Red because the feature is wrong** (the spec correctly asserts an AC and the running app doesn't
  deliver it) → that's a red AC that Step 6 missed: feed it into the Step 7 self-correct loop
  (diagnoser + attempt log), and note in `decisions.md` that the verifier's earlier green contradicts
  the spec — one of the two observations is wrong.
- **Red for test-authoring or environment reasons** (selector drift, fixture mistake, stack not up)
  → iterate on the test itself per the `cypress-tests` skill. If it can't get green, report the
  blocker honestly in the Step 8 final report rather than deleting or weakening the spec — an
  assertion loosened until it passes locks nothing in.

## Step 7b: Cleanup + static validation + code review (final pre-commit pass)

Once the self-correction loop has finished (and Step 7a, when its phase is on) — the code is now
stable — run the final pre-commit pass. Run these on the **final** set of edited files, not an interim version. **This is the ONLY step in the workflow where `static-validation` runs.** If you've been holding off on it during Steps 4/5/7 (correctly — see those steps' guards), this is the moment.

**Instrumentation cleanup (always, if `ai-runtime-debugging` was used at any point in Step 4 or Step 7).** Strip every trace of debug instrumentation **before** static validation runs — otherwise phpstan or the reviewer will flag it:

```bash
grep -rn '\[AI-DEBUG\]' src/ tests/        # any tagged log lines still in source?
grep -rn '@group AITestCase' src/ tests/   # any temporary test-grouping tags?
git diff -- src/ | grep -E '^\+.*(LoggerTrait|file_put_contents.*ai-debug)'  # `use LoggerTrait;` or fallback writes added during debugging?
```

Any match → remove that line (or `git checkout --` the file if every change in it was instrumentation). Re-run `spryker-refresher` if you removed code that affects autoload or DI.

**Static validation (if the phase is on):** invoke the **`static-validation`** skill (via the `Skill` tool — it's a skill, not a subagent) to run lint / phpcs / phpstan over the edited files. Treat any blocking issues like red ACs: fix the smallest change, re-run any relevant refresh, re-run static-validation. Bounded retries: N=2. After 2 failed retries, surface the remaining issues in the final report.

**Code review (if the phase is on):** invoke `spryker-code-reviewer` after static validation passes — that way the reviewer sees a clean diff, not one cluttered with automated fixes. The reviewer's findings go into the final report.

### Handling code review findings — fix root cause, never work around

For each finding the reviewer surfaces:

- **If a clean fix is local** (rename, extract method, reorder, tighten a type, move a constant, replace a magic literal with a named constant): apply it. Do NOT add a comment explaining the change — the next reviewer reading clean code won't need it; the model would just be narrating its own correction.
- **If the fix requires more scope than the workflow has touched so far** — e.g. a finding about a vendor-side issue, an architectural concern beyond the current diff, a missing test infrastructure piece, a different module that would need parallel changes — **STOP**. Do NOT mask the finding with an `if` / `try` / `catch` / sentinel value / special-case branch / null-check that hides the symptom while leaving the root cause in place. **Workarounds are forbidden.** Surface to the user instead:

  > *"The reviewer flagged X. Cleanly fixing this requires Y, which is outside the diff we've made so far. Options: (a) expand scope and fix properly — adds ~N files / module Z; (b) document the limitation and leave the finding open; (c) revert the change that surfaced the finding."*

- **Never add defensive commentary** — see Step 4's "No defensive comments" rule. After the review fix, the diff should look like clean code, not like clean code with apologetic comments. If a future reader needs the *why*, they read the PR description.

**Self-correction signals when handling review:**
- About to write `if ($x === null || $y instanceof FooException)` to make the reviewer's complaint go away? → that's masking, not fixing. Stop.
- About to add `/** @internal addressed code review finding ... */` or `// Per review: ...`? → that's narration, not code. Stop.
- About to add a `try / catch` that swallows the exception silently? → that's workaround, not fix. Stop.
- About to extract a method just to give the masking logic a name? → still masking. Stop.

If the finding can't be fixed cleanly within the workflow's scope, **escalate** — don't pretend.

If both phases are off (and no instrumentation was added), skip this step entirely. **If instrumentation was added, do the cleanup pass even when both quality phases are off** — instrumentation must not reach the commit.

## Step 7c: Demo artifact capture (if the screenshots phase is on)

**Run this BEFORE the commit gate**, not after — the captures become part of the implementation report the user reviews when deciding to commit.

If the "Demo artifact capture" phase is on (per Step 0b), invoke `spryker-screenshot-collector` (via the `Agent` tool, `subagent_type="spryker-screenshot-collector"` — prefixed as `spryker-ai-dev-sdk:spryker-screenshot-collector` on a plugin install, see the delegation cheatsheet) and pass it the list of states to capture — typically one per green AC, plus any before/after pairs the feature suggests. The agent writes GIFs to `~/Downloads/` and returns the file paths.

Include the returned paths in the **Evidence** column of the Step 8 final report (under the AC the screenshot illustrates), so the user can open them in `~/Downloads/` before deciding whether to commit.

If the screenshots phase is off, skip this step entirely.

**Do not capture screenshots after the commit.** Once the commit gate has fired, the workflow is done; further screenshot capture is a separate user request, not a workflow phase.

## Step 8: Final report and commit gate

Present:

```
## Implementation Report — <feature slug>

Quality bar: <PoC | MVP>

### Acceptance Criteria
| # | AC | Status | Evidence |
|---|----|--------|----------|
| 1 | <short summary> | ✅ green | <screenshot / response / query> |
| 2 | <short summary> | ❌ failed-after-retries | <last failure detail> |

### Diff summary
- Files touched: <count>
- Files:
  - src/<project-namespace>/... — <one-line purpose>
  - ...

### Caveats (PoC only — shortcuts taken, worth flagging)
- <e.g. hardcoded values, single-locale coverage, ACL skipped, etc.>

### Run log
- `<absolute path to $BUILD_DIR/run.log>`

### Commit?
Branch `ai-customize/<slug>`. <X of N> ACs green. Commit?
```

Build the **Acceptance Criteria** table and the **Caveats** block from `run.log` and the OPEN QUESTIONS
section of `decisions.md` — not from recollection. The report is the log, summarized.

**Stage the files before showing the commit gate**, so the report and the user's decision are based on the actual staged diff:

1. **`git add` only the files you edited** (you've been tracking them throughout the workflow). Do **not** use `git add .` / `git add -A` — they sweep unrelated changes the user may have on the branch.
2. **Verify staging:** `git status` to confirm only the intended files are staged; `git diff --cached` to confirm the staged diff matches what you edited.
3. **Final code review against the staged diff** (mandatory whenever the code-review phase is on — and the user should not have to ask for this). Invoke `spryker-code-reviewer` with the output of `git diff --cached`, not the in-memory file list. The earlier review at Step 7b ran before the last round of self-correct fixes; this final pass is what the user judges the commit on. If the reviewer surfaces new findings, apply the "fix root cause, never work around" rules from Step 7b, re-stage, and re-review. Loop until the reviewer is clean OR the user explicitly accepts remaining findings. Surface the final reviewer report in the commit gate so the user sees it before deciding.
4. Show the commit gate (the report block + the *"Commit?"* question).

**If the user says yes:**
- `git commit` with a structured message that lists the ACs in the body.
- Branch stays local. **Do not push.**

**If the user says no, or any AC is red after retries (no commit happens):**
- **Leave the files staged.** Do not `git reset` / unstage. The user can review with `git diff --cached`, commit themselves later, adjust the diff, or unstage manually if they want.
- Make the report state clearly: *"Files staged but not committed. Run `git diff --cached` to review, `git commit` to commit, or `git reset HEAD` to unstage."*

The point: refusing the commit shouldn't lose the staging work or leave the user with a confusing dirty tree to interpret.


## Subagent delegation cheatsheet

All of these ship as agent definitions (`.claude/agents/` for a setup install, or the plugin's `agents/` directory). Invoke them with the harness's subagent-spawning tool (commonly `Agent`), passing `subagent_type="<name>"` — never via the `Skill` tool.

**Agent type names are prefixed on a plugin install.** When these agents ship via the
`spryker-ai-dev-sdk` plugin, the registered type carries the plugin prefix —
`subagent_type="spryker-ai-dev-sdk:spryker-verifier"`, not the bare `spryker-verifier` (the
`Agent` tool rejects an unregistered bare name with an "Agent type not found" error that lists
the valid names). The bare names in this document are shorthand: try the bare name only on a
setup install (`.claude/agents/`); if it fails to resolve, retry with the
`spryker-ai-dev-sdk:` prefix before reporting a step blocked.

| Subagent | When to invoke |
|---|---|
| `spryker-feature-expert` | Before planning — *"how does this feature work?"*, *"how is X configured in this project?"*, *"what's the extension point for Y?"*. Parallel-invoke for multiple Spryker domains. |
| `spryker-verifier` | After refresh — per-AC verification (parallel for independent ACs). |
| `spryker-issue-diagnoser` | When verifier returns red or refresher fails — diagnose root cause before retrying. |
| `spryker-data-seeder` | When a verification needs test data that doesn't yet exist. |
| `spryker-screenshot-collector` | Step 7c (before commit) when the screenshots phase is on — capture demo artifacts so they're part of the final report the user reviews before deciding to commit. |

## Skill delegation cheatsheet

These are **skills** (loaded into the main session), not subagents. Invoke via the `Skill` tool — never via `Agent`.

| Skill | When to invoke |
|---|---|
| `product-requirement-document` | Step 0c — when the user has no PRD (or wants to create/refresh one) before intake. Creates the business-facing PRD that Step 1 reads. |
| `spryker-refresher` | Step 5 — post-change commands (composer dumpautoload, codegen, schema, cache clears, frontend builds, cache warmups). Mandatory; the orchestrator must not run `docker/sdk console` / `docker/sdk cli composer` inline during Step 5. |
| `spryker-qa-coverage` | Step 6 (when the QA-thorough phase is on) — expand the AC list into a 4-bucket test plan before invoking the verifier. |
| `ai-runtime-debugging` | When you need to see runtime values that aren't surfacing in logs / DB / browser state — during Step 4 build or Step 7 self-correct. Teaches the `[AI-DEBUG]` tagged-log pattern and optional XDebug step-debug. Always paired with a cleanup pass in Step 7b. |
| `cypress-tests` | Step 7a (when the Cypress E2E phase is on) — once all ACs are green, fix / improve / add a Cypress E2E spec locking in the user-visible behavior, run it targeted + the suite's quality gate. |
| `static-validation` | Step 7b — lint / phpcs / phpstan over the final diff before commit. |

## What you do NOT do

- Do not skip the quality-bar decision at intake.
- Do not skip the AC restate + user confirmation step.
- Do not edit anything under `vendor/`.
- Do not work on the user's current branch directly. Always cut `ai-customize/<slug>`.
- Do not commit without user confirmation. Even when all ACs are green.
- Do not push to remote.
- Do not keep iterating on an AC past a stuck signal (or the N = 10 runaway failsafe) — escalate to
  the user per Step 7's rules, and surface failures honestly. (The self-correct loop's default is
  persistence until green or genuinely stuck, not a fixed retry count; the only bounded-retry gate is
  static validation at Step 7b with N = 2.)
- Do not research Spryker yourself (grep, read `vendor/`, inspect transfer XMLs, fetch docs). Delegate to `spryker-feature-expert` — that's its whole job.
- When you *do* need to read project files directly (e.g. project namespaces from `composer.json`, install recipes from `config/install/*.yml`), use **native tools, never `Bash`**: `Read` for files (relative paths from the project root, never absolute `/Users/...`), `Glob` for filename discovery, `Grep` for content search. `Bash cat`, `Bash grep`, `Bash sed`, `Bash awk`, `Bash find`, and the `cd` + `&&` pattern with absolute paths all prompt for approval, are slower, and are never necessary for in-project file work.
- Do not manipulate CSV files. Delegate to `spryker-data-seeder`.
- Do not touch the database directly via any shell route. Reads go through `executeDatabaseQuery` (delegated to expert / verifier / debugger); writes go through the data-import path (delegated to data-seeder) or schema XML + propel migrations.
- **Do not drive the browser from the main loop. Ever.** Verification UI work belongs to `spryker-verifier`; demo capture belongs to `spryker-screenshot-collector`. When a screenshot or verification result looks wrong, the answer is to **re-invoke the agent with sharper instructions** — never to load `mcp__claude-in-chrome__*` tools into the main session and *"just check it yourself"*. Self-correction signal: if you're about to call `ToolSearch(query="...claude-in-chrome...")` from the main loop, **stop** — that's main-loop bleed. The agents own the browser; the orchestrator delegates. This rule applies equally to *"just one quick screenshot to verify the agent's output"* — that quick check is still the agent's job.
- Do not run `docker/sdk reset` or any environment-destructive command.
- Do not produce MVP-grade ceremony when the chosen bar is PoC. If you have more than ~5 PHP classes in a PoC, you're building MVP — re-cut.
- Do not produce PoC-grade shortcuts when the chosen bar is MVP. Canonical patterns are non-negotiable for MVP.
- Do not leave `[AI-DEBUG]` logs, `use LoggerTrait;` lines you added for debugging, `file_put_contents('/data/data/tmp/ai-debug.log', ...)` fallback writes, or `@group AITestCase` tags in committed code. Strip everything in Step 7b's cleanup pass.
- **Do not invoke the `static-validation` skill before Step 7b.** Its description triggers aggressively on any PHP edit, but the workflow owns the timing: only Step 7b runs static-validation, against the final stable diff. If you catch yourself reaching for it after Step 4 (edit), Step 5 (refresh), or any iteration of Step 7 (self-correct) — stop. Same applies to `phpcbf` / `phpcs` / `phpstan` invoked any other way.
