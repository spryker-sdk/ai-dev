# Spryker Bugfix — Reference

Context discipline, operating principles, the decision & question log, and red flags for the
workflow spine in [SKILL.md](SKILL.md). Read this file once at the start of every run.

## Run lean — the orchestrator is a coordinator, not a worker

This is critical for **autonomous** runs, which can span hours and many loop iterations: the main
(orchestrator) context must stay small, because everything in it is re-read on every loopback and
every scheduled wake-up. A bloated orchestrator is the #1 failure mode here — it crowds out the
working room the later gates need and eventually destabilizes the run.

**The rule: heavy work happens in subagents; the orchestrator holds only compact state.** A "heavy"
stage is anything that produces bulk output — driving Chrome (screenshots, page text), runtime/XDebug
dumps, `codecept run` output, phpcs/phpstan reports, code-review findings, final-verification runs, and
`gh run view --log-failed`. Run each of those **inside a subagent** (via the `Agent` tool, pointing it
at the relevant `Skill(...)` or agent `subagent_type`), and require the subagent to:

- **write its raw output to a file** under the run directory `$BUGFIX_DIR` (e.g.
  `$BUGFIX_DIR/<stage>-attempt<N>.log`), and
- **return only a compact, structured verdict** as its final message — never the raw logs.

The orchestrator then keeps just that verdict. The **State Object** below is the *entire* working
memory the main loop needs; if something isn't in it, it lives in a file you can re-open on demand.

### The run directory (`$BUGFIX_DIR`)

Every file this skill produces — logs, decision log, repro notes, per-gate output, the watch handoff —
lives together in **one per-bugfix folder** so a run is self-contained and easy to find or clean up.
Anchor it to the project root Claude Code loaded (`$CLAUDE_PROJECT_DIR`, with a `$(pwd)` fallback) so
it is stable regardless of the current working directory:

```
${CLAUDE_PROJECT_DIR:-$(pwd)}/.claude/.cache/spryker-bugfix/<bugfix-id>/
```

`<bugfix-id>` is the ticket key (e.g. a JIRA key `CC-39232` or a GitHub issue number) when there is
one, else the sanitized branch suffix (e.g. `no-ticket-<brief-name>`). Set `BUGFIX_DIR` once at Step 0 and reference it everywhere; keep
**all** run files inside it — never scatter them elsewhere. The folder is the run's scratch space and
survives across loopbacks and wake-ups, which is exactly why the watch loop can re-hydrate from it.

### The State Object (the only thing the orchestrator must retain)

Keep this small block current in your head and mirror it into the step log. Everything else is on disk.

- `mode`, `base`, `branch`, `attempt` (current value), env-freshness choice.
- **`PR_CHANNEL`** — how the run can interact with a PR, probed once at Step 0: `gh` (push + Draft PR +
  CI watch), `git-only` (push a branch, no PR API), or `none` (local-only). Step 11 and the optional
  GitHub-issue ticket pull check this before any `gh`/push action; anything the channel can't do is
  skipped, not attempted.
- **Extra expectations** — any Step 0 delta from the standard scope (e.g. "also update JIRA", "fix
  related bugs too", "single module only"), so every later step honors it without re-asking.
- **Repro:** 1–3 line scenario summary + path to the full repro notes file.
- **Root cause:** the `file:line` references + one-sentence defect explanation.
- **Diff:** `git diff --stat` summary (files + ±lines), not the diff body.
- **Per-gate verdict:** for tests / static / review / QA / final-verification / remote-CI — `pass|fail`,
  a one-line reason, and the path to that gate's output file. For a failing gate, keep **only the ≤5
  actionable items** you must act on (e.g. blocker/major review findings, the failing test name), not
  the surrounding report.
- Pointers to the **decision log** and **step log** files.

If you ever need detail you dropped (a full stack trace, the complete review report), `Read` the file
the subagent wrote — pull the specific lines you need, don't reload the whole thing. Pulling 20 lines
on demand beats carrying 2,000 lines for the whole run.

### Plan task list

Alongside the State Object, the run keeps a **plan task list** (created at the end of Step 0 — see
[stages.md](stages.md) § Arrange the plan as a task list) with one task per stage. It is the run's
**live, at-a-glance position**: exactly one task `in_progress` (the current stage), completed tasks
behind it, pending stages ahead, and a fresh attempt task per loopback. Three surfaces, three roles —
keep them distinct:

- **`run.log`** — the append-only *timeline* (what happened, when).
- **`decisions.md`** — the *rationale* (why each fork was resolved a given way).
- **Task list (`TaskCreate`/`TaskUpdate`/`TaskList`)** — the *current state* (where the run is now).

The task list is the cheapest thing to read to re-orient — a resumed run or the watch loop should check
`TaskList` first, then `$BUGFIX_DIR/watch-state.md`, before touching the transcript. Because it lives
outside the conversation, it survives compaction and scheduled wake-ups. Drive it faithfully: never
mark a stage completed whose gate wasn't actually green (the same honesty rule as the step log).

## Operating principles

- **The bug is the contract.** Everything is judged against "does the original reported symptom no
  longer reproduce, with evidence". A green test suite is necessary but not sufficient — reproduce
  the *user-visible* symptom and confirm it's gone (see `spryker-qa-coverage`'s E2E-over-workaround rule).
- **Carry context forward.** Each stage hands the next one what it learned: the repro steps, the
  root-cause file:line references, the diff, the failing/added tests. Don't re-derive.
- **Find the real cause, not the first plausible one.** Resist fixing the first suspicious line.
  Confirm the path actually executes (runtime debugging / a failing test) before editing.
- **Never report a stage green that wasn't.** If a step was skipped, blocked, or only partially
  done, say so plainly. Faithful status beats optimistic status.
- **The ticket is optional.** The bug context may be an **optional** tracker ticket (JIRA, GitHub
  Issues, any service) **or** a plain free-text description — a ticket is never required. Branch name,
  PR title, and commit message all have a `no-ticket` / `NO-TICKET` fallback.
- **Claude-only orchestration.** Every stage delegates to a bundled/installed `Skill(...)` or a Claude
  subagent — no third-party MCP server is required for the core flow. The single **optional** external
  touchpoint is the Step 0 ticket pull (e.g. JIRA via the Atlassian MCP, or `gh issue view`); if the
  ticket text is provided directly, or there's no ticket at all, the run needs no external MCP. Keep
  every subagent on a Claude model.
- **Project rules still apply.** Honor the repo's `.claude/rules/*` (php-code-style, no-redundant-docblocks,
  facade-method-signatures, BC policy, etc.) and `CLAUDE.local.md` file-reference format throughout.
- **Autonomous means autonomous (from Step 3 onward).** After the single Step 0 intake, Autonomous
  mode runs to completion **without any further `AskUserQuestion` or permission stop** — no scope
  clarifications, no "which fix should I pick", no "is this in scope" pauses. When you hit a fork
  (multiple candidate root causes, ambiguous scope, a second bug discovered, a risky trade-off),
  **decide it yourself using the most reasonable interpretation, proceed, and record it as a
  CRITICAL DECISION in the running report** (see "The decision & question log" below). The *only*
  permitted Autonomous stops are the two hard safety gates already defined: a stale/dirty base in
  Step 2, and the `attempt > 3` budget exhaustion. Everything else is a logged decision, not a question.

## The decision & question log (maintained across every step)

From Step 0 onward, keep a running, append-only log in `$BUGFIX_DIR/decisions.md` (see the run
directory below) with two sections you update as you go:

- **CRITICAL DECISIONS** — every fork you resolved on your own: the choice, the alternatives you
  rejected, and the one-line reason. (e.g. "Picked path A in `<ModuleA>` over path B in
  `<ModuleB>` — B is downstream of A; confirmed by runtime log at file:line.")
- **OPEN QUESTIONS / RISKS / FURTHER BUGS** — anything you could not fully resolve and chose to
  proceed past: out-of-scope smells, additional suspected bugs, BC risks, data assumptions, things a
  human should confirm later.

This log is the source for the Step 12 final report. Update it at each loopback (note the `attempt`
value) and whenever you make a non-obvious choice — do not wait until the end.

## Red flags — stop and reconsider

- "Tests pass, so the bug is fixed" → No. Reproduce the *user-visible* symptom and confirm it's gone.
  The gate is the **functional (Codeception) regression test from Step 6** — one that failed before
  the fix and passes after — plus the **final verification in the running app (Step 10)**. A test that
  never went red, or that merely executes the changed code without asserting the fixed behavior, proves
  nothing about the fix.
- "The first suspicious line is the cause" → No. Confirm the path actually executes; look for all
  paths that produce the symptom.
- "Review failed again, I'll just push it anyway" → No. After 3 attempts, STOP and report — never
  push broken code.
- "I'll branch even though the tree is dirty / master is stale" → No. Surface it; ask or abort.
- "Autonomous means I can also change permissions / delete data / merge the PR" → No. Autonomous
  authorizes the *bugfix-to-Draft-PR* path only; prohibited actions stay prohibited.
- "Skip QA, the gates are green" → No. Independent QA acceptance is a required stage.
- "Skip the final verification, tests and QA already passed" → No. Step 10 drives the running app one
  last time before commit and is a required gate; a FAIL loops back to Step 4 within the budget.
- "In Autonomous mode I should ask the user which fix / whether this is in scope" → No. After Step 0,
  Autonomous mode never asks — decide it, proceed, and log it as a CRITICAL DECISION. The only
  Autonomous stops are the stale/dirty base (Step 2) and `attempt > 3` (Steps 8/11).
- "I'll write the final report only on success" → No. Step 12 runs on every terminal state, and its
  last line is always the log file path.
- "I'll just run `codecept` / the verifier / `gh run view --log-failed` here and read the output" → No.
  Bulk output belongs in a subagent that writes it to a file and returns a compact verdict. The
  orchestrator carries the verdict, not the logs — otherwise an autonomous run drowns its own context
  (re-read on every loopback and every wake-up).
- "The watch loop can just re-run the whole skill from the top each wake" → No. It re-hydrates from
  `$BUGFIX_DIR/watch-state.md`, polls with `gh pr checks`, and only fans out to a subagent if a
  check is red.
- "Just run `gh pr create` / `git push` — it'll work" → No. **Never assume `gh` or a remote exists.**
  Use the `PR_CHANNEL` probed at Step 0: `gh` → full push + PR + watch; `git-only` → push + hand over a
  `gh pr create` line; `none` → commit locally and stop. A missing/unauthenticated `gh` **downgrades**
  Step 11 to a clean local terminal state — it never aborts the run, and a skipped GitHub step is
  reported plainly, never attempted-and-failed.
