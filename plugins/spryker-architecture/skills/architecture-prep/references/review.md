# Self-review — is the arc42 output signed-off-ready for a Spryker project?

This is the **quality gate** for Step 6, run by a dedicated review subagent (never the writers who
produced the sections — an independent reader catches what the author's context hides). The reviewer
reads the written `architecture/` files, the run's `intake.md`, and this checklist, then returns a
**per-section verdict + a ranked defect list**, not a rewrite.

The bar is **"is every sentence here earning its place, and is it true?"** — in that order. The
document is long-lived context that later work depends on, so excess is a defect in its own right,
not a harmless surplus.

Run in two passes, reduction first:

| Pass | Question | Output |
|---|---|---|
| **1 — Reduction** | What should not be here at all? | Cut list |
| **2 — Correctness** | Is what remains true and grounded? | Ranked defect list |

Reduction comes first so pass 2 never spends effort fact-checking content that is about to be cut.

## How the reviewer runs

- **Read-only.** The reviewer does not edit `architecture/`. It reports; the orchestrator (Gated) or a
  targeted fix pass (Autonomous) applies changes. Spawn it with your harness's subagent tool as a
  read-only research subagent or a fresh general-purpose subagent on a full-strength model, fed: the
  list of written files, `intake.md`, `research-docs.md`, and this `review.md`.
- **Scope = the sections actually written this run.** Don't fault an untouched template section that
  wasn't in scope. Do fault a section that was *supposed* to be written but left as template example
  content (SAP/Akeneo/Auth0 leftovers) — that's the #1 silent failure.
- **Every finding is actionable and located.** `file:heading` + one-line defect + the fix. Rank by
  severity: **BLOCKER** (wrong/fabricated/build-blocking) → **MAJOR** (depth-bar miss) → **MINOR**
  (polish, cross-link, terminology).
- **Verdict per section:** `PASS` / `PASS-with-TODOs` / `NEEDS-WORK`, plus one overall verdict.
- An honest, well-owned `> **TODO:**` is **not** a defect — it's a professional outcome. A *fabricated*
  number, an *invented* integration, or a TODO where the intake gave a real answer **is** a defect.

## Pass 1 — Reduction (run this first, and be ruthless)

Every item below is a **cut**, not a suggestion. The writer's job was to be lean; this pass catches
what slipped through.

- [ ] **Duplication, within and across sections.** The same fact stated in a section, a table, and a
      diagram is two defects. Keep the one authoritative place, replace the rest with a cross-reference.
- [ ] **Duplicate diagrams.** Two diagrams showing the same relationships — most commonly a runtime
      diagram restating the context diagram. Cut the redundant one.
- [ ] **Unverified platform claims are marked.** A capability named without a docs link must carry
      `(unverified)`. An unmarked unverified claim is a defect; a marked one is acceptable.
- [ ] **Any trace of the process that produced the document.** Deliberation, self-correction, review
      history, "this was derived from…", notes about what the AI decided or fixed. The document is a
      **snapshot of the current architecture**, not a record of how it was written. Reasoning stays in
      the session.
- [ ] **Content the intake never covered.** If no question asked about it, it does not belong —
      however sensible it looks.
- [ ] **Content generated because a value was missing.** Derived figures, interim assumptions, load
      test plans, capacity projections, mitigation plans for unstated problems. Replace the whole
      passage with one line naming the gap and its owner.
- [ ] **Detail beyond what was asked.** Where the intake gave a direction but no specifics, a short
      suggestion is right; a plan with numbers, phases and owners is not. The user can ask for depth.
- [ ] **Authored Solution Designs or ADRs.** `sd-XXX-*.md` / `adr-XXX-*.md` files, or anything marked
      `Accepted`, are out of scope — this skill produces candidate lists only.
- [ ] **Findings mined from project config files.** Placeholder credentials, permissive CORS, default
      regions: properties of the template, not of this project. Cut entirely.
- [ ] **Source material copied into `architecture/`.** Briefs, TADs, exports, notes. Cut; cite by name.
- [ ] **Surviving template examples.** Example entities (SAP, Akeneo, Auth0, sample volumes) and
      example diagram files unrelated to this project.

### Section-purpose compliance — check this first, per section

For each section in scope, state **the one architectural question arc42 gives it**, then judge the
content against that question alone:

- Does every part of the section help answer it?
- Is anything here that belongs to another section's question — or to a Solution Design?
- Are abstraction levels mixed (a context section carrying integration specifics, a decomposition
  section carrying requirements mapping or implementation detail)?
- Is source information present without architectural value — project context, delivery information,
  requirement inventories, research findings?
- Could this be said in fewer words without losing architectural meaning?

Content that is true but misplaced is still a defect. Say where it should go.

**Term discipline.** Check every row filed under quality goal, constraint, risk, technical debt, open
question and decision against the definitions in
[architect-persona.md](architect-persona.md). A functional requirement filed as a quality goal, a
platform behaviour filed as a constraint, an unknown filed as a risk, or a requirement filed as debt
is a defect — name the correct home or cut it.

**Diagram notation.** Check every diagram against [diagrams.md](diagrams.md): one box per system,
no protocol or state boxes, no integration IDs in labels, correct level discipline, and **identical
names across all diagrams, tables and prose**. Naming drift between diagrams is a defect.

Report the cut list first, with a line count. If reduction removes more than a third of a section,
say so plainly — that is a signal the writer misjudged the bar, worth naming.

## A — Grounding & honesty (Spryker-specific, highest weight)

The failure mode that a generic reviewer misses. Check every platform claim:

- [ ] **No fabrication.** Every volume, integration, actor, and module name traces to `intake.md`, a
      provided doc, or `research-docs.md`. A confident number with no source is a BLOCKER.
- [ ] **Derived-but-unconfirmed facts are still marked.** Anything `intake.md` records as
      `derived (unconfirmed)` — pre-filled from a document but never confirmed by the user — must
      read as an assumption in the section, not as a stated fact. An unconfirmed derivation
      presented as settled is a BLOCKER; it is fabrication with a citation attached.
- [ ] **Spryker behavior is grounded, not remembered.** Any "Spryker does X / uses P&S / this module
      handles Y" claim is backed by `research-docs.md` (ideally with a docs URL). Unsourced platform
      assertions are MAJOR.
- [ ] **Named features are real and current.** Mitigations and building blocks cite features that
      actually exist and are generally available. Verify each named feature against the current
      documentation via `research-docs.md` rather than from memory — including its availability
      status, since a feature that is announced, deprecated, or partially available is easy to cite
      as if it were GA. A cited-but-nonexistent or wrong-status feature is a BLOCKER.
- [ ] **No template leftovers.** Grep the written sections for the template's example entities (SAP,
      Akeneo, Auth0, the sample volume figures, sample diagram labels). Any survivor in an in-scope
      section is a BLOCKER — it means the section was not actually rewritten.
- [ ] **Every gap has an owner.** An open item names what is missing and who can answer it. An
      ownerless `TODO` is MAJOR. A gap filled with a derived value instead is a BLOCKER.

## B — Substance (from architecture-depth.md)

Lean is the target, but lean is not empty. For the sections in scope:

- [ ] **Every answer the intake gave is reflected somewhere.** A supplied fact that reached no section
      is the one gap that matters — the interview cost the user time for nothing.
- [ ] **Release phasing.** Scope items and integrations carry the phase names the intake supplied, and
      the volume table columns use the same names. (§01/§03/§10)
- [ ] **Components + Connectors tables accompany the context diagram** — never a diagram alone.
      Connector rows carry a real Type and put numbers (or an honest `TBD`) in the constraints cell.
      This detail appears **only** here, not also in the diagram or the prose. (§03)
- [ ] **Volume table records what was given, and lists what was not** as one grouped gap list with
      owners. No derived figures. (§10)
- [ ] **Risk mitigations name real Spryker features** with a docs link, not generic advice. Feature
      availability is verified, never asserted from memory. (§11)
- [ ] **Solution Design and ADR candidates are listed** where the intake flagged something as needing
      investigation — as candidates, two or three sentences each, never authored.
- [ ] **Each section returned its top 1–3 depth candidates** to the orchestrator, and none of that
      content was written into the document.

## D — arc42 structure & internal consistency (the generic-but-necessary layer)

- [ ] **§01 header block** present: Status (Draft/In Review/Approved, optional RAG), Author/Driver,
      Approver(s), key dates, version.
- [ ] **Quality goals**: 3–5, each with a measurable scenario (a real number/threshold), prioritized.
- [ ] **Stakeholders**: each party has a stated expectation; deployment/ops roles present if §07 written.
- [ ] **Cross-links resolve**: §03→C1, §05→C2, §06→sequences; every "See diagram" link points at a
      file that exists; §04/§09 README indexes list their SDs/ADRs.
- [ ] **Terminology reconciles with §12** — every domain/Spryker term used is defined in the glossary.
- [ ] **External systems are consistent** across §03/§02/§06/§11 and the diagrams (same names, no system
      appearing in a diagram but missing from the Components table).
- [ ] **Diagrams are Mermaid `.mmd` only** (ERDs = `erDiagram`), follow the color convention, and
      actually render — no PlantUML/`.puml`, no binaries. A `.puml` file, or a `;` in a note / a `%%`
      comment before the `---` frontmatter (both break the Mermaid parser), is a defect.

## What the reviewer returns

```
OVERALL: PASS-with-TODOs   (or PASS / NEEDS-WORK)

Per-section:
  01-introduction-and-goals.md ...... PASS-with-TODOs
  03-system-scope-and-context.md .... NEEDS-WORK
  ...

Findings (ranked):
  [BLOCKER] 03 §External Systems — "SAP S/4HANA" is template leftover, intake names Infor M3. Replace.
  [MAJOR]   10 §Volumes — no +1Y column; intake gives year-2 country expansion. Add per-phase columns.
  [MINOR]   05 §Components — C2 link points at c2-container.mmd which doesn't exist (file is c2-containers.mmd).
  ...
```

The reviewer never rewrites sections — it hands this report back so the fix is a deliberate, logged
step.

## Review is a loop, not a report — every finding must be resolved

This checklist exists to **drive fixes**, not to produce a verdict and stop. Per SKILL.md Step 6, the
orchestrator runs **review → fix → re-verify** until it passes:

1. **Fix is mandatory** after any round that finds a BLOCKER or MAJOR — in both Gated and Autonomous
   mode. Fixes go back to writer teammates (same per-section / per-deliverable isolation as the
   original write); small mechanical fixes the orchestrator applies inline.
2. **Every finding resolves in exactly one honest way**: *fixed*, or *converted to an owner-named
   gap*. Never silently dropped, never left standing as a defect.
3. **Re-verify with a fresh reviewer** (new independent context) after each fix pass — an author, or a
   reviewer re-reading its own pass, can't see what it missed. Loop until **zero open BLOCKER/MAJOR**
   (capped at 3 rounds; an unresolved blocker at round 3 is escalated in the summary, not hidden).

So a reviewer's job is to make each finding *fixable*: located, actionable, and severity-ranked — so
the fix pass knows exactly what to change and the re-verify knows exactly what to re-check.
