# Section-writer guidance

One `§` per arc42 section: what belongs in the file, which diagram(s) pair with it, and how to fill it
from the shared inputs. A **section-writer teammate reads only its own §** plus `intake.md`, the
relevant research file(s), and its part of [architecture-depth.md](architecture-depth.md) (the content-depth bar);
the orchestrator skims the map and the shared conventions.

> **Depth bar:** every section aims for real decision-grade substance (see [architecture-depth.md](architecture-depth.md)),
> not a template fill. In particular: release-phase tags on scope/integrations, the **Components +
> Connectors** rationale tables beside diagrams, a per-phase volume table, and risk mitigations that
> cite real Spryker features. Where a `§` below points at architecture-depth.md, read that part too.

## Shared conventions (every writer follows these)

- **Replace examples, keep structure.** The template files ship illustrative content (SAP, Akeneo,
  Auth0, sample volume tables, sample diagrams). Preserve the heading structure, the `*Corresponds to
  [arc42 Section N]*` footer, and the table shapes; swap the *content* for this project's reality from
  `intake.md`. Never leave a mix of real and example content without marking the example part.
- **Ground, don't guess.** Anything about how Spryker behaves comes from `research-docs.md`; external
  facts from `research-web.md`; facts about *this* project from `intake.md`. If a fact isn't in the
  inputs, write `> **TODO:** <what's missing and who can answer it>` instead of inventing it.
- **Diagrams follow [diagrams.md](diagrams.md)** — C4 notation rules, level discipline, sequence-
  diagram scope, Mermaid-only format, and the colour scheme. Read it before drawing anything.
- **The deliverable holds only the architecture record.** Never copy source material — briefs, TADs,
  exports, notes — into `architecture/`. Read it where the user put it and cite it by name.
- **Remove template example content in every folder you touch**, including example diagram files that
  belong to no section of this project. A leftover example is a defect, not a harmless extra.
- **Name sections, never number them alone.** In anything a reader sees, write
  `§11 Risks and Technical Debt` or "the Risks and Technical Debt section" — never a bare `§11`.
  A reader who has not memorised arc42 cannot resolve a number. Same for diagram references: name
  what it shows, not just `C1`.
- **File-reference format.** When a section cites code, use the project's absolute-clickable-path
  convention (`/abs/path/File.php:line`).
- **Return compact.** A writer returns: files written, a 2–3 line "what I put in it", and its open
  TODOs — not the prose it wrote.

## Section → diagram map

| Section | Paired diagram(s) | Category / file |
|---|---|---|
| 03 System Scope & Context | **C1 System Context** | `diagrams/c4/c1-system-context.mmd` |
| 05 Building Block View | **C2 Container** | `diagrams/c4/c2-*.mmd` |
| 06 Runtime View | **Sequence** (per scenario), optional **data-flow** | `diagrams/sequence/*.mmd`, `diagrams/data-flow/*.mmd` |
| 03/06 integrations | **Integration overview** | `diagrams/integration/*.mmd` |
| 05/persistence | **ERD** (if data model matters) | `diagrams/erd/*.mmd` (Mermaid `erDiagram`) |

---

## § 01 — Introduction and Goals

**File:** `01-introduction-and-goals.md`. **Inputs:** intake (Q2 name, Q3 outcomes, Q4 build type,
Q5 actors, Q9 quality goals, Q24 migration).

**Answers one question: what is this system for, and by what qualities is it judged?**

- **Status header:** Status, author (from the intake — never a name lifted from a provided
  document), key dates, version. Mark unknowns `TBD`.
- **Requirements overview:** the business model, the system being replaced if any, go-live, and
  whether the scope is MVP or full — short, in this project's terms.
- **Functional scope table:** scope items with a phase column, described in project terms.
- **Quality goals:** the goals the intake ranked, each with a concrete scenario. **Non-functional
  only** — performance, availability, security, changeability, operability. A functional capability
  is not a quality goal, however important it is; it belongs in the functional scope. If the intake
  named fewer than three, list fewer.
- **Migration:** source system, entities, volumes, timeline. If none, one line saying so.
- **Stakeholders:** the roles the intake named, with what each expects from the architecture.

## § 02 — Constraints

**File:** `02-constraints.md`. **Inputs:** intake (Q27 inherited limitations, Q43 fixed technical
choices, Q44 legal obligations, Q10 hosting); conventions from the project's own instructions file
and rules folder where relevant — `CLAUDE.md` + `.claude/rules/` under Claude Code,
`.github/copilot-instructions.md` + `.github/instructions/` under GitHub Copilot.

**Answers one question: what removes the architect's freedom?**

A constraint is imposed from **outside** the architecture — mandated technology, regulation, budget,
deadline, organisational policy, a limitation of a system that must be lived with. Record each with
its motivation; a constraint without a reason is noise.

**Not constraints, and not to be written here:**

- **How the chosen platform works.** Its documented behaviours and architectural properties are the
  platform, not restrictions on the project.
- **Anything observed in the starting template** — its namespace, package versions, language version,
  sample configuration. These are defaults that the project will change, and stating them as fixed is
  wrong as well as short-lived.
- Consequences of design decisions this document is making.

If a table has no genuine entries, say so in a line. An empty constraints table is a valid and
honest outcome; an invented one misleads every later reader.

## § 03 — System Scope and Context  ⟶ C1 + C2

**File:** `03-system-scope-and-context.md`. **Inputs:** intake (Q5 actors, Q7 systems, Q20
connections); `research-docs.md`.

**Answers one question: where is the system boundary, and what does it relate to across it?** Not how
those relationships are implemented.

- **Business Context:** the system and its communication partners — actors and external systems —
  with the business reason for each. Name the C1 diagram as the subject.
- **Technical Context:** name the **C2 container diagram** as the subject, then the two tables:
  - **Components** — one row per system on the diagrams: system, what it provides or accepts,
    stakeholders.
  - **Connectors** — one row per relationship: interaction, description, type (sync/async, REST,
    file, queue…), and frequency/volume/constraints.

  These two tables are the complete integration statement. **Do not add a third table** repeating
  direction, protocol or frequency — C4 prescribes components and connectors, and a third view is
  duplication by construction.
- **This writer produces both the C1 and C2 diagrams** (see [diagrams.md](diagrams.md)), so their
  names stay consistent with each other and with the tables here. §05 Building Block View reuses the
  C2 diagram and its names verbatim.
- **Verify before returning:** every system name in the tables appears identically on both diagrams,
  and every diagram box appears in the Components table. A mismatch is a defect.

## § 04 — Solution Designs (stubs only — never designed here)

**Dir:** `04-solution-designs/`. **Template:** `sd-000-template.md`. **Naming:** `sd-XXX-brief-title.md`.

**Create a real file per topic that needs designing — and leave the design empty.** The stub is where
that topic's open questions live, so they stop cluttering the main sections.

Each stub contains, and nothing more:

- **Metadata** — status `Draft`, today's date, the author from the intake.
- **Problem statement** — two or three sentences: what needs designing, and why the architecture
  record cannot settle it.
- **Open questions** — every question and TODO belonging to this topic, as a list. This is the
  substance of the stub and the reason it exists.
- The template's remaining headings (proposed solution, data model, implementation plan, trade-offs,
  alternatives) **left as empty headings.** Do not fill them, do not sketch them, do not write
  "TODO" under each one.

Rules:

- **Never design.** No proposed solution, no data model, no diagrams, no implementation plan, no
  trade-off analysis — even when the source material contains enough depth to attempt one. Designing
  is a separate exercise with its own context and its own review.
- Anything the intake answered `Too complex to decide now — needs investigation` gets a stub.
- Any topic that accumulated more than a couple of integration-specific questions gets a stub.
- List the stubs in `04-solution-designs/README.md`, one line each.
- Leave `sd-000-template.md` untouched.

### Where a TODO belongs

| TODO | Goes |
|---|---|
| Affects the architecture, or blocks progress on it | Inline in the section it affects |
| Specific to one integration, capability or design topic | That topic's Solution Design stub |

A section carrying a pile of integration-specific TODOs is a signal the stub is missing, not that the
section is thorough.

## § 05 — Building Block View

**File:** `05-building-block-view.md`. **Inputs:** intake (Q11 out-of-scope, Q25 capability
classification); `research-docs.md`; the C2 diagram produced with §03.

**Answers one question: how is the system internally decomposed, and how do those parts relate?**
Not why it was built that way, not which requirement each part serves, not how any part is
implemented.

- **Containers:** the platform's containers in scope (storefront, back office, API surfaces, data
  stores, messaging, workers, plus any project-specific service) and each one's responsibility in a
  line. Reference the C2 diagram; reuse its names exactly.
- **Custom domains:** the capabilities the intake classified as custom development, one row each with
  a one-line responsibility.
- **Never a requirements-to-solution mapping**, an effort estimate, or a module-level breakdown.

**C4 Level 3 (Component) is out of scope** — component decomposition belongs to a Solution Design.
Never create `c3-*.mmd`.

## § 06 — Runtime View  ⟶ sequences

**File:** `06-runtime-view.md`. **Inputs:** intake (Q28 flows worth diagramming); `research-docs.md`.

**Answers one question: how do the parts interact at runtime, for the flows that matter?**

- Only the flows the intake named as important or risky. Never one per integration.
- Each flow: a short step list **between systems**, and a sequence diagram per
  [diagrams.md](diagrams.md).
- **System level only.** Which systems participate and in what order — never which internal
  component calls which, never payloads, persistence steps or error branches. That is design work:
  register a Solution Design stub and put the questions there.
- Do not restate what the context diagram already shows.

## § 07 — Deployment View

**File:** `07-deployment-view.md`. **Inputs:** intake (Q10 hosting, Q8 markets); `research-docs.md`.

**Gate — confirm the target setup before writing anything.** The intake is the primary source. If it
does not state the store and region setup:

1. If the project has a deploy file, ask the user plainly: *"Does `deploy.<x>.yml` reflect the
   **intended target** setup — regions, stores, and their split?"* Show what it contains and let them
   confirm, correct, or reject it.
2. **Confirmed** → write the section, citing the confirmation as the source.
3. **Rejected, or no answer** → **do not write this section.** Record one TODO naming what is needed
   (target regions, stores per region, shared-vs-separate data) and who owns it.

A deploy file is only ever a **question to confirm**, never a source of fact. Its contents are
frequently framework defaults: writing them up as the project's architecture states as fact something
nobody decided. The same applies to every other project config file.

**Never mine config files for findings.** Placeholder credentials, permissive CORS, sample regions
and similar defaults are properties of the starting template, not of this project. They are not risks,
not constraints, and not architecture — leave them out entirely.

**Multi-store setup (when >1 store/country and the setup is confirmed):** region(s), stores per
region, one-DB-vs-per-store, what is shared versus separate, and any data-residency constraint. A
small Mermaid diagram showing region → store → DB/Redis/ES helps. Name Dynamic Multi-Store only if
the intake indicates it and docs research confirms its status.

## § 08 — Cross-Cutting Concepts

**File:** `08-crosscutting-concepts.md`. **Inputs:** intake; `research-docs.md`.

**Answers one question: which concepts apply across several parts of the system, and what approach
does the architecture take to each?**

This is where the commerce concepts that shape a Spryker architecture are recorded — price
formation, inventory and availability, B2B customer structure, multi-store, identity and access.
Cover the ones the intake actually touched, each in a short subsection: the approach taken and the
architectural consequence.

- **State the approach, never design it.** "Prices are mastered externally and imported per store" is
  an architectural statement. A field mapping, an import schedule, or a proposed module is not —
  those go to a Solution Design stub.
- **Do not describe the platform's internal mechanisms** as though they were project decisions.
  Mention one only where the project's choice genuinely depends on it.
- Skip any concept the intake did not touch. An absent concept is not a gap to fill with generic prose.

Where a concept grows past a short subsection, give it its own file in an `08-crosscutting-concepts/`
folder and link it from the section.

## § 09 — Architecture Decision candidates (never authored here)

**Dir:** `09-architecture-decisions/`.

**This skill identifies decisions worth recording; it does not author ADRs.** Writing them is a
separate exercise, run once the decisions are genuinely made and agreed.

Produce a single `README.md` listing the candidates. Each is **a title plus two or three sentences**
— the decision to be made or already taken, and why it belongs on the record:

> **ADR candidate: SSO protocol for subscriber login**
> The intake names OAuth2/OIDC as the intended mechanism but no decision is recorded. Worth an ADR
> once confirmed, since the identity choice constrains both storefront and Back Office access.

Rules:

- **Never create `adr-XXX-*.md` files**, and never mark anything `Accepted`. Status is the user's to
  set, not the skill's — a decision the user has not explicitly confirmed is a candidate, nothing more.
- **No Context / Decision / Consequences sections, no trade-off analysis.** Three sentences.
- Leave the shipped `adr-000-template.md` untouched, for the later authoring step.

## § 10 — Quality Requirements

**File:** `10-quality-requirements.md`. **Inputs:** intake (Q25 volumes, Q26 targets, Q9 quality goals).

- **Volume table:** record the figures the intake supplied, one column per phase named in Q8. Do not
  add rows the intake did not cover.
- **Gaps:** follow the volume table with **one grouped list of what is missing** — grouped by category
  (catalogue, load, customers, expansion, migration), one line each: the missing figure, why it
  matters in a clause, and who owns it. Nothing longer.
- **Never derive a missing figure**, and never size anything against a derived one. No interim
  concurrency assumptions, no peak-load test plans, no capacity projections. A missing number is a
  gap to name, not a problem to solve.
- **Never ask for, or invent, a search index size** — it is a derivative of catalogue data and cannot
  be stated meaningfully up front.
- **Quality scenarios:** measurable stimulus/response/measure rows for the goals named in Q9. One row
  per goal, no elaboration.

## § 11 — Risks and Technical Debt

**File:** `11-risks-and-technical-debt.md`. **Inputs:** intake; the run's open questions.

**Answers one question: what architectural exposure does this system carry?** It summarises exposure
— it does not explain the architecture, and it does not restate content from other sections.

Apply the definitions in [architect-persona.md](architect-persona.md) strictly. Each row must survive
its own test:

- **Risks** — an uncertainty *plus* a plausible negative consequence, with likelihood, impact, and a
  mitigation naming a real Spryker capability (confirmed via docs research, docs page linked).
  **When research is unavailable**, name the capability and mark it `(unverified)` without a link —
  never drop the mitigation, never invent a link, and never block the section. An unverified
  capability the reader can check beats a vague mitigation they cannot.

  Two tests every candidate row must pass:

  1. **Could it not happen?** If it is certain, it is not a risk. Functionality that must be built,
     a capability the platform does not ship, an integration with no first-party connector — these
     are **work to be estimated**, not risks. Build effort belongs to planning; absence of an
     out-of-the-box connector belongs to the functional scope.
  2. **Name the consequence.** "X is unknown" is an open question. It becomes a risk only when you
     can state what goes wrong if X resolves badly.

  Infrastructure and environment gaps behind a **committed requirement** do qualify — a region that
  must exist for a committed market and has no provisioning plan is a genuine uncertainty with a
  clear consequence.
- **Technical debt** — only consciously accepted compromises that create future cost, complexity or
  constraint and are expected to be revisited. Requirements, temporary migration states, unknowns and
  unavoidable complexity are **not debt**. An empty table is a valid outcome for a greenfield build.
- **Open questions** — only those whose answer would change the architecture. Anything specific to one
  integration or capability belongs in its Solution Design stub, not here.

**Never record the same uncertainty twice.** One uncertainty gets one form — a risk, or an open
question — never both, and never a third "decision gate" restating it. Where a launch-blocking
question needs an owner and a date, put them on that one row.

## § 12 — Glossary

**File:** `12-glossary.md`. **Inputs:** every other section's terms; `research-docs.md` (correct
Spryker definitions).

Define every domain, technical, and Spryker-specific term used across the chosen sections. Keep the
three-table split (Business / Technical / Spryker-Specific). This section is written/updated **last or
during the cross-link pass**, because it must cover exactly the terms the other sections introduced —
the orchestrator can own it at Step 5 if no dedicated writer did (in a multi-deliverable run, the
deliverable's own writer owns it as part of its cross-link pass).
