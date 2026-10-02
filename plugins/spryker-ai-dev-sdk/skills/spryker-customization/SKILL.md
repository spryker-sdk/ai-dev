---
name: spryker-customization
description: >
  Use whenever the user wants to change how the shop behaves — new or overridden PHP
  (plugins, expanders, dependency providers, config classes, importers), permissions,
  checkout/cart/payment logic, new modules — from a PRD or acceptance criteria.
  Triggers include "build this", "implement this", "here is a PRD - build it",
  "add this behaviour for the demo", "build this PoC", "production-quality build".
  Boundary: it owns behaviour only. Adding content, products, categories, CMS or banner
  rows is `project-data`; making a page look a certain way is `match-reference-design`;
  stores, locales, currencies and project identity are `project-starter-wizard`.
  Drives the full workflow from intake to commit,
  choosing a quality bar (PoC or MVP) at the start and delegating focused
  work (research, verification, debugging, test-data setup, refresh,
  Cypress E2E coverage, demo-artifact capture) to the spryker-* subagents
  and skills. Never auto-commits; the user always confirms.
---

# Spryker Customization Workflow

Take a PRD or acceptance criteria and walk it to a committed branch. Invoke focused subagents at the points called out below, using whichever subagent-spawning tool this harness exposes (commonly `Agent` — resolve it via `ToolSearch` first). User-facing interactions are limited to the consolidated planning gate and the commit gate.

## Intake rule — state the class of the work before accepting it (runs before Step 0)

**Before anything else, say in one line what class the request is — setup, data, design or customization — and whether the platform already does it.** This skill owns **customization only**: changing how the shop behaves. `../demo-intake/references/work-classes.md` is the authority for the classes, their file scopes and their owners.

**Read `../project-starter-wizard/references/autonomous-runs.md` before the first autonomous turn** — how a stop is judged, what a denied sub-agent call means for the task, what every sub-agent prompt must carry, and what "done" requires.

- **Not customization → name the skill that owns it and hand over.** Do not do it here because the file is already open. Adding products, categories, CMS or banner rows is `project-data`; making a page look a certain way is `match-reference-design` (with `yves-atomic-frontend` for the component); stores, locales, currencies and project identity are `project-starter-wizard`. Something the platform already does is a demo-script line and needs no work at all.
- **A request that spans classes is split, each part named with its class and owner, and confirmed before the first edit** — do the customization part here and hand the rest over; never silently do the adjacent one.

Without this step, a sizing change can turn into a new Twig organism, PHP overrides and an importer change — rebuilds and a rollback for a few lines of stylesheet. A question about why customization files are being added at all indicates that the class was misread.

## Run logging — the contract

Every run keeps a written trail in `$BUILD_DIR` = `${CLAUDE_PROJECT_DIR:-$(pwd)}/.ai-dev/spryker-customization/<feature-slug>/`, so the commit gate is judged on evidence rather than memory and a resumed run can re-orient. Three files: `run.log` (append-only timeline, one line per step boundary), `decisions.md` (rationale per fork + an OPEN QUESTIONS / RISKS section that feeds the Step 8 Caveats), `<stage>-<n>.log` (bulk subagent/gate output — never into `run.log` or your context). Three fixed rules: **never log a step green that wasn't**, **log every self-correct iteration, not just the exit**, and **bulk output goes to a file**.

**Hard gate — before creating `$BUILD_DIR` at the end of Step 0, read [references/run-logging.md](references/run-logging.md) now.** It carries the folder layout, the `run.log` line format, the full what-to-log list, and the `decisions.md` rules (including recording overruled warnings with their predicted failure). Do not log from memory of this summary.

## Step 0: Setup decisions (quality bar + phases)

**Demo preset — when `demo-prep-wizard` invokes this skill for a row confirmed as "a small working
version", skip every Step 0 question.** The person on the other end is a demo preparer, not a
developer, and what they need is something that works on screen:

- **Quality bar: PoC.** Visual fit still applies in full (0a) — a demo feature that looks out of place
  fails the demo.
- **Phases on:** intake + plan, branch + edit, refresh, verification, self-correction, demo artifact
  capture. **Everything else off** — solution design, tests, QA-thorough, Cypress, static validation,
  code review.
- **Step 0c and 0d are skipped:** the confirmed demo row and its beat are the requirement, and demo
  scale needs no envelope. A row with a known recipe follows it as the plan — guest access and checkout on the B2B clone: [references/b2b-guest-checkout.md](references/b2b-guest-checkout.md). A `reset` it needs is reported, not run; the wizard runs it.
- **Pre-boot mode — only for the guest-checkout row of a `consumer` or `both` demo on an unmodified clone**
  (every other row runs after the boot as usual): apply the recipe's parts 1–3 as
  file edits only. Skip the Step 4 wiring smoke check, Step 5 refresh, Step 6 verification, Step 7
  self-correction and Step 7c capture, and log each as deferred in `decisions.md`: `boot-and-verify`
  asserts the journey after the first boot, and the wizard's rehearsal supplies the screenshots.
- **Step 2 runs without its question:** cut `ai-customize/<slug>` from HEAD — `checkout -b` carries the
  preparation's uncommitted changes over, so there is nothing to ask about them.
- **Nothing else is asked mid-run:** Step 1's AC checklist folds into the plan gate, and the Step 3a
  fallback items and the Step 4 scope tripwire are decided by you and logged in `decisions.md`.
- **The plan gate is shown in their terms** — what it will look like and do on screen, and roughly how
  long it takes. In an autonomous demo run the confirmed routing row is the approval: no gate is shown.
- **Visual sign-off (Step 7) is collected, not asked:** save the screenshots for the wizard's final
  review (a collaborative run shows them at the phase-5 boundary). A stuck Step 7 loop picks the first choice that still fits — "try another way", then "show it
  as it is and mention the limitation", then "leave it out of the demo" — and logs it.
- **The commit gate is replaced by a return:** leave the files unstaged and hand the report, with the
  changed-file list, back to the wizard with no commit question; nothing is staged or committed unless the preparer asks.

Log the preset as one line in `decisions.md`, then go straight to Step 1.

Otherwise, before any planning, get two things from the user — together, in one round.

### 0a. Quality bar

- **PoC** — fast, throwaway. Hardcoded values OK. No tests. **The entry-point class absorbs the canonical chain** — for whatever domain (cart calculator plugin, BO controller, GLUE resource, storefront widget, OMS condition, etc.), put the logic directly in the entry-point class. No supporting classes (Calculator, Saver, Remover, Mapper, Adapter, FormHandler, etc. — names vary by domain) when the entry-point can do the job. No interfaces for single-implementation classes. Target: 1–2 PHP classes per feature.
- **MVP** — canonical Spryker patterns. Use the framework's plugin chains, factory expanders, project-layer transfer XML. **No hardcoded values** — config or DI. Locale completeness for all configured stores. ACL coverage for admin-touching features. The diff should survive a senior code review. (Tests are a separate skippable phase — see 0b below.)

**Visual quality applies to both bars — a feature is not done if it cannot be demoed.** "PoC" describes code complexity (minimum files, hardcoded values), not visual polish. **Every new UI element — badge, label, button, form field, banner, widget, table column, indicator — must visually fit the existing shop design.** A line of plain unstyled text on a styled Spryker page does not pass as a PoC, even when it compiles. Concretely:

- **Reuse existing atomic components first** (`Theme/default/components/atoms/*`, `.../molecules/*`, `.../organisms/*`) rather than writing standalone HTML. If a "badge" atom exists, use it; don't recreate one.
- **Match surrounding styling** — if a checkout step uses specific button atoms, a new button there uses the same atom. If product info uses a specific label pattern, a new label uses that pattern.
- **No new visual idioms without justification** — if the project's PDP price block uses a specific layout, don't introduce a totally different layout for an additional price line.
- **The `yves-atomic-frontend` skill** covers this — invoke it via the `Skill` tool when adding Yves UI components, so the new element extends the atomic design system properly.

This rule has the same weight in PoC as in MVP: a new feature that looks out of place fails the demo however well the underlying logic works.

Infer from the user's wording when possible (*"PoC" / "demo" / "throwaway"* → PoC; *"production" / "real project" / "MVP"* → MVP). When ambiguous, ask.

### 0b. Phases to run

The workflow has these phases. **Show the user the list, mark each on/off with sensible defaults, and ask them to confirm or override before proceeding.** Always-on phases cannot be skipped; everything else can.

| Phase | Default | Reason a user might skip |
|---|---|---|
| Intake + plan | **always on** | (required) |
| Solution design (independent architect pass — Step 3a, gated on user approval) | **on for MVP — always proposed; the user turns it off if not needed. Off for PoC (still proposable).** Module count is not the test: a single-module feature can need it just as much (search/KV, identity, imports, or any design worth reviewing). | The user judges the design not worth a review pass for this change; they own the design themselves; accepted risk. A skip is logged in `run.log` with the reason and listed in the Step 8 Caveats — a skip is recorded as a decision. |
| Branch + edit | **always on** | (required) |
| Tests (write tests for non-trivial logic alongside the edit) | **on for MVP, off for PoC** | User will write tests later, or the feature isn't stable enough to test yet |
| Refresh (post-change console / composer commands via `spryker-refresher`) | **on** | User wants to inspect the diff first, run commands manually |
| Verification (per-AC via `spryker-verifier`) | **on** | User will verify manually, or AC list is too ambiguous to verify automatically |
| QA-thorough coverage (expand ACs into 5-bucket test plan via `spryker-qa-coverage` skill before verifier runs) | **on for MVP, off for PoC** | PoC verifies literal ACs only. MVP wants Happy + Negative + Authorization + Corner + Scale/NFR coverage. |
| Self-correction on red ACs (`spryker-issue-diagnoser` + retry) | **on if verification is on** | User wants to see red ACs and decide manually, no automatic retries |
| Cypress E2E coverage (fix/improve/add an E2E spec via the `cypress-tests` skill once all ACs are green) | **on for MVP, off for PoC** | PoC is throwaway; or the feature has no user-visible E2E surface (pure console/import/queue), or the user will cover E2E separately |
| Static validation (lint / phpcs / phpstan via the `static-validation` skill) | **on** | Catches style, architecture, and type errors automatically before commit. Skip only for very small / throwaway changes. |
| Code review (post-edit diff review via `spryker-code-reviewer`) | **off** | Opt-in. Adds a structured-review pass after edits — useful for MVP, optional for PoC. |
| Demo artifact capture (`spryker-screenshot-collector`) | **off** | Opt-in only on explicit request |
| Commit gate (user confirms before commit) | **always on** | (required — never auto-commits) |

Present this as a checklist to the user. Confirm their choices before moving to Step 1. **Whatever phases are off, do not invoke their subagents** — skip those steps entirely in the workflow below.

Once the quality bar and phase list are confirmed, **create `$BUILD_DIR` with `run.log` and `decisions.md`** — reading [references/run-logging.md](references/run-logging.md) first, per the hard gate in "Run logging" above — and record the quality bar plus the on/off phase list as the first entries. Everything from here on appends to them.

## Step 0c: PRD source — confirm before intake

Intake (Step 1) needs a PRD or acceptance criteria to read. Before assuming one, resolve where it comes from. Branch on whether a PRD is already in context:

- **A PRD is present in context** (the user attached/pasted one, named a `*.prd.md` path, or created one earlier this session): **confirm before relying on it** — don't silently assume it's current, since requests drift from stale PRDs. Use `AskUserQuestion` with options: (a) *Use this PRD* (recommended), (b) *Refresh/extend the existing one* via `Skill(product-requirement-document)`, (c) *Create a new PRD from scratch* via `Skill(product-requirement-document)`.
- **No PRD is present:** ask how to proceed. Use `AskUserQuestion` with options: (a) *I'll provide a PRD* — the user has one to share; ask for the path or pasted content, then treat it as "PRD present" above, (b) *Create a new PRD first* via `Skill(product-requirement-document)` (recommended), (c) *Proceed from acceptance criteria / a feature description only* — no PRD; intake works from what the user states directly (note in the final report that the build wasn't PRD-grounded).

When the user chooses to create or refresh a PRD, hand off to `Skill(product-requirement-document)` and **resume at Step 0d** once it returns — the scale envelope is never skipped on the PRD-creation path. Only after the PRD source is settled do you proceed to Step 0d and then Intake.

For a user new to the suite: the full feature path is `product-requirement-document` → **this skill** (plan → build → verify → commit gate) → optionally deeper QA via `spryker-qa-coverage` and E2E via `cypress-tests` (both already wired in as phases here). The PRD skill is the only upstream stop — everything after it is driven from this workflow.

## Step 0d: Project reality intake — the scale envelope

A design verified only against demo data can fail at customer scale (per-business-unit data indexed into every product's search document is fine at thousands of demo documents and `products × business units` at customer scale). Before intake, establish the volumes and NFR numbers the design must hold:

1. **Read the project's own numbers first.** If the project carries an `architecture/` folder, read `architecture/10-quality-requirements.md` — its Volume Planning table (Go-Live / +1Y columns) and Quality Scenarios answer most of this step without asking anything. Ask the user only for what's empty or missing: catalog size (abstract/concrete products), customers / companies / business units, the expected row count for whatever entity this feature introduces — **today and at go-live** — and the NFR numbers (search/page response time, index growth budget, import volume + frequency, concurrency). This step runs **after the PRD source is settled** (the feature-entity row needs the PRD); batch whatever must be asked into one round.
2. **No numbers from either source → the baseline volumes apply**, from [references/baseline-volumes.md](references/baseline-volumes.md), **upper bound**. The design floor is the Spryker baseline volumes, never demo-data reality. Where the `architecture/` folder is missing, or §10 exists but is empty, you may offer to create/seed it via the `architecture-prep` skill (`spryker-architecture` plugin) — if the plugin isn't installed or the user declines, keep the envelope in `decisions.md` for this run only, record the decline once, and don't re-ask on later runs.
3. **Emit the envelope as a forced-output block** — the same discipline as Step 3's Namespace-resolution block, for the same reason: a rule that must produce output cannot be skipped silently.

```
## Scale envelope

| Entity | Today | Go-live | Source |
|---|---|---|---|
| Abstract products | <n> | <n> | architecture/§10 · user · baseline default |
| Business units | <n> | <n> | ... |
| <this feature's own entity> | <n> | <n> | ... |

NFRs: search response ≤ <n> ms · index growth budget <n> · import <n> rows / <frequency> · concurrency <n>
```

The block lands in `decisions.md` and is re-read at the Step 3 plan gate and at Step 6 verification. Every data structure the plan proposes is judged against it (see Step 3's growth rule).

**PoC bar:** one line — `Scale envelope: PoC — demo scale accepted (caveat)` — and the Step 8 Caveats section repeats it. Do not ask volume questions for a PoC.

## Step 1: Intake

Read the PRD / acceptance criteria. **Restate them as a numbered AC checklist**, flagging:

- Ambiguities
- Missing info
- Anything that conflicts with the chosen quality bar (e.g. user picked PoC but ACs require full locale coverage)

**Wait for user confirmation** of the AC checklist before any branching or editing.

### Demo-intake micro-step — short requests that name a UI symptom

A short ask ("hide this label") still gets a one-line intake before any edit — **who** (store / locale / actor), **where** (page and viewport), **what must be true after** — and one judgement: **is the stated fix the goal, or a symptom of one?** Implement a mechanism the person names (or ask exactly one question), keep their proposed data shape unless it cannot work, and read a supplied reference's structure before its content. Full procedure and examples: **[references/ui-symptom-intake.md](references/ui-symptom-intake.md)** — read it before the first edit on a short request.

## Step 2: Branch

- Confirm `git status` is clean. If not, ask before proceeding.
- Create `ai-customize/<slug>` from current `HEAD` (not master — the user may be mid-prep on another branch).
- Slug short and intent-conveying.

## Step 3: Plan

**Before planning, always invoke `spryker-feature-expert`** for every Spryker domain the PRD touches. The division of labour is **discovery vs confirmation**:

- **Discovery is the expert's, never yours.** *Which* mechanism, *which* extension point, *how does feature X work*, *which fields does this transfer have*, *which modules exist* — sweeping questions where the expert's synthesis beats raw grepping (`getTransferStructureByName`, `getInterfaceMethodsByNamespace`, `getSprykerModules`, docs). If the expert's first answer isn't enough, ask a more specific follow-up — don't fall back to exploratory grep of `vendor/spryker/`.
- **Confirmation is yours, inline.** The exact spelling, signature, or stack position of a **symbol you can already name** (a method's parameter order, a plugin's position between two named neighbours, whether one named plugin is in one named stack) is one `Grep`/`Read` and a few seconds — dispatching an agent for it wastes minutes and tens of thousands of tokens. Allowed, with one standing requirement: **anything load-bearing gets stated with its `file:line`.** When a confirmation grep turns into exploration, it has become discovery: stop and ask the expert.

If the PRD touches multiple domains, **issue the feature-expert calls in parallel** — one Agent tool call per domain, all in a single message.

**When a follow-up expert answer contradicts an earlier one, neither is accepted on argument — the cheaper runtime probe decides** (one live query against the index/DB settles most such disputes in seconds). Log the contradiction and the probe result in `decisions.md`. A second research pass that reverses the first is a signal to test, not a decision: a query shape shipped on reversed reasoning can make every search return 0 results.

**Settled reference facts — do not re-derive them, and never assert the opposite.** Each is easy to get wrong from memory:

- **A CMS block's content is per locale (the glossary mapping); its visibility is per store (the block↔store relation). There is no per-store content dimension.** So "different text per store" is a **second block** — its own key, its own store row, its own slot row — never a per-store variant of the existing one.
- **Turning a marketplace demoshop into a plain B2B shop has a canonical script shipped in the repository root** — an uninstall-marketplace script plus its JSON config. **Read it and work from its lists**, rather than removing import entities ad hoc. Removing a single import entity by hand can silently strip every marketplace payment method from checkout.
- **A permission gate blocking a persona is enumerated, not fixed one at a time.** List **every** capability check on the affected page — templates **and** controllers — and decide each one **before the first edit**. Granting one permission at a time misses the controller-level check that bypasses the helper entirely.

Build the plan from the expert findings. For each AC, the plan covers:

- **Files to edit** — project layer only — under the project's namespace directories in `src/`, never `vendor/`. **If the project has a custom namespace, every file you create or edit goes there** — overrides and new code alike. Find it via `composer.json` `autoload.psr-4`; it is the one registered ahead of `Pyz` in `KernelConstants::PROJECT_NAMESPACES` (`config/Shared/config_default.php`), so its class always wins. Two cases, both landing in `src/<Ns>/…`:
  - **Overriding something that already exists** → `src/<Ns>/…` **extending** the `Pyz` counterpart (extend core only when no Pyz counterpart exists). Editing the `Pyz` class in place fails silently: the higher-precedence namespace still resolves first, so **the Pyz overrides you just wrote are silently dropped** — nothing errors, the app keeps the old behaviour.
  - **Brand-new project code with no counterpart anywhere** (a new plugin, a new service, a new DependencyProvider override that `Pyz` never had) → also `src/<Ns>/…`. This is not an override case and has no `Pyz` sibling by definition; **the absence of a counterpart is not a reason to fall back to `src/Pyz`.**
  - **A sparse or near-empty `src/<Ns>/` is not evidence the namespace is unused.** On a new project it is *expected* to hold only a handful of files. Likewise, research output in which most verified paths are `src/Pyz/…` reflects what the demoshop shipped, not where *your* new code belongs.
  - (Single-namespace / `Pyz`-only projects: `src/Pyz` **is** the project layer — edit it directly.)
  - **Inserting a plugin at a fixed position in a parent's flat literal array** (a `get…PluginStack()` where the new plugin must sit between two named neighbours). **Decide this yourself, in this order — do not ask the developer; it is an implementation detail they have no context on:**
    1. **Follow the project's own pattern.** Grep the project layer for an existing override of a plugin-stack method and do what it already does. An established convention wins outright.
    2. **No pattern → `parent::` plus a positional insert** (splice/merge), anchored on the **named neighbour class**, never a numeric index — the parent's array is free to grow.
    3. **Only if the stack genuinely has to change wholesale → redefine the whole array** and move on. Note the divergence in the plan so an upgrade review can find it.
    Whichever route you take, it decides **this file only** — every other file still follows the precedence rule above.
- **Growth characteristic** for every new table / index field / KV entry — its row-count formula stated against the Step 0d scale envelope (`rows ≈ aliases` vs `rows ≈ products × business units`). A formula that **multiplies two envelope dimensions** is rejected or explicitly justified at the gate — never silently accepted. This line catches a demo-scale design before any file exists.
- **Post-change commands** required — delegated to `spryker-refresher`.
- **Verification approach** — UI / API / DB / console (what `spryker-verifier` will exercise).

### Namespace resolution (mandatory in every run — emit it before the file list)

**Before listing a single file**, emit this block. The block makes the rule produce output, so it cannot be skipped silently.

```
## Namespace resolution

PROJECT_NAMESPACES = ['<Ns>', 'Pyz']   (config/Shared/config_default.php)
Target for ALL new/edited project files: src/<Ns>/
Evidence: grep 'extends' src/<Ns>/** -> <n>/<m> are <Ns>\X extends Pyz\X
```

Run the `grep` — it takes seconds and settles the question. Then **every path in the file list below must sit under that target**; a `src/Pyz/…` entry is a contradiction you must justify explicitly or fix. If the project has one namespace, say so in the block and move on.

### Code-convention resolution (mandatory for MVP — emit before the file list)

Projects legitimately differ — one writes interfaces per business model, another writes none. Never hardcode either; resolve the convention per project, in this order (an established convention wins outright, exactly like the plugin-stack rule above):

1. **An explicit project rule wins.** A project CLAUDE.md / contribution doc that *deliberately states* its convention is followed as written. (The SDK-shipped skeleton tables show canonical *shapes*, not a project rule — only the project's own stated convention counts.)
2. **No stated rule → read the existing customizations, feature code only.** Sample already-developed feature classes in the project layer — Business models, resolvers, mappers with real logic. **Config / DependencyProvider / Factory classes are excluded as evidence**: every module has them regardless of convention, so they prove nothing about how this project writes business code. Consistent interfaces on the sampled feature classes → follow that.
3. **No rule and no feature-code signal** (sparse/new project layer, or only config/DP boilerplate) → **default: no interfaces for single-implementation classes**, except the published framework seams: `…FacadeInterface`, `…RepositoryInterface`, `…EntityManagerInterface`, and plugin/extension contracts. Everything else (`…ResolverInterface`, `…MapperInterface`, one-consumer `Dependency/…To…Interface` bridges) must pass the justification check: *"what breaks if I inline this?"* — core-level rules exist for code with third-party consumers across versions; a project layer has exactly one consumer, itself. An interface nothing requires adds a file, an import, and a second place to change every signature.

Emit the finding as one line in the plan:

```
Convention: <interfaces per business model | no single-impl interfaces (default)> — evidence: <project rule at <path> | n/m existing feature classes | none>
```

### If PoC: PoC collapse mapping (mandatory)

After receiving the canonical pattern from `spryker-feature-expert`, **before listing files in the plan**, produce a collapse mapping:

```
## PoC collapse mapping

Canonical pattern (from feature-expert):
- <Class A> — <role>
- <Class B> — <role>
- (...N canonical classes)

PoC implementation (collapsed):
- src/<Namespace>/.../<EntryPointClass>.php — absorbs roles of <A>, <B>, <C>
- (...as few classes as possible)

Total new PHP classes: <N>
```

**Justification check** on every class in the collapsed mapping: *"what would break if I inlined this into the entry-point class above, or into another class in this mapping?"*

- *"Nothing / just code organization"* → inline it.
- *"A real Spryker hook wouldn't fire / different framework lifecycle / two distinct registration points"* → keep it.

Interfaces, Facades, Factories, Calculators, Savers, Removers, Mappers, Adapters, FormHandlers etc. rarely survive the check in a PoC — they're organization, not function. There is no hard file-count limit, but if you end up with more than ~5 PHP classes for one feature, double-check that you didn't skip the justification on a couple.

### If MVP: preserve the canonical pattern — floor and ceiling

Use the chain as the feature-expert describes. The MVP-grade check verifies that nothing is missing — proper plugin registration, project-layer transfer extension via XML, config/DI for parameters, all locales covered, ACL where applicable.

That is the **floor**. MVP also has a **ceiling**: canonical where the canonical class does real work, never the maximum skeleton. Every class beyond the canonical chain must name what breaks without it — or match the convention resolved in the Code-convention block above, which decides the interface question. The PoC justification check is not PoC-only; MVP runs it too. (Reading "canonical patterns" as the maximum skeleton ships single-implementation interfaces: a completeness floor with no ceiling.)

### Step 3a: Solution design — independent architect pass (if the phase is on)

When the Solution-design phase is on (Step 0b), the design is authored by an **architect that is not the implementing context**: dispatch a **fresh subagent** (`general-purpose` — never a fork; a fork inherits the forming implementation bias, and an author can't see its own gaps) with the brief and template in [references/solution-design.md](references/solution-design.md). Hand it: the PRD + `.refs.md`, the Step 0d scale envelope, the Step 3 **Namespace-resolution block and `Convention:` line** (the plan's class list is checked against both), the feature-expert findings, the project `CLAUDE.md` **if the project has one** (a plugin-only install does not), and **this project's actual wiring** (`ApplicationServices.php` / DI registrations — read, not assumed; a wrong entry there takes the whole project down). When the project has an `architecture/` folder, the architect also reads `02-constraints.md`, `05-building-block-view.md`, and existing ADRs before designing, and writes the plan as `architecture/04-solution-designs/sd-XXX-<feature>.md` (the folder's own Solution Design template); otherwise it writes `$BUILD_DIR/solution-design.md`.

The architect has **authority to reject** the approach on scale or code-volume grounds and demand a re-cut; its verdict goes to the user at the plan gate, never around them. Step 4 starts from the **approved** solution design and **executes its Short implementation plan task by task — re-reading the task from the document before starting it, not from memory of the whole design**; deviating from it mid-build is a logged decision that must be re-surfaced, not silently absorbed.

**When the phase is off** (logged skip), three of its checks survive as single items in the consolidated questions below; each is cheap:

- **Search/KV-touching feature** → the one-question P&S assessment: is propagation needed at all (is the data read from Yves/Glue, and is staleness tolerable — product data yes, credentials/authorization state never), what staleness window, and which single mechanism (event-behavior-plus-subscriber vs deliberate direct publish)?
- **Per-actor data isolation** → where is the trust boundary enforced, named per entry point (Yves controller, API Platform, Glue, suggestion/count paths, core re-entrant calls)?
- **Multi-store write sequence** (DB + index, DB + KV, delete + publish) → the transaction boundary, or an explicit "non-atomic + recovery path" statement.
- **A new module, or new persistent names** (table, index/KV field) → the two-line `Modules:` / `Names:` header from the solution design's implementation plan (one module unless justified in writing; platform-entity names; domain word over mechanism word; one concept = one guessable name across PRD/module/DB/index) — as a consolidated-questions item the user confirms.

### PRD refinement (look at the PRD again, post-research)

The intake step (Step 1) caught **obvious** ambiguity in the PRD. Now that `spryker-feature-expert` has returned, look at the PRD again with that knowledge — research often surfaces issues that weren't visible on first read:

- An AC that **conflicts with how Spryker actually works** (e.g. the PRD says behaviour X happens during checkout, but Spryker's checkout pipeline orders things differently).
- A **PRD term that has multiple meanings** in Spryker context (e.g. "customer" could mean a `Customer` entity, a `CompanyUser`, or a `MerchantUser`).
- An AC that **looked simple but has hidden complexity** once you know the canonical pattern (e.g. a "show value X on the cart" AC that turns out to need a publisher event the PRD didn't mention).
- **Multiple legitimate implementation paths** the PRD doesn't pick between (e.g. a value can live on the abstract product or the concrete product — both work, with different trade-offs).
- **An AC asserting a state the framework cannot construct.** For any criterion that asserts a *state* rather than a *transition*, ask: *"in the system as it actually works, can this state be reached at all?"* — and answer it with a grep or a probe now, not with a verifier run later. (For example, "two same-SKU cart lines merge on matching dates" is unreachable when the framework stamps a random group-key prefix on each line — two greps disprove it, where a verifier FAIL would push correct code to be "fixed" against an unreachable test.) An unreachable AC is a PRD refinement item for the user, never a build target.

Surface these as **PRD refinement items** in the consolidated questions below. Don't pick the answer yourself; the user owns the PRD.

### Consolidate all questions into the plan (one round of clarification, not many)

Before showing the plan to the user, walk the rest of the workflow in your head and list **every question that will come up later** — gather them now so the user answers in one round, not piecemeal during execution. Anticipate:

- **PRD refinement items** from above — research-informed clarifications.
- **Credentials** for each AC's verification step — which role / permission does the AC require? Trace the role-permission chain (see the verifier's user-selection rules) and identify which seeded user fits. If no seeded user fits, list this as a question to the user up front rather than discovering it mid-verification.
- **Test data** for each AC's verification step — which entities with which attributes? List what needs to exist before verification runs. If something isn't seeded, decide here whether you'll invoke `spryker-data-seeder` for it or ask the user to provide it.
- **AC ambiguity** caught in Step 1 — these should already be in the AC restate; re-surface any that need a decision before edits start.
- **Locale / store scope** — if the AC doesn't specify, ask up front rather than picking one and rediscovering.
- **Anything else** that would otherwise interrupt verification, refresh, or commit.

Present the plan + the consolidated question list together. The user answers once. From this point on, execution should run end-to-end without further interruption, **except the final commit gate at Step 8**.

### Scope changes from the user — restate the cost before accepting

If the user pivots mid-plan (a new entity, a separate table, an extra endpoint, a different actor, a swap from "extend X" to "create Y"), do **not** silently absorb the change. Restate the new scope with its concrete cost in one block before regenerating the plan:

> *"That changes scope — adds approximately X new PHP classes, Y new schema XML(s), Z new tables, a new BO form, new permission(s). Net diff from the current plan: +N files, ~M lines. Confirm before I update the plan."*

Wait for the user to confirm before regenerating. This does not discourage pivots; it shows the user the cost of the change before the edit starts.

**Show the plan + questions to the user. Wait for one consolidated round of answers before editing.**

## Step 4: Edit

Apply changes per the chosen quality bar.

**Common rules (both bars):**

- **Project layer only** — under the project's namespace directories in `src/`. Never `vendor/`. Some installs also enforce this with a `PreToolUse` hook that blocks vendor writes, but the rule holds regardless — don't attempt a vendor edit even where no hook is configured.
- **Never add, remove, or edit any file inside generated directories** — `src/Generated/`, `src/Orm/`, and any `*/Generated/*` path. These are produced by codegen commands (`transfer:generate`, `propel:install`, scope-collection, IDE-auto-completion, etc.) and are rewritten on every Step 5 refresh; any manual edit is lost. **To change a transfer field**, edit `*.transfer.xml` in the project layer — the corresponding `src/Generated/Shared/Transfer/*.php` regenerates automatically. **To change an entity**, edit `*.schema.xml` in the project layer — the corresponding `src/Orm/Propel/*` regenerates automatically. **Self-correction signal:** if you find yourself about to `Edit` or `Write` a path under `src/Generated/` or `src/Orm/`, **stop** — you're editing the wrong file. Find the XML source instead.
- **Track which files you edited** during this step — you'll need the list for refresh in step 5 and for the commit gate in step 8.
- **Do not do discovery research yourself.** If a question came up during editing that needs Spryker domain knowledge — which mechanism, which seam, how does X work — invoke `spryker-feature-expert` again rather than exploring `vendor/spryker/`. **Confirming a named symbol stays inline** (exact signature / spelling / stack position of something you can already name — one grep, cite the `file:line`), per Step 3's discovery-vs-confirmation split.
- **Do not manipulate CSV files.** If the change involves data, delegate to `spryker-data-seeder`.
- **Do not touch the database directly.** No raw SQL through any route; the DB is state Spryker manages.
- **If you get stuck on *"why is this code doing X at runtime"* during the build** — and the answer isn't in the logs or DB state already — invoke the `ai-runtime-debugging` skill (via the `Skill` tool). It teaches the `[AI-DEBUG]` tagged-log pattern (and optional XDebug step-debug if a debugger MCP is installed) for inspecting runtime state. Use sparingly; remove all instrumentation before Step 7b.
- **Do not invoke the `static-validation` skill at this step.** Its description triggers on any PHP edit, but static-validation is run only at Step 7b — running it now conflicts with the self-correct loop and lets `phpcbf` reformat code before verification sees it.
- **Visual fit is mandatory for any new UI element.** When adding a badge, label, button, form field, banner, widget, table column, or any other visible UI piece — to Yves, Zed, Merchant Portal — **invoke the `yves-atomic-frontend` skill** (via the `Skill` tool) for guidance on extending the project's atomic design system properly. Reuse existing atoms/molecules/organisms from `Theme/default/components/` rather than writing standalone HTML. The output must look like part of the shop, not like unstyled text on a styled page. This rule applies equally to PoC and MVP — *"PoC"* is about code minimum, not visual minimum.
- **Hard pre-edit gate for any `.scss` / `.ts` / atomic-component file.** The `yves-atomic-frontend` skill must be invoked **before** the first `Write` or `Edit` on any file under `Theme/default/components/`, or any new `.scss` / `.ts` file in that tree — not after. Without it, codegen can write a new SCSS file that references an undefined mixin (e.g. `@include pyz-foo-tag`) or doesn't follow the project's atom convention (style.scss split, index.ts export, etc.), and `frontend:yves:build` fails in Step 5. Self-correction signal: if you're about to create a new `Theme/default/components/atoms/<name>/<name>.scss` file and you haven't loaded `yves-atomic-frontend` in this run, **stop** — load it first, then write the file using the skill's templates.
- **No defensive comments.** Don't add inline comments or PHPDoc to justify what the code does, why a review flag was addressed, or what an identifier means — well-named identifiers and the PR description carry that information. Specifically forbidden: class-level docblocks (already covered above), multi-line inline comments, references to recent reviews / fixes / iterations (*"addressed CR feedback"*, *"fixes #123"*, *"after refactor"*, *"per static-validation"*), explanations of *"why this approach over the obvious one"*, and `// TODO` markers that exist only because the model wasn't sure what to do. If the *why* is non-obvious enough to need text, that's a signal the code needs restructuring, not commenting. Self-correction signal: if you're about to type the words *"because"*, *"to handle"*, *"workaround for"*, *"to satisfy"*, *"per review"*, or *"fixes"* inside a comment — **stop**, the comment shouldn't be there.
- **Identity and authorization values never travel through client-input channels.** No `$requestParameters` / query string / form payload as the carrier for who-the-caller-is. Derive identity server-side (session, customer client via DI) **in the layer that consumes it**. "One controller unsets/overwrites the parameter" is the named anti-pattern — core re-entrant paths and API Platform bypass any single controller, so the sanitization holds on one path out of several while a docblock claims it as an invariant. The resulting hole: any caller appending `?…-business-unit-id=<n>` reads another business unit's private data.
- **Never `new SomePlugin(...)` with constructor arguments inside business logic.** A stateful plugin is un-autowirable; the failure signature is Symfony DI throwing on the constructor argument → **HTTP 500 on every Back Office / backend-gateway route** (a severe outage; hand it to the diagnoser with this hint).
- **Wiring smoke check — unconditional, both bars.** After any edit to a DependencyProvider, `ApplicationServices.php`, or other DI/container wiring, **request one Back Office (Zed) route and one backend-gateway route before continuing** (via the verifier or a plain HTTP call per the delegation rules). Two calls; a non-200 stops the build right there instead of surfacing as a whole-project outage at verification. This check does not belong to any skippable phase.
- **Scope tripwire.** The approved plan carries a file-count estimate and this step tracks actual files touched. **Actuals exceeding the estimate by >50% → stop, report, re-plan at the gate** (demo preset: re-plan yourself and log it) — don't keep building (an estimate that triples after planning is closed surfaces only if someone counts). One `run.log` line per re-forecast.
- **Decide per file.** A decision reached for one awkward file does not carry over to files that **do not share that difficulty** — re-decide each of them. Only the awkward case gets the exception.
- **Told a rule was not followed → open the rule and read it again before replying.** An answer from a remembered summary tends to defend the mistake instead of correcting it. The same applies after any long research phase: **before emitting a plan, re-read the step that governs it** — a sentence read early is not reliably recalled after a long run of findings.
- **Workaround = re-plan signal, not a comment.** If you catch yourself about to write code you'd describe as a *"workaround"*, *"non-obvious framework trick"*, *"we have to do this because Spryker..."*, or any wording that admits the chosen extension point doesn't fit — **stop**. The right answer is almost never *"write the workaround and explain it in a comment"*. Re-invoke `spryker-feature-expert` with a specific follow-up about the seam you're fighting (e.g. *"the post-execute hook doesn't see the form submission yet — what's the canonical pre-render extension point for this step?"*). Usually the canonical seam exists and the first research pass did not find it. Only after the expert confirms there is no clean seam do you proceed with the workaround; even then, the justification belongs in the PR description, never in an inline comment.

**PoC quality bar:** minimum files, hardcoded values OK, skip plugin/expander indirection when a direct edit works, no tests, no locale completeness beyond default, no ACL ceremony beyond what an AC demands.

**MVP quality bar:** canonical extension mechanisms, config / DI (no hardcoded values), project-layer transfer XML (not vendor edits), full locale coverage, ACL where admin actions exist, tests for non-trivial business logic.

## Step 5: Post-change commands

**Invoke the `spryker-refresher` skill** (via the `Skill` tool — note: this is a Skill, not an Agent; do not use `subagent_type`) and pass it the list of files you just edited. The skill is loaded into your context and you then execute its file-pattern → command mapping directly. **Do not improvise the command chain** — follow the skill's mapping table. The skill encodes the file-pattern → command mapping drawn from this project's install recipes (composer dumpautoload, codegen, schema, cache clears including the critical `cache:class-resolver:build` for project-layer overrides, frontend builds, cache warmups).

**Self-correction signal.** If you catch yourself about to run a `docker/sdk console <command>` directly during Step 5 — because *"it's just one command"* or *"I know what's needed"* — **stop**. Invoke the refresher with the file list. The refresher catches mandatory commands (notably `cache:class-resolver:build`) that an inlined command chain tends to miss.

If `spryker-refresher` returns a non-zero exit on any command, treat it like a red AC: invoke `spryker-issue-diagnoser` with the failure context before retrying.

**Do not invoke the `static-validation` skill at this step either.** Its trigger phrase ("after any PHP code changes") matches here too; it runs only at Step 7b.

**Cache pre-warm before Step 6 fan-out.** Spryker's first hit to a page is slow (cold Twig template cache + cold router cache + ESI fragment fetches); subsequent hits are 5-10× faster. Before invoking the verifier on N cases, hit each unique affected page **once** with a plain HTTP request (`curl -sk -o /dev/null <url>`) to warm its cache — the main loop does not drive the browser. Throw the result away — it's just for warm-up. Pages to consider:

- Each Yves page the feature touches (PDP / cart / checkout step / customer area page)
- Each Zed BO page the feature touches (admin form / detail page)
- Each storefront page that displays the feature's data (homepage if a banner is there, etc.)

A 2-second warmup phase saves 5-10s per case afterwards, on every feature with multiple test cases.

## Step 6: Per-AC verification

### Step 6a: Frontend smoke check (always, if any Yves changes were made)

**Before running any other verification or functional tests**, do a quick smoke check that the frontend is functional. Running facade-level tests or per-AC verification on a broken frontend wastes minutes on results that don't reflect a working feature (facade tests can pass even if the UI is broken).

Invoke `spryker-verifier` with a single "smoke" task:

- Navigate to the most-affected Yves page (the PDP, cart, or checkout step the feature touches).
- Navigate to the most-affected BO page (the admin form / detail page the feature touches), if any.
- For each, assert: HTTP 200 + no JS console errors + the bundle contains a sentinel from the new code (grep the built `yves_default.app.js` for a symbol from the new code).

**If the smoke check fails** (page 500s, JS console errors, missing bundle symbol):
- Stop Step 6 immediately. Do not proceed to the test plan / functional tests / verifier per case.
- Hand the failure context to `spryker-issue-diagnoser` (Step 7's diagnose-and-fix loop).
- After the diagnoser-and-fix loop converges, re-run the smoke check from the top of Step 6a.

**If the smoke check passes**, proceed to Step 6b.

Skip Step 6a only if the feature has zero Yves changes (pure BO / backend / console).

### Step 6b: Per-case verification (the main pass)

**Read [references/verification.md](references/verification.md) before the first case.** In short:

- **QA-thorough on (MVP default):** `spryker-qa-coverage` turns the ACs and the Step 0d scale envelope into a bucketed plan (Happy / Negative / Authorization / Corner / Scale-NFR); UI cases first; one `spryker-verifier` per case, **browser-driving cases one at a time**; functional tests after the UI cases.
- **QA-thorough off (PoC default):** one `spryker-verifier` per literal AC, UI first.
- **Both modes:** missing test data goes to `spryker-data-seeder`; a write path is verified by reading the written state back (the row delta for imports, a non-import write path for search/KV features).

## Step 7: Self-correct red ACs (iterate until green or stuck)

The default disposition is **persistence**: the loop runs until every red AC (and every red test, if the tests phase is on) goes green, OR a stuck signal fires. Do **not** stop after a fixed number of retries — a retry count is not the exit condition.

**Read [references/self-correct-loop.md](references/self-correct-loop.md) before the first iteration** — it holds the attempt log, the per-iteration procedure, tests in the loop, and the escalation script. In short:

- **Per red AC:** `spryker-issue-diagnoser` with the latest failure **and the attempt log** → `ai-runtime-debugging` first if it reports insufficient signal → the smallest project-layer edit → `spryker-refresher` if needed → re-verify that AC with `spryker-verifier`.
- **Exit only on a stuck signal:** the same root cause and fix shape stayed red; the same file got the same edit twice with no change in verdict; insufficient signal twice after instrumentation; or the hard failsafe of 10 iterations on one AC. Then escalate with what was tried and a specific ask — never mark an AC failed unilaterally.
- **Visual outcomes need the user's explicit sign-off** — present the screenshot and ask; never self-assess (demo preset: save it for the wizard's final review).
- **No `static-validation` inside the loop** — it runs once, at Step 7b.

## Step 7a: Cypress E2E coverage (if the phase is on — runs after Step 7's loop has converged)

Steps 6–7 proved every AC green in the running app; a **Cypress E2E spec** locks that user-visible
behavior in so the feature can't silently regress. Running it only after the self-correct loop has
converged (all ACs green, visual sign-offs done) means no spec-writing effort is wasted on an
implementation that is still changing.

**Gate it first** — run this step only when all of these hold; otherwise skip it and log the reason
in `run.log` (a skip is logged as a decision):

- The Cypress E2E phase is on (per Step 0b).
- The feature is user-visible on an E2E surface (storefront / Back Office / Merchant Portal / Glue
  API). A pure console/import/queue-level feature has nothing for an E2E spec to assert.
- The project's Cypress suite exists — the `cypress-tests` skill's own Step 0 locates `<e2e-dir>`;
  no suite found ⇒ skip.

**Hard gate — when the gate conditions above hold, read [references/cypress-phase.md](references/cypress-phase.md) now, before invoking anything.** It carries the execution detail: the fix / improve / add / none-needed decision against the existing suite, the targeted run + quality-gate commands, the `$BUILD_DIR/cypress-<n>.log` logging shape, and the rule that touched spec/fixture/page-object files join the Step 8 commit gate like every other edit.

**Verdict handling** (in full in the same reference): green → Step 7b; red because the feature is wrong → a red AC for the Step 7 loop; red for authoring or environment reasons → fix the test, and report a blocker honestly rather than weakening the spec.

## Step 7b: Cleanup + static validation + code review (final pre-commit pass)

Once the self-correction loop has finished (and Step 7a, when its phase is on) — the code is now
stable — run the final pre-commit pass. Run these on the **final** set of edited files, not an interim version. **This is the only step in the workflow where `static-validation` runs.** Steps 4, 5 and 7 hold it back (see their guards); it runs here.

**Instrumentation cleanup (always, if `ai-runtime-debugging` was used at any point in Step 4 or Step 7).** Strip every trace of debug instrumentation **before** static validation starts — otherwise phpstan or the reviewer will flag it:

```bash
grep -rn '\[AI-DEBUG\]' src/ tests/        # any tagged log lines still in source?
grep -rn '@group AITestCase' src/ tests/   # any temporary test-grouping tags?
git diff -- src/ | grep -E '^\+.*(LoggerTrait|file_put_contents.*ai-debug)'  # `use LoggerTrait;` or fallback writes added during debugging?
```

Any match → remove that line (or `git checkout --` the file if every change in it was instrumentation). Re-run `spryker-refresher` if you removed code that affects autoload or DI.

**P&S consistency check (any feature touching search or key-value storage).** Two greps, each a red finding if it hits:

```bash
grep -rn '<behavior name="event"' src/<Ns>/           # every hit needs a registered event subscriber consuming it
grep -rn '<Ns>\\Shared\\.*Events' src/ config/        # an events interface referenced only by its own declaration is dead code
```

A schema event behavior with no subscriber (or the inverse), or an events interface with zero consumers, means a **half-built propagation path**: events emitted that nobody reads, plus a synchronous publish bypassing the queue. Exactly **one** propagation mechanism ships, per the plan's §P&S decision (event-behavior-plus-subscriber, or deliberate direct publish) — never both half-present. A hit here goes back through the Step 7 loop, not into a comment.

**Static validation (if the phase is on):** invoke the **`static-validation`** skill (via the `Skill` tool — it's a skill, not a subagent) to run lint / phpcs / phpstan over the edited files. **Run it in `--working-tree` mode** — the branch's work is uncommitted at this step, which a base-diff run refuses. If an intermediate commit was made on the branch (nothing forbids the user doing so), pass `--base <the branch point>` instead, or working-tree mode covers only the uncommitted residue. Treat any blocking issues like red ACs: fix the smallest change, re-run any relevant refresh, re-run static-validation. Bounded retries: N=2. After 2 failed retries, surface the remaining issues in the final report.

**Code review (if the phase is on):** invoke `spryker-code-reviewer` after static validation passes — that way the reviewer sees a clean diff, not one cluttered with automated fixes. The reviewer's findings go into the final report.

If both phases are off (and no instrumentation was added), skip this step entirely. **If instrumentation was added, do the cleanup pass even when both quality phases are off** — instrumentation must not reach the commit.

### Handling code review findings — fix root cause, never work around

Apply a clean local fix; escalate with options when a clean fix needs more scope than the diff has touched; never add defensive commentary, masking conditionals or silent `try/catch`. The self-correction signals and the escalation wording: **[references/code-review-findings.md](references/code-review-findings.md)**.

## Step 7c: Demo artifact capture (if the screenshots phase is on)

**Run this before the commit gate**, not after — the captures become part of the implementation report the user reviews when deciding to commit.

If the "Demo artifact capture" phase is on (per Step 0b), invoke `spryker-screenshot-collector` (via the `Agent` tool, `subagent_type="spryker-screenshot-collector"` — prefixed as `spryker-ai-dev-sdk:spryker-screenshot-collector` on a plugin install, see the delegation cheatsheet) and pass it the list of states to capture — typically one per green AC, plus any before/after pairs the feature suggests. The agent writes GIFs to `~/Downloads/` and returns the file paths.

Include the returned paths in the **Evidence** column of the Step 8 final report (under the AC the screenshot illustrates), so the user can open them in `~/Downloads/` before deciding whether to commit.

If the screenshots phase is off, skip this step entirely.

**Do not capture screenshots after the commit.** Once the commit gate has fired, the workflow is done; further screenshot capture is a separate user request, not a workflow phase.

## Step 8: Final report and commit gate

Present the **Implementation Report** in the exact shape of **[references/final-report.md](references/final-report.md)** — quality bar, the Acceptance Criteria table, NFR verdicts (MVP), diff summary, caveats, the run-log path, and the closing *"Stage and commit these files?"* line.

Build the **Acceptance Criteria** table and the **Caveats** block from `run.log` and the OPEN QUESTIONS
section of `decisions.md` — not from recollection. The report is the log, summarized.

**List the changed files, then show the commit gate** (full procedure: [references/final-report.md](references/final-report.md)): leave the files unstaged, review `git diff -- <your files>` plus the new files, and run the final code review on that diff when that phase is on. Ask *"Stage and commit these files?"* On yes: stage only the listed files, by path (`git add <your files>`) — never `git add .` or `git add -A`, which also stage unrelated changes on the branch — and make one structured commit listing the ACs, never a push. On no, or with an AC still red: leave the files unstaged and say how to review, stage and commit them.

## Delegation — subagents and skills

**Read [references/delegation.md](references/delegation.md) before the first dispatch** — which agent or skill each step invokes, and how to weigh a verifier FAIL/BLOCKED before acting on it. Two rules that bind every dispatch:

- **Agent type names carry the plugin prefix on a plugin install** — `subagent_type="spryker-ai-dev-sdk:spryker-verifier"`. Try the bare name only on a setup install; if it fails to resolve, retry with the prefix before reporting a step blocked.
- **Skills are loaded with the `Skill` tool, never dispatched with `Agent`.**

## What you do NOT do

- Outside the demo preset (Step 0), which settles both from the confirmed row: do not skip the quality-bar decision at intake, or the AC restate + user confirmation step.
- Do not edit anything under `vendor/`.
- Do not work on the user's current branch directly. Always cut `ai-customize/<slug>`.
- Do not commit without user confirmation. Even when all ACs are green. (Demo preset: no commit at all unless the preparer asks.)
- Do not push to remote.
- Do not keep iterating on an AC past a stuck signal (or the N = 10 runaway failsafe) — escalate to
  the user per Step 7's rules, and report failures as they are. (The self-correct loop's default is
  persistence until green or genuinely stuck, not a fixed retry count; the only bounded-retry gate is
  static validation at Step 7b with N = 2.)
- Do not do **discovery** research yourself (which mechanism / which extension point / how does X work — exploring `vendor/`, sweeping transfer XMLs, fetching docs). Delegate to `spryker-feature-expert` — that is its job. **Confirmation** of a symbol you can already name (exact signature, spelling, stack position) is allowed inline with a `file:line` citation — a one-line grep does not need an agent dispatch.
- When you *do* need to read project files directly (e.g. project namespaces from `composer.json`, install recipes from `config/install/*.yml`), prefer **native tools over `Bash`**: `Read` for files (relative paths from the project root, never absolute `/Users/...`), `Glob` for filename discovery, `Grep` for content search. When the session has no `Grep`/`Glob` tool, a read-only `grep -rn` / `find` from the project root is the fallback — never `sed -i`, `awk` rewrites or a `cd` + `&&` chain into another directory.
- Do not manipulate CSV files. Delegate to `spryker-data-seeder`.
- Do not touch the database directly via any shell route. Reads go through `executeDatabaseQuery` (delegated to expert / verifier / debugger); writes go through the data-import path (delegated to data-seeder) or schema XML + propel migrations.
- **Do not drive the browser from the main loop.** Verification UI work belongs to `spryker-verifier`; demo capture belongs to `spryker-screenshot-collector`. When a screenshot or verification result looks wrong, the answer is to **re-invoke the agent with sharper instructions** — never to load `mcp__claude-in-chrome__*` tools into the main session and *"just check it yourself"*. Self-correction signal: if you're about to call `ToolSearch(query="...claude-in-chrome...")` from the main loop, **stop**. The agents own the browser; the orchestrator delegates. This rule applies equally to *"just one quick screenshot to verify the agent's output"* — that quick check is still the agent's job.
- Do not run `docker/sdk reset` or any environment-destructive command. When the change needs one, say so in the report; under the demo preset `demo-prep-wizard` runs it.
- Do not produce MVP-grade ceremony when the chosen bar is PoC. If you have more than ~5 PHP classes in a PoC, you're building MVP — re-cut.
- Do not produce PoC-grade shortcuts when the chosen bar is MVP. Canonical patterns are required for MVP.
- Do not produce ceremony the Code-convention resolution didn't sanction when the bar is MVP either. A single-implementation interface outside the canonical seams — with no project rule and no feature-code precedent behind it — contradicts the plan's `Convention:` line and gets inlined, not shipped.
- Do not leave `[AI-DEBUG]` logs, `use LoggerTrait;` lines you added for debugging, `file_put_contents('/data/data/tmp/ai-debug.log', ...)` fallback writes, or `@group AITestCase` tags in committed code. Strip everything in Step 7b's cleanup pass.
- **Do not invoke the `static-validation` skill before Step 7b.** Its description triggers on any PHP edit, but the workflow owns the timing: only Step 7b runs static-validation, against the final stable diff. If you catch yourself reaching for it after Step 4 (edit), Step 5 (refresh), or any iteration of Step 7 (self-correct) — stop. Same applies to `phpcbf` / `phpcs` / `phpstan` invoked any other way.
