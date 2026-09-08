# Depth bar — lean by default, deeper only on request

This document is **context that other work depends on**: feature development, reviews, and later
architecture changes all read it. That makes leanness a feature, not a compromise — every sentence a
reader has to skip is a cost paid by everyone downstream, forever. Aim for **the shortest document
that is still useful**.

Two failure modes, and the second is the common one:

1. A thin template fill — headings kept, cells generic, nothing project-specific.
2. **Volume as a proxy for quality** — pages of derivation, plans, and elaboration nobody asked for,
   burying the handful of facts that matter.

The bar below is **what a section may contain when the answers justify it** — not a checklist every
section must satisfy.

## The rule: answers in, content out

**Write what the intake supports. Nothing more.**

- An answer given → document it, concisely.
- An answer missing → **one line naming the gap and its owner.** Never a derivation, never a plan to
  work around it, never an "interim assumption" section.
- Something not asked about at all → **not in the document.**

A missing value is a gap to name, not a prompt to generate. If the volume table has no concurrency
figure, the document says concurrency is unknown and who owns it — it does not derive one, and it
does not size a load test around the derivation.

## Where depth lands, when the answers support it

| Concern | Lands in | What good looks like |
|---|---|---|
| Status header | §01 Introduction and Goals | Status, author, date, version — a few lines |
| Business overview | §01 | Model, objectives, go-live, scope. From the answers, not elaborated |
| Functional scope | §01 + §03 System Scope and Context | Scope table with a Release Phase column |
| Volumes | §10 Quality Requirements | The figures given, per phase. Blanks listed as gaps — see below |
| Integrations | §03 + §06 Runtime View | System, function, phase; the Connectors table |
| Context diagram | §03 | Plus the Components and Connectors tables |
| Risks | §11 Risks and Technical Debt | Risk, impact, mitigation naming a real Spryker capability |
| Infrastructure | §07 Deployment View | Region/store/DB strategy — only when the target setup is confirmed |

## The two rationale tables

Pair these with the context diagram so nothing important lives only inside a picture. The diagram
shows **systems, actors, and the transitions between them**; these tables carry the detail.

**Components** — one row per system on the diagram:

| System (type, name) | Description (what it provides / accepts) | Stakeholders |
|---|---|---|

**Connectors** — one row per interaction/edge:

| Interaction | Description | Type | Frequency / volume / constraints |
|---|---|---|---|

Detail belongs in exactly one of these two tables — never repeated in the diagram, the section prose,
and the table. Fill the constraints cell where the intake gave a number; write `TBD` where it did not.

## Volumes (§10)

Record the figures the intake supplied. For everything blank, write a **single grouped list of gaps**
— one line each: what is missing, why it matters, who owns it. Group by category (catalogue, load,
customers, expansion, migration).

Do not derive missing figures. Do not size tests, capacity, or infrastructure against numbers that
were not given.

## Mitigations name real Spryker capabilities (§11)

What separates a strong Spryker architecture document from a generic one: each risk's mitigation
names a **real Spryker feature**, confirmed to exist via docs research, with its docs page linked.

Common patterns: store data isolation for Back Office users → Persistence ACL. Dealer/branch
hierarchy and merchant-specific pricing → Merchant Relationship, Merchant Product Restrictions,
custom Back Office roles. Order comments → the Comments module. High-volume import → Data Import
optimisation and Publish & Sync guidelines. Many stores under one domain → Dynamic Multi-Store.
External DB access for BI → Data Exchange API.

Verify availability status before citing — never assert it from memory.

## Release phasing

Tag scope items and integrations with their phase, using the phase names the intake supplied. Carry
those names into the volume table columns so the document tells one consistent story.

## Honest status over false completeness

A gap naming the open question and its owner is a **first-class professional outcome** — better than
a fabricated number or an invented integration. Strong architecture documents are full of named
gaps; that is what makes them trustworthy.

Two honest forms, and they are not interchangeable:

- **Open unknown** → one line: the question, the owner.
- **Launch-blocking unknown** → the same one line, plus a resolve-by date.

Neither form is an invitation to fill the gap with generated content.

## Candidates for deeper treatment

A lean section will leave real depth on the table. That is the intent — but say so rather than
silently dropping it.

Every section writer returns **its top 1–3 candidates** for deeper treatment, as a list, alongside
its section. Each candidate is one line:

> **What would be deepened** — why it matters architecturally, in a clause.

Rank them: 1 = would most change a build decision. Write **none of this content** — the orchestrator
offers the candidates to the user after the document is complete, and only what the user picks gets
written.

Good candidates name a decision the document currently leaves implicit — a scaling jump the volumes
imply, an integration whose failure mode is unresolved, a data-ownership question. Poor candidates
are generic ("more detail on security") or restate a known gap.
