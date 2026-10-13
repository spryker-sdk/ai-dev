# The state file: `.ai-dev/demo-prep.md`

Same shape and same role as the wizard's `.ai-dev/project-setup.md`: the run **is** this file. Write it
with Write/Edit.

```markdown
---
version: 1
run_mode: collaborative                 # or autonomous — asked last (SKILL.md, Run modes); governs cadence between phases only
answers_source: brief                   # where the demo came from — brief | script | paste. Never `proposed`:
                                        # this skill does not invent a demo. The first write of this file
                                        # waits until the preparer has seen and confirmed the routing table.
answers_confirmed_at: 2026-09-22T11:04:00Z   # required — the moment the preparer confirmed the routing table (phase 2).
                                        # Written only by that confirmation, never pre-filled. Its absence means the
                                        # routing was never agreed: resume re-shows the table instead of adopting the file.
up_front_confirmed_at: 2026-09-22T11:12:00Z  # the moment the one-sitting answers were confirmed. The demo fast path
                                        # copies it into project-setup.md's answers_confirmed_at.
customer: <name>
purpose: demo                           # from demo-intake — demo | project
platform: b2b-marketplace               # the literal composer.json name's flavour, from demo-intake §1
audience: both                          # business | consumer | both — from demo-intake §1; one clone serves all three
source: https://www.<customer>.com
brief: briefs/<customer>-brief.docx     # where the supplied demo lives — the file, or `pasted in chat`
entry_phase: 1                          # the phase this run started at (SKILL.md, Skip-ahead)
gaps_accepted: []                       # phase 4 and 6 gaps the preparer accepted, by needs-list line
up_front:                               # the one sitting (SKILL.md, Run modes) — delegates read these instead of asking
  products: []                          # names or URLs; [] = pick from the story. Key present = answered, never re-asked
  reference_sites: [https://www.<customer>.com]  # the look is matched to these; default = the intake's source URL
  shop_name: <name>                     # demo fast path — unmodified demoshop only
  stores: [{ country: DE, languages: [de_DE], currency: EUR }]
  sample_products: keep                 # keep | replace | trim: [<category>, …]
  run_sheet_format: html                # html | doc | chat | markdown — demo-run-sheet §0's four options
  harvest_root: ../<customer>-harvest/  # harvest-source-materials §1 — derived, shown at the confirmation
---
## Steps
<!-- status: pending | in-progress (+ progress note) | done | skipped (+ reason) | failed (+ where) -->
| phase | step | status | note |
|---|---|---|---|
| 1 | demo-intake | pending | |
| 2 | requirements | pending | (this skill — the confirmed routing table, then the needs list phase 4 harvests against and phase 6 accepts against; stamps answers_confirmed_at) |
| 3 | match-reference-design (spec) | pending | (before harvest — it sizes the editorial imagery) |
| 4 | harvest-source-materials | pending | |
| 5 | build (project track) | pending | (project-data · define-stores · translate-content · match-reference-design · boot-and-verify) |
| 6 | rehearsal | pending | (pass/gap per beat in .ai-dev/rehearsal.md) |
| 7 | demo-run-sheet | pending | |

## Decision log
<!-- One terse entry per decision: the phase, what was decided, the evidence, the alternatives rejected,
     and how to reverse it. A routing call the preparer overruled belongs here too — it is why the
     next table is shaped the way it is. Same file role as the wizard's .ai-dev/decision-log.md; keep
     it here so one demo reads as one artifact. -->

## Required follow-ups
<!-- Durable cross-phase handoffs, written by the phase that finds one and read by the phase that needs it. -->
```

## Rules the file follows

- **The first write waits for the routing confirmation.** Write `.ai-dev/demo-prep.md` only after the
  preparer has confirmed the routing table with one AskUserQuestion (phase 2). The demo fast path writes
  `.ai-dev/project-setup.md` with `answers_source: demo-prep` only when `.ai-dev/demo-prep.md` carries
  `up_front_confirmed_at`.
- **The phase-5 step skills wait for `answers_confirmed_at`.** Do not start `project-data`,
  `define-stores`, `translate-content`, `brand-project`, `boot-and-verify`, … while the state file
  carries no confirmation stamp: phase 2 is not closed, so close it first. Never write the stamp without
  the confirmation.
- **A step is `done` only with its evidence.** `build` → `.ai-dev/verifier-report.md`, and a
  `.ai-dev/design-acceptance.md` newer than the newest template or style edit and the last rebuild;
  `rehearsal` → `.ai-dev/rehearsal.md` with pass/gap lines written after the last rebuild (and the same
  design-acceptance rule after a template or style fix); `demo-run-sheet` → `.ai-dev/demo-run-sheet.html`,
  `.md` or `.txt`.
- **An autonomous run does not end its turn early.** While `run_mode: autonomous` and the Steps table
  has a row that is not `done` or `skipped`, the Stop guard (`hooks/guard-stop.php`,
  [`hooks/README.md`](../../../hooks/README.md)) keeps the turn going; it lets the turn end on a question
  only the preparer can answer, a `NEEDS YOU:` line, or an AskUserQuestion.
