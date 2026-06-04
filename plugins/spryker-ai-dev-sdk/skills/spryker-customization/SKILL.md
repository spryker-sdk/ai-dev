---
name: spryker-customization
description: >
  Use whenever the user wants to implement a Spryker customization from a PRD
  or acceptance criteria. Triggers include "build this", "implement this",
  "here is a PRD - build it", "add X for the demo", "build this PoC",
  "production-quality build". Drives the full workflow from intake to commit,
  choosing a quality bar (PoC or MVP) at the start and delegating focused
  work (research, verification, debugging, test-data setup, refresh,
  demo-artifact capture) to the spryker-* subagents. Never auto-commits;
  the user always confirms.
---

# Spryker Customization Workflow

Take a PRD or acceptance criteria and walk it to a committed branch. Invoke focused subagents via the `Agent` tool at the points called out below. User-facing interactions are limited to the consolidated planning gate and the commit gate.

## Step 0: Setup decisions (quality bar + phases)

Before any planning, get two things from the user — together, in one round.

### 0a. Quality bar

- **PoC** — fast, throwaway. Hardcoded values OK. No tests. **The entry-point class absorbs the canonical chain** — for whatever domain (cart calculator plugin, BO controller, GLUE resource, storefront widget, OMS condition, etc.), put the logic directly in the entry-point class. No supporting classes (Calculator, Saver, Remover, Mapper, Adapter, FormHandler, etc. — names vary by domain) when the entry-point can do the job. No interfaces for single-implementation classes. Target: 1–2 PHP classes per feature.
- **MVP** — canonical Spryker patterns. Use the framework's plugin chains, factory expanders, project-layer transfer XML. **No hardcoded values** — config or DI. Locale completeness for all configured stores. ACL coverage for admin-touching features. The diff should survive a senior code review. (Tests are a separate skippable phase — see 0b below.)

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
| Self-correction on red ACs (`spryker-debugger` + retry) | **on if verification is on** | User wants to see red ACs and decide manually, no automatic retries |
| Static validation (lint / phpcs / phpstan via the `static-validation` skill) | **on** | Catches style, architecture, and type errors automatically before commit. Skip only for very small / throwaway changes. |
| Code review (post-edit diff review via `spryker-code-reviewer`) | **off** | Opt-in. Adds a structured-review pass after edits — useful for MVP, optional for PoC. |
| Demo artifact capture (`spryker-screenshot-collector`) | **off** | Opt-in only on explicit request |
| Commit gate (user confirms before commit) | **always on** | (required — never auto-commits) |

Present this as a checklist to the user. Confirm their choices before moving to Step 1. **Whatever phases are off, do not invoke their subagents** — skip those steps entirely in the workflow below.

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

**Before planning, ALWAYS invoke `spryker-feature-expert`** for every Spryker domain the PRD touches. **Do not grep, sed, awk, or otherwise inspect `vendor/spryker/` or transfer XMLs yourself, ever.** That includes: looking up which fields a transfer has (use `mcp__spryker-project__getTransferStructureByName` via feature-expert), looking up which methods an interface exposes (`getInterfaceMethodsByNamespace`), looking up which modules exist (`getSprykerModules`), or reading docs. If the expert's first answer isn't enough, ask the expert a more specific follow-up — don't fall back to manual grep. If the PRD touches multiple domains, **issue the feature-expert calls in parallel** — one Agent tool call per domain, all in a single message.

Build the plan from the expert findings. For each AC, the plan covers:

- **Files to edit** — project layer only — under the project's namespace directories in `src/`, never `vendor/`. Find the project namespaces via `composer.json` `autoload.psr-4`; there may be more than one.
- **Post-change commands** required — delegated to `spryker-refresher`.
- **Verification approach** — UI / API / DB / console (what `spryker-verifier` will exercise).

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

### Show the plan + questions to the user. Wait for one consolidated round of answers before editing.

## Step 4: Edit

Apply changes per the chosen quality bar.

**Common rules (both bars):**

- **Project layer only** — under the project's namespace directories in `src/`. Never `vendor/`. The `PreToolUse` hook will block vendor writes; don't even attempt.
- **Track which files you edited** during this step — you'll need the list for refresh in step 5 and for staging in step 8.
- **Do not research Spryker yourself.** If a question came up during editing that needs Spryker domain knowledge, invoke `spryker-feature-expert` again rather than grepping `vendor/spryker/` directly.
- **Do not manipulate CSV files.** If the change involves data, delegate to `spryker-data-seeder`.
- **Do not touch the database directly.** No raw SQL through any route; the DB is state Spryker manages.

**PoC quality bar:** minimum files, hardcoded values OK, skip plugin/expander indirection when a direct edit works, no tests, no locale completeness beyond default, no ACL ceremony beyond what an AC demands.

**MVP quality bar:** canonical extension mechanisms, config / DI (no hardcoded values), project-layer transfer XML (not vendor edits), full locale coverage, ACL where admin actions exist, tests for non-trivial business logic.

## Step 5: Post-change commands

Invoke **`spryker-refresher`** (via `Agent` tool, `subagent_type="spryker-refresher"`) and pass it the list of files you just edited. It owns the file-pattern → command mapping drawn from this project's install recipes and runs them in dependency order (composer dumpautoload via `docker/sdk cli composer`, codegen via `docker/sdk console`, schema, cache clears, frontend builds, cache warmups).

If `spryker-refresher` returns a non-zero exit on any command, treat it like a red AC: invoke `spryker-debugger` with the failure context before retrying.

## Step 6: Per-AC verification

Invoke **`spryker-verifier`** for the AC checklist. Verifier returns green/red per AC plus raw evidence.

**Parallelise where ACs are independent.** If two or more ACs exercise different surfaces (e.g. one is BO-side, another is storefront-side) or different entities and have no ordering dependency between them, invoke `spryker-verifier` once per AC in a single message — one Agent tool call per AC, issued in parallel.

If a verification needs test data that doesn't exist (a specific entity with specific attributes referenced by the AC), invoke **`spryker-data-seeder`** first to seed the minimum entities, then run verifier.

## Step 7: Self-correct red ACs (bounded retries)

For each red AC, up to **N = 2 retries** per AC:

1. Invoke **`spryker-debugger`** with the verifier's failure context to get a root cause and suggested direction.
2. Apply the **smallest edit** that addresses the root cause. Stay in project layer. Track the new edits.
3. Re-run `spryker-refresher` if the edit implied new post-change commands.
4. Re-verify just that AC via `spryker-verifier`.

After 2 retries on the same AC: stop trying. Mark as `failed-after-retries` and surface it in the final report. The user decides whether to take over manually or ask for a different approach.

## Step 7b: Static validation + code review (final code-quality pass, if those phases are on)

Once the self-correction loop has finished — the code is now stable — run the final code-quality checks before the commit gate. Run these on the **final** set of edited files, not an interim version.

**Static validation (if the phase is on):** invoke the **`static-validation`** skill (via the `Skill` tool — it's a skill, not a subagent) to run lint / phpcs / phpstan over the edited files. Treat any blocking issues like red ACs: fix the smallest change, re-run any relevant refresh, re-run static-validation. Bounded retries: N=2. After 2 failed retries, surface the remaining issues in the final report.

**Code review (if the phase is on):** invoke `spryker-code-reviewer` after static validation passes — that way the reviewer sees a clean diff, not one cluttered with automated fixes. The reviewer's findings go into the final report.

If both phases are off, skip this step entirely.

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

### Commit?
Branch `ai-customize/<slug>`. <X of N> ACs green. Commit?
```

**Stage the files before showing the commit gate**, so the report and the user's decision are based on the actual staged diff:

1. **`git add` only the files you edited** (you've been tracking them throughout the workflow). Do **not** use `git add .` / `git add -A` — they sweep unrelated changes the user may have on the branch.
2. **Verify staging:** `git status` to confirm only the intended files are staged; `git diff --cached` to confirm the staged diff matches what you edited.
3. Show the commit gate (the report block + the *"Commit?"* question).

**If the user says yes:**
- `git commit` with a structured message that lists the ACs in the body.
- Branch stays local. **Do not push.**

**If the user says no, or any AC is red after retries (no commit happens):**
- **Leave the files staged.** Do not `git reset` / unstage. The user can review with `git diff --cached`, commit themselves later, adjust the diff, or unstage manually if they want.
- Make the report state clearly: *"Files staged but not committed. Run `git diff --cached` to review, `git commit` to commit, or `git reset HEAD` to unstage."*

The point: refusing the commit shouldn't lose the staging work or leave the user with a confusing dirty tree to interpret.

**Optional:** after a successful commit, if the user explicitly asks for demo material, invoke `spryker-screenshot-collector` to capture artifacts.

## Subagent delegation cheatsheet

All of these live under `.claude/agents/`. Invoke via the `Agent` tool with `subagent_type="<name>"` — never via the `Skill` tool.

| Subagent | When to invoke |
|---|---|
| `spryker-feature-expert` | Before planning — *"how does this feature work?"*, *"how is X configured in this project?"*, *"what's the extension point for Y?"*. Parallel-invoke for multiple Spryker domains. |
| `spryker-refresher` | After edits — runs the post-change console / composer chain. |
| `spryker-verifier` | After refresh — per-AC verification (parallel for independent ACs). |
| `spryker-debugger` | When verifier returns red or refresher fails — diagnose root cause before retrying. |
| `spryker-data-seeder` | When a verification needs test data that doesn't yet exist. |
| `spryker-screenshot-collector` | Only on explicit user request after green — capture demo artifacts. |

## What you do NOT do

- Do not skip the quality-bar decision at intake.
- Do not skip the AC restate + user confirmation step.
- Do not edit anything under `vendor/`.
- Do not work on the user's current branch directly. Always cut `ai-customize/<slug>`.
- Do not commit without user confirmation. Even when all ACs are green.
- Do not push to remote.
- Do not auto-retry past N = 2 per AC. Surface failures honestly.
- Do not research Spryker yourself (grep, read `vendor/`, inspect transfer XMLs, fetch docs). Delegate to `spryker-feature-expert` — that's its whole job.
- When you *do* need to read project files directly (e.g. project namespaces from `composer.json`, install recipes from `config/install/*.yml`), use **native tools, never `Bash`**: `Read` for files (relative paths from the project root, never absolute `/Users/...`), `Glob` for filename discovery, `Grep` for content search. `Bash cat`, `Bash grep`, `Bash sed`, `Bash awk`, `Bash find`, and the `cd` + `&&` pattern with absolute paths all prompt for approval, are slower, and are never necessary for in-project file work.
- Do not manipulate CSV files. Delegate to `spryker-data-seeder`.
- Do not touch the database directly via any shell route. Reads go through `executeDatabaseQuery` (delegated to expert / verifier / debugger); writes go through the data-import path (delegated to data-seeder) or schema XML + propel migrations.
- Do not drive the browser yourself. Verification UI work belongs to `spryker-verifier`; demo capture belongs to `spryker-screenshot-collector`.
- Do not run `docker/sdk reset` or any environment-destructive command.
- Do not produce MVP-grade ceremony when the chosen bar is PoC. If you have more than ~5 PHP classes in a PoC, you're building MVP — re-cut.
- Do not produce PoC-grade shortcuts when the chosen bar is MVP. Canonical patterns are non-negotiable for MVP.
