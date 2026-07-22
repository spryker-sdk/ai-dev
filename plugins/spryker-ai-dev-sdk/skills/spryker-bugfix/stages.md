# Spryker Bugfix — Stage Details

Full, authoritative per-step instructions for the workflow spine in [SKILL.md](SKILL.md).
Read the relevant § before executing each step.

## Step 0 — Choose mode and gather context (ALWAYS FIRST)

This is the **one and only** place the skill collects choices up front. In **Autonomous** mode,
everything you need to run unattended must be settled here — after this step, Autonomous mode asks
nothing more (see the Operating principle above).

Begin with **one `AskUserQuestion` call carrying multiple questions** (multi-tab). Ask:

1. **Mode** — `Autonomous` (agent decides everything, no human in the loop, goes all the way to
   pushed Draft PR + watch loop) vs `Collaborative` (agent asks the user at the important decision
   points and **stops before push** for the user to review).
2. **Context** — the bug context. A tracker ticket is **optional**; accept **either or both** of:
   - a **ticket** from any tracker (JIRA key like `XY-1122`, a GitHub issue URL/number, or any other
     service reference), and/or
   - a **free-text description** of the symptom plus technical hints and any advice.
   (Open-ended; the user can paste a ticket reference, a paragraph, or both. If neither the ticket nor a
   usable description is given, ask once for at least a description — the workflow needs a symptom to
   reproduce, but it does **not** need a ticket.)
3. **Target branch** — base branch to cut from (default `master`).
4. **Environment freshness** — is the local Docker environment freshly reset to the recent target
   branch? Offer three answers: `Fresh — already reset` / `Not reset — reset it for me` /
   `Not reset — I'll keep the current env` (drives Step 2's safety check + the reset decision below).
5. **Personal review before push** — does the user want to personally review and accept the fix
   before it is pushed, in addition to the automated gates? (yes / no.)
6. **Extra expectations beyond the standard workflow** — is there anything you want from this run
   *on top of* the default scope and steps below? This is **open-ended and optional** — the default
   answer is "no, just the standard workflow". Surface it because Autonomous mode won't ask again, so
   anything non-standard must be captured now. Examples the user might raise: also update the JIRA
   ticket / post a status comment, fix related bugs you find along the way (vs. only-the-reported one),
   add a changelog entry, target a specific reviewer or extra PR labels, skip the E2E/QA stage,
   produce a written RCA doc, keep the fix to a single module, or a hard time/scope cap.

   > **The default scope** (so the user knows what's already covered and need not restate it): reproduce
   > → root-cause → minimal fix → functional test → static validation → code review → independent QA →
   > final verification → (autonomous) commit + push + Draft PR + remote-CI watch. Capture any **delta**
   > from this, record it as a project constraint in `$BUGFIX_DIR/decisions.md`, and honor it throughout
   > the run.

After the answers, restate the chosen mode, the context, and any extra expectations in one line, then
proceed. In **Autonomous** mode, do not ask further questions — make reasoned decisions and record them
in your running decision log. In **Collaborative** mode, pause for confirmation at the marked decision points.

**Set up the run directory and step logger (do this immediately after the answers).** Create the
per-bugfix folder and a single run log file inside it, and write every step's start/end and key
outcome there as you go — this is the file whose path you show at the very end (Step 12). Anchor the
run directory to `$CLAUDE_PROJECT_DIR` (the project root Claude Code loaded) so it is stable no matter
the current working directory; fall back to `$(pwd)` if the variable is unset.

```bash
# <bugfix-id>: JIRA key if known, else "no-ticket-<brief-name>". Finalize after Step 2 if unknown now.
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(pwd)}"
BUGFIX_DIR="$PROJECT_DIR/.claude/.cache/spryker-bugfix/<bugfix-id>"
mkdir -p "$BUGFIX_DIR"
BUGFIX_LOG="$BUGFIX_DIR/run.log"
printf '[%s] STEP 0 — mode=%s base=%s env=%s | START\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$MODE" "$BASE" "$ENV_FRESHNESS" >> "$BUGFIX_LOG"
```

- All run files live under `$BUGFIX_DIR`: `run.log` (step log), `decisions.md` (decision log),
  `repro-notes.md`, the per-gate `<stage>-attempt<N>.log`, and `watch-state.md`. Do **not** write run
  files anywhere else.
- Log **one line per step boundary**: `[<timestamp>] STEP <n> — <name> | START` and `| END <one-line outcome>`.
- Also log every loopback (`attempt=<n>`), every CRITICAL DECISION (mirror the one-liner into the log),
  and any gate verdict (review/QA/verification pass-fail).
- Keep the human-readable decision log (`decisions.md`) separate from this terse step log; the step log
  is the timeline, the decision log is the rationale.

### Arrange the plan as a task list (do this right after the logger)

Immediately after setting up the run directory, **create the plan as a task list** so the run's
progress is visible and survives compaction or a scheduled wake-up. Use `TaskCreate` once per stage
— one task for each of Steps 1–12 — with the mode/`<bugfix-id>` in each subject for context:

```
TaskCreate  "S1  Intake & framing"
TaskCreate  "S2  Create bugfix branch (safety gate)"
TaskCreate  "S3  Reproduce & understand"
TaskCreate  "S4  Root-cause investigation (loop re-entry)"
TaskCreate  "S5  Implement fix & verify repro"
TaskCreate  "S6  Functional test coverage"
TaskCreate  "S7  Static validation"
TaskCreate  "S8  Code review (gate)"
TaskCreate  "S9  QA acceptance (gate)"
TaskCreate  "S10 Final verification before commit"
TaskCreate  "S11 Commit / push / Draft PR / watch (mode gate)"
TaskCreate  "S12 Final report"
```

Then drive it as you go:

- **Entering a stage** → `TaskUpdate <id> status=in_progress`. Keep **exactly one** task `in_progress`
  at a time (the stage you're actually running) so the list reads as the true current position.
- **Stage green** → `TaskUpdate <id> status=completed`, then move to the next.
- **On a loopback** (Step 8 review fail / Step 9 QA reject / Step 10 verify fail / Step 11 red CI) →
  reopen the affected gate task (`status=in_progress` again) and `TaskCreate` a short attempt task
  (e.g. `"S4 re-investigate — attempt <N>"`) so each pass through the loop is auditable. Mirror the
  same `attempt=<n>` note into `run.log`.
- **At any terminal state** (success, stop-before-push, or `attempt > 3`) → mark the reached task
  completed and leave the rest as-is; Step 12's report reflects the final task states.

`TaskList` is the live checklist — read it (not the full transcript) to see where the run stands; the
watch loop and any resumed run should re-orient from it plus `$BUGFIX_DIR/watch-state.md`. This
**complements** the `run.log` timeline and the `decisions.md` rationale — it does not replace either.

### Environment reset decision (item from Step 0 answer 4)

- **`Fresh — already reset`** → proceed; Step 2 still verifies the base independently (the answer is a
  claim, not proof).
- **`Not reset — reset it for me`** → reset the environment to the target branch **before Step 3**:
  check out/pull `<base>`, then run `script -q /dev/null docker/sdk reset` (this removes all
  containers and volumes — destructive but intended here), then bring it up with
  `script -q /dev/null docker/sdk up`. In **Collaborative** mode, this confirmation was already given
  by the answer — do not ask again. In **Autonomous** mode, the Step 0 answer is the authorization;
  run the reset, and **log it as a CRITICAL DECISION** ("reset env to <base> per user request").
- **`Not reset — I'll keep the current env`** → do **not** reset. Proceed with the current env, but
  log an OPEN RISK that the env may be stale and reproduction/QA results carry that caveat.

### Optional ticket pull (only when a ticket was given)

The ticket is **optional** and may come from any tracker. Resolve it only if the user gave a ticket
reference **and** the matching integration is available — otherwise skip this entirely and work from
the pasted description:

- **JIRA key** (e.g. `XY-1122`) with the Atlassian MCP available → `jira_get_issue`; if its text comes
  back thin, fall back to `jira_get_issue_images` to read the bug visually.
- **GitHub issue** (URL or `#number`) with `gh` available → `gh issue view <ref>`.
- **Any other tracker / no integration available** → do **not** block. Ask the user to paste the
  relevant ticket text, or proceed from the free-text description alone.

Whatever the source, capture the reported steps, expected vs actual, and any environment/version notes
— they seed Step 3. A ticket pull is **never** required for the run to proceed: if the ticket text is
pasted or only a free-text description is given, skip the pull and work from that. This is the **only**
external touchpoint in the whole workflow.

## Step 1 — Intake & framing

Turn the context into a crisp problem statement before touching code:
- **What is reported** (verbatim symptom), **affected actor/surface**, **environment**.
- **Provisional scope** — which module(s)/layer(s) likely involved (a guess to focus Step 3, not a
  commitment).

Keep this short. It exists so the later stages — and any subagents — share the same framing.

## Step 2 — Create the bugfix branch (with safety check)

**Verify the base before branching** (the user's "environment is recent" answer is a claim, not proof):

```bash
git status --porcelain          # working tree must be clean
git fetch origin <base>         # default: master
git rev-list --left-right --count origin/<base>...HEAD   # how far behind/ahead
```

- If the working tree is **dirty** or HEAD is **behind** `origin/<base>`: surface exactly what you
  found and **ask before proceeding** (Collaborative) or **abort with a clear report** (Autonomous —
  do not silently branch off a polluted/stale base). Branching off the wrong base produces a fix that
  can't be reviewed cleanly.
- When clean and current, create the branch:

```bash
git checkout <base> && git pull origin <base>
git checkout -b bugfix/<JIRA-KEY>/<brief-kebab-name>
```

Branch name pattern: `bugfix/{ticket-key}/brief-name` (e.g. `bugfix/ab-1234/short-symptom-name`), always lowercase.
The ticket key is whatever tracker key exists (JIRA key, GitHub issue number, etc.). **If there is no
ticket, use `bugfix/no-ticket/brief-name`** and note it — the ticket is optional.

**Finalize the run directory** now that the branch exists: if `<bugfix-id>` wasn't known at Step 0
(no JIRA key), rename `$BUGFIX_DIR` to `$PROJECT_DIR/.claude/.cache/spryker-bugfix/no-ticket-<brief-name>/`,
update `BUGFIX_DIR`/`BUGFIX_LOG`, and append the Step 2 boundary line. From here on, every run file stays
under this stable folder.

## Step 3 — Reproduce & understand the bug

Goal: a written, **reproducible** scenario — there may be several to cover the issue fully.

- **Ensure the environment is actually running first** — Step 0's "freshness" answer is a claim, not
  a live env. If you ran a reset in Step 0, the env is already up; otherwise bring it up before
  reproducing: `script -q /dev/null docker/sdk run` (or `up` for a cold build). A repro that fails
  because the stack is down wastes the investigation.
- **Delegate the reproduction to a subagent** so the Chrome/console bulk (screenshots, page text,
  command output) stays out of the orchestrator. Spawn an `Agent` that uses `Skill(spryker-runtime)`
  to reproduce the reported steps end-to-end as the right actor (resolve hosts from `deploy.dev.yml`;
  lightest mode that shows the symptom, but drive Chrome for rendered-UI/JS symptoms). Tell it to
  **write the full repro notes + evidence to `$BUGFIX_DIR/repro-notes.md`** and return only:
  reproduced yes/no, a 1–3 line scenario summary per scenario, and the key evidence values (status
  code, the wrong vs expected rendered value). The orchestrator keeps that summary in the State Object.
- In parallel, spawn **`Skill(spryker-docs-research)`** as a subagent to ground the affected
  functionality in official docs — how it's *supposed* to behave. It is docs-only and starts **blind**,
  so pass it the **module/feature name**, **reported symptom**, **actor/surface**, and the specific
  **expected-vs-actual question**. Ask it to return a short "expected behavior" summary, not a doc dump.
- If the repro is blocked (broken control, missing data, env issue), the subagent says so and you
  resolve it before claiming you reproduced it. A bug you couldn't reproduce is a hypothesis.

The per-scenario notes (**step-by-step repro**, **current behavior**, **expected behavior**, concrete
evidence) live in the `$BUGFIX_DIR/repro-notes.md` file the subagent wrote — the orchestrator
references it by path and carries only the summary forward.

## Step 4 — Root-cause investigation  ← LOOP RE-ENTRY POINT

> **The attempt counter (define once, here).** There is **one shared counter**, `attempt`, starting
> at `1` on the first entry to this step. **Increment it by 1 every time *any* downstream gate sends
> control back to Step 4** — that is: a Step 8 code-review failure, a Step 9 QA rejection, a Step 10
> final-verification failure, OR a Step 11 remote-CI failure all draw from the same budget. Passing a
> gate does **not** reset the counter.
> **Hard stop when `attempt > 3`**: do not loop again — go to the stop-and-report terminal for
> whichever gate you were in (see Steps 8 and 11). State the current `attempt` value in your running
> report at each loopback so it's auditable.

Identify *why* it happens, with **code references and an explanation**, not a guess.

- **Delegate the runtime tracing to a subagent.** XDebug variable dumps and `[AI-DEBUG]` log streams
  are bulky — run them in an `Agent` that uses **`Skill(ai-runtime-debugging)`** + **`Skill(spryker-runtime)`**
  to confirm which path actually executes and the values at the failure point, writes the raw trace to
  `$BUGFIX_DIR/rootcause-attempt<N>.log`, and returns only: the confirmed executing path
  (`file:line`), the decisive values observed, and which candidate paths it ruled out. Keep that, not
  the trace.
- Remember the core lesson: **the displayed symptom may be produced by a different path than the
  one you first suspect.** Confirm the candidate path is the one that runs before committing to it.
  Look for *all* paths that could produce the symptom.
- **When several candidate causes survive (Autonomous mode):** do not stop to ask which to fix. Pick
  the one the runtime evidence most strongly supports (the path you confirmed executes), fix that,
  and **log a CRITICAL DECISION** naming the rejected candidates and why. If fixing it later proves
  wrong, the loop (Step 8/9/10/11 → Step 4) catches it within the attempt budget.
- Output: root-cause statement + `file:line` references + a short explanation of the defect.

> **This step is the re-entry point of the verification loop.** If Step 8 (code review) fails and a
> retry is warranted, you come back *here* — because a review failure means the understanding or the
> fix was wrong, not just the formatting.

## Step 5 — Implement the fix & verify it resolves the bug

- Apply the smallest correct change that fixes the root cause.
- **Use subagents in parallel** for independent edits when the fix spans multiple files/modules, to
  speed things up. Keep each subagent's task scoped and hand it the root-cause context.
- **Verify against the bug**, not just the unit: re-run the Step 3 repro and confirm the symptom is
  gone with the same evidence you captured before. This is mandatory before moving on.

## Step 6 — Functional test coverage  (run in a subagent)

**Delegate this whole stage to one subagent** that uses **`Skill(codecept-functional)`** — test
authoring, the `codecept build`/`codecept run` cycle, and any fix iterations all produce bulk output
(build chatter, stack traces) that must not land in the orchestrator. Spawn an `Agent` and hand it the
root-cause `file:line`, the changed files, and the repro scenarios; instruct it to:

- Decide whether a test must be **updated or added** to lock in the fix and prevent regression, and
  write/edit it under the affected module's `tests/` tree.
- **Enter testing mode before running anything**: `script -q /dev/null docker/sdk testing "exit"`, and
  `docker/sdk testing codecept build -c <dir>` after creating a suite/helper or changing
  `codeception.yml` — otherwise the first `codecept run` fails because the testing container/bootstrap
  isn't ready and an attempt is burned for an avoidable reason. Build the suite first if the module had
  none (`module-test-infrastructure` rule).
- Prefer a regression test that **fails without the fix and passes with it** (prove it against the
  pre-fix state when feasible — the strongest evidence a fix actually addresses the bug).
- Follow the skill's conventions (entry-point focus, AAA, helpers, correct suite name, `-c <dir>`,
  single-colon method filter).
- **Write the full run to `$BUGFIX_DIR/tests-attempt<N>.log`** and return only: `pass|fail`, counts,
  the path(s) of any test file it created/edited, and — if failing — the failing test names + the
  one-line assertion message each.

The orchestrator keeps just that verdict (and the new test paths, for the diff); it `Read`s the log
only for a failure it must act on. Confirm green before moving on.

## Step 7 — Static validation  (run in a subagent)

**Delegate to a subagent** that runs **`Skill(static-validation)`** (phpcbf/phpcs/phpstan on the diff)
— the raw sniffer output is verbose. The subagent applies the auto-fixes, addresses what the sniffer
cannot, re-runs until clean, writes the run to `$BUGFIX_DIR/static-attempt<N>.log`, and returns only
`clean` or the list of remaining violations (`file:line` + rule). Keep just that verdict. (For a quick
interim check `sh .claude/bash-local/validation.sh` is fine; the skill is the authoritative gate.)

## Step 8 — Code review  ← GATE (loops back to Step 4)

1. Run **`Skill(code-review)`** — it fans the diff out to `spryker-code-reviewer` subagents. Have the
   review write its full findings to `$BUGFIX_DIR/review-attempt<N>.md` and surface back to the
   orchestrator only the **blocker/major** items (≤5, each: `file:line` + one-line issue). Nits stay in
   the file. The orchestrator keeps only that short blocker/major list in the State Object.
2. **Fix the findings that relate to the code** (blockers/majors first; apply nits where cheap — open
   the review file to read a nit only when you're about to fix it).
3. After fixing, **re-run `Skill(static-validation)`** and **re-run the functional tests if the review
   fixes touched tested behavior**.

**The verification loop:**
- Only **blocker/major** findings gate the loop. **Nits never cause a return to Step 4** — apply them
  where cheap and move on; a reviewer re-raising the same nit must not cause oscillation.
- If review is clean (no blocker/major remaining) → continue to Step 9.
- If review still finds **code-related** blockers/majors that indicate the fix is wrong or incomplete
  → increment `attempt` (the shared counter from Step 4) and **return to Step 4** (re-investigate),
  then forward through 5→6→7→8 again.
- **Hard stop when `attempt > 3`.** If the budget is exhausted and Step 8 still fails: **STOP and
  report.** Do not push, do not open a PR, do not commit broken code. Produce a clear report: what was
  tried each attempt, the remaining review findings, the current diff, and a recommendation. Hand back
  to the user. (This applies in both modes — in Autonomous mode, stopping with a report is the correct
  terminal state here.)

## Step 9 — QA acceptance (independent)

Spawn **`Skill(spryker-qa-coverage)` as a subagent in an isolated context** and have it return a QA
report that explicitly **accepts or rejects** the fix. Because the subagent starts blind, you MUST
pass it the session context per that skill's rule: what changed (files), the bug + repro scenarios,
environment gotchas (worker running, accounts/passwords, queue state, feature installed), **which
regen/cache commands were already run in Step 5** (so the QA env matches — e.g. transfer regen, schema
install, twig path-cache reset), the ticket key/description (whichever the run has), and ask it to
return the full report.

- QA must verify the **user-visible symptom is gone E2E** (not just a server-layer workaround).
- **Accepted** → continue. **Rejected** (a real failure found) → treat like a Step 8 failure: return
  to Step 4 within the 3-attempt budget; if exhausted, STOP and report.

## Step 10 — Final verification before commit

The last gate before commit/push — prove the fix holds in the **running application** with fresh
evidence, not just green tests.

- Re-run the affected functional tests once more for a clean final signal (subagent, as in Step 6).
- **Perform an end-to-end final verification in the running app (subagent).** Spawn the
  `spryker-verifier` agent (via the `Agent` tool with `subagent_type="spryker-verifier"`), or a
  subagent using **`Skill(spryker-runtime)`** if you prefer a raw runtime drive. Hand it the changed
  files, the repro scenarios, the acceptance expectation (the exact user-visible symptom that must be
  gone), and any env gotchas. It drives the affected surface (Yves / Back Office / Glue as relevant),
  writes its evidence to `$BUGFIX_DIR/final-verify-attempt<N>.log`, and returns only a **PASS / FAIL /
  BLOCKED** verdict with the decisive evidence (status code, the now-correct rendered value, DB/queue
  state as applicable).
- **PASS** → proceed to Step 11. **FAIL** → treat like a Step 8 failure: increment `attempt` and
  return to Step 4 within the 3-attempt budget; if exhausted, STOP and report. **BLOCKED** (env/data
  issue, not the fix) → resolve the blocker and re-verify without consuming an attempt; if it can't be
  resolved, report it honestly rather than claiming a pass.

## Step 11 — Commit, push, Draft PR, watch loop

**Mode-gated:**

- **Collaborative mode (or if the user asked for personal review before push):** commit on the branch,
  then **STOP and present** the result — summary, root cause, diff, test/QA/verification status — and
  ask the user to review and confirm before any push/PR. Do not push without that confirmation. (This
  honors the project rule that committing/pushing is a human-confirmed step.)

- **Autonomous mode (and user did not request pre-push review):** go all the way:
  1. Commit with a message referencing the ticket if there is one (e.g.
     `fix(<module>): <summary> (<TICKET-KEY>)`); with no ticket, omit the trailing key.
  2. `git push -u origin <branch>`.
  3. Open a **Draft PR** via `gh pr create --draft`. Requirements:
     - **`--draft`** (always a draft).
     - **Title starts with the ticket number in UPPER CASE**, e.g. `AB-1234: <short summary>`. If
       there is no ticket, prefix with `NO-TICKET:`.
     - **Labels `bug` and `generate-changelog`** (`--label bug --label generate-changelog`). If a
       label doesn't exist on the repo, create it with `gh label create` or note it in the report —
       do not let a missing label block the PR.
     - **Body** summarizing: bug, root cause (file:line), fix, tests added, QA verdict, and the ticket
       link if there is one.
     - Record the PR URL.

     ```bash
     gh pr create --draft \
       --title "<JIRA-KEY>: <short summary>" \
       --label bug --label generate-changelog \
       --body "<summary body>"
     ```
  4. **Arm a watch loop** that polls the PR's **remote GitHub checks** roughly every 15 minutes.

     **Write a one-page handoff file first** — `$BUGFIX_DIR/watch-state.md` containing the PR
     URL, branch, current `attempt`, the State Object, and the paths to the decision/step logs. The
     watch loop should re-hydrate from *this file*, not from the full bugfix transcript. This matters
     because the run may already be long: re-reading the whole conversation on every 15-min wake is
     exactly what pushes context to the danger zone. The handoff file is the loop's working memory.

     Pick the mechanism by how the run is driven:
       - Interactive session: `ScheduleWakeup` with `delaySeconds: 900`. Re-pass a **minimal** `/loop`
         input that says "resume the bugfix watch loop from `$BUGFIX_DIR/watch-state.md`" —
         not the original full bug context.
       - Unattended/headless: a **Cron** (`CronCreate`) — load its schema via `ToolSearch` first; point
         it at the same handoff file.
       - **Fallback if no scheduler is available:** do NOT claim a loop is running. Report the PR URL
         and explicitly hand monitoring back to the user.
     On each wake, **poll with the cheapest call** — `gh pr checks <pr>` (a compact status table),
     **not** `gh run view`. The poll itself must add almost nothing to context.
     - **All green** → report success and **remove the loop** (cancel the Cron / stop rescheduling).
     - **Any red** in the changed code → **do not pull the raw CI logs into the orchestrator.** Spawn a
       subagent that runs `gh run view --log-failed`, writes it to `$BUGFIX_DIR/ci-remote-attempt<N>.log`,
       and returns only the failing job(s) + the one-line root error each. Increment `attempt`, update
       the State Object, and **return to Step 4**. The fix must traverse the **full gate chain again**
       (4→5→6→7→8→9→10) before you re-push — do not jump 4→push and skip the gates. Then push and keep
       watching. If it's flaky/infra (not your code), note it and re-poll without consuming an attempt.
     - **Budget exhausted (`attempt > 3`) on a red check:** STOP. Cancel the scheduled wakeup/Cron,
       **leave the PR in Draft**, post a PR comment summarizing the remaining failures and the diff
       state, and report to the user. Never force-push a known-broken state and never mark the PR
       ready.
     - 15 min fits remote CI that takes minutes; don't poll faster (wasted cache).

> Pushing and opening a PR are outward-facing actions. Autonomous mode was explicitly authorized by
> the user's mode choice in Step 0; without that authorization, default to the Collaborative stop.

## Step 12 — Final report (ALWAYS LAST, both modes, every terminal state)

This step runs at the very end **no matter how the run terminated** — full success, a Collaborative
stop-before-push, or a hard stop (`attempt > 3`). Append a `STEP 12 | END` line to the step log, then
present a concise report to the user containing, in this order:

1. **Outcome** — one line: shipped (PR URL) / awaiting your review (committed, not pushed) / stopped
   after N attempts (why).
2. **The bug & root cause** — symptom + root-cause `file:line` (clickable absolute-path format).
3. **CRITICAL DECISIONS** — bullet list pulled from the decision log: each fork you resolved on your
   own, the choice, and the one-line reason. This is the headline section — surface it prominently so
   the user can sanity-check the calls you made without being asked mid-run.
4. **OPEN QUESTIONS / RISKS / FURTHER BUGS** — scope concerns, additional suspected bugs, BC/data
   risks, stale-env caveats, anything a human should confirm. Be honest; an empty list is fine only if
   genuinely empty.
5. **Gate status** — tests / static / review / QA / final verification / remote CI, each green/red with
   the real result (never report green what wasn't).
6. **Extra expectations** — if the user set any Step 0 delta from the standard scope, confirm how each
   was handled (done / partially / not done + why). Omit this line only if there were none.
7. **Log file path** — print the absolute path to the step log (`$BUGFIX_LOG`) and the decision log so
   the user can open them. This MUST be the last line of the report.

Keep it skimmable — it is the artifact the user reads instead of having been interrupted during the run.

## Stage → skill quick map

| Step | Skill / tool |
|------|--------------|
| 0 Mode, context, logger, env-reset decision | `AskUserQuestion`, **optional** ticket pull (JIRA via Atlassian MCP `jira_get_issue`/`jira_get_issue_images`, or `gh issue view`, or paste) — skipped when there's no ticket, `docker/sdk reset` (if chosen) |
| 3 Reproduce | `Skill(spryker-runtime)` + `Skill(spryker-docs-research)` — **subagents**, return summaries to `repro-notes.md` |
| 4 Root cause | `Skill(ai-runtime-debugging)` + `Skill(spryker-runtime)` — **subagent**, returns path+values |
| 5 Fix | edits (+ parallel subagents), regen/cache commands |
| 6 Tests | `Skill(codecept-functional)` — **subagent**, returns pass/fail + failing names |
| 7 Static | `Skill(static-validation)` — **subagent**, returns clean/violations |
| 8 Review (gate) | `Skill(code-review)` — returns ≤5 blocker/major; loop to 4 (max 3) |
| 9 QA | `Skill(spryker-qa-coverage)` (isolated subagent) |
| 10 Final verification (gate) | `Skill(codecept-functional)` re-run + `spryker-verifier` agent / `Skill(spryker-runtime)` — **subagent**, returns PASS/FAIL + evidence |
| 11 Ship + remote CI watch | git / `gh pr create --draft` (title `CC-XXXX: …`, labels `bug` + `generate-changelog`) / `ScheduleWakeup` or `CronCreate` watch loop |
| 12 Final report | decision log + step log → user-facing report ending with the log file path |
