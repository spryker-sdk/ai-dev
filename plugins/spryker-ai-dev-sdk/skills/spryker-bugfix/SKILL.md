---
name: spryker-bugfix
description: >
  End-to-end bug-fixing workflow: from an optional tracker ticket (JIRA, GitHub Issues, or any
  service) or a plain bug description to a committed,
  validated, QA-accepted fix on a bugfix branch (autonomous mode adds a pushed Draft PR +
  CI watch). Orchestrates reproduce → root-cause → fix → functional tests → static checks →
  review → QA → final verification via the project's stage skills. Trigger — "fix this bug",
  "fix ticket XY-1122", "broken, reproduce and fix it": any bug symptom PLUS the expectation of a
  DELIVERED fix. NOT for a single isolated step (one test, "just run CI"), new features,
  refactors without a symptom, or investigation-only requests ("just find where it goes wrong,
  don't fix").
---

# Spryker Bug-Fixing Workflow

This skill drives a bug from intake to a delivered, validated fix. It is an **orchestrator**: each
stage delegates to an existing installed skill. Your job is to run the stages in order, carry context
between them, enforce the verification loop, and respect the operating mode the user picked.

> **Note — the ticket is optional.** The bug can come from a tracker ticket (JIRA, GitHub Issues, or
> any other service) **or** from a plain free-text description with no ticket at all. A ticket is never
> required — it is just one possible source of the bug context. When a ticket exists and the matching
> MCP/CLI is available, the skill pulls it for extra context; otherwise it works entirely from the
> description. Everything downstream (branch name, PR title, commit message) has a `no-ticket` fallback.

> **Note — the PR channel is capability-gated.** The push/PR/CI-watch stage (Step 11) needs a way to
> talk to the remote. Step 0 **probes** for one and records a `PR_CHANNEL`: `gh` (full — push + Draft PR
> + CI watch), `git-only` (can push a branch but has no PR API — commit + push, then hand the PR to the
> user), or `none` (no reachable remote / not a GitHub remote — **local-only**: commit and stop). Every
> GitHub-dependent action checks `PR_CHANNEL` first; if the needed capability is absent, that action is
> **skipped with a clear note in the report**, never attempted-and-failed. A missing or unauthenticated
> `gh` never aborts the run — it just downgrades how far Step 11 can go.

> **Note — Claude-only orchestration.** This skill is built to run on **Claude and the skills/agents
> already installed in the project** — nothing else. Every stage delegates to a bundled/installed
> `Skill(...)` or Claude subagent; no third-party MCP server is required for the core flow. The only
> external touchpoint is the **optional** Step 0 ticket pull (e.g. JIRA via the Atlassian MCP); if the
> ticket text is pasted or a free-text description is given, the whole workflow runs without any external
> MCP. Keep every subagent this skill spawns on a Claude model.

The core lessons this workflow enforces: a bug usually has *multiple* candidate paths, the visible
symptom is often downstream of the real cause, and a fix is not "done" until the running app proves
the symptom is gone **and** every gate is green. Build that rigor in regardless of which module,
feature, or layer the bug lives in.

**This file is the workflow spine only.** The full, authoritative instructions live verbatim in two
companion files in this directory:

- [stages.md](stages.md) — complete per-step instructions (Steps 0–12) + the stage→skill quick map.
  **Read the relevant § before executing each step below.**
- [reference.md](reference.md) — the run-lean context discipline (`$BUGFIX_DIR`, State Object),
  operating principles, the decision & question log, and the red-flags list. **Read it once at the
  start of every run, before Step 0.**

## Context discipline (summary)

Heavy work (Chrome, XDebug, codecept, phpcs/phpstan, review, final verification, CI logs) runs in
**subagents** that write raw output to files under `$BUGFIX_DIR`
(`$CLAUDE_PROJECT_DIR/.claude/.cache/spryker-bugfix/<bugfix-id>/`) and return only compact verdicts.
The orchestrator retains only the **State Object**: mode/base/branch/attempt, extra expectations, repro
summary, root-cause `file:line`, diff stat, per-gate verdicts (≤5 actionable items each), and log-file
pointers. Details: [reference.md](reference.md) § Run lean / § The run directory / § The State Object.

## Plan tracking (summary)

At the end of Step 0, **arrange the plan as a task list** with `TaskCreate` — one task per stage
(Steps 1–12) — and drive it with `TaskUpdate` as the run proceeds: mark a stage `in_progress` when you
enter it and `completed` when its gate is green; on a loopback, reopen the affected gate task(s) and add
a fresh attempt task. `TaskList` is the live, at-a-glance checklist a human (or a resumed run) can read
to see where the run stands — it **survives compaction and scheduled wake-ups**, complementing the
append-only `run.log` timeline (the log is the history; the task list is the current state). This is the
same pattern the PRD workflow uses. Details: [stages.md](stages.md) § Step 0 (plan task list) and
[reference.md](reference.md) § Plan task list.

## Principles (summary)

The bug is the contract — the fix is proven only when the user-visible symptom no longer reproduces,
with evidence. Carry context forward between stages; confirm the real executing path before fixing;
never report a stage green that wasn't; honor `.claude/rules/*`. **Autonomous mode never asks after
Step 0** — decide, proceed, and log a CRITICAL DECISION in `$BUGFIX_DIR/decisions.md`; the only
Autonomous stops are the stale/dirty base (Step 2) and `attempt > 3`. Maintain the append-only
decision & question log from Step 0 onward. Details: [reference.md](reference.md) § Operating
principles and § The decision & question log.

## Workflow spine

**Step 0 — Choose mode and gather context (ALWAYS FIRST).** One multi-tab `AskUserQuestion`: mode
(Autonomous vs Collaborative), the bug context (an **optional** ticket from any tracker **and/or** a
free-text description), base branch, env freshness, pre-push personal review, extra expectations beyond
the default scope. Then create `$BUGFIX_DIR` + `run.log` step logger, handle the env-reset decision,
and — only if a ticket was given and its tracker is reachable — pull the ticket for extra context.
Read [stages.md](stages.md) § Step 0 before executing this stage.

**Step 1 — Intake & framing.** Turn the context into a crisp problem statement: verbatim symptom,
affected actor/surface, environment, provisional module/layer scope. Keep it short — it aligns
later stages and subagents. Read [stages.md](stages.md) § Step 1 before executing this stage.

**Step 2 — Create the bugfix branch. ← SAFETY GATE.** Verify a clean tree and an up-to-date base
first; if dirty/stale, ask (Collaborative) or abort with a report (Autonomous). Branch
`bugfix/<jira-key>/<brief-kebab-name>` (lowercase; `no-ticket` fallback), then finalize
`$BUGFIX_DIR`. Read [stages.md](stages.md) § Step 2 before executing this stage.

**Step 3 — Reproduce & understand the bug.** Ensure the env is actually running, then delegate
reproduction to a subagent using `Skill(spryker-runtime)` (writes `$BUGFIX_DIR/repro-notes.md`,
returns a summary) and, in parallel, `Skill(spryker-docs-research)` for expected behavior. A bug you
couldn't reproduce is a hypothesis. Read [stages.md](stages.md) § Step 3 before executing this stage.

**Step 4 — Root-cause investigation. ← LOOP RE-ENTRY POINT.** The shared `attempt` counter is
defined here (starts at 1; +1 on every loopback from Steps 8/9/11; **hard stop when `attempt > 3`**).
Delegate runtime tracing to a subagent (`Skill(ai-runtime-debugging)` + `Skill(spryker-runtime)`)
that returns the confirmed executing path `file:line` + decisive values. When several candidates
survive in Autonomous mode, pick the evidence-backed one and log a CRITICAL DECISION.
Read [stages.md](stages.md) § Step 4 before executing this stage.

**Step 5 — Implement the fix & verify it resolves the bug.** Smallest correct change at the root
cause; parallel subagents for independent multi-file edits. **Mandatory:** re-run the Step 3 repro
and confirm the symptom is gone with the same evidence. Read [stages.md](stages.md) § Step 5 before
executing this stage.

**Step 6 — Functional test coverage (subagent).** One subagent with `Skill(codecept-functional)`:
enter testing mode first, add/update a regression test (ideally fails without the fix, passes with
it), write the run log to `$BUGFIX_DIR`, return only the pass/fail verdict + test paths. Confirm
green before moving on. Read [stages.md](stages.md) § Step 6 before executing this stage.

**Step 7 — Static validation (subagent).** Subagent runs `Skill(static-validation)`
(phpcbf/phpcs/phpstan on the diff), fixes and re-runs until clean, returns `clean` or the
remaining violations. Read [stages.md](stages.md) § Step 7 before executing this stage.

**Step 8 — Code review. ← GATE (loops back to Step 4).** Run `Skill(code-review)` (keep
only ≤5 blocker/major items); fix findings, then re-run static checks (and tests if behavior was
touched). Only blocker/major findings gate — **nits never loop**. Clean → Step 9; failing →
`attempt`+1 and return to Step 4 through 5→6→7→8; `attempt > 3` → **STOP and report** (no push, no
PR). Read [stages.md](stages.md) § Step 8 before executing this stage.

**Step 9 — QA acceptance (independent). ← GATE.** `Skill(spryker-qa-coverage)` in an isolated
subagent, handed the full session context (changed files, repro scenarios, env gotchas, regen
commands already run, ticket/description). QA must confirm the symptom is gone **E2E**. Accepted → continue;
rejected → treat as a Step 8 failure (loop to Step 4 within the budget).
Read [stages.md](stages.md) § Step 9 before executing this stage.

**Step 10 — Final verification before commit.** Re-run the affected functional tests (subagent),
then perform an **end-to-end final verification of the fix in the running app** (subagent — the
`spryker-verifier` agent, or `Skill(spryker-runtime)`) as the last gate before commit/push: confirm
the user-visible symptom is gone with fresh evidence. Returns a PASS/FAIL verdict + evidence; a FAIL
is treated as a Step 8 failure (loop to Step 4 within the budget).
Read [stages.md](stages.md) § Step 10 before executing this stage.

**Step 11 — Commit, push, Draft PR, watch loop. ← MODE GATE + PR-CHANNEL GATE.** Always commit on the
branch first. Then act by mode **and** by the `PR_CHANNEL` probed at Step 0. Collaborative (or pre-push
review requested): commit, then **STOP and present** for user confirmation — never push without it.
Autonomous: push and open a **Draft PR** *only if `PR_CHANNEL=gh`* (title `<TICKET-KEY>: <summary>`,
**no labels**), write `$BUGFIX_DIR/watch-state.md`, and arm a ~15-min watch loop
that re-hydrates from that file and polls `gh pr checks` — red check → subagent pulls the failed logs,
`attempt`+1, return to Step 4 through the **full** gate chain before re-pushing; `attempt > 3` → STOP,
cancel the loop, leave the PR in Draft, comment and report. If `PR_CHANNEL=git-only`, push the branch
and **skip** the PR + watch loop, handing the user a ready-to-run `gh pr create` line and the branch
name. If `PR_CHANNEL=none`, **skip push, PR, and watch entirely** — leave the committed branch local and
tell the user how to push it themselves. Read [stages.md](stages.md) § Step 11 before executing this stage.

**Step 12 — Final report (ALWAYS LAST, every terminal state).** Outcome, bug + root cause,
CRITICAL DECISIONS (headline section), OPEN QUESTIONS/RISKS, per-gate status (honest), extra
expectations handled, and — always the last line — the absolute path to the step log and decision
log. Read [stages.md](stages.md) § Step 12 before executing this stage.

## Quick map & red flags

The stage → skill quick-map table lives in [stages.md](stages.md) § Stage → skill quick map.
Before deviating from any gate, shortcut, or budget above, read
[reference.md](reference.md) § Red flags — stop and reconsider.
