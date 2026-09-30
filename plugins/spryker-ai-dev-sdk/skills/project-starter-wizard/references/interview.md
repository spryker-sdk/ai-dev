# Interview & state file (wizard §1–§2 — the decision catalog)

## Contents

- [The question bank lives in [questionnaire.md](questionnaire.md) — this file is HOW to collect it](#the-question-bank-lives-in-questionnairemdquestionnairemd--this-file-is-how-to-collect-it)
- [Rule 0 — A filled questionnaire replaces (or shortens) the interview; a BRIEF does not](#rule-0--a-filled-questionnaire-replaces-or-shortens-the-interview-a-brief-does-not)
- [Rule 1 — Offer "fill the list yourself" vs "interview me"](#rule-1--offer-fill-the-list-yourself-vs-interview-me)
- [How to ask (AskUserQuestion mechanics — the live-interview path)](#how-to-ask-askuserquestion-mechanics--the-live-interview-path)
- [Plain language — the operator may not be an engineer (never make them google a word)](#plain-language--the-operator-may-not-be-an-engineer-never-make-them-google-a-word)
- [Demo fast path — purpose: demo](#demo-fast-path--purpose-demo)
- [The nine decision sections](#the-nine-decision-sections)
- [Confirm & write state (the state-file template)](#confirm--write-state-the-state-file-template)

The wizard's interview phase: **how** to collect the nine decision sections (live AskUserQuestion
interview, or a pre-filled questionnaire), and the state-file template the answers are written to.
Read this BEFORE the first question; the flow, pre-flight, execution steps, and cross-cutting rules
live in `../SKILL.md`.

Ask section by section. Always show the demoshop default and let the developer accept it. Accepting every default is valid.

**§9 (run mode) is the LAST question of the interview — never the first.** Autonomy governs how the run *executes*, never how the answers are *collected*: asking it first would let a run decide questions nobody asked. Autonomous mode begins only once every question has been asked; collecting the answers is never autonomous. Sections 1–8 are asked in full first, whatever the answer to §9 turns out to be.

## The question bank lives in [questionnaire.md](questionnaire.md) — this file is HOW to collect it

The canonical, **fillable** list of every decision is the standalone
**[questionnaire.md](questionnaire.md)** (groups P/N/S/T/D/C/L/Q + run-config R, each question
carrying its default inline, mapped back to the interview section it feeds). The nine sections below
are the same decisions expressed as the live-interview script.

Always drive collection from questionnaire.md so a filled file, a partial file, and a live interview
all populate the *same* state-file fields. When you change a decision, change it in questionnaire.md
too, so the two files stay in sync.

## Rule 0 — A filled questionnaire replaces (or shortens) the interview; a BRIEF does not

**What counts as a questionnaire — and only this:** answers keyed by the question IDs of
[questionnaire.md](questionnaire.md) — `P1: acme`, `R1: autonomous`, a `yaml` block with those
keys, or a filled copy of the file (path or pasted). The IDs are the contract; without them nothing
was "filled". **A prose brief does not count as a questionnaire** — "Store United Kingdom, English, EUR, brand red,
replace the catalogue with …" is *input to the interview*, not a substitute for it. A brief pre-fills
the candidates you offer (mark them "from your brief" and put them first), and every section is still
asked and the whole resolved set still confirmed — **except on the demo fast path** (`purpose: demo`, below), where the technical sections are derived rather than asked and appear in the confirmation instead. Failure signature: a brief read as a
questionnaire, `answers_source: questionnaire+interview`, one `AskUserQuestion` for the run mode, a
dozen values recorded as agreed, over answers that were never requested. The plugin hook
denies the first state-file write unless the transcript shows either a questionnaire (ID-tagged
answers from the developer) or the interview (several questions plus a confirmation question) — or,
on the `demo-prep` path, a `demo-prep.md` carrying `up_front_confirmed_at` —
so the brief-as-questionnaire shortcut fails at write time, not at review time.

**"A blank is a default" applies ONLY to a questionnaire the developer filled.** On an interview run
there are no blanks — there are **unasked questions** — and `run_mode: autonomous` says nothing about
them: autonomy begins once the catalog is covered and the whole set is confirmed, never before.
`answers_source: interview` therefore writes `answers_defaulted: []`. **Check before the state write:**
every `[REQUIRED]` ID (`P1`, `R1`, `R2`) appears in a question you actually asked, and
`answers_defaulted` is not longer than the questions asked (the hook counts calls rather than coverage —
rounds that never asked `P1` still pass it). Otherwise unasked values — `P1` and `R2` among
them — are recorded as agreed. Autonomous begins only after every question is answered.

Before asking anything, check whether the developer already supplied questionnaire answers — a filled
copy (a path or pasted text) or a `yaml` answer block keyed by question IDs:

- **Filled** (P1, R1, R2 answered by ID — the minimum viable set): **skip the interview entirely.** Copy the
  answers verbatim into the state file, apply the shipped default for every blank, and log
  `INTERVIEW | SKIP (questionnaire pre-filled)` to `.ai-dev/run.log`. Confirm the resolved set once
  (§ Confirm & write state — an `AskUserQuestion` whose text contains "confirm", listing every resolved
  value) and start running — that single confirmation is the
  gate that keeps a typo from reaching a 40-minute boot.
- **Partially filled** — behaviour depends on `R1`:
  - `R1: autonomous` → **do NOT ask the blanks.** Resolve each one to its documented default, or
    where there is a genuine fork, decide it and record it in `.ai-dev/decision-log.md`. Resolving
    blanks without asking is what the autonomous mode is for.
  - `R1: collaborative` → ask **ONLY the still-blank questions**, batched per § How to ask. Never
    re-ask something already answered.
- **`R1` itself missing** while other answers are present: that is the one blank you must ask about
  (a single AskUserQuestion), because it decides whether every other blank becomes a question or a
  logged decision. Do not assume either mode.
- **Not provided at all:** offer the choice in Rule 1 before defaulting to the full live interview.

**Parsing rules (questionnaire path only).** Match answers to question IDs (`P1`, `T1`, `D1`…). A
blank, omitted, or `?` line is **unanswered → take the default** (not "ask me"). `decide for me` is an explicit instruction to
choose (autonomous) or to ask (collaborative). An answer of `default` / `keep` / `n/a` is
**ANSWERED** — the developer consciously took the shipped value; don't re-ask it. A gated section
(`only if …`) whose gate doesn't apply is not a blank at all — skip it silently.

**One thing a questionnaire does NOT skip: pre-flight.** Everything in SKILL.md §0 (flavor, fresh,
unmodified demoshop, volume collision, ports, composer auth, disk, SDK pin) still runs, and its
developer-must-do findings are still surfaced as `⚠ ACTION NEEDED`. A filled questionnaire removes
*questions*, never *checks* — and the namespace-collision check in particular has a name to test
(`P1`) before any boot.

## Rule 1 — Offer "fill the list yourself" vs "interview me"

When no filled questionnaire exists, don't launch straight into nine sections. First offer both paths
in a single AskUserQuestion:

> "I can either (a) hand you the setup questionnaire to fill at your own pace — then run with no
> interview at all, or (b) interview you now in a few batched question sets. Which do you prefer?"

- **Fill-it-yourself** → point them at [questionnaire.md](questionnaire.md) (or paste its content),
  tell them the **minimum viable set is just P1, R1, R2** and that every blank line means "take the
  default", then **wait** for their answers. Treat the result per Rule 0.
- **Interview me** → run the batched interview below.
- If the developer's request already said "autonomous, don't ask me" but gave no answers, don't offer
  the choice and don't interview: take the demoshop defaults for all nine sections, treat it as
  `run_mode: autonomous`, log `INTERVIEW | SKIP (autonomous, defaults)`, and confirm the resolved set
  once before running.

## How to ask (AskUserQuestion mechanics — the live-interview path)

**Conduct the interview with the AskUserQuestion tool.** Present each decision as a structured question with selectable options; do **not** dump a markdown list of defaults and wait for a freeform reply. For each question:
- Put the **default first** as a selectable option (e.g. "Keep default: b2b-demo-marketplace", "Keep `Pyz`").
- List the real alternatives when the answer is a small closed set (which apps to disable [multiSelect], mail engine).
- For **free-text values** (project name, dev domain, hex colors, custom namespace, store codes, currencies, rates): **offer 2–4 concrete PRE-COMPUTED candidates** as options — derived from the repo dir name, the store geography, common hex shades — with the built-in **"Other"** field as the escape hatch, in the **same** step. Be ready to map a prose answer to tokens in a single follow-up (`golden colors`→`#F0B323`; `Swedish, Norwegian and Polish`→`SE/NO/PL`). **NEVER** a yes/no "do you want to customise X?" gate followed by a separate "now type it" prompt, and never a bare "type it in Other" as the only path — it unreliably returns the typed value (an option such as "Custom namespace" or "Type my store set" can return only the label, or prose instead of the token), which costs the extra turn the inline design avoids.
- **Every question needs AT LEAST TWO options — the tool REJECTS a single-option question**, and that rejection costs a full retry turn. When only one concrete candidate exists, **pair the default with an explicit deferral option**. The logo is the canonical case: "Keep the shipped Spryker logo (flagged as a go-live follow-up)" + "I'll provide a file path or URL", with the built-in `Other` still available. Failure signature: an `AskUserQuestion` rejected for having one option — or a yes/no "do you want to customise X?" standing in for the missing second option (which the rule above already forbids).
- **Batch a section's related decisions into one AskUserQuestion call** (it takes up to 4 questions) — e.g. name + domain + colors as one identity screen. A section should be one exchange, not many.

Picking the default option is always a valid answer, for every question.

## Plain language — the operator may not be an engineer (never make them google a word)

The operator is often a partner or solution consultant, not a developer — on a demo, the preparer. Every operator-facing question and option must be answerable with **no outside knowledge** — if a choice only makes sense to someone who already knows the term, it is phrased wrong. For every question:
- **Label in plain words; explain any unavoidable technical term inline** (in parentheses) and state the **business consequence** ("…so future Spryker updates won't overwrite your changes"), not the mechanism.
- **Mark the recommended option** and make accepting it one click. When the technical answer is fully **derivable from the project** (PHP/database versions, engines, region, CI platform), derive it and present a **confirmation**, not an open question — never ask the operator to supply what the repo already states.
- **Never surface an implementation toggle whose only correct answer is derivable.** The PHP/database "version matrix" is the clearest case — the operator can't and shouldn't pick it.
- **The derivable-answers rule governs EVERY operator-facing line at ANY point in the run — not only the interview phase.** "Never surface an implementation toggle whose only correct answer is derivable" and "never ask the operator to supply what the repo already states" bind a mid-run gate, a step report, and a recovery prompt at hour six exactly as they bind §1–§9. Failure signature: any question, at any point in the run, whose options you could have resolved yourself by reading a file in this clone.
- **Never turn a self-made observation into an operator question.** If *you* noticed something (a container name, a token in a config, a leftover directory), **verify it** — `docker inspect`, read the file, check the label — and act on the verified fact. If it cannot be verified, **say so** and state what you are doing instead; never hand it to the operator as a fork to arbitrate, because they cannot see what you saw. Failure signature: "the live Docker environment already expects `<TOKEN>` — which store code do you want?" on a clone that never booted (those containers belong to an unrelated project).

Plain phrasings for the recurring jargon (reuse these; don't invent new confusing ones):

| Technical term | Say instead |
|---|---|
| custom **namespace** vs the shipped one | "Your project's own private code area, kept separate from the demo code **so Spryker updates don't overwrite your customizations**. Recommended if you expect to customize much." — label the keep option with the namespace the clone actually ships, read from `PROJECT_NAMESPACES`, not with `Pyz` by reflex |
| **version matrix** / single version | Don't ask — derive it. "The demo tests itself on several PHP + database versions to protect Spryker's product; your shop runs one, so I'll test on yours." |
| **suites to keep** (CI) | "Which automatic checks run whenever the code changes. Recommended: the quick code-quality and functional checks; skip the heavy full-product test rig you don't need." |
| **wipe scope** (CI) | "Delete the old demo CI files we're replacing (a clean setup), or leave them in place." |
| **headless** (`yves` off) | "Turn off the built-in storefront — only if a separate front-end app will talk to the shop through its API." |

If a term isn't in this table and you can't phrase it without jargon, treat that as a signal the decision may not belong in the operator interview at all — derive it, or defer it to a developer follow-up.

## Demo fast path — `purpose: demo`

A demo is prepared by someone who is usually not an engineer, so every technical value is derived
rather than asked. When `purpose: demo` (from the `demo-intake` block, or the first answer), **skip Rule 1's
questionnaire offer** — its groups are technical — and ask only what the preparer knows, each question
pre-filled from the brief with the recommended option first:

| ask, in these words | section | recommended option |
|---|---|---|
| "Is this for a demo?" — **skip when `purpose` is already known** (the `demo-intake` block records it) | §1 purpose | Demo |
| "What should the shop be called?" | §1 name | the customer's name |
| "Which countries, languages and currencies should the demo show?" | §4 stores | what the brief names; if it names none, keep the shipped stores |
| "What should happen to the sample products?" | §5 / §6 | keep them · replace them with the customer's products (when a harvest exists) · keep only some categories (named, with product counts) |
| "Run it through and tell me when it's ready, or check in after each step? I stop only for something only you can do, and then give you one command to copy." | §9 run mode + `R2` | Run it through |

Then the one confirmation question, listing every value — asked and derived — in plain words.

**Run from `demo-prep-wizard`, ask nothing and show no confirmation.** When `.ai-dev/demo-prep.md`
carries `up_front` and an `up_front_confirmed_at` stamp (its `answers_confirmed_at` confirms the routing
table, not these answers), the wizard's one sitting already asked these questions and confirmed the
whole set, derived values included: take the answers from `up_front`, write the state file with
`answers_source: demo-prep`, copy `up_front_confirmed_at` into `answers_confirmed_at`, copy `run_mode`
as-is, `up_front.reference_sites` into `project.reference_sites` and `audience` into
`project.audience`. `guard-files.php` accepts that first write on the strength of the stamp. Without
`up_front_confirmed_at`, ask the questions above as usual.

**Derived, never asked.** Write each into the state file and log it as one decision-log line. These are
gated sections whose gate did not fire, not blanks, so they never go into `answers_defaulted`:

- §1 dev domain → the domain `deploy.dev.yml` already has, unchanged — pre-flight checked its host
  names against `/etc/hosts`;
- §1 audience → `business`, `consumer` or `both`, read from the brief (`demo-intake` records it); written as
  `project.audience` and shown in the confirmation; brand colour
  and logo → measured from the source site by `demo-intake` (the shipped logo when there is none);
- §2 namespace → keep what the clone ships;
- §3 services → keep everything the clone ships;
- §4 region → the region `deploy.dev.yml` already has, unchanged — the demo's stores live inside it; store codes → the shipped codes where a store is kept,
  otherwise the two-letter country code;
- §5 D4 (`data.existing_data`) → from the sample-products answer (`up_front.sample_products` on the
  `demo-prep` path): `keep` → not owed (catalogue kept: `adapt`, or `leave` when the stores are unchanged); `replace` or
  `trim` → the D4 table over `php <VALIDATE> inventory`, every group set to its recommended
  disposition (drop SKU-bound activity, rebuild CMS and navigation, keep accounts);
- §7 localization → no, unless the brief names a language or specific wording;
- §8 CI → skip, and the E2E suite migration → skip;
- §9 run mode → the preparer's answer, or on the `demo-prep` path `run_mode` from `demo-prep.md`.

Every derived value appears in the confirmation, so the preparer can still change it there — on the
`demo-prep` path that confirmation is the one `demo-prep-wizard` already showed.

## The nine decision sections

1. **Project identity** — **purpose first: is this a `demo` or a `project`?** (Same field `demo-intake` emits when a briefing produced the handoff; recorded as `project.purpose`.) One question, asked at the top of this section, because it suppresses a whole class of later noise. **`demo`** — a pitch that gets shown and thrown away: CI generation (§8) and the E2E test-suite migration default to **skip**; **go-live debt is not raised at all** (licensing of the source site's content, generated-password rotation, CDN hosting of imagery — a demo has no go-live); and wherever this run offers options, the **recommended** one is whichever lands closest to the source site's appearance, not whichever imports less data. **`project`** — a real build: nothing is suppressed, the wizard's own defaults stand. Then:
   name (→ composer name, docker namespace, README); local dev domain (e.g. `acme.local`; else it stays `spryker.local`); 1–2 brand colors (full palette derived); **optional custom logo** — ask for a logo file path/URL; if provided, `brand-project` places it and sets the storefront logo config; if not, the shipped Spryker logo stays (flagged as a go-live follow-up). Never fabricate a logo. **Reference site(s)** (`P5`), asked with the colours and logo — "which site(s) should this shop look like?", usually the customer's live site; recorded as `project.reference_sites` (`[]` = none, never picked for them) and read by `brand-project` for layout.
2. **Namespace** — **read what the clone actually ships BEFORE writing the option labels.** Run it, do not assume:

   ```bash
   sed -n '/PROJECT_NAMESPACES/,/];/p' config/Shared/config_default.php; ls src/
   ```

   The first entry of `PROJECT_NAMESPACES` is the shipped project namespace and it is **not always `Pyz`** — a demo branch of the marketplace demoshop ships `Demo` ahead of `Pyz`, with `src/Demo/**` on disk and both autoloaded. A run that offers "Keep shipped: Pyz" against such a clone has missed the namespace the codebase actually ships. `Pyz` is only the stock value.

   Then ask: keep what the clone ships (label the option with the **resolved** name, and name every project namespace when there is more than one, in precedence order — "Keep shipped: Demo, then Pyz"), or a custom namespace (CamelCase, colliding with none of the resolved ones nor with core). Phrase it for the operator per the plain-language table ("your project's own private code area … so Spryker updates don't overwrite your customizations") — the CamelCase / no-collision constraint is yours to enforce, not theirs to understand; if they choose a custom area, propose a valid name from the project name rather than asking them to invent one. Write the resolved value into `namespace.name`; the hook refuses a `keep-shipped` name the codebase does not carry.
3. **Services** — the clone already ships every service and application **enabled**; ask what to **turn off**, never what to "enable". Accepting the shipped set as-is is valid. Read the actual `deploy.dev.yml` first — present what is really there, not a memorised list. Phrase app choices per the plain-language table — the operator hears a plain "what turning this off means for your shop", not the bare app names as jargon.
   - **Infra engines are fixed — never asked, never offered.** The database (`mariadb`), search (`opensearch`), broker (`rabbitmq`) and key-value store/session (`valkey`) can be neither disabled nor swapped: we do not support engine swaps, so there is no question here at all. Only the **`dev_services_disabled`** set (next bullet) and the applications are genuinely disablable. Failure signature: any option reading "disable search", "turn off the key-value store", or "switch mysql → postgres". **Show the PHP image tag read-only** (read it from `deploy.dev.yml` `image:`, e.g. `spryker/php:8.4`) — changing it is out of scope for this wizard; if the customer environment pins a different PHP, flag it as a follow-up rather than editing.
   - **Optional dev services** shipped **on** (`mail_catcher`→mailpit, `swagger`, `dashboard`, `redis-gui`, `webdriver`, `scheduler`): ask which to **disable**, and whether to **swap an engine** (e.g. mail catcher `mailpit` → `mailhog`/`mailcatcher`). Default keeps all.
   - **Applications / APIs** — grep `deploy.dev.yml` for EVERY `application:` entry under `groups.*.applications` and offer **each one except the two fixed apps, `backend-gateway` and `backoffice`** (see below). Default keeps all the rest. Three rules:
     - **Never curate to a subset or silently drop "obvious keeps"** — omitting an app from the options removes the developer's choice entirely. Failure signature: an app present in `deploy.dev.yml` that never appeared as an option and therefore shipped by default.
     - **Each application is its OWN independent toggle — never bundle two into one choice** (e.g. `glue` and `glue-backend` are separate apps, offered separately).
     - **Never offer the two fixed apps** (a deliberate, stated exclusion — NOT the silent curation the rule above forbids): **`backend-gateway`** (the Zed RPC / `HOST_ZED_API` gateway every Yves *and* Glue business call routes through — the one hard boot dependency) and **`backoffice`** (the admin UI). `backoffice` is kept mandatory because the vendored Cypress E2E baseline exercises Back Office admin/checkout flows, and a demoshop-derived project realistically always wants an admin panel — a genuinely admin-headless setup is an advanced manual deviation, not a wizard option. Every OTHER app in the file is an informed choice (a UI/API onto Zed — disabling it doesn't break boot or data import).
     - **The known set** (verify against the real file — it evolves):
       - `yves` — server-rendered storefront; disabling = **headless** (frontend consumes Glue; boot-verify shifts to the API). Like every frontend app, keeping it **costs boot time** — `frontend:yves:build` + Yves `assets:install` + router/twig cache warm run in the install recipe — so a genuinely headless project saves all of that by dropping it. (Frontend/build-heavy apps — `yves`, `merchant-portal`, `static` — each add install-recipe build steps; note the cost when offering them.)
       - `merchant-portal` — marketplace merchant self-service admin; drop it if the project isn't a real marketplace or manages merchants via BackOffice/import (won't be present on a non-marketplace clone).
       - `backoffice` — the BackOffice admin UI; **fixed on, not offered** (see the two-fixed-apps rule above): the E2E baseline needs it and a project always wants the admin panel.
       - `glue` — **Storefront API** (headless/PWA/mobile/frontend consumes it). A **separate, independent** app — disable it on its own.
       - `glue-backend` — **Backend API** (programmatic back-office / management / integrations). Also **separate and independent** — offer it as its own toggle, NOT bundled with `glue`. You can keep either without the other (e.g. a headless storefront wants `glue` only; a management-only integration wants `glue-backend` only). Both route through `backend-gateway`, but that's the shared Zed gateway, not a link between the two APIs.
       - `static` — **Storybook** (component / design-system explorer; endpoint `storybook.<domain>`). Most customer projects don't ship it, and keeping it also costs boot time (`frontend:storybook:build` runs in the install recipe) — so it's a **common disable**. Offer it like every other app; never drop it from the options.
4. **Stores** — **ask stores first; the region is proposed after** (the developer need not know regions up front). **Store mode is NOT a question** — `store_mode: dms` is a fixed value written straight to the state file: nothing downstream supports classic mode (`define-stores` *deletes* the classic-mode `dynamic-store-off` variants), so the only supportable answer is DMS. Never surface it.
   - Per store: name (DMS rule `^(?!.*_{2})[A-Z][A-Z_]*[A-Z]$`), locales + default, currencies + default, countries, timezone. **Reject two locales sharing a language for one store** (they would share the same 2-char URL prefix, so the second locale is unreachable).
   - **State the store code's business consequence BEFORE they pick it:** the code appears in **every storefront URL** as `/<STORE>/<lang>/…`, and in the deploy/env tokens — it is permanently visible to shoppers. **Short uppercase codes (2–4 chars), like the shipped `DE`/`AT`/`US`, are the convention;** longer codes work and ship as-is, showing up in full in every URL. Say this in the question, not afterwards.
   - **Region** — *you* propose it from the entered stores: a region is the deploy-file deployment group the stores live in (infra grouping), not something the developer must name up front. Propose a token (default: one region grouping all stores; suggest by geography, e.g. `NA` for US+CA). **When the project REPLACES the shipped region outright — the single-region case this wizard builds — REUSING the shipped token (`EU`) is the DEFAULT, not a collision**: the shipped `/etc/hosts` entries and every deploy endpoint stay valid, so the run needs no hosts-file prerequisite at all. **Reject a collision only when the shipped region SURVIVES alongside the new one** — two live regions cannot share a token. Let the developer accept or override. Failure signature: a fresh token proposed when the shipped one would have done, forcing needless host and deploy changes. **Scope: this wizard creates exactly ONE new region** — genuinely multi-region infrastructure (separate EU + US deployments) is a manual follow-up on `deploy.dev.multi-region.yml`'s pattern; say so here if the store set implies it, not during define-stores.
5. **Demo data** — choose `data.mode`. **The options depend on the step-4 store decision** (data mode is gated by stores — changing a store *is* adaptation):
   - If step 4 **changed** stores/locales (the usual case), offer three: **`adapt`** (reshape the shipped demo catalog to the project), **`clean`** (no demo catalog — a minimal shop that boots green: empty catalog, working email/tax/payment/shipment config, project stores), or **`generate`** (a project catalog in place of the demo one — **two sources, one axis, recorded as `data.source`:**
     - **`authored`** — the wizard writes the catalog content itself from a theme. **⚠ experimental / supervised: unstable, interaction-heavy, and NOT hands-off; the developer must stay present and validate the result. Present `adapt`/`clean` as the stable defaults and flag `authored` as a test mode when offering it.** The warning belongs to *authoring*, not to "not the demo data".
     - **`dataset: <path>`** — the developer supplies a complete, already-structured catalogue (masters, variants, images, a category tree, related products). No authoring; ask for the **path** and the **field mapping** (which file/column feeds sku, name, category, price, image, variant axis) and record them as `data.dataset_mapping`. **Safe for an autonomous run.** Failure signature: `generate` chosen with an invented `authoring: none` marker because no mode fitted — that is this option, unnamed.)
   - If step 4 **kept the demo stores/locales unchanged**, a fourth option unlocks: **`leave`** (leave the demo data exactly as shipped — a rebrand-only project; skips all store + data transformation). `leave` is valid **only** when nothing about stores/locales changed.
   - **adapt** — collect **only** the currency→rate table (offer bundled defaults; drives price conversion). No other questions: adapt carries the demo data as-is. It only **warns** the developer that store-bound demo activity (orders/carts, tied to the old stores) can't be persisted under the new stores and was removed.
   - **clean** — nothing extra to collect; it's shape-driven from the stores (see `project-data`'s clean strategy). Rate table / catalog reduction do not apply.
   - **generate, `dataset`** — collect only the path and the field mapping (above) plus D2's rate table if the dataset's prices are single-currency. None of the authoring inputs below apply.
   - **generate, `authored`** — collect (see `project-data`'s generate strategy); stores/locales/currencies come from step 4:
     - **The demo-catalog disposition is DERIVED, never asked.** Generating **against a full demo catalog = replace the demo domain, keep the structural skeleton**; onto a cleaned/minimal base it is a plain add. **Never offer "add alongside"** — `generate.md:97` states collision-handling is **not built**, so the option is unbuildable *and* the answer is derivable from the current catalog state (`generate.md`'s step 0 reads it). Failure signature: a "Wipe existing catalog / Add alongside" question.
     - **theme** (free text), **product count** (default ~20), **category list** (or let it propose), **attribute set**, **variants?** (default no).
     - **prices** — either a price range per category **per assigned currency**, or one base-currency range **plus a currency→rate table** (same shape as adapt's) to convert the authored prices. One of the two is required — without a rate source, multi-currency prices have nothing to derive from.
     - **two separate image sources** (images are user-supplied, never generated; each a folder path or URL list): **product imagery** (the catalog photos) and, distinctly, **CMS / banner imagery** (homepage hero/carousel/banner blocks). Ask for them separately — a single combined `image_source` answer hides that two deliverables are expected: CMS-block authoring then has no images of its own, and the one folder ends up copied to `frontend/static/` unused.
     - **content language** (generate authors *fresh* content, so it can write in-language from the start): author the generated names/descriptions/CMS **directly in the project's locale(s)** (default — free for generated content, avoids re-translating), or in **English placeholders** to translate later via `translate-content`. Generate-only — `adapt` can't author in-language; it must translate shipped demo afterward.
     - **homepage banners** — how many, and what each one links to (default: **one per top-level category, capped at the carousel's slot count**). Resolve against `generate.md`'s home slot map (`slt-2` carousel, `slt-3`/`slt-5` full-width, `slt-4` grid, `slt-home-bottom`) and its 64/128-codepoint title/text limits, so the answer is buildable as given. Load-bearing: unasked, it surfaces as an ad-hoc question mid-run.
     - **merchant portal users** — how many per merchant, and the email convention (default: **1 per merchant, `<merchant-slug>@<dev-domain>`**). The generated credentials are **go-live debt**: record them in the close summary, never leave them as a silent default nobody was told about.
     - **merchant sellers** — this is a marketplace by default, so author merchants and offers as the shipped norm. (A product is still buyable from its own price+stock, so offers aren't a checkout prerequisite — but the default model is a marketplace, not a single-seller shop.) Ask how many sellers per product and their names (default: the shipped marketplace model), or whether the developer wants a simpler single-seller setup. Each additional seller is a distinct merchant — unless explicitly asked, not the same merchant selling a product both directly and via an offer (one merchant twice in the buy box).
   - **leave** — nothing to collect; the demo data (and demo stores) stay as-is.
   - **D4 — disposition of the shipped NON-catalogue data.** Ask it whenever `data.mode` is `clean`/`generate` (either source) **or** step 6 (C1) removes branches. Group C covers only which category branches survive; nothing else asks what happens to the rest of what the demoshop ships, and those entities **import cleanly against missing SKUs and then fail silently** — empty screens and 404s in exactly the B2B flows a demo opens first (quote request, shopping list, product list), surfacing at demo time rather than at import time. Skipping the question leaves the shipped quotes, orders and lists unasked about.
     - **Derive the table first, never ask it abstractly:** run `php <VALIDATE> inventory <shipped manifest>` and render, per group, **entity → row count (from inventory) → breaks when the catalogue changes? (yes / no / partly)**, then ask `drop` / `keep` / `rebuild` per group as one `AskUserQuestion` (multiSelect what to `drop`, default recommendation marked). Groups: **quote requests + versions**; **shopping lists** (+ items, business-unit and company-user relations); **product lists** (+ categories, merchant relation, ssp model, content lists); **sales orders**; **customers**; **companies / business units / company users**; **CMS pages / blocks**; **navigation**; **discounts**; **SSP assets / inquiries / service points / files**.
     - `rebuild` means re-author the group against the final catalog (project-data owns it, recorded as a required follow-up if post-boot); `keep` is valid only for a group that references nothing the catalog change removes.
     - Record it as `data.existing_data: { <group>: drop|keep|rebuild, … }` in the state file. **project-data diffs it against the final manifest before `done`** (every `drop` absent; every `keep`/`rebuild` present and `product-refs`-clean) — the answer is checked mechanically, not trusted.
6. **Catalog scope (adapt mode only — skip for clean/generate/leave; optional, default: keep the whole demo catalog)** — ask whether the project sells the entire demo catalog or only part of it. Most projects keep everything (the demo catalog is a placeholder they'll replace later). Default: keep all. This drives `project-data`'s **reduce** strategy, which runs **pre-boot** (so the first boot imports the reduced set — no reset).
   - **Derive the options from the data BEFORE asking: read `category.csv`, count the products under each top-level branch, and offer the branches BY NAME WITH PRODUCT COUNTS** as a `multiSelect` of what to **REMOVE** — "Office (388)", "Transport (26)", "Branch C (29)". The count is what makes the answer informed: a bare branch name hides how much of the catalog it takes with it.
   - **Never present a themed label whose membership the operator cannot see.** A shorthand ("only one top-level branch", "one branch of the tree") is yours to **resolve**, never to accept as an answer — and if you offer a shorthand at all, **render its resolved membership inline in the same question** (which branches, which counts). Signature: the operator confirmed a phrase and the drop that followed touched branches the phrase never named.
   - Record the answer as the **resolved set** (branch names + counts), not the operator's phrase. **This is where catalog scope is settled, and the only place it is asked** — `reduce.md` then renders the resolved tree and its feature-coupling findings as a *report* and prunes; it never stops mid-run to re-confirm, however large the drop.
7. **Localization (optional, default off)** — ask it as: **"Does any storefront wording need to change — a different language, OR a different register/variant of the same language (`en_US → en_GB`, cart → basket, catalog → catalogue)?"** Both are the same glossary machinery in `translate-content` (after boot); the second does not *look* like translation, so a brief's `"basket" wording` gets filed as a data-block note while `L1` resolves to `no` — when the real scope is a full regional-English review of the glossary. **Default: no** — every locale stays an English copy (translation debt, flagged); localizing is slow and strictly opt-in. If yes, record which locale(s) and scope (glossary only, or glossary + catalog content). **Consistency check at state-write time:** any brief or answer naming specific UI wording with `localize: { locales: [] }` is self-contradictory — refuse the write and ask this question.
8. **CI (automatic quality checks)** — the plan `project-ci-generator` executes (collected HERE so the run needs no further config questions; only the destructive wipe is confirmed at execution). **Almost everything is derived, not asked** (per the plain-language rule) — phrase this as one plain choice plus at most one follow-up, never a five-part engineer's questionnaire:
   - **The one plain question:** "Set up an automatic quality check that runs on every change to your project — code style, static analysis, and the functional tests — replacing the demo's heavy multi-configuration test rig (which exists to protect Spryker's product, not your shop)?" Options: **Set it up (recommended)** / Skip CI for now / Let a developer tune the details.
   - **Derived from the project, never asked:** the CI platform (from the git remote — a GitHub repo → GitHub Actions); the single PHP + database it runs on (from the project's own `deploy.dev.yml` image tag and shipped engines — this replaces any "version matrix", which the operator never sees); which checks to keep (the fast code-quality + functional gating checks kept, the heavy product-QA suites dropped); and cleanup (delete the demo CI files being replaced). Write these into the `ci:` block as the resolved plan.
   - **The only extra plain choice:** notifications — "send check results to a chat channel? (default: no)". If the operator picked "let a developer tune it," THEN expose the underlying suite/version/cleanup detail for a technical user; otherwise it stays derived.
   - The kept-checks decision **IS** the **robot/acceptance-fixture lane decision** other steps read — **derived from "keep functional/acceptance gating, drop heavy product QA", never asked and never shown to the operator.** Exactly **two** states, no third "leave it alone": lane **kept** → adapting the `b2b_robot` fixtures is a **required follow-up** (write it to `## Required follow-ups`); lane **dropped** → `project-ci-generator` removes the fixture tree. Failure signature: any operator-facing question about robot/acceptance fixtures, or fixtures left in place unadapted — the broken inert config `define-stores` forbids.

9. **Run mode** (= questionnaire `R1`) — how the wizard proceeds once the answers are confirmed. **ASKED LAST, after sections 1–8 are answered — this order is fixed.** It is a deliberate choice with no silent default, and it decides only the execution cadence: an autonomous answer never retroactively licenses an unasked question (taking it first licenses deciding answers nobody was asked for; the mode governs cadence, never consent). Same work either way — only the check-in cadence differs (full behaviour: §3 of the wizard SKILL):
   - **autonomous** — runs steps 1–9 in one pass with no "continue?" check-ins; at any **reversible, in-project** decision point it picks the best option and records it in `.ai-dev/decision-log.md`. **This includes every questionnaire question left blank** — a blank in a *filled questionnaire* is a delegated decision, not a deferred question (on an interview run there are no blanks — Rule 0). **Irrecoverable** actions (`sudo`, publishing outside the clone, deleting untracked files, destructive operations on a project that already carries real data — `reset`/`clean-data` during this first setup are **not** gated) and human-only prerequisites (start Docker, `/etc/hosts`, tokens) **still stop for explicit OK** (SKILL.md §3 hard-stops). Best for a repeat/experienced run, and the mode the questionnaire exists to serve.
   - **collaborative** (accepted alias: `supervised` — same mode; both read as `collaborative` in the state file) — asks **"continue?" at each step boundary** and surfaces **every decision as a question** with a recommendation; never runs straight through, never silently stops. It also asks the questionnaire's blanks rather than resolving them. Best for a first run or when the developer wants to steer.

   Ask this section **even when a questionnaire was supplied but `R1` is missing** (Rule 0) — it is the one answer that has no safe default, because it decides whether every other blank becomes a question or a logged decision.

## Confirm & write state (the state-file template)

Summarise every answer — **including which ones you resolved to a default or decided yourself**, marked
as such, so the confirmation shows the developer the *whole* resolved set and not just what they typed.
Get explicit confirmation. (This one confirmation stands even on the fully-filled questionnaire
fast-path; it keeps a typo from reaching a 40-minute boot.) **That confirmation — and only
it — writes `answers_confirmed_at` into the frontmatter** (on the `demo-prep` path the stamp is
copied from `demo-prep.md`'s `up_front_confirmed_at`, and nothing is asked here). Without the field the file is an aborted
interview, and Resume restarts collection from it rather than adopting it; so never pre-fill the
timestamp, and never leave it in place when the developer rejects the set.

**Three checks before the write, all mechanical (SKILL.md §2 has the full text):** (1) interview run →
`answers_defaulted: []` and `P1`/`R1`/`R2` each asked; (2) UI wording named anywhere → `localize.locales`
non-empty; (3) `data.mode` clean/generate or `reduce_catalog` removing branches → `data.existing_data`
present with every D4 group answered. Any miss: ask that one question, then write.

**A blank `T1` (stores) gets its OWN explicit confirmation line, in both modes (not on the `demo-prep` path — the one sitting already confirmed the stores) — it is the one default
that is never silently taken.** It resolves out of an **untouched** `deploy.dev.yml` and then cascades
into `data.mode: leave`, skipping `define-stores` and `project-data` entirely — and a store value
inherited from a file the developer never edited has not been chosen. State the resolved consequence in
full and wait: *"your project will ship the demo stores DE/AT in region EU, and the demo data stays
exactly as shipped — confirm."* Failure signature: `define-stores` and `project-data` logged
`SKIPPED (data.mode=leave)` on a decision nobody stated.

Then write
`.ai-dev/project-setup.md` in this format:

```markdown
---
version: 1
run_mode: autonomous                    # or collaborative (alias: supervised) — interview §9 / questionnaire R1; governs check-in cadence, never the hard-stops (destructive/irreversible/human-prerequisite always ask). autonomous logs reversible decisions, and any blank that needed a genuine fork, to .ai-dev/decision-log.md — blanks that took their documented default are recorded ONLY in answers_defaulted below, never duplicated into the decision log
answers_confirmed_at: 2026-09-21T14:11:05Z   # REQUIRED — the moment the developer confirmed the whole resolved set. Written ONLY by that confirmation, never pre-filled and never carried over from an edit. Its ABSENCE means an aborted interview: Resume must restart collection instead of adopting this file — otherwise a just-rejected state file is adopted as accepted
answers_source: questionnaire           # questionnaire | interview | questionnaire+interview (partial fill, collaborative) | defaults (autonomous, nothing supplied) | demo-prep (run from demo-prep-wizard: nothing asked here; answers_confirmed_at copied from demo-prep.md's up_front_confirmed_at)
answers_defaulted: [P4, S2, S3, L1, Q1, Q2]   # question IDs left blank and resolved to their documented default — the audit trail for "I never chose that". ALWAYS [] on answers_source: interview (no blanks, only unasked questions); a list longer than the questions asked is the invented-answer-set signature. EXCLUDES gated questions whose gate didn't fire (a skipped D2/D3/C1/Q3 is not a blank at all, per Rule 0) and excludes genuine forks (those go in the decision log + run.log `decided=[…]`)
project: { purpose: project, audience: null, name: acme-shop, dev_domain: acme.local, brand_colors: { primary: "#C8102E", accent: null }, logo: null, reference_sites: [] }   # purpose: demo | project (§1, asked first) — demo defaults CI + e2e-suite migration to skip, raises NO go-live debt, and makes the recommended option the one closest to the source site's appearance. audience: business | consumer | both (demo: from the brief / demo-prep.md; null on a project). logo: path/URL to a developer-supplied logo, or null = keep the shipped Spryker logo (flagged). reference_sites: P5 URLs brand-project reads for layout ([] = none; demo-prep path: copied from up_front.reference_sites)
namespace: { mode: custom, name: Acme }        # or { mode: keep-shipped, name: <the namespace the clone ships — the FIRST entry of PROJECT_NAMESPACES, `Pyz` on a stock demoshop but `Demo` on a demo branch; read it, never assume> }
services:                               # deviations from the shipped deploy.dev.yml — omit/empty = keep demoshop defaults
                                        # infra engines are FIXED (mariadb/opensearch/rabbitmq/valkey) — never swapped, never recorded
  dev_services_disabled: [swagger]      # optional dev services shipped ON, turned OFF ([] = keep all)
  dev_services_engine: { mail_catcher: mailhog }   # swap a kept dev service's engine
  applications_disabled: [glue]         # apps/APIs shipped ON, turned OFF ([] = keep all)
store_mode: dms                          # FIXED VALUE, never a question — nothing downstream supports classic mode (define-stores deletes the dynamic-store-off variants)
standing_approval: null                 # or { scope: "<the class the developer pre-approved, in their own words>", granted: <timestamp> } — honoured only inside that scope; covers reset/clean-data/volume drops and post-boot data deletion only when scope is rebuilds, granted in the developer's own words (hard-stops.md → Standing approval)
region: NA                              # proposed from the stores below, developer-confirmed
stores:
  - { name: US, locales: [en_US], default_locale: en_US, currencies: [USD], default_currency: USD, countries: [US], timezone: America/New_York }
  - { name: CA, locales: [en_US, fr_CA], default_locale: en_US, currencies: [CAD], default_currency: CAD, countries: [CA], timezone: America/Toronto }
data: { mode: adapt, rate_table: { USD: 1.08, CAD: 1.47 } }   # adapt: reshape demo to project stores/locales/currencies (rate_table drives price conversion). Demo data carried as-is; store-bound orders/carts removed with a warning. No keep/drop edge policies.
# clean alternative     → data: { mode: clean }                # no demo catalog; nothing else to collect
# generate alternative  → data: { mode: generate, source: authored, theme: "women's dresses", product_count: 20, categories: [...], attributes: [...], variants: false, price_range: {...}, rate_table: { PLN: 4.3 }, product_image_source: "path/or/urls", cms_image_source: "path/or/urls", content_language: native }   # source: authored = interactive authoring (NOT hands-off); price_range: per category per currency, OR base-currency ranges + rate_table to convert; product vs CMS/banner imagery are separate deliverables; content_language: native = author directly in the project locale(s) (default; no later translation), english = English placeholders to translate later via translate-content
# dataset alternative   → data: { mode: generate, source: "dataset: data/acme-catalogue", dataset_mapping: { sku: "masters.csv:article_no", name: "masters.csv:title", category: "tree.csv", price: "prices.csv:eur", image: "images/", variant_axis: [colour, size] }, rate_table: { GBP: 0.86 } }   # a supplied, structured catalogue: path + field mapping, no authoring — safe for autonomous
# leave alternative      → data: { mode: leave }                # only if stores/locales unchanged; rebrand-only, skips store + data transformation
# D4 (mode clean/generate, or reduce_catalog removes branches) → data.existing_data: { quote_requests: drop, shopping_lists: drop, product_lists: rebuild, sales_orders: drop, customers: keep, companies: keep, cms: rebuild, navigation: rebuild, discounts: drop, ssp: drop }   # per-group disposition of the shipped non-catalogue data, asked over the `php <VALIDATE> inventory` row counts; project-data diffs it against the final manifest before `done`
reduce_catalog: { keep: all }   # adapt-only. all = keep whole demo catalog (default); else { remove_categories: [office, transport] } / { keep_categories: [heat-recovery] }
localize: { locales: [], scope: glossary }   # [] = keep English copies (default); e.g. locales: [uk_UA], scope: catalog
ci: { platform: github, keep_suites: [validation, functional], matrix: single, notifications: drop, wipe_unreferenced: true }   # interview §8; project-ci-generator executes this plan (the wipe itself still confirmed at execution). keep_suites doubles as the robot/acceptance-fixture lane decision
---
## Steps
<!-- status values: pending | in-progress (+ progress note) | done | skipped (+ reason, set at state-WRITE time for conditional steps) | failed (+ where) -->
| step | status | note |
|---|---|---|
| wizard:interview | done | confirmed |
| project-ci-generator | pending | (runs FIRST, pre-boot; executes the interview's `ci:` plan against the discovered CI — outward-facing, deletes CI files; only the wipe itself is confirmed at execution) |
| configure-codebase | pending | |
| brand-project | pending | |
| configure-services | pending | |
| define-stores | pending | (skipped if data.mode = leave — demo stores unchanged) |
| project-data | pending | (skipped if data.mode = leave; strategy by data.mode: adapt / clean / generate; + a reduce pass, adapt-only, if reduce_catalog ≠ keep:all — all pre-boot) |
| cypress-migration | pending | (E2E test infra; last pre-boot step — file work pre-boot, `cy:run` proof post-boot) |
| boot-and-verify | pending | |
| translate-content | pending | (optional; only if localize.locales non-empty; runs after boot) |

## Required follow-ups
<!-- Durable cross-step handoffs, written by the step that discovers them and read by the step that consumes them (survives a resume; conversation-only would be lost). E.g. project-ci-generator lists the kept `.github/deploy/*.yml` files here for define-stores (region token) + brand-project (base domain) to sweep. Empty until a step adds one. -->
```
