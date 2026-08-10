# project-starter-wizard

Turn a **fresh, un-booted clone of a Spryker b2b / b2b-marketplace demoshop into the customer's
project** — one developer interview up front, then nine orchestrated steps to a verified running shop.

This skill owns the conversation and the flow; it writes almost nothing itself. The transformation
work belongs to the sibling step-skills, and the wizard's job is to ask everything once, record the
answers in a resumable state file, and drive the steps in the one order that works.

## When it triggers

At the very start of a new customer project, on a fresh clone: "turn this demoshop into our
project", "start the project setup", "run the project starter". It is **also the resume entry** — if
a prior run left `.ai-dev/project-setup.md`, invoking it skips the interview and continues from the
first step that isn't `done` or `skipped`.

Not for an already-booted or dirty clone with no state file — that stops with the
**"Return to fresh" recipe**, never a bare refusal.

## Flow schema

```mermaid
flowchart TD
    A([Invoked on a clone]) --> P["0 · Pre-flight<br/>flavor · fresh · un-booted<br/>docker volume collision<br/>ports · composer auth · disk<br/>.git.docker SDK pin · inventory"]

    P --> PS{"State file<br/>.ai-dev/project-setup.md<br/>exists?"}
    PS -- "yes" --> RES["Resume<br/>re-read run_mode<br/>skip to first step<br/>not done/skipped"]
    PS -- "no" --> PF{"Fresh &amp;<br/>un-booted?"}

    PF -- "no" --> STOP([Stop + hand over<br/>the Return-to-fresh recipe])
    PF -- "yes" --> I["1 · Interview<br/>references/interview.md<br/>nine sections, AskUserQuestion<br/>identity · namespace · services ·<br/>stores+region · data mode ·<br/>catalog scope · localization ·<br/>CI · run mode"]

    I --> C{"2 · Confirm<br/>every answer?"}
    C -- "no" --> I
    C -- "yes" --> W["Write .ai-dev/project-setup.md<br/>frontmatter + Steps table"]

    W --> S1["1 · project-ci-generator<br/>pre-boot, runs FIRST"]
    RES --> S1
    S1 --> S2["2 · configure-codebase<br/>skipped if keep-pyz"]
    S2 --> S3["3 · brand-project<br/>identity half only"]
    S3 --> S4["4 · configure-services"]
    S4 --> S5["5 · define-stores<br/>skipped if data.mode = leave"]
    S5 --> S6["6 · project-data<br/>adapt / clean / generate / leave<br/>+ reduce pass, adapt only"]
    S6 --> S7["7 · cypress-migration<br/>last pre-boot step"]

    S7 --> GATE{"Ordering gate<br/>steps 1–7 all<br/>done or skipped?"}
    GATE -- "no" --> S1
    GATE -- "yes" --> S8["8 · boot-and-verify<br/>first boot + verification<br/>+ brand theming half<br/>+ codecept seed + cy:run"]

    S8 --> S9["9 · translate-content<br/>only if localize.locales<br/>is non-empty"]
    S9 --> DONE([Close: staged files ·<br/>go-live debt · /etc/hosts line])

    S1 -.-> MODE
    S8 -.-> MODE
    MODE{"Per step:<br/>run mode + hard-stops"}
    MODE -- "autonomous · reversible" --> LOG["Decide, log to<br/>.ai-dev/decision-log.md,<br/>keep going"]
    MODE -- "supervised · step boundary" --> ASK["Ask 'Continue to next step?'<br/>and wait"]
    MODE -- "⚠ ACTION NEEDED<br/>(both modes)" --> HUMAN["Wait for the developer<br/>Docker · /etc/hosts · token"]
    MODE -- "deletion / data wipe / sudo<br/>(both modes)" --> HUMAN2["Present blast radius,<br/>get explicit go-ahead"]
    MODE -- "step failure" --> FAIL([Stop with guidance,<br/>state file records where])
```

## Files

| File | Role |
|------|------|
| [`SKILL.md`](SKILL.md) | The spine — pre-flight, the nine steps and their ordering rationale, run modes, hard-stops, resume, abort paths, and the tooling discipline that binds every step. |
| [`references/interview.md`](references/interview.md) | The decision catalog — AskUserQuestion mechanics, plain-language phrasings for the jargon, the nine sections, and the `.ai-dev/project-setup.md` template the answers are written to. Read **before** the first question. |
| [`references/pitfalls.md`](references/pitfalls.md) | The Known-traps catalog — every known failure signature across the whole run as *signature → cause → fix*, grouped by area (cross-cutting, boot aborts, post-boot false greens, per-step). Match a failure here before diagnosing from scratch. |

## Step → skill map

The wizard runs these in this exact order; each links to its own README.

| Step | Skill | Notes |
|------|-------|-------|
| 1 | [project-ci-generator](../project-ci-generator/README.md) | Pre-boot, first — executes interview §8's `ci:` plan; owns a dropped suite's whole footprint. |
| 2 | [configure-codebase](../configure-codebase/README.md) | Namespace + FE/test wiring; skipped on `keep-pyz`. |
| 3 | [brand-project](../brand-project/README.md) | Identity half only — theming half runs in step 8. |
| 4 | [configure-services](../configure-services/README.md) | Engines, dev services, applications into `deploy.dev.yml`. |
| 5 | [define-stores](../define-stores/README.md) | Region + store definitions; skipped if `data.mode = leave`. |
| 6 | [project-data](../project-data/README.md) | Strategy by `data.mode`: adapt / clean / generate / leave. |
| 7 | [cypress-migration](../cypress-migration/README.md) | E2E infra; last pre-boot because it reads steps 1, 3, 4, 5 and 6's output. |
| 8 | [boot-and-verify](../boot-and-verify/README.md) | First boot, per-store verification, and the post-boot halves of steps 2, 3 and 7. |
| 9 | [translate-content](../translate-content/README.md) | Optional; only when `localize.locales` is non-empty. |

Steps also lean on [spryker-import-tools](../spryker-import-tools/README.md) for all CSV work, and
step 8 spawns the `spryker-verifier` agent for its authoritative verdict.

## Modes

Chosen in interview §9, recorded as `run_mode` in the state file, honoured again on resume.

- **autonomous** — steps 1–9 as one continuous pass, no "continue?" check-ins. At a reversible,
  in-project decision point it picks the best option and records it in `.ai-dev/decision-log.md`.
- **supervised** — a one-line result and an explicit "Continue to `<next step>`?" at each step
  boundary; every decision surfaced as a question with a recommendation.

Neither mode relaxes the hard-stops: a `⚠ ACTION NEEDED` prerequisite, any deletion / data wipe /
`sudo`, and a step failure all return control to the developer in **both** modes.

## Design decisions baked in

- **Interview once, then no further configuration questions.** All nine sections are collected up
  front so the run needs no new config decisions — but the skill refuses to over-promise silence:
  destructive operations and any data defect the boot surfaces still consult the developer.
- **Communication is load-bearing, not cosmetic.** A required human action leads the message as a
  single `⚠ ACTION NEEDED:` line, alone, above any status — a prerequisite buried under a
  validation table reads as status, and the run stalls on an unread ask.
- **The state file is the run.** `.ai-dev/project-setup.md` carries the answers and a Steps table
  with per-step status and intra-step progress notes, so an interrupted run resumes precisely
  instead of blindly re-running a non-idempotent deletion pass.
- **Surgical edits only.** A step changes only the exact keys it owns; neighbouring blocks stay
  byte-for-byte as shipped (an agent that also "tidied" the adjacent `docker.mount.mutagen` block
  crippled the Mac dev env).
- **Environment limits are not project defects.** No TTY, the tool timeout, shell word-splitting —
  those are limits of how the agent runs. Surface them; never edit project config to work around one.
- **A closed set of capabilities, one simple command per call.** CSV work goes through the
  `spryker-import-tools` php tools, file work through Read/Grep/Edit, state changes through
  `docker/sdk`. Pipes, `&&`, loops and subshells prompt regardless of the allowlist — so they're
  avoided by shape, not by permission.

## Run artifacts

The run writes four files into the clone's own tree — it is self-contained, and "Return to fresh"
deletes them:

| File | Role |
|------|------|
| `.ai-dev/project-setup.md` | The **state** — interview answers + the Steps table Resume operates on. |
| `.ai-dev/run.log` | The **timeline** — step START/END, conditional skips with their reason, hard-stops, sub-skill handoffs, the step-8 verifier verdict, and every `RESUME`. |
| `.ai-dev/decision-log.md` | The **rationale** — each autonomous decision with its evidence and how to reverse it. |
| `.ai-dev/skill-improvement-log.md` | Maintainer feedback (**dev scaffolding — stripped before release**). |

The log is created in §2 beside the state file, and a resumed run **appends a `RESUME` line rather
than starting a new file**, so one run reads as one continuous timeline across interruptions. It is
written with Write/Edit — not `printf >>` — because redirects prompt regardless of the allowlist,
which would break the hands-off guarantee.

The four files sit flat at `.ai-dev/` because the state file's path is load-bearing: pre-flight
detects a prior run by it, Resume reads it, and "Return to fresh" deletes it.

## Output

A transformed clone: project identity and branding, a registered namespace, the chosen services and
stores, project-shaped import data, a lean CI pipeline, a vendored Cypress suite — booted, verified
per store by an independent verifier agent, with changes staged (never committed).

Two kinds of leftover work are tracked rather than silently dropped:

- **`## Required follow-ups`** in the state file — durable cross-step handoffs, written by the step
  that discovers one and read by the step that consumes it, so they survive a resume.
- **The close summary** — flags what still needs a human before go-live, such as an `origin` that
  still points at the demoshop upstream, a still-shipped Spryker logo, and any translation debt from
  locales left as English copies.

## Packaging note

This skill ships in the `spryker-ai-dev-sdk` plugin under `vendor/spryker-sdk/ai-dev/…`, which is
Composer-managed — `composer update spryker-sdk/ai-dev` may overwrite it. The durable home for edits
is the plugin's own repository (`github.com/spryker-sdk/ai-dev`).
