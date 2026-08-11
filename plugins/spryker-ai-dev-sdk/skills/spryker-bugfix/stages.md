# Spryker Bugfix — Stage Details

Full, authoritative per-step instructions for the workflow spine in [SKILL.md](SKILL.md).
Read the relevant § before executing each step.

## Step 0 — Choose mode and gather context (ALWAYS FIRST)

This is the **one and only** place the skill collects choices up front. In **Autonomous** mode,
everything you need to run unattended must be settled here — after this step, Autonomous mode asks
nothing more (see the Operating principle above).

Begin with **one up-front intake round of `AskUserQuestion` calls**. `AskUserQuestion` carries at
most **4 questions per call**, so ask the 8 questions below in **two back-to-back multi-tab calls**
— questions 1–4, then questions 5–8 — with nothing in between. This is still a single intake: after
the second call's answers, nothing more is asked. Ask:

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
6. **PR delivery — create a PR, and via what?** How should the run end at Step 11? Offer:
   - **Create a PR — auto (recommended)** — open a Draft PR using the **best channel the Step 0 probe
     finds** (native `gh` for GitHub, else a connected forge MCP for GitHub/GitLab/Bitbucket/…, else
     push-only handoff). This is the default.
   - **Create a PR — specific channel** — force it: `gh` CLI, or a **named forge MCP server** (e.g.
     GitHub/GitLab/Bitbucket MCP), or `git push only` (push the branch, hand over a create-PR command).
     If the forced channel turns out unavailable at Step 11, the run reports the mismatch rather than
     silently switching (Autonomous: downgrades with a logged CRITICAL DECISION).
   - **No PR — commit only** — commit on the branch and stop (push only if a remote exists and the user
     allows it); never open a PR or arm the watch loop.

   Record the answer as the **PR preference**; it combines with the probed `PR_CHANNEL` (below) at
   Step 11. Because Autonomous mode won't ask again, this is the moment to capture "don't open a PR" or
   "use the GitLab MCP, not gh".
7. **Cypress E2E coverage** — should the run also fix/improve/add a **Cypress E2E test** for this
   bug (Step 9b, via `Skill(cypress-tests)`, after QA acceptance)? Offer:
   - **Auto (recommended)** — cover it with Cypress only when the bug is user-visible on an E2E
     surface (storefront / Back Office / Merchant Portal / Glue API) and the project's Cypress suite
     exists; otherwise skip with a logged reason.
   - **Always** — require a Cypress spec change (fix a broken spec, strengthen assertions that
     missed the bug, or add a new spec) before the run may proceed past Step 9b.
   - **Skip** — never touch the Cypress suite in this run.

   Record the answer as the **Cypress preference**; it gates Step 9b. Because Autonomous mode won't
   ask again, in `Auto` the Step 9b cover-vs-skip call is made by the agent and logged as a CRITICAL
   DECISION.
8. **Extra expectations beyond the standard workflow** — is there anything you want from this run
   *on top of* the default scope and steps below? This is **open-ended and optional** — the default
   answer is "no, just the standard workflow". Surface it because Autonomous mode won't ask again, so
   anything non-standard must be captured now. Examples the user might raise: also update the tracker
   ticket / post a status comment, fix related bugs you find along the way (vs. only-the-reported one),
   add a changelog entry, target a specific reviewer, apply specific PR labels (the skill sets none by
   default), skip the E2E/QA stage, produce a written RCA doc, keep the fix to a single module, or a
   hard time/scope cap.

   > **The default scope** (so the user knows what's already covered and need not restate it): reproduce
   > → root-cause → minimal fix → functional test → static validation → code review → independent QA →
   > Cypress E2E coverage (when warranted, per answer 7) →
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
BUGFIX_DIR="$PROJECT_DIR/.ai-dev/spryker-bugfix/<bugfix-id>"
mkdir -p "$BUGFIX_DIR"
# Make the run directory ignore itself, so this run's own scratch files cannot make
# the working tree dirty and trip the Step 2 clean-tree gate. Touches no tracked file.
[ -f "$PROJECT_DIR/.ai-dev/.gitignore" ] || printf '*\n' > "$PROJECT_DIR/.ai-dev/.gitignore"
BUGFIX_LOG="$BUGFIX_DIR/run.log"
printf '[%s] STEP 0 — mode=%s base=%s env=%s | START\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$MODE" "$BASE" "$ENV_FRESHNESS" >> "$BUGFIX_LOG"
```

- All run files live under `$BUGFIX_DIR`: `run.log` (step log), `decisions.md` (decision log),
  `repro-notes.md`, the per-gate `<stage>-attempt<N>.log`, and `watch-state.md`. Do **not** write run
  files anywhere else.
- Log **one line per step boundary**: `[<timestamp>] STEP <n> — <name> | START` and `| END <one-line outcome>`.
- Also log every loopback (`attempt=<n>`), every CRITICAL DECISION (mirror the one-liner into the log),
  and any gate verdict (review/QA/Cypress/verification pass-fail).
- Keep the human-readable decision log (`decisions.md`) separate from this terse step log; the step log
  is the timeline, the decision log is the rationale.

### Probe the PR channel (do this in Step 0, before you rely on it)

Step 11 (and the optional ticket pull) needs a way to talk to the remote forge. **Never assume `gh`
exists or is authenticated** — and `gh` is not the only way. A connected **forge MCP server** (GitHub,
GitLab, Bitbucket, Gitea, …) can create and watch a PR/MR just as well; the `git` CLI alone can still
push a branch. Probe once at Step 0 and record the result as `PR_CHANNEL`. This is a capability check,
not an action — it makes no network changes.

Resolve the channel in this order (first match wins), deciding from the **remote** first (a run's
channel is a property of its repo, not of a globally-authenticated tool):

1. **`none`** — no `origin` remote at all → local-only.
2. **`gh`** — the origin is a GitHub remote **and** `gh` is installed + `gh auth status` succeeds. Native
   CLI: push + Draft PR + `gh pr checks` watch loop.
3. **`mcp`** — a **forge MCP server matching the remote host is connected** (e.g. a GitHub MCP for a
   `github.com` remote, a GitLab MCP for a `gitlab.*` remote, a Bitbucket MCP for `bitbucket.org`). Use
   the MCP's create-PR / list-checks tools for the same push + PR + watch flow. Discover these via
   `ToolSearch` (e.g. query the forge name + "pull request"/"merge request"); if a create-PR tool
   resolves for the remote's host, the channel is `mcp`. **Prefer `gh` over `mcp` only for GitHub when
   both exist** (native `gh pr checks` is the cheapest poll); for non-GitHub remotes `mcp` is the *only*
   PR-capable channel.
4. **`git-only`** — a remote exists but neither `gh` nor a matching forge MCP is usable. Push a branch,
   but no PR API.

```bash
# CLI/remote part of the probe (the MCP check is a ToolSearch, done in-agent — see below).
REMOTE_URL="$(git remote get-url origin 2>/dev/null || true)"
if [ -z "$REMOTE_URL" ]; then
  PR_CHANNEL="none"                      # no origin remote → local-only
else
  PR_CHANNEL="git-only"                  # a remote exists → at least push a branch
  case "$REMOTE_URL" in
    *github.com*)                        # GitHub remote → native gh is possible…
      if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
        PR_CHANNEL="gh"                   # …and gh is installed + authenticated → full native
      fi
      ;;
  esac
fi
# Then, if PR_CHANNEL is still "git-only", check for a forge MCP matching the remote host
# (ToolSearch for the host's create-PR/MR tool). If one resolves, set PR_CHANNEL="mcp"
# and remember the tool names. This step is done by the agent, not in bash.
printf '[%s] STEP 0 — PR_CHANNEL=%s remote=%s | probe\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$PR_CHANNEL" "${REMOTE_URL:-none}" >> "$BUGFIX_LOG"
```

- **`gh`** — GitHub remote + usable `gh`. Step 11 pushes, opens a Draft PR, and runs the `gh pr checks`
  watch loop. GitHub-issue ticket pull available via `gh`.
- **`mcp`** — a forge MCP server for the remote host is connected. Step 11 pushes (git), then creates
  the Draft PR/MR and polls checks **through the MCP tools** instead of `gh`. Works for GitHub, GitLab,
  Bitbucket, etc. Ticket pull can use the same MCP if it exposes an issue-fetch tool.
- **`git-only`** — remote exists, but no PR-capable tool. Step 11 commits and pushes the branch, then
  **skips** the PR + watch loop and hands the user a ready-to-run `gh pr create` (or MR) line. Ticket
  pull falls back to "paste the ticket text".
- **`none`** — no `origin` remote (or nothing usable). Step 11 commits **locally only** and **skips
  push, PR, and watch**; the report tells the user how to add a remote and push. The rest of the
  workflow (reproduce → … → final verification) is unaffected — it never needed the remote.

Record `PR_CHANNEL` (and, for `mcp`, the resolved create-PR / list-checks tool names) in the **State
Object**. If it is `git-only` or `none`, note it as an OPEN RISK in `decisions.md` ("no PR channel —
Step 11 will stop at a local commit / branch push") so the downgrade is visible up front rather than a
surprise at Step 11. In **Autonomous** mode this is a logged fact, not a question — do **not** stop to
ask the user to install `gh` or connect an MCP.

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
TaskCreate  "S9b Cypress E2E coverage (conditional)"
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
- **GitHub issue** (URL or `#number`) with `PR_CHANNEL=gh` → `gh issue view <ref>`. If `gh` isn't
  usable (`PR_CHANNEL` is `git-only`/`none`), fall back to paste — do not try to call `gh`.
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
# Fetch only when a remote exists (PR_CHANNEL != none); otherwise compare against the local base.
if [ "$PR_CHANNEL" != "none" ]; then
  git fetch origin <base>       # default: master
  git rev-list --left-right --count origin/<base>...HEAD   # how far behind/ahead vs remote
else
  git rev-list --left-right --count <base>...HEAD          # local base only — no remote to compare
fi
```

- An untracked `.ai-dev/` is **this run's own scratch space** (created in Step 0, self-ignoring) — it
  does **not** count as a dirty tree. If it is the only thing `git status` reports, the tree is clean.
- If the working tree is **dirty** or HEAD is **behind** the base: surface exactly what you found and
  **ask before proceeding** (Collaborative) or **abort with a clear report** (Autonomous — do not
  silently branch off a polluted/stale base). Branching off the wrong base produces a fix that can't be
  reviewed cleanly.
- When `PR_CHANNEL=none` there is no remote to compare against — verify the working tree is clean and
  branch off the **local** base, and note in the report that base-freshness could not be checked against
  a remote. Do not treat "no remote" as a stale-base abort.
- When clean and current, create the branch:

```bash
git checkout <base>
[ "$PR_CHANNEL" != "none" ] && git pull origin <base>   # pull only when a remote exists
git checkout -b bugfix/<TICKET-KEY-or-no-ticket>/<brief-kebab-name>
```

Branch name pattern: `bugfix/{ticket-key}/brief-name` (e.g. `bugfix/ab-1234/short-symptom-name`), always lowercase.
The ticket key is whatever tracker key exists (JIRA key, GitHub issue number, etc.). **If there is no
ticket, use `bugfix/no-ticket/brief-name`** and note it — the ticket is optional.

**Finalize the run directory** now that the branch exists: if `<bugfix-id>` wasn't known at Step 0
(no JIRA key), rename `$BUGFIX_DIR` to `$PROJECT_DIR/.ai-dev/spryker-bugfix/no-ticket-<brief-name>/`,
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
> control back to Step 4** — that is: a Step 8 code-review failure, a Step 9 QA rejection, a Step 9b
> Cypress failure that indicts the fix, a Step 10
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
   fixes touched tested behavior** (on a loopback pass, that includes the Step 9b Cypress spec if one
   exists from a previous attempt and the fixes touched its flow).

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

## Step 9b — Cypress E2E test coverage  (conditional, run in a subagent)

QA (Step 9) proved the symptom is gone manually; a **Cypress E2E spec** locks that user-visible
behavior in so the bug can't silently return. Running it *after* QA acceptance means the spec is
authored against a fix that already passed review and QA — no wasted spec-writing on a fix that
still loops. This step is gated by the **Cypress preference** from Step 0 (answer 7) plus the
nature of the bug:

- **Run it** when the preference is `Always`, or it is `Auto` **and** the symptom is user-visible on
  an E2E surface (storefront / Back Office / Merchant Portal / Glue API) **and** the project's
  Cypress suite exists (the `cypress-tests` skill's own Step 0 locates `<e2e-dir>`; no suite found ⇒
  skip).
- **Skip it** when the preference is `Skip`, the bug has no user-visible E2E surface (pure
  console/import/queue-level defect), or no suite exists. Skipping is a decision, not an omission:
  log it (Autonomous: as a CRITICAL DECISION — "no Cypress coverage because <reason>") and mark the
  S9b task completed with that note.

When it runs, **delegate the whole stage to one subagent** that uses **`Skill(cypress-tests)`** —
`npm ci`, cypress run output, and lint chatter are bulky and must not land in the orchestrator. Hand
it the repro scenarios, the root-cause summary, the changed files, and the QA report's E2E steps
(they are the ready-made scenario the spec should encode), and instruct it to:

- **Decide fix vs improve vs add** against the existing suite first (the skill's Step 1 orientation):
  1. **Fix** — an existing spec covering the broken flow is red, or was weakened/skipped because of
     this bug → repair it against the now-correct behavior.
  2. **Improve** — an existing spec exercises the flow but its assertions would **not** have caught
     this bug → strengthen them to assert the concrete value the bug corrupted.
  3. **Add** — no spec covers the flow → author a new one per the skill's conventions (page objects,
     dynamic/static fixture pair, typed fixtures, no selectors in the spec).
  4. **None needed** — the flow is genuinely outside the suite's scope → return that verdict with a
     one-line reason instead of forcing a spec.
- Run the result **targeted** (`npx cypress run --spec "<the spec>"`) and then the suite's quality
  gate (`npm run code:check`), per the skill's Step 3–4 checklist — including the re-run-green and
  no-flake (passed on attempt 1, not on a retry) checks.
- **Write the full run output to `$BUGFIX_DIR/cypress-attempt<N>.log`** and return only:
  `pass|fail|skipped(<reason>)`, the action taken (fix/improve/add/none), the spec + fixture paths
  touched, and — if failing — the failing test names + a one-line error each.

**Verdict handling:**
- **Pass** (or a reasoned `none needed`/skip) → continue to Step 10. Spec/fixture changes made here
  are part of the bugfix diff — they go into the same commit and through Step 10 with the rest.
- **Fail because the fix is wrong or incomplete** (the spec correctly asserts the expected behavior
  and the running app doesn't deliver it) → treat like a Step 8 failure: increment `attempt` and
  return to Step 4. This contradicts the Step 9 QA acceptance — note that discrepancy in
  `decisions.md`, since one of the two observations is wrong.
- **Fail for test-authoring or environment reasons** (selector drift, fixture mistake, stack not up)
  → the subagent iterates on the test itself; this does **not** consume an attempt. If it can't get
  green, report the blocker honestly rather than deleting or weakening the spec — an assertion
  loosened until it passes would no longer catch this bug, which defeats the point of the step.

## Step 10 — Final verification before commit

The last gate before commit/push — prove the fix holds in the **running application** with fresh
evidence, not just green tests.

- Re-run the affected functional tests once more for a clean final signal (subagent, as in Step 6);
  if Step 9b produced or changed a Cypress spec, re-run that spec targeted (`--spec`) as well.
- **Perform an end-to-end final verification in the running app (subagent).** Spawn the
  `spryker-verifier` agent (via the harness's subagent-spawning tool — commonly `Agent`; resolve it
  via `ToolSearch` first — passing `subagent_type="spryker-verifier"`), or a
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

**Gated by mode, the Step 0 PR preference, _and_ `PR_CHANNEL`.** Always **commit on the branch first** —
the commit happens in every mode and every channel. What happens *after* the commit depends on (a)
whether the user asked for a PR at all (Step 0 answer 6) and (b) which channel is available.

**First honor the Step 0 PR preference:**
- **"No PR — commit only"** → commit on the branch and stop (optionally push if a remote exists and the
  user allowed pushing). Skip PR creation and the watch loop regardless of channel. Report the branch.
- **"Create a PR"** with a **forced channel** (the user named `gh` / a specific forge MCP / `git push
  only`) → use that channel if the probe confirms it's usable; if the forced channel isn't available,
  do **not** silently fall back — report the mismatch (Autonomous: log it and downgrade to the best
  available channel with a CRITICAL DECISION).
- **"Create a PR — auto"** (default) → use the best channel the probe found, per the table below.

| `PR_CHANNEL` | Collaborative | Autonomous |
|---|---|---|
| `gh` / `mcp` | commit → STOP for review; push/PR after user OK | commit → push → Draft PR → checks-watch loop |
| `git-only` | commit → STOP for review; on OK, push branch + hand over a `gh pr create`/MR line | commit → push branch → **skip PR + watch**; hand over the create-PR line |
| `none` | commit → STOP; report the local branch + how to push | commit **locally** → **skip push, PR, watch**; report the local branch + how to add a remote and push |

The commit message references the ticket if there is one (e.g. `fix(<module>): <summary> (<TICKET-KEY>)`);
with no ticket, omit the trailing key.

- **Collaborative mode (or if the user asked for personal review before push):** commit on the branch,
  then **STOP and present** the result — summary, root cause, diff, test/QA/verification status, and the
  `PR_CHANNEL` — and ask the user to review and confirm before any push/PR. Do not push without that
  confirmation. After the user confirms, do the push/PR that the preference + channel allow (Draft PR on
  `gh`/`mcp`, branch push + handover on `git-only`, nothing to push on `none`).

- **`PR_CHANNEL=none` (any mode) — local-only terminal.** Commit on the branch and **stop there**: do
  **not** attempt `git push`, PR creation, or the watch loop (there is no reachable remote). Report the
  committed branch name, the diff summary, and a copy-paste snippet for the user to publish it themselves
  (`git remote add origin <url>` if needed, then `git push -u origin <branch>` and `gh pr create
  --draft …` / the forge's MR command). This is a **successful** terminal state, not a failure — mark
  Step 11's task completed and go to Step 12.

- **`PR_CHANNEL=git-only` (Autonomous) — push, then hand off the PR.** Commit and `git push -u origin
  <branch>`, then **skip** PR creation and the checks-watch loop (no PR API available). Report the
  pushed branch and a ready-to-run create-PR line (`gh pr create --draft --title
  "<TICKET-KEY-or-NO-TICKET>: <summary>" --body "…"`, no labels — or the equivalent MR command for the
  forge) so the user (or a machine with the tool) can open the PR in one step. Mark Step 11 completed and
  go to Step 12.

- **`PR_CHANNEL=mcp` (Autonomous) — same flow as `gh`, through the MCP.** `git push -u origin <branch>`,
  then create the Draft PR/MR via the forge MCP's create-PR tool (title `<TICKET-KEY-or-NO-TICKET>:
  <summary>`, **no labels**, body as below), and run the watch loop polling checks via the MCP's
  list-checks/status tool instead of `gh pr checks`. Everything else (red-check loopback, budget,
  handoff file) is identical to the `gh` path.

- **Autonomous mode with `PR_CHANNEL=gh` (and user did not request pre-push review):** go all the way
  (`mcp` follows the identical shape, swapping `gh` calls for the forge MCP's create-PR / list-checks tools):
  1. Commit with a message referencing the ticket if there is one (e.g.
     `fix(<module>): <summary> (<TICKET-KEY>)`); with no ticket, omit the trailing key.
  2. `git push -u origin <branch>`.
  3. Open a **Draft PR** via `gh pr create --draft` (or the MCP create-PR tool for `mcp`). Requirements:
     - **`--draft`** (always a draft).
     - **Title starts with the ticket number in UPPER CASE**, e.g. `AB-1234: <short summary>`. If
       there is no ticket, prefix with `NO-TICKET:`.
     - **Do not set any labels.** Labeling is left to the repo's own automation / reviewers; the skill
       never passes `--label` (and never creates labels). *(Exception: only if the user explicitly asked
       for specific labels as a Step 0 extra expectation — then add exactly those, nothing more.)*
     - **Body** summarizing: bug, root cause (file:line), fix, tests added, QA verdict, and the ticket
       link if there is one.
     - Record the PR URL.

     ```bash
     gh pr create --draft \
       --title "<TICKET-KEY-or-NO-TICKET>: <short summary>" \
       --body "<summary body>"
     ```
  4. **Arm a watch loop** that polls the PR's **remote checks** roughly every 15 minutes.
     *(Steps 3–4 run only when `PR_CHANNEL` is `gh` or `mcp`; for `git-only` you already handed the
     create-PR line to the user and there is no PR to watch.)*

     **Write a one-page handoff file first** — `$BUGFIX_DIR/watch-state.md` containing the PR
     URL, branch, current `attempt`, the State Object, and the paths to the decision/step logs. The
     watch loop should re-hydrate from *this file*, not from the full bugfix transcript. This matters
     because the run may already be long: re-reading the whole conversation on every 15-min wake is
     exactly what pushes context to the danger zone. The handoff file is the loop's working memory.

     Pick the mechanism by what this harness actually exposes — **resolve it via `ToolSearch` first
     and use its real schema**; the names below are the common ones, not a guarantee, and parameters
     differ between them (a cron tool takes a cron expression, not a delay in seconds):
       - A **polling/monitor** tool (e.g. `Monitor`) is the best fit when available: it is built for
         "poll a command, emit each terminal state, exit when the run completes".
       - A **self-wakeup** tool (e.g. `ScheduleWakeup`) for an interactive session. Re-pass a
         **minimal** input that says "resume the bugfix watch loop from `$BUGFIX_DIR/watch-state.md`"
         — not the original full bug context.
       - A **cron** tool (e.g. `CronCreate`) for unattended/headless runs; point it at the same
         handoff file. Note cron jobs are typically session-scoped and expire on their own.
       - **Fallback if no scheduler resolves:** do NOT claim a loop is running. Report the PR URL
         and explicitly hand monitoring back to the user.
     On each wake, **poll with the cheapest call** — `gh pr checks <pr>` (a compact status table), or for
     `mcp` the forge's list-checks/status tool — **not** `gh run view`. The poll itself must add almost
     nothing to context.
     - **All green** → report success and **remove the loop** (cancel the Cron / stop rescheduling).
     - **Any red** in the changed code → **do not pull the raw CI logs into the orchestrator.** Spawn a
       subagent that runs `gh run view --log-failed`, writes it to `$BUGFIX_DIR/ci-remote-attempt<N>.log`,
       and returns only the failing job(s) + the one-line root error each. Increment `attempt`, update
       the State Object, and **return to Step 4**. The fix must traverse the **full gate chain again**
       (4→5→6→7→8→9→9b→10) before you re-push — do not jump 4→push and skip the gates. Then push and keep
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

1. **Outcome** — one line, reflecting the `PR_CHANNEL` that was available: shipped (PR URL) / pushed —
   PR handoff line provided (`git-only`) / committed locally — not pushed, no remote (`none`) / awaiting
   your review (committed, not pushed) / stopped after N attempts (why). When the channel downgraded the
   ending (`git-only` or `none`), say so explicitly and include the exact command(s) the user needs to
   finish publishing.
2. **The bug & root cause** — symptom + root-cause `file:line` (clickable absolute-path format).
3. **CRITICAL DECISIONS** — bullet list pulled from the decision log: each fork you resolved on your
   own, the choice, and the one-line reason. This is the headline section — surface it prominently so
   the user can sanity-check the calls you made without being asked mid-run.
4. **OPEN QUESTIONS / RISKS / FURTHER BUGS** — scope concerns, additional suspected bugs, BC/data
   risks, stale-env caveats, anything a human should confirm. Be honest; an empty list is fine only if
   genuinely empty.
5. **Gate status** — tests / static / review / QA / Cypress E2E (run, skipped-with-reason, or off) /
   final verification / remote CI, each green/red with
   the real result (never report green what wasn't).
6. **Extra expectations** — if the user set any Step 0 delta from the standard scope, confirm how each
   was handled (done / partially / not done + why). Omit this line only if there were none.
7. **Log file path** — print the absolute path to the step log (`$BUGFIX_LOG`) and the decision log so
   the user can open them. This MUST be the last line of the report.

Keep it skimmable — it is the artifact the user reads instead of having been interrupted during the run.

## Stage → skill quick map

**Skill invocation names.** Every skill this workflow delegates to ships in the
`spryker-ai-dev-sdk` plugin, so its invocable name carries that prefix —
`Skill(spryker-ai-dev-sdk:spryker-runtime)`, `Skill(spryker-ai-dev-sdk:code-review)`, and so on.
The bare names used in this document and in the table below are **shorthand for the prefixed
form**. If a bare name fails to resolve, retry it with the `spryker-ai-dev-sdk:` prefix before
reporting a stage blocked — a delegation that cannot resolve is a naming problem, not a blocked
gate.

| Step | Skill / tool |
|------|--------------|
| 0 Mode, context, logger, PR-channel probe, env-reset decision | `AskUserQuestion`, **optional** ticket pull (JIRA via Atlassian MCP `jira_get_issue`/`jira_get_issue_images`, or `gh issue view`, or paste) — skipped when there's no ticket, `PR_CHANNEL` probe (`command -v gh` + `gh auth status` + remote check), `docker/sdk reset` (if chosen) |
| 3 Reproduce | `Skill(spryker-runtime)` + `Skill(spryker-docs-research)` — **subagents**, return summaries to `repro-notes.md` |
| 4 Root cause | `Skill(ai-runtime-debugging)` + `Skill(spryker-runtime)` — **subagent**, returns path+values |
| 5 Fix | edits (+ parallel subagents), regen/cache commands |
| 6 Tests | `Skill(codecept-functional)` — **subagent**, returns pass/fail + failing names |
| 7 Static | `Skill(static-validation)` — **subagent**, returns clean/violations |
| 8 Review (gate) | `Skill(code-review)` — returns ≤5 blocker/major; loop to 4 (max 3) |
| 9 QA | `Skill(spryker-qa-coverage)` (isolated subagent) |
| 9b Cypress E2E (conditional, Step 0 answer 7 + user-visible surface) | `Skill(cypress-tests)` — **subagent**, returns pass/fail/skipped + action (fix/improve/add/none) + spec paths |
| 10 Final verification (gate) | `Skill(codecept-functional)` re-run + `spryker-verifier` agent / `Skill(spryker-runtime)` — **subagent**, returns PASS/FAIL + evidence |
| 11 Ship + remote CI watch (PR-pref + channel gated) | always `git commit`; then by PR preference + `PR_CHANNEL`: `gh`/`mcp` → `git push` + Draft PR (no labels; `gh pr create` or forge-MCP create-PR tool) + a watch loop on whichever scheduler/monitor tool `ToolSearch` resolves (polling `gh pr checks` / MCP status) · `git-only` → `git push` + handover create-PR line · `none` or "no PR" → commit (push if allowed), report publish commands |
| 12 Final report | decision log + step log → user-facing report ending with the log file path |
