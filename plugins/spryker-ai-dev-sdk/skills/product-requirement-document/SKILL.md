---
name: product-requirement-document
description: Use when creating PRDs for Spryker features, before implementation planning, or when a user requests a spec/requirements document that another AI agent will turn into tasks. This is the START of the Spryker feature-building chain — "I want to build a feature" begins here, then flows to spryker-customization (which drives build, verification, and commit). Drives an interactive, research-grounded PRD — first investigates the real codebase and official Spryker docs, strictly assigns a real Spryker actor to every story, names the affected endpoint + path, and keeps the document business-focused (no implementation, no quality gates, no task breakdown).
---

# Product Requirement Document (PRD) Creation

## Overview

A PRD defines WHAT to build and WHY — never HOW, HOW LONG, or which commands to run. It is business-facing and AI-parseable so another agent can later extract tasks and a plan from it.

**Core principle:** Clear, reality-grounded requirements enable autonomous task generation. Anchor every requirement in real Spryker behavior, then structure it for machines first, humans second.

**This is an INTERACTIVE, ITERATIVE process. Do NOT write a complete PRD in one pass.** Gather requirements through questions, present sections at checkpoints, and get approval before each next phase. **Checkpoint pacing is the user's choice:** at the start of Phase 3, ask once how they want to review — per criterion, per story (story + its criteria as one batch), or draft-then-review (full draft, then one revision pass) — and honour that answer for the rest of the run. Default to per-story when they have no preference. Never impose the slowest mode: ~28 approval round-trips for a 7-story PRD is how users abandon the skill mid-run. Whatever the pacing, corrections stay possible at every checkpoint that does occur.

## When to Use

- User requests a PRD, spec, or requirements document for a Spryker feature
- Planning a new feature before implementation
- Aligning on scope and goals

Do NOT use for: implementation plans, technical design docs, API reference, project timelines, or quick informal requirement gathering.

## What this PRD deliberately EXCLUDES

This skill produces a **business requirements** document. Keep technical/implementation material OUT:

- ❌ **No "Quality Gates"** — no `phpstan` / `phpcs` / `codecept` commands. Testing strategy belongs to planning, not the PRD.
- ❌ **No "Tasks" / implementation breakdown** — that is the planner's job.
- ❌ **No architecture/code design** in user stories.
- ❌ **No code references anywhere in the PRD body** — no fully-qualified class names, no `Class::method()`, no controller/action/plugin/facade/transfer/repository class names, no file paths. Refer to capabilities by **feature/module name** (e.g. "AiCommerce module", "Content module") and to settings by **configuration name** (e.g. "the `ai_commerce:smart_cms:general:is_enabled` configuration", "the Smart CMS feature flag") — names, not code symbols. The endpoint line carries only the **URL path** (see Phase 5).

If you catch yourself adding any of the above, stop and remove it.

## Terseness is the default

Default to **narrow and sharp**; expand only where the user asks for detail. Cut anything whose removal loses no story, no distinct behaviour, and no quantified target:

- **One behaviour, one scenario.** Merge scenarios that differ only in input (past-date and beyond-max-horizon are one range check; filter and search are one query check) — distinct behaviours stay distinct, variations of one behaviour don't.
- **Never write an `And` that restates the `Then`** in different words. Every `And` adds a new observable fact or it goes.
- **Constraints, Decisions & Accepted Risks, Dependencies, and Out of Scope are one line per item.** No paragraph-form entries in list sections.
- **Expand only when the user asks** — detail on request, not by default.

**Where the code references go instead — the `.refs.md` attachment.** The FQCNs, transfer field names, controller/action/plugin names, config keys, and file paths you discover during Phase 0 are valuable for the planner — do **not** discard them. Capture them in a **sibling attachment file** `<feature-name>.refs.md` saved next to the `.prd.md` (see [refs-attachment.md](refs-attachment.md)), and link it from the PRD's research header. The PRD body stays business-readable and code-free; the attachment is the implementation crosswalk. Tell the user, when saving, that you saved the discovered code references as this attachment.

## Core Workflow

### Phase 0: Research FIRST — ground the PRD in real Spryker 🔬 REQUIRED

**Before any requirement, story, or design, investigate.** Requirements written from memory drift from how Spryker actually works. Do this in three layers — **code first, then docs, then (for existing flows) the running app**: the codebase facts are cheap inline calls that settle most questions outright and tell you exactly what the expensive docs research should target. **Two of the three layers are opt-in — ASK the user before running them** (steps 2 and 3 below). Step 1 (codebase facts) is always done.

1. **Codebase facts — inline, first, using the Spryker tooling MCP server.** Establish against the real install what exists and pin down exact names — this is where the decisive findings usually come from. Match tools by **tool name** (server names vary by install; some clients namespace as `mcp__<server>__<tool>`):
   - `getSprykerModules` — confirm the module exists and get its exact name.
   - `getSprykerModuleMap` — the module's API surface: find the **controller + action** behind the feature, the **plugins / extension points** it hooks into, and whether the capability already ships. (For Yves, routes come from `RouteProviderPlugin`s; for Glue, the resource name from a `ResourceRoutePlugin`.)
   - `getTransferStructureByName` — real field names/types for any transfer a story touches. Never invent transfer fields.
   **Take vendor paths from the module-map output — never guess `Business/...` sub-paths** (a guessed path returns zero hits and wastes the call; the correct paths are already in the map).
   **If the Spryker tooling MCP server is not connected / not running** (e.g. `getSprykerModules` is unavailable or a `/mcp` reconnect failed): try to **start the underlying server first** — it backs the local app, so `script -q /dev/null docker/sdk run` (or `up`) brings it up — then **tell the user to reload the MCP connection** (e.g. run `/mcp` to reconnect) and wait for them to confirm it is back before proceeding. Do **not** guess the names the tools would have returned; fall back to reading the codebase directly (grep/Read) and say so explicitly. Only after the reconnect fails twice should you continue from codebase facts alone.
   **Project-overlap sweep — has this project already solved (part of) this?** Before writing any story: grep the **project namespace** for the feature's nouns, and read the **registered plugin stacks in the touched modules' project DependencyProviders**. Core research tells you what *Spryker* ships; this sweep tells you what *this project* already built — per-item fields that already persist and render, metadata plugins already registered on the exact form the feature targets. Two greps, and it is the difference between "add a field" and "reconcile with what's already there" (unreconciled, a real PRD would have shipped a form with two differently-scoped dates and two notes). Anything overlapping becomes a story input and a `.refs.md` entry.
   From these facts, **form a hypothesis** about the feature's shape (which mechanism, which precedent feature, which fields) — the docs research below then confirms or refutes it instead of sweeping blind.

2. **Public documentation — ASK FIRST, then run `spryker-docs-research`, targeted by step 1.** Ask the user **one research-depth question that folds the dispatch consent in** (so consent never costs a separate question slot): `AskUserQuestion` with options *(a) docs research via subagents (recommended — isolated, parallel)*, *(b) docs research inline (when this harness forbids unrequested subagents)*, *(c) skip docs research*. Some features are project-specific or already settled by the codebase facts and need no doc research. **On (a)**, dispatch the research to **one or more subagents via the Agent tool** — each subagent's prompt instructs it to invoke the `spryker-docs-research` skill and states the specific hypothesis/concepts from step 1 to confirm. **On (b)**, run the `spryker-docs-research` skill inline and say so. Keeping docs research in a subagent isolates its large doc-fetch output from the PRD-drafting context and returns only the distilled brief. **Fan out when the feature spans multiple concepts/PBCs** — launch parallel subagents (one per concept) in a single message, **giving each an explicit non-overlap boundary** ("agent B covers Product Lists — don't"); use a single subagent for a narrow, single-concept feature. Each subagent returns the **relevant documentation content + source links** plus a short brief: official feature/PBC name, supported actors, documented behavior/constraints, and any documented endpoints. Collect and merge the briefs. If a subagent reports a missing MCP tool, relay that to the user and suggest enabling it. (It is **docs-only** — it does not read the codebase, and it is **not trusted for identifier spellings** — real field/route names come from step 1.)
   The subagents/inline choice above **is** the harness fallback — consent to dispatch is given by picking (a), and a harness that forbids unrequested subagents is served by (b) at zero extra question cost. A followable inline run beats an unfollowable rule.

3. **Validate existing flows when relevant — ASK FIRST, then invoke the `spryker-runtime` skill.** If the feature modifies or extends behavior that already exists, **ask the user whether you should validate the existing endpoint(s)/flow against the running app** (use `AskUserQuestion`: "This extends an existing flow (<name> → <endpoint>). Should I run the app and validate how it behaves today?"). Running the app is slow and not always wanted. **Only if the user says yes**, invoke the `spryker-runtime` skill to confirm how it behaves today (run a console command, call the endpoint, or log in and drive the UI) — and if the app is not running, start it with `script -q /dev/null docker/sdk run`. Do this only for flows that already exist; skip it (and skip the question) for purely greenfield behavior that can't be run yet. Use what you observe to write accurate Given/When/Then and the correct endpoint path.

Keep all gathered findings at hand — documented behavior (if researched), real module/transfer names, and observed behavior (if validated) feed directly into the stories and endpoints below.

### Phase 1: Initial Requirements Gathering 🔄 INTERACTIVE
Use `AskUserQuestion` to gather: the problem/opportunity, relevant context/constraints, and preliminary measurable goals.

### Phase 2: Draft Background & Goals 🔄 INTERACTIVE
Write Background (WHY) and 3–5 measurable Goals. **CHECKPOINT:** present, get approval.

### Phase 3: Create User Stories 🔄 INTERACTIVE — at the chosen pacing
- **First, ask the pacing question (once):** per-criterion, per-story, or draft-then-review (see Overview). Honour the answer through Phases 3–4.
- **Assign the actor strictly** from the canonical Spryker actor set — see [actors.md](actors.md). Never write "As a user". Pick Back Office user, Customer, **Company user (Customer)** — the most common storefront actor on B2B projects — Agent, Merchant user, or Merchant Agent. For Back Office, you MAY name a narrower ACL role keeping the canonical actor mapped — e.g. `As a Back Office content manager (Back Office user)`. When unsure which roles exist on this install, confirm at `http://<backoffice-host>/user` (users) and `/acl` (roles) via `spryker-runtime` (resolve `<backoffice-host>` from the `backoffice` application in deploy.dev.yml).
- Format: `As a [canonical actor], I want [action], so that [benefit]`.
- **Resolve the affected endpoint now** (Phase 5 rules) so it can be confirmed together with the story — do not defer it to after approval.
- **Resolving a route proves where a story lands — not that the thing it decorates exists.** For any story whose criteria assert something is **displayed**, confirm the **rendering surface** too: read the view template (does the page render an item list / the block the story extends at all?) or hit the page. A criterion decorating a surface that doesn't exist is a different, larger story ("build the list, then add the field") — surface that scope to the user instead of letting an estimate absorb it. (Real case: `/checkout/success` resolved perfectly; the template renders order items only as invisible `<meta>` tags.)
- **CHECKPOINT — the story-approval question MUST present, and ask the user to confirm, all three together:**
  1. the **story text**,
  2. the **actor** (the exact canonical actor / role chosen, so the user can correct a wrong actor), and
  3. the **affected endpoint** — the **URL path** + module/feature name + existing/greenfield (or "No endpoint affected"). Show the path, not the controller/action class. (You still resolve the controller+action during research and record it in the `.refs.md` attachment — it just doesn't appear in the PRD body.)

  Example to show before the AskUserQuestion:
  ```markdown
  **Story:** As a Back Office content manager (Back Office user), I want to edit CMS block content, so that …
  **Actor:** Back Office content manager (Back Office user)
  **Affected endpoint:** `/cms-gui/glossary/edit` (existing; CmsGui module)
  ```
  Approve each story (with its actor and endpoint) at the chosen pacing — before its criteria at per-criterion/per-story pacing, or within the draft review otherwise. If the user corrects the actor or endpoint, revise and re-present before proceeding.

### Phase 4: Add Acceptance Criteria 🔄 INTERACTIVE — at the chosen pacing
- Typically 2–4 Gherkin scenarios per story — see [gherkin-guide.md](gherkin-guide.md). **The cap is advisory:** the story carrying the feature's primary behavior may legitimately need more (happy path, isolation, anonymous, no-regression, collision are distinct behaviors) — never merge distinct behaviors to fit the cap.
- **Reachability check for state-asserting criteria.** For any criterion that asserts a *state* rather than a *transition*, ask: *"in the system as it actually works, can this state be constructed at all?"* — and answer from the Phase 0 facts (or one targeted grep), not assumption. An unreachable criterion reads fine, costs a full verification run downstream, and invites "fixing" correct code to satisfy it. (Real case: "same SKU + same date merges into one line" — impossible by framework design; a random group-key prefix prevents merging regardless.)
- **CHECKPOINT:** per the pacing chosen at Phase 3 — each criterion, the story's criteria as a batch, or collected for the draft review.

### Phase 5: Record the Affected Endpoint(s) 🎯 REQUIRED per story
These are the rules for resolving the endpoint — apply them in Phase 3 so the endpoint is **confirmed at the story checkpoint** (alongside the actor), then written into the PRD here. Every story that touches a request/response surface MUST name the **endpoint affected**, derived from the Phase 0 codebase facts — not guessed. **What goes in the PRD body is the URL path only** (a routing identifier the reader can hit), never the controller/action class — the FQCN belongs in the `.refs.md` attachment.
- **Resolve the controller + action** from `getSprykerModuleMap` (e.g. the User module's index action) so you know the real route — but **write the FQCN into `<feature-name>.refs.md`, not the PRD**.
- **Path (this is what the PRD shows):** combine the route with the **host resolved from `deploy.dev.yml`** (`groups.<region>.applications.<app>.endpoints`) — hosts are configurable and may differ from the defaults. **If the user specified an endpoint to use** (e.g. a real cloud URL like `https://<env>.cloud.spryker.com`), use that host instead. Route conventions: **Zed/Back Office** → `/<module-name-dashed>/<controller>/<action>` (e.g. `/user`); **Yves** → `/<locale>/<route>` (route from a Yves route provider); **Glue** → `<glue-host>/<resource-name>` (Glue resource name).
- **Status:** `existing` or `greenfield` (and for greenfield, the closest existing neighbor endpoint — never fabricate a path).

Add it under each story (path + status + module name, no class):
```markdown
**Affected endpoint:** `/user` (existing; User module)
```
If a story has no endpoint (pure config/data), state "No endpoint affected" explicitly. Record the matching controller+action FQCN for each story in the `.refs.md` attachment.

### Phase 6: Non-functional Requirements 🔄 INTERACTIVE
Business-level quality attributes only: performance targets, reliability/availability, security/compliance, scalability. Quantify everything. **When the user has no numbers, propose the baseline volumes explicitly** (the `spryker-customization` skill ships them in `references/baseline-volumes.md`; a project `architecture/10-quality-requirements.md` volume table overrides them when present) **and record per number whether it is user-supplied or defaulted.** These NFRs are a *contract input* to the implementation skill — its design and verification are judged against them, so vague or missing numbers here surface later as unshippable designs. Do NOT list tooling commands or test suites here. **CHECKPOINT.**

### Phase 7: Constraints, Decisions, Success Metrics & Scope
- **Constraints:** discovered hard constraints that change what can be promised — business-phrased, mechanism-free (e.g. "customer-specific identifiers must physically reside in the shared search index — a confidentiality trade-off the customer must accept", "index text analysis cannot be changed in place after go-live"). These typically surface during Phase 0 research; they are neither implementation detail nor goals, and dropping them is how a PRD ships an unbuildable promise. If the project keeps an `architecture/02-constraints.md`, cross-check against it.
- **Decisions & accepted risks:** any user choice whose cost they may not have fully priced (e.g. "custom identifier wins exclusively on collision — a real catalog product becomes unfindable by its own SKU"). Restate the cost plainly, get explicit acceptance, record both.
- **Open decisions have their own shape.** A genuinely undecided item that blocks implementation is recorded at the **top** of that section as `**OPEN — blocks implementation:** <question> — <what it blocks>` — never dressed up as decided, never buried. Every OPEN item is also **surfaced in the hand-off message** when the PRD is saved, not only in the file: the planner must see the blockers without opening the document.
- Success Metrics: measurable KPIs with baseline → target and the measurement tool.
- Out of Scope: what is explicitly NOT built.
- Dependencies: internal/external prerequisites (if any).
Quick confirmation.

### Phase 8: Final Review & Save 🔄 INTERACTIVE
Run the **scenario lint pass** (see the checklist in [gherkin-guide.md](gherkin-guide.md) — one Given/When/Then spine, quantified inputs, no unfailable assertions, titles match bodies), then the Red-Flag check (below), present the complete PRD, get final approval, then save.

**For the detailed phase-by-phase script, see [workflow.md](workflow.md).**

## Critical Requirements

### 1. Research before requirements — code first
Codebase facts (step 1) are not optional and come **first** — module names, transfer fields, controller/actions, and endpoint paths trace to the inline codebase tools (`getSprykerModules`/`getSprykerModuleMap`/`getTransferStructureByName` + `deploy.dev.yml`), never guessed, and they target the docs research that follows. Docs research (step 2) and running-app validation (step 3) are **opt-in — ask the user first**. Docs research, when run, is dispatched to **`spryker-docs-research` subagent(s) via the Agent tool** (parallel subagents with explicit non-overlap boundaries when the feature spans multiple concepts) so documented behavior/actors trace to the merged subagent briefs — with the stated harness fallback (consent, else inline) when subagent dispatch isn't permitted; existing-flow behavior traces to what `spryker-runtime` observed. If the tooling MCP server is down, start it and ask the user to reload the MCP connection before falling back to grep/Read.

### 2. Strict actor assignment
Every story names one canonical actor from [actors.md](actors.md). One actor per story — if two actors are involved, split the story.

### 3. Endpoint path per story
See Phase 5. Real, resolved URL **paths** only in the body — no controller/action class names (those go in the `.refs.md` attachment).

### 3a. No code references in the PRD body
The PRD body MUST be code-free: no FQCNs, no `Class::method()`, no controller/action/plugin/facade/transfer/repository class names, no file paths. Use **feature/module names** and **configuration names** instead.

**Explicit exception — composer package names and version constraints.** A package name (`spryker/quote-requests-rest-api`) is a **product identifier**, not a code reference — the slash is incidental. It is *allowed* in the body, and **required** in any story whose scope includes adding a dependency: a story that says "install a package" without naming the package and version is missing its single most important fact. Present it as a small **"Packages to add"** table under the affected endpoint (package · version constraint · installed-or-not). The FQCNs, plugin interfaces and resource-config constants that come *with* those packages still belong in `.refs.md`. **Corollary (Phase 0):** when a story installs a package, resolve its **published version and dependency compatibility against the project's `composer.lock`** during research, the same way endpoints are resolved — "not installed" is not a sufficient answer; "not installed, published at X, dependency-clean / needs an upgrade of Y" is. This check also verifies resource/endpoint names against the package *source*, which has caught documentation errors the docs brief propagated. All discovered code references are captured in the sibling `<feature-name>.refs.md` attachment (see [refs-attachment.md](refs-attachment.md)) and linked from the PRD's research header. This applies to **every** section, **including Dependencies** — list dependencies as module/feature names and configuration names, never as class/method references.

### 4. Gherkin acceptance criteria
ALL criteria use Gherkin. See [gherkin-guide.md](gherkin-guide.md). Max 4 steps, one behavior per scenario, every outcome quantified, two trailing spaces per line for Markdown line breaks.

### 5. Quantified outcomes — no vague language
- ❌ "Fast" → ✅ "within 500 ms"  ❌ "Good experience" → ✅ "≥90% satisfaction"  ❌ "Works well" → ✅ "handles 1000 req/sec"

### 6. Interactive checkpoints — at the user's chosen pacing
Approval required after Background & Goals, at each story/criterion checkpoint **per the pacing the user chose at Phase 3** (per-criterion / per-story / draft-then-review), after NFRs, and before saving. Ask the pacing once and honour it — don't hardcode the slowest mode, and don't silently skip checkpoints the chosen pacing implies. **Whenever a story is surfaced, the checkpoint MUST include the chosen actor and the affected endpoint (name + path, or "No endpoint affected") so the user explicitly confirms — or corrects — both, not just the story sentence.**

## Using AskUserQuestion

Use it at each checkpoint. Keep labels short (2–4 words); description explains the consequence; wait for the response before proceeding.

**After any free-text or "Other" answer, re-read the user's prior selections for contradiction** — free text is where users correct the framing of the question itself, not just answer it. If the new answer conflicts with an earlier multiple-choice selection (e.g. "do not touch shipment dates at all" after previously selecting "feeds checkout"), **restate both and re-confirm which stands before drafting anything on top of either.** Taking the first answer at face value has produced a PRD for a materially larger feature than the user wanted.

For the per-story checkpoint, present the story + actor + affected endpoint (as shown in Phase 3) before asking, and make the options let the user correct the actor or endpoint specifically:

```json
{
  "questions": [{
    "question": "Approve this story with its actor and affected endpoint?",
    "header": "Story Review",
    "options": [
      {"label": "Approve", "description": "Story, actor, and endpoint are correct — proceed to acceptance criteria"},
      {"label": "Fix actor", "description": "Wrong actor/role — change it (e.g. Back Office content manager, not admin)"},
      {"label": "Fix endpoint", "description": "Endpoint name/path is wrong, or it should be 'no endpoint affected'"},
      {"label": "Modify story", "description": "Adjust the action or benefit"}
    ],
    "multiSelect": false
  }]
}
```

## PRD Template

Use [template.md](template.md). It contains exactly the allowed sections — no Quality Gates, no Tasks, no priority tiers.

## Storage Location

Ask the user at Phase 8 which location:
- **Global feature:** `resources/plan/PRD/Features/{FeatureName}/{feature-name}.prd.md`
- **Module-specific:** `src/{Org}/{Module}/resources/plan/PRD/{feature-name}.prd.md`

**Always save the code-reference attachment alongside the PRD** as `<feature-name>.refs.md` in the **same directory** as the `.prd.md`. The PRD's research header links to it. When you confirm the save to the user, mention both files (the code-free PRD and the `.refs.md` containing the discovered code references). See [refs-attachment.md](refs-attachment.md) for its structure.

## Red Flags — STOP and Revise

- "I'll write the whole PRD at once" → NO — unless the user explicitly chose **draft-then-review** pacing, which is still interactive (a full revision pass follows).
- "I'll hardcode one-checkpoint-per-criterion because it's safest" → NO. Ask the pacing once at Phase 3 and honour it; imposing the slowest mode is how users abandon the skill.
- "I'll skip the research" → NO. Always gather codebase facts first (no invented module/transfer/endpoint names); and ASK the user before docs research and before running-app validation — never silently skip the question.
- "I'll run docs research inline without the user choosing that" → NO. The research-depth question offers subagents / inline / skip — the user picks. Subagent dispatch (parallel for multi-concept features, each with a non-overlap boundary) is the recommended option; inline is legitimate only as the user's explicit choice (e.g. the harness forbids unrequested subagents).
- "As a user…" → NO. Use a canonical actor from [actors.md](actors.md).
- "I'll guess the endpoint path" → NO. Take it from the research brief.
- "Add the phpstan/codecept commands / Quality Gates" → NO. Excluded by design.
- "Let me add implementation details" → NO. WHAT and WHY only.
- "This criterion is obvious" → NO. Gherkin + quantified.
- "I'll put the controller/facade/transfer class name in the body" → NO. Code-free body. Path-only endpoint; FQCNs and config keys go in `<feature-name>.refs.md`.
- "Dependencies can list the facade method I'll call" → NO. Module/feature names + configuration names only; the methods/classes go in the `.refs.md` attachment.
- "I'll discard the FQCNs I found" → NO. Save them to `<feature-name>.refs.md` so the planner has the crosswalk.

## Interactive Workflow Checklist

- [ ] Phase 0: Gathered codebase facts inline **first** (`getSprykerModules`/`getSprykerModuleMap`/`getTransferStructureByName`, vendor paths from the module map — never guessed) and formed a hypothesis — started the server + asked the user to reload MCP if the tooling server was down; asked the user whether docs research is needed (and, only if yes, dispatched `spryker-docs-research` as **subagent(s) via the Agent tool**, targeted by the hypothesis, non-overlapping scopes — or used the stated harness fallback: consent, else inline; merged their briefs); asked the user whether to validate existing endpoints/flows against the running app (and ran `spryker-runtime` only if yes); flagged any missing tools
- [ ] Phase 3 (start): Asked the pacing question once (per-criterion / per-story / draft-then-review) and honoured it through Phases 3–4
- [ ] Phase 2: Background & Goals approved
- [ ] Phase 3: Each story approved at the chosen pacing — every checkpoint that occurred presented the **actor** and the **affected endpoint (URL path, or "no endpoint")** alongside the story, and the user confirmed both
- [ ] Phase 4: Every acceptance criterion approved (individually, per story, or in the draft review — per the chosen pacing), all Gherkin + quantified
- [ ] Phase 5: Each story records affected endpoint as a **URL path** (+ existing/greenfield + module name), or "no endpoint" — as confirmed at the Phase 3 checkpoint; the controller+action FQCN is recorded in `<feature-name>.refs.md`, not the body
- [ ] Phase 6: Business NFRs approved (no tooling/test commands); numbers marked user-supplied vs baseline-default
- [ ] Phase 7: Constraints captured (or explicitly "none discovered"); decisions & accepted risks recorded with their cost; success metrics, out-of-scope, dependencies confirmed (dependencies as module/feature + configuration names, no code references)
- [ ] Phase 8: PRD body is code-free (no FQCNs/`::method`/file paths anywhere, including Dependencies); discovered code references captured in `<feature-name>.refs.md`; research header links the attachment
- [ ] Final PRD approved before saving; saved the `.prd.md` and its sibling `.refs.md`, and told the user about both

## Supporting Files

- **[workflow.md](workflow.md)** — detailed phase-by-phase interactive script
- **[actors.md](actors.md)** — canonical Spryker actor reference (strict actor assignment)
- **[gherkin-guide.md](gherkin-guide.md)** — Gherkin format rules and examples
- **[examples.md](examples.md)** — full PRD example and anti-patterns
- **[template.md](template.md)** — allowed PRD section structure
- **[refs-attachment.md](refs-attachment.md)** — structure of the `<feature-name>.refs.md` code-reference attachment (keeps the PRD body code-free)

## Summary

PRD creation is INTERACTIVE and RESEARCH-GROUNDED. Investigate real Spryker (docs + code, and run the app for existing flows) → ask → draft incrementally → assign a real actor and real endpoint to every story → approve at each checkpoint → keep it business-only. Alignment over speed.
