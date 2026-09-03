# Solution design — architect brief and template (Step 3a)

The brief handed to the architect subagent, and the document it produces: a **solution design** — an
architecture document, at home in the project's `architecture/` folder. The architect is a fresh
`general-purpose` subagent — **never a fork, never the context that will implement** — because
implementation planning done by the implementing role gets written to fit the code that is already
forming, and an author can't see its own gaps.

## Where the document lands — sd-000, not a new format

If the project has an `architecture/` folder (arc42 scaffold), the document **is** a Solution
Design there: copy `architecture/04-solution-designs/sd-000-template.md` to
`architecture/04-solution-designs/sd-XXX-<feature-slug>.md` and fill it — `XXX` = the highest
existing sd number + 1, and **add the new document to `04-solution-designs/README.md`'s SD list**
(the folder's own housekeeping rule; an unlisted SD is invisible to the previews and to
`architecture-prep`). The sd-000 sections already
cover most of what's needed — Problem Statement, Goals & Requirements (Functional / Non-Functional /
Constraints), Proposed Solution (Architecture, Key Components, Integration Points, Data Model),
Implementation Plan (Phases, Dependencies, Estimated Effort), Trade-offs, Risks, Alternatives
Considered, Open Questions. **Fill those sections; don't restate them under new headings.**

No `architecture/` folder → write the same content as `$BUILD_DIR/solution-design.md`, using the
section list below as the outline. (Offering to create the folder via `architecture-prep` is the
orchestrator's Step 0d call, not the architect's.)

## Inputs the architect must be handed (and must actually read)

1. The PRD + its `.refs.md` code crosswalk.
2. The **Step 0d scale envelope** (volumes + NFR numbers, with per-row sources).
3. The Step 3 **Namespace-resolution block and `Convention:` line** — the plan's file list must sit under the resolved namespace target, and its class list is checked against the convention verdict.
4. The feature-expert findings from Step 3.
5. Project `CLAUDE.md` and, when present, `architecture/02-constraints.md`,
   `architecture/05-building-block-view.md`, and existing ADRs in `architecture/09-architecture-decisions/`.
6. **This project's actual wiring**: `ApplicationServices.php` and the DependencyProviders the feature
   will touch — read, never assumed. (A SEV1 whole-project outage once lived one read away in that file.)

## What the plan must contain

The sd-000 sections, plus four Spryker-specific sections sd-000 doesn't carry:

### 1. Growth characteristics (extends §Data Model)

Every new table, index field, and KV entry states its row/size formula against the scale envelope:

```
dmx_<entity>:                rows ≈ <formula>            → <n> at go-live envelope
search doc field <name>:     size ≈ <formula> per doc    → index growth <n>
```

A formula that **multiplies two envelope dimensions** (`products × business units`,
`customers × categories`, …) is a rejection candidate: justify it explicitly against the NFR budget
or redesign. This single check is what disqualifies a demo-scale design before any file exists.

### 2. Publish & Synchronize design (assess → choose → agree)

Only for features touching search or key-value storage. Three steps, in order:

1. **Needed-ness.** P&S serves denormalized read models where **windows of inconsistency are
   acceptable**. Test: *is this data read from Yves or Glue, and can the reader tolerate staleness?*
   Product data → yes. Credentials, authorization/permission state, anything a security decision
   reads → **no** — synchronous read path (facade/DB); putting it in a read model would be the defect.
   Name the data, the reader (Yves/Glue/BO), and the tolerable staleness window (a PRD "within N
   seconds" AC is that number).
2. **Mechanism — exactly one.** Event-behavior-plus-subscriber (canonical: schema `<behavior
   name="event">` + a registered subscriber + queue) or a deliberate direct publish. State the
   consequence for **every write path**: import, Back Office, API, cascade delete. "Both paths
   half-present" (behavior declared, no subscriber, synchronous publish in an import hook) is a
   forbidden end state — it shipped once and drifted the index silently on every non-import write.
3. **Transaction boundary.** For every write sequence touching more than one store (DB + index,
   DB + KV, delete-then-publish): name the boundary, or state explicitly *non-atomic + the recovery
   path* ("re-run the import to converge" is acceptable; not having considered it is not).

This section is **agreed with the developer at the plan gate** — an explicit confirm item, never a
silent decision.

### 3. Trust-boundary statement

Only for features carrying identity/authorization or per-actor data isolation. Name **every entry
point** — Yves controller, API Platform, Glue, suggestion/count paths, core re-entrant calls — and
where the boundary is enforced for each. Identity never travels through client-input channels
(`$requestParameters`, query string, form payload); it is derived server-side in the layer that
consumes it. A single sanitizing controller is the named anti-pattern: re-entrant and API paths
bypass it.

### 4. Short implementation plan (fills sd-000 §Implementation Plan — the build executes FROM this)

A **short, ordered task list** the builder works through **from the document, task by task** — so the
build re-reads one task before starting it instead of holding the whole design in context. It opens
with a two-line header that settles modules and names, then the tasks:

```
Modules: <one module, or the written justification for a split — "how core would package it" is invalid on a project>
Names:   <PRD term> = <module> = <DB column> = <index/KV field>   (one line per concept — a reader of any spelling must be able to guess the others)

T1. <outcome in one clause>
    Files: <the files this task creates/edits>
    Verify: <the one check that proves this task done — a grep, a route hit, a console command>
T2. ...
```

Naming constraints for the header: **name after platform entities** (`CompanyBusinessUnit`, never a
truncation of it) and **domain word over mechanism word** (the concept the PRD/user uses — "customer's
own SKU" — not the implementation trick — "alias").

Task rules: ordered by dependency (schema/transfer → codegen-consuming code → wiring → UI);
every file in the file-count estimate appears in exactly one task; a task with no verify line is not
a task. Step 4 executes these in order and logs `run.log` boundaries per task — deviating from the
list mid-build is the same logged, re-surfaced decision as deviating from the design itself.

### Also required (sd-000 sections, filled honestly)

- **Rejected alternatives, with reasons** (§Alternatives Considered) — the seams considered and why
  the chosen one wins.
- **Honest file-count estimate + risk list** (§Estimated Effort, §Risks). This estimate feeds Step
  4's scope tripwire (>50% over → stop and re-plan), so it must be a real count, not a hope.
- **Convention line** — carry the orchestrator's Code-convention resolution verdict into the plan so
  the class list is checkable against it.

## The architect's authority

The brief includes, verbatim: *"You may reject this approach outright on scale or code-volume
grounds and demand a re-cut. Your verdict goes to the user at the plan gate — never soften a
rejection into a caveat."* A plan whose growth characteristics bust the envelope, or whose file
count is disproportionate to the feature, is returned as REJECTED with the reason and a sketched
alternative — not polished and passed along.
