# Run logging — what, when, how, where

A build spans many steps, several subagents, and a self-correct loop that can revisit the same AC more
than once. The run keeps a written trail so the commit gate is judged on evidence rather than memory,
and so a resumed or compacted run can re-orient. This **records** the workflow — it does not change any
step's behavior, gates, or ordering.

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

Because this skill does file work with **native tools, not `Bash`** (see the SKILL.md "What you do NOT
do"), create and update these files with `Write` / `Edit` / `Read`, using project-relative paths. The
shape of a `run.log` line:

```
[2026-08-10 14:02:11] STEP 4 — edit | START
[2026-08-10 14:31:47] STEP 4 — edit | END 6 files touched (3 new, 3 modified)
[2026-08-10 14:52:03] STEP 6 — verification | END AC 1,2,4 green · AC 3 red → see verify-1.log
[2026-08-10 15:07:20] STEP 7 — self-correct iter=1 AC=3 | END still red → iter=2
```

**What.** Log one line per step boundary (`| START`, `| END <one-line outcome>`), plus every result a
later reader would need:

- The Step 0 answers: quality bar, and which phases are ON/OFF (an OFF phase explains a missing step later).
- The resolved PRD source from Step 0c, the Step 0d scale envelope, and the AC checklist count from Step 1.
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
confirm, and **every spike/architect warning the user overruled** (with the predicted failure, so a later
diagnosis can match against it). This is the source for the Step 8 report's Caveats block — do not wait
until the end to write it.

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
