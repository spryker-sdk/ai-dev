# Run logging — `.ai-dev/run.log`

The full contract behind `SKILL.md` §3 → Run logging.


A run spans nine steps, several sub-skills, a boot, and can be interrupted and resumed hours later. The
three `.ai-dev/` files already cover *configuration* (the state file), *rationale* (the decision log),
and *maintainer feedback* (the skill-improvement log) — what is missing is the **timeline**: what actually
ran, in what order, and how each step ended. `.ai-dev/run.log` is that file. It **records** the steps
of `SKILL.md` §3; it changes nothing about what any step does, which gate fires, or when control returns.

**Where.** Alongside the other run artifacts, in the clone's own tree — `.ai-dev/run.log`, a
project-relative path like every other `.ai-dev/` file. The run is self-contained: never write run
files outside this clone. It is created by the run and removed in the "Return to fresh" recipe, which
hands the developer the `rm` command for `.ai-dev/project-setup.md` **(+ run logs)** to run.

The run's four files sit flat at `.ai-dev/` because the state file's path is load-bearing: pre-flight
detects a prior run by it, Resume reads it, and "Return to fresh" lists it for the developer to delete.

**When.** Create it in **§2 (Confirm & write state)**, in the same pass that writes
`.ai-dev/project-setup.md` — the interview answers are the first thing worth recording, and every step
from §3 onward appends to it. On **Resume**, do not start a new file: append a `RESUME` line and keep
going, so one run reads as one continuous timeline across interruptions.

**How.** Write it with the built-in **Write / Edit / Read** tools, not the shell. This is a direct
application of `SKILL.md` → Tooling discipline: a `printf … >>` append to a non-state `.ai-dev/` file
such as `run.log` is permitted, but a redirect (like pipes, `&&`, and subshells) can prompt regardless
of the allowlist. Appending a line with
`Edit` costs no prompt and keeps the run hands-off. Keep entries terse — one line per event:

```
[2026-08-10 14:02:11] INTERVIEW — SKIP (questionnaire pre-filled) · source=questionnaire · run_mode=autonomous · data.mode=adapt · defaulted=[S2,C1,L1]
[2026-08-10 14:02:40] STEP 1 project-ci-generator | START run_mode=autonomous
[2026-08-10 14:11:05] STEP 1 project-ci-generator | END done — 1 workflow kept, 14 CI/deploy/install files wiped (approved) · Deviations: none
[2026-08-10 14:11:20] STEP 5 define-stores | SKIPPED (data.mode=leave)
[2026-08-10 14:48:02] STEP 8 boot-and-verify | ⚠ ACTION NEEDED /etc/hosts — waiting
[2026-08-10 15:03:55] RESUME — continuing from step 8 (in-progress: browser ACs pending)
[2026-08-10 15:12:31] STEP 8 boot-and-verify | END done (browser ACs BLOCKED — /etc/hosts declined)
```

**What.** One line per step boundary — `| START run_mode=<mode>` and `| END <one-line outcome>`. The
mode is re-asserted on **every** START on purpose: it is the one fact a long run drifts away from, and a
`| START run_mode=autonomous` line immediately above a "step done — continue?" turn is the greppable
proof of the drift. Plus every event a later
reader would need to explain the run:

- The collection summary: **how the answers were collected** (`source=questionnaire | interview |
  questionnaire+interview | defaults | demo-prep`, and `defaulted=[…]` — the questions resolved without the
  developer choosing), `run_mode`, and the answers that decide whether steps run (`data.mode`,
  `reduce_catalog`, `localize`, the `ci:` plan). The source and the defaulted list are what let a
  later reader tell a deliberate choice from a taken default.
- Each step's START/END with its terminal status, and each **conditional skip with its reason**
  (`SKIPPED (data.mode=leave)`) — a skip is a result, and it explains a "missing" step later.
- Every **hard-stop** as it happens: a `⚠ ACTION NEEDED` prerequisite and how it resolved, each
  **destructive-op gate** with the blast radius presented and the developer's answer, and any step
  failure with the signature you matched in `pitfalls.md`.
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
  accurate terminal state and is what belongs in the log — not `done`. A skipped, blocked, or
  partially-completed step is recorded as precisely that. This is the same honesty rule the state
  file's status column already carries.
- **Write it as you go, never reconstruct it at the end.** The log exists to survive an
  interruption; a timeline assembled from memory afterwards does not serve that purpose.

The closing summary points at `.ai-dev/run.log` so the developer can audit what ran without re-reading
the conversation.
