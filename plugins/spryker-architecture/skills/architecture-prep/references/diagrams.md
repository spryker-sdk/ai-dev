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
3. **Every box carries name, type and description — in that order, on three lines.**

   ```
   Name
   [Type: Technology]
   Short description
   ```

   | Element | Label |
   |---|---|
   | Actor | `Business Buyer` · `[Person]` · `Orders supplies for their company` |
   | System under design | `Acme Marketplace` · `[Software System]` · `B2B marketplace for four brands` |
   | External system | `Epicor M2K` · `[Software System: ERP]` · `Masters orders, stock and pricing` |
   | Container | `Storefront` · `[Container: Yves]` · `Customer-facing shop` |

   The type is mandatory on every element, and the qualifier after the colon is what tells a reader
   *what kind* of system it is — ERP, PIM, Payment Provider, Tax Provider, CRM. The product name
   stays in the name slot; never fold the category into it.

   Technology is mandatory on containers, optional on systems. Descriptions are one short line —
   responsibilities at a glance, never a specification.

   **No integration IDs in labels.** Identifiers belong on edges and in the Connectors table.

   In Mermaid, use `<br/>` between the three lines.
4. **Every relationship is labelled**, consistent with the direction and intent of the
   relationship — what flows, or what one element does to the other (`Places order`, `Imports stock`).
   Relationships between containers also carry the technology or protocol: `[CSV over SFTP, daily]`,
   `[REST/JSON]`. Never leave an edge unlabelled.
5. **Level discipline.**
   - **C1 (context):** the system under design is **exactly one box**. Every external system is
     **exactly one box**, named as the business names it — even when it internally comprises several
     subsystems. Actors are boxes. Nothing internal appears.
   - **C2 (container):** the system under design opens up into its containers. External systems stay
     as they were on C1 — same names, same granularity — unless the intake genuinely requires one to
     be opened up, in which case its subsystems appear **inside a box carrying its C1 name**.
   - **C3 (component) is out of scope.** Component decomposition belongs to a Solution Design.
6. **The C2 container diagram always shows the full Spryker application set**, whether or not this
   project uses every part. A reader must see the platform as it is, not only the slice in scope.
   Mark anything out of scope for this project — dimmed, or a `(not in scope)` note on the
   description line — but never omit it.

   | Container | Technology | Responsibility |
   |---|---|---|
   | Storefront (Yves) | Twig + PHP | Customer-facing shop |
   | Back Office | Twig + PHP | Platform administration |
   | Merchant Portal | Twig + PHP | Merchant product and order management |
   | Storefront API | PHP | REST API for storefront operations |
   | Backend API | PHP | System-to-system integration surface |
   | Backend Gateway | PHP | Routes Storefront and Storefront API requests to backend services |
   | Scheduler | Jenkins + PHP | Runs jobs: imports, and the publish step that populates cache and search |
   | Database | MySQL / MariaDB | Persistent data, system of record |
   | Key-value store | Redis | Sessions and storefront read model |
   | Search | OpenSearch | Catalogue search index |
   | Message broker | RabbitMQ | Asynchronous event queues |

   **Data-flow facts that are frequently drawn wrong:** the Scheduler — not the broker — writes the
   read model into the key-value store and the search index; the Backend Gateway sits between the
   Storefront / Storefront API and the backend; the broker carries queued events and is consumed by
   the Scheduler's workers. Never draw the broker writing directly to search or cache.

   The shipped `architecture/diagrams/c4/c2-spryker-container.mmd` template is the reference for this
   set — read it before drawing, and keep its container names.
7. **Names are identical across every diagram and every section.** A system named on C1 keeps that
   exact name on C2, in the Components table, and in prose. No synonyms, no re-categorising.
8. **Icons: emoji only — never Font Awesome.** Prefix the name line with an emoji: 👤 actor,
   🛒 the system under design, 🏭 ERP, 💳 payment, 🧮 tax, 📦 storage, 🗄️ database, 🔍 search,
   📨 queue, ✉️ mail, 📊 analytics, 🛡️ security/consent. `fa:` icons render in some Mermaid
   viewers and silently fail in others — emoji render everywhere, including IDE previews and PDF.

## What a diagram carries

Nodes are systems, components and actors, labelled per rule 3. Edges are labelled relationships per
rule 4.

Frequency, volume, retry behaviour, field mappings and error handling live in the **Connectors
table**, never on the diagram and never repeated in the section prose. One fact, one place. The
exception is rule 4's protocol on container-level edges, which C4 requires on the diagram itself.

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

## Colour scheme — flowchart-type diagrams only

**`classDef` is invalid inside `sequenceDiagram` and breaks rendering silently.** Apply the palette
below to `flowchart` and `graph` diagrams only; leave sequence diagrams unstyled.

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
