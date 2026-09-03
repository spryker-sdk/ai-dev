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
- **Diagrams as code — Mermaid (`.mmd`) ONLY, for every diagram type including ERDs.** Use Mermaid
  `flowchart`/`sequenceDiagram`/`erDiagram`/etc. for flow, context, sequence, data-flow, integration,
  AND entity-relationship diagrams. **Do NOT use PlantUML (`.puml`)** — it can't render client-side, so
  it's invisible in the browser preview, the exported PDF, and GitHub's Markdown renderer. Model ERDs
  with Mermaid `erDiagram` (entities with `PK`/`FK` markers and crow's-foot cardinality). Store diagram
  files under `architecture/diagrams/<category>/` and reference them from the section with a relative
  link (`[C1 System Context](diagrams/c4/c1-system-context.mmd)`) — the template uses this external-file
  approach. Match the existing example diagrams' style.
  **Harvest before you author:** if the source doc (a TAD, a brief) already embeds a diagram, start
  from it — restyle to the color convention below; **if it's PlantUML, transcribe it to Mermaid** rather
  than keeping `.puml`. Don't redraw from scratch and drift from what the architect already approved.
  Two Mermaid syntax traps to avoid so diagrams actually render: never put a `;` in note/label text
  (it's a statement separator — use `,`), and keep any `%%` comments AFTER the `---config---`
  frontmatter, never before it.
- **Diagrams carry systems, actors, and the transitions between them — nothing else.** A node is a
  system or an actor; an edge is a transition with a short label (a few words, or an integration ID).
  Protocol, frequency, volume, retry behaviour and constraints live in the Connectors table, never on
  the diagram and never repeated in the section prose. One fact, one place.
  - Do not draw the same relationship twice across diagrams. The context diagram shows external
    systems and actors; runtime diagrams show a specific flow's sequence. If a runtime diagram would
    restate the context diagram, it is not worth drawing.
  - Draw only the flows the intake identified as important or risky (Q28). Never one per integration
    by default.
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

### Universal diagram color scheme (readability-first — every fill/text pair must be legible in print)

Reuse these `classDef` styles so all diagrams read as one system. **Each style below pins BOTH a fill
and a contrasting text `color:`** — this is not optional. The rule of thumb: dark fill → `color:#fff`;
light fill → an explicit dark `color:` (never rely on the default, and never white text on a light or
mid-grey fill). These values are tuned to survive black-and-white-ish PDF printing.

| Element / class | `classDef` (copy verbatim) | Meaning |
|---|---|---|
| **user / actor** — `userStyle`,`authStyle` | `fill:#e1f5ff,stroke:#0366d6,stroke-width:2px,color:#0b2545` | user/actor (light fill, **dark navy text**) |
| **system (this project)** — `systemStyle` | `fill:#FDEBD0,stroke:#CA6F1E,stroke-width:3px,color:#7E3F0C` | the system under design (**soft amber, dark brown text** — a gentle highlight, not a loud orange block) |
| **communication / storefront edge** — `communicationStyle` | `fill:#FDEBD0,stroke:#CA6F1E,stroke-width:2px,color:#7E3F0C` | comm layer / storefront edge (same soft amber) |
| **backend / API / process** — `backendStyle`,`apiStyle`,`businessStyle`,`searchStyle`,`custom` | `fill:#2980B9,stroke:#1B4F72,stroke-width:2px,color:#fff` | backend services, APIs, processes (blue, white text) |
| **web app / external web** — `webStyle`,`webAppStyle`,`presentationStyle` | `fill:#27AE60,stroke:#1D8348,stroke-width:2px,color:#fff` | web apps, external web systems (green, white text) |
| **storage** — `storageStyle`,`persistenceStyle`,`core` | `fill:#9B59B6,stroke:#6C3483,stroke-width:2px,color:#fff` | DB, cache, search (purple, white text) |
| **external / infra** — `externalStyle`,`infraStyle` | `fill:#5D6D7E,stroke:#34495E,stroke-width:2px,color:#fff` | external systems, infrastructure (**dark slate, white text** — NOT the old light `#95A5A6` grey, which washes out white text) |

Deprecated/unreadable values to avoid (they were replaced for contrast): light grey `#95A5A6` fills,
and loud orange `#E67E22`/`#CA6F1E` fills with white text. Use `layout: elk` config header for larger
flowcharts as the examples do. When restyling a harvested diagram, remap its colors to this table.

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

**File:** `01-introduction-and-goals.md`. **Inputs:** intake tab groups A, B; migration from C.
**Depth:** the approval/status block, general overview, and release-phased functional scope — see
[architecture-depth.md](architecture-depth.md).

- **Status/approval header (recommended):** add a short metadata block at the top — Status (e.g.
  Draft / In Review / Approved), Author/Driver, Approver(s), key dates, document version.
  Fill what the interview gave; mark the rest `TBD`.
- **Requirements Overview:** turn the one-paragraph goal + in-scope capabilities into a short bulleted
  list of functional drivers, phrased for *this* project (not the template's generic B2B bullets).
  State the business model, the legacy system being re-platformed (if any), the go-live date, and
  whether it's MVP or 100% scope.
- **Functional scope table:** list scope items with a **Release Phase** column (Phase 1 / Phase 2),
  each described in project terms.
- **Migration Requirements:** if migrating, name the source system, data entities & volumes, what's
  replaced, and the timeline. If not migrating, say "No migration — greenfield build" (don't delete
  the subsection, just state it).
- **Quality Goals:** fill the priority table with the user's ranked 3–5 goals and a concrete scenario
  per goal (these pair with section 10's measurable scenarios — keep them consistent).
- **Stakeholders:** the real roles + expectations from tab group B.

## § 02 — Constraints

**File:** `02-constraints.md`. **Inputs:** intake tab group D; conventions from CLAUDE.md.

Fill the three tables (Technical / Organizational / Conventions) with real constraints and their
*motivation* (the "why" column is the point — a constraint without a reason is noise). Hosting model,
compliance, team size, release cadence, PHPStan level, branching model. Pull Spryker/PHP conventions
from the project's own `CLAUDE.md`/`.claude/rules` where relevant.

## § 03 — System Scope and Context  ⟶ C1

**File:** `03-system-scope-and-context.md`. **Inputs:** intake B, C; `research-docs.md` (actor names,
integration patterns). Integrations come from the intake (Q7 systems, Q20 connections).

- **Business Context:** the system and every communication partner (actors + external systems), with
  the business reason for each. Point to the C1 diagram.
- **Technical Context — External Systems table:** each external system, its responsibility, the
  technology, and stakeholders. Use the *real* products named in the interview.
- **Integration Details table:** direction, protocol, sync/async, frequency, constraints — straight
  from tab group C, corrected against docs research (e.g. confirm the real Spryker integration
  pattern for that system type). Tag each with its **Release Phase**.
- **Components + Connectors rationale tables (required):** beside the diagram, always include the
  two tables from [architecture-depth.md](architecture-depth.md) — **Components** (system / description /
  stakeholders) and **Connectors** (interaction / description / type / frequency-volume-constraints).
  Nothing important should live only inside the picture.
- **C1 diagram** (`diagrams/c4/c1-system-context.mmd`): the platform as one box, all actors and
  external systems around it with labeled edges. Follow the example's style and color classes exactly.
  For an integration-heavy project, also produce an integration overview diagram under
  `diagrams/integration/`.

## § 04 — Solution Design candidates (never authored here)

**Dir:** `04-solution-designs/`.

**This skill identifies Solution Designs; it does not write them.** Authoring is a separate exercise
with its own skill, run later against the finished architecture record.

Produce a single `README.md` in the folder listing the candidates. Each is **a title plus two or
three sentences** — what needs designing, and why the architecture record cannot settle it:

> **SD candidate: Outbound supplier punch-out**
> The supplier catalogue round-trip is the only integration where a shopper waits on a third party,
> and the failure behaviour is unresolved. Needs a design before the integration is estimated.

Rules:

- **No problem statements, no proposed solutions, no diagrams, no data models, no implementation
  plans, no trade-off tables.** A candidate that runs past three sentences is over-written.
- **Never create `sd-XXX-*.md` files.** The folder holds the candidate list and the template only.
- Anything answered `Too complex to decide now — needs investigation` in the intake becomes a
  candidate automatically.
- Leave the shipped `sd-000-template.md` untouched, for the later authoring step.

## § 05 — Building Block View  ⟶ C2

**File:** `05-building-block-view.md`. **Inputs:** intake (Q11 out-of-scope, Q25 capability
classification); `research-docs.md`.

- **C4 Level 2 (Container):** the main Spryker containers in scope (Yves, Zed, Glue, DB, Redis,
  Elasticsearch/OpenSearch, RabbitMQ, plus any project-specific service) and their responsibilities.
  Pair with `diagrams/c4/c2-*.mmd`.
- **List the custom domains** the intake identified as custom development, one row each with a
  one-line responsibility. No effort estimates, no module decomposition.

**C4 Level 3 (Component) is out of scope.** Component-level decomposition belongs to an individual
Solution Design, not to the architecture record. Never create `c3-*.mmd`.

## § 06 — Runtime View  ⟶ sequences

**File:** `06-runtime-view.md`. **Inputs:** intake F (key scenarios); `research-docs.md` (how the flow
actually works in Spryker).

Document the key runtime scenarios the user picked (checkout/payment, Publish & Sync, a headline
integration flow, order fulfillment). For each: a short prose "Key Steps" list + a sequence diagram
under `diagrams/sequence/`. Confirm the real step order against docs research — Publish & Sync,
checkout, and OMS flows have canonical shapes; don't paraphrase from memory. Reuse the existing
`api-payment.mmd` / `publish-sync.mmd` examples as style templates.

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

**File:** `08-crosscutting-concepts.md`. **Inputs:** intake F (cross-cutting); `research-docs.md`.

Document the concepts that span components: auth/authz approach (incl. Spryker permission model if
relevant), logging/observability, error handling, i18n & multi-store, caching (Redis + Publish &
Sync), security patterns. Prefer real implementation examples over generic prose; small diagrams
welcome. Only include concepts that actually apply to this project.

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

**File:** `11-risks-and-technical-debt.md`. **Inputs:** intake C (integration risks), D (constraints);
decision log OPEN QUESTIONS. **Depth:** mitigations cite real Spryker features — see the
mitigation patterns in [architecture-depth.md](architecture-depth.md).

Fill the Risks table (risk / impact / mitigation) and Technical Debt table (item / impact / plan) with
project-specific entries. Integration uncertainties, external-system delivery risks, migration risks,
high-volume import / Publish & Sync performance, large category trees, and any constraint the
intake flagged as inherited from existing systems (Q27) are prime candidates. **Where a mitigation relies on a Spryker capability, name
it and link its docs page** (Persistence ACL, Merchant Relationship, Merchant Product Restrictions,
Comments module, BO user roles, Data Import & P&S optimization guidelines, Dynamic Multi-Store, Data
Exchange API) — confirm it exists via docs research first. The run's own OPEN QUESTIONS from
`decisions.md` often map straight to rows here.

**Decision gates, not standing debt:** when the project has a hard deadline, triage the risk rows —
any *launch-blocking* unknown (data-isolation mechanism, migration cut-over/password fallback, a
provider-maturity question) gets a **pre-Go-Live decision gate**: the decision, the spike that
answers it, an owner, and a resolve-by date tied to the roadmap. Related ADRs stay `Proposed` until
their gate closes. Everything else may remain an owner-named TODO. See
[architecture-depth.md](architecture-depth.md) ("build-ready", item 3, and the gap-triage rule).

## § 12 — Glossary

**File:** `12-glossary.md`. **Inputs:** every other section's terms; `research-docs.md` (correct
Spryker definitions).

Define every domain, technical, and Spryker-specific term used across the chosen sections. Keep the
three-table split (Business / Technical / Spryker-Specific). This section is written/updated **last or
during the cross-link pass**, because it must cover exactly the terms the other sections introduced —
the orchestrator can own it at Step 5 if no dedicated writer did (in a multi-deliverable run, the
deliverable's own writer owns it as part of its cross-link pass).
