# Diagrams — C4 notation, strictly

**Read by every writer that produces a diagram.** C4 is a notation with rules; a diagram that breaks
them misinforms the reader even when every fact on it is true.

## One agent draws C1 and C2 — never two

The context and container diagrams must name the same things the same way. Parallel writers drift:
the same system ends up as `Stripe` on one diagram and `Payment providers` on the other. **A single
writer produces both**, and the §03 System Scope and Context and §05 Building Block View sections
reuse its names verbatim.

## Hard rules — a violation is a defect, not a style preference

1. **One box per system or component.** Never split a system across several boxes because it has
   several integrations, responsibilities, or interfaces. Duplicate a box only when it genuinely
   improves readability, and only within one diagram.
2. **A box is a system or a component. Nothing else.** Never a protocol, a mechanism, a state, or the
   absence of one. `SSO`, `No SSO`, `REST API`, `Nightly batch` are edges or labels — never nodes.
3. **No integration IDs in node labels.** Identifiers belong on edges and in the Connectors table.
   A node carries a name and, optionally, its technology.
4. **Level discipline.**
   - **C1 (context):** the system under design is **exactly one box**. Every external system is
     **exactly one box**, named as the business names it — even when it internally comprises several
     subsystems. Actors are boxes. Nothing internal appears.
   - **C2 (container):** the system under design opens up into its containers. External systems stay
     as they were on C1 — same names, same granularity — unless the intake genuinely requires one to
     be opened up, in which case its subsystems appear **inside a box carrying its C1 name**.
   - **C3 (component) is out of scope.** Component decomposition belongs to a Solution Design.
5. **Names are identical across every diagram and every section.** A system named on C1 keeps that
   exact name on C2, in the Components table, and in prose. No synonyms, no re-categorising.
6. **Icons on every actor and system box** — Mermaid renders Font Awesome, e.g.
   `fa:fa-user` for actors, `fa:fa-server` / `fa:fa-database` for systems. They make the actor/system
   distinction readable at a glance.

## What a diagram carries

Nodes are systems, components and actors. Edges are relationships with a short label — a few words
saying what flows, or the integration ID.

Protocol, frequency, volume, retry behaviour, field mappings and constraints live in the
**Connectors table**, never on the diagram and never repeated in the section prose. One fact, one
place.

Do not draw the same relationship twice across diagrams. If a diagram would restate what another
already shows, it is not worth drawing.

## Sequence diagrams — system level only

Sequence diagrams at this stage convey **which systems participate in a flow and in what order**.
They are not designs.

- Participants are **systems**, not internal components, classes or modules.
- Never specify which component calls which, message payloads, persistence steps, or error-handling
  branches. That detail requires design work this exercise does not do — register a Solution Design
  candidate instead.
- Draw only the flows the intake identified as important or risky. Never one per integration by
  default.

## Format

**Mermaid (`.mmd`) only**, for every diagram type including ERDs — PlantUML cannot render
client-side, so it is invisible in the browser preview, the exported PDF, and GitHub's renderer.
Model ERDs with `erDiagram` (`PK`/`FK` markers, crow's-foot cardinality).

Store under `architecture/diagrams/<category>/` and reference from the section with a relative link,
naming what the diagram shows rather than just its level.

**Harvest before you author.** If the source material already embeds a diagram, start from it and
restyle it to the convention below; transcribe PlantUML to Mermaid rather than keeping `.puml`. Do
not redraw from scratch and drift from what the architect already approved.

Two syntax traps that silently break rendering: never put `;` in note or label text (it is a
statement separator — use `,`), and keep `%%` comments **after** the `---config---` frontmatter.

## Universal colour scheme

Reuse these `classDef` styles so all diagrams read as one system. Each pins **both** a fill and a
contrasting text colour: dark fill → `color:#fff`; light fill → an explicit dark `color:`. These
values survive black-and-white printing.

| Element / class | `classDef` (copy verbatim) | Meaning |
|---|---|---|
| **user / actor** — `userStyle` | `fill:#e1f5ff,stroke:#0366d6,stroke-width:2px,color:#0b2545` | user or actor |
| **system under design** — `systemStyle` | `fill:#FDEBD0,stroke:#CA6F1E,stroke-width:3px,color:#7E3F0C` | the system being designed |
| **external system** — `externalStyle` | `fill:#E67E22,stroke:#AF601A,stroke-width:2px,color:#fff` | third-party or partner system |
| **application / container** — `webStyle` | `fill:#27AE60,stroke:#1D8348,stroke-width:2px,color:#fff` | app, service, API surface |
| **backend / logic** — `backendStyle` | `fill:#2980B9,stroke:#1B4F72,stroke-width:2px,color:#fff` | backend, worker, business logic |
| **storage** — `storageStyle` | `fill:#9B59B6,stroke:#6C3483,stroke-width:2px,color:#fff` | database, cache, search, queue |
