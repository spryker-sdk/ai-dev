# Project setup questionnaire (the fillable question list)

## Contents

- [Three ways to use it](#three-ways-to-use-it)
- [How to answer](#how-to-answer)
- [Group P — Project identity  (→ interview §1, step brand-project)](#group-p--project-identity---interview-1-step-brand-project)
- [Group N — Code namespace  (→ interview §2, step configure-codebase)](#group-n--code-namespace---interview-2-step-configure-codebase)
- [Group S — Services & applications  (→ interview §3, step configure-services)](#group-s--services--applications---interview-3-step-configure-services)
- [Group T — Stores  (→ interview §4, step define-stores)](#group-t--stores---interview-4-step-define-stores)
- [Group D — Demo data  (→ interview §5, step project-data)](#group-d--demo-data---interview-5-step-project-data)
- [Group C — Catalog scope  (→ interview §6, project-data reduce pass)](#group-c--catalog-scope---interview-6-project-data-reduce-pass)
- [Group L — Localization  (→ interview §7, step translate-content)](#group-l--localization---interview-7-step-translate-content)
- [Group Q — Automatic quality checks (CI)  (→ interview §8, step project-ci-generator)](#group-q--automatic-quality-checks-ci---interview-8-step-project-ci-generator)
- [Group R — Run configuration  (R1 and R2 are required — and asked LAST)](#group-r--run-configuration--r1-and-r2-are-required--and-asked-last)
- [Minimum viable answer set](#minimum-viable-answer-set)
- [Copy-paste answer block](#copy-paste-answer-block)

This is the canonical list of everything the wizard needs to turn a fresh demoshop clone into your
project **without interviewing you live**. It is the same nine decision sections the live interview
walks through (`interview.md`), flattened into a file you can fill at your own pace.

**Every question has a default.** Leaving a line blank means "take the default" — it does NOT mean
"ask me". A file where you answered nothing at all is a valid, complete answer set: it produces a
rebrand-only project on the shipped demoshop defaults, so you fill only what
you care about. **This applies only to a copy of this file you filled and handed over.** A prose brief is
not a questionnaire, and on a live interview there are no blanks — only questions not yet asked; the
wizard asks them all before it runs in either mode.

## Three ways to use it

1. **Pre-fill and skip the interview.** Copy this file, answer inline under each question, and hand
   the filled copy to the wizard (a path, or paste). Every `[REQUIRED]` answered → the wizard runs
   with **NO interview**.
2. **Partial fill.** Answer what you know and set `R1: autonomous` — the wizard decides every blank
   itself and logs each choice to `.ai-dev/decision-log.md`. With `R1: collaborative` it instead asks
   only the still-blank questions.
3. **Interactive.** Give nothing; the wizard either hands you this list to fill, or walks you through
   it in batched questions — your choice.

However it is collected, every answer is written to `.ai-dev/project-setup.md` (the state file) with
its source noted, so every step reads the same grounded input.

## How to answer

- Plain, short answers. A name, a token, a yes/no, a comma-separated list.
- **Blank = take the default.** Defaults are shown inline as `(default: …)`.
- `[REQUIRED]` means the wizard cannot proceed without it — P1, R1 and R2. Everything else has a
  working default.
- **Group R is asked last**, always. When the wizard walks you through this list interactively, the
  run mode is the final question, after every other section has an answer. Asking it first would
  let "autonomous" be read as licence to decide questions that were never asked. Autonomous mode
  begins only once every question has been asked and answered; collecting them is never autonomous.
- **"decide for me" is a valid answer to any question.** In `autonomous` mode the wizard picks and
  logs it; in `collaborative` mode it asks you.
- Some sections are **gated** by an earlier answer (marked `only if …`). If the gate does not apply,
  skip the whole section — do not answer it "just in case".
- Answer with **tokens where a token is asked for** (`SE`, `USD`, `#F0B323`), prose where prose is
  asked for. Prose in a token field costs a clarification round-trip.

---

## Group P — Project identity  (→ interview §1, step `brand-project`)

P0. Purpose — `demo` or `project`?  `(default: project)`
    - **`demo`** — a customer pitch built from a real source site. CI generation and the e2e suite
      migration default to **skipped**, go-live debt (licensing of the source content, demo password
      rotation, CDN imagery) is **not** raised, and wherever options are offered the recommended one
      is the one closest to the source site's appearance, not the one that imports fewer rows.
    - **`project`** — a real customer codebase that will be developed and deployed. Everything above
      applies in reverse: CI and the suites are in scope and go-live debt is worth naming early.
    A wrong answer puts a demo through a CI wipe and a Cypress migration it does not need, and raises
    licensing questions about scraped source content that a demo never has to settle.
P1. `[REQUIRED]` Project name? (drives the composer name, docker namespace, README title)
    e.g. `acme-shop`
P2. Local dev domain? `(default: spryker.local)` e.g. `acme.local`
P3. Brand colors — 1 or 2 hex values, primary first; the full palette is derived from them.
    `(default: keep the shipped Spryker colors)` e.g. `#C8102E, #F0B323`
    Blank is written as `brand_colors: { primary: null, accent: null }` — `null` means "no override,
    leave the shipped theme alone". Never resolve it by writing Spryker's own hex values into the
    state file: that reads as a deliberate brand choice and hides that nothing was chosen.
P4. Custom logo — a file path or URL to your logo.
    `(default: keep the shipped Spryker logo — flagged as a go-live follow-up)`
    Never fabricated: no answer = the Spryker logo stays.
P5. Which site(s) should this shop look like? — one or more URLs, usually the customer's live site.
    `(default: none — no reference; never picked for you)` e.g. `https://www.acme.com`
    Recorded as `project.reference_sites`; `brand-project` reads it for layout (asked with P3/P4).

## Group N — Code namespace  (→ interview §2, step `configure-codebase`)

N1. Your project's own private code area, kept separate from the demo code **so Spryker updates
    don't overwrite your customizations**. `(default: keep — whatever namespace the clone ships;
    `Pyz` on a stock demoshop, `Demo` ahead of `Pyz` on a demo branch, possibly something else on a
    partner clone — the wizard reads `PROJECT_NAMESPACES` and never assumes)`
    Answer either `keep` (recorded as `namespace: { mode: keep-shipped, name: <resolved> }`, and
    the confirmation names the resolved value) or a CamelCase name, e.g. `Acme`. Recommended if
    you expect to customize much. If you want one but don't care about the name, write `custom`
    and the wizard derives it from P1.

## Group S — Services & applications  (→ interview §3, step `configure-services`)

The clone ships **every service and application enabled**. These questions only ask what to turn
**off** — there is nothing to turn on, and the infra engines (`mariadb` / `opensearch` / `rabbitmq` /
`valkey`) are fixed: they are neither disablable nor swappable, so they are not asked about at all.

S2. Optional dev services to turn OFF? `(default: keep all)`
    Available: `mail_catcher`, `swagger`, `dashboard`, `redis-gui`, `webdriver`, `scheduler`.
S3. Swap a kept dev service's engine? `(default: no)` e.g. `mail_catcher: mailhog`
S4. Applications to turn OFF? `(default: keep all)` Each is an independent toggle:
    - `yves` — the built-in storefront. Off = **headless** (a separate front-end talks to the shop
      through its API). Also saves noticeable boot time.
    - `merchant-portal` — marketplace merchant self-service admin. Common off if you aren't a real
      marketplace or manage merchants via Back Office/import.
    - `glue` — Storefront API (headless / PWA / mobile consumers).
    - `glue-backend` — Backend API (programmatic back-office / integrations). Independent of `glue`.
    - `static` — Storybook component explorer. **Common off** — most customer projects don't ship it
      and it costs boot time.
    Not offered (fixed on): `backend-gateway` (the gateway every business call routes through) and
    `backoffice` (the admin UI the E2E baseline needs).

## Group T — Stores  (→ interview §4, step `define-stores`)

T1. Your stores. `(default: keep the shipped demo stores — EU: DE, AT)`
    **Leaving this blank is what unlocks `leave` mode in D1** (a rebrand-only project).
    **This is the one default the wizard confirms OUT LOUD before acting on it, in both run modes.** A
    blank T1 resolves out of a `deploy.dev.yml` you never edited and then cascades into
    `data.mode: leave` — and a store value inherited from an untouched file has not been chosen. So
    before `define-stores` runs you get a plain confirmation and it waits: **"your project will ship
    the demo stores DE/AT in region EU, and the demo data stays exactly as shipped — confirm."**
    **Your store code is public.** It appears in **every** storefront URL as `/<STORE>/<lang>/…` and in
    the deploy and environment tokens — shoppers see it permanently. Short uppercase codes (**2–4
    chars**, like the shipped `DE`/`AT`/`US`) are the convention; longer ones work but ship as-is — a
    long code shows up in full in every URL.
    One row per store — a small table is ideal:

    | store | locales (default first) | currencies (default first) | countries | timezone |
    |---|---|---|---|---|
    | US | en_US | USD | US | America/New_York |
    | CA | en_US, fr_CA | CAD | CA | America/Toronto |

    Rules the wizard enforces for you (you don't need to memorize them): store names match
    `^(?!.*_{2})[A-Z][A-Z_]*[A-Z]$`; **two locales sharing a language in one store is rejected**
    (they'd share a 2-char URL prefix, so the second is unreachable).
T2. Region token — the deploy-file deployment group your stores live in.
    `(default: the wizard proposes one from your stores' geography, e.g. NA for US+CA)`
    **Reusing the shipped token (`EU`) is the DEFAULT when the project REPLACES the shipped region
    outright** — the single-region case this wizard builds — because the shipped hosts entries and
    deploy endpoints stay valid. A collision is rejected only when the shipped region SURVIVES
    alongside a new one, or against **any of
    your own store names** — a region named `US` alongside a store named `US` is ambiguous in the
    deploy file and later config. So a single `US` store gets region `NA`, not `US`.
    If the proposed token also collides (a store literally named `NA`), keep proposing — next-widest
    geography, then a project-derived token (`ACME_NA`) — until one collides with nothing. Only if
    that runs out do you ask.
    **Scope: exactly ONE new region** —
    genuinely multi-region infrastructure (separate EU + US deployments) is a manual follow-up.

## Group D — Demo data  (→ interview §5, step `project-data`)

D1. What to do with the shipped demo catalog? `(default: adapt if T1 changed stores; leave if not)`
    - `adapt` — reshape the shipped demo catalog to your stores/locales/currencies. **The usual
      choice.** Demo data is carried as-is; store-bound orders/carts are removed (they can't be
      re-persisted under new stores) — the wizard warns, it isn't a defect.
    - `clean` — no demo catalog: a minimal shop that boots green (empty catalog, working
      email/tax/payment/shipment config, your stores).
    - `generate` — a project catalog in place of the demo one. **Name the source** (recorded as
      `data.source`):
      - `generate authored` — the wizard writes the catalog content from a theme (D3 inputs).
        **⚠ experimental / supervised: unstable, interaction-heavy, NOT hands-off.** You must stay
        present and validate the result. Do not pick this for an autonomous run.
      - `generate dataset: <path>` — you supply a complete, already-structured catalogue (masters,
        variants, images, category tree, related products). No authoring; D3 asks only for the path
        and the field mapping. **Safe for an autonomous run.**
    - `leave` — leave the demo data exactly as shipped (rebrand-only; skips all store + data work).
      **Valid only if T1 is blank / unchanged.**
    **Not an option, and never asked:** adding a generated catalog *alongside* the demo catalog.
    Collision-handling for that is not built — and the disposition is derived anyway: `generate`
    against a full demo catalog **replaces the demo domain and keeps the structural skeleton**; onto a
    `clean` base it is a plain add.

D2. `only if D1 = adapt or generate` Currency → rate table (drives price conversion).
    Rates are **relative to the demo catalog's shipped base currency, `EUR`** — `USD: 1.08` means one
    EUR becomes 1.08 USD. `(default: bundled rates)` e.g. `USD: 1.08, CAD: 1.47`
    **You need one rate per non-EUR currency in your stores** — the shipped demo prices are in EUR, so
    a USD-only shop still needs a `USD` rate to convert them.
    Resolving a blank D2 (the wizard's rule, so it never invents a number):
    - every store currency is `EUR` → `rate_table: {}`. Nothing to convert; do NOT write an identity rate.
    - otherwise → take the **bundled rate** for each non-EUR currency and **log it as a decision**
      (a rate is a money value the developer didn't choose, so it belongs in the decision log, not
      silently in `answers_defaulted`). Name the rate and its source in the entry.
    - a currency with no bundled rate → that is a genuine fork: **ask**, even in autonomous mode. A
      wrong exchange rate silently mis-prices the whole catalog, so it fails the "reversible" test
      that lets autonomous decide alone.

D3. `only if D1 = generate` The generate inputs (stores/locales/currencies come from Group T).
    **If `D1 = generate dataset`, answer only these two and skip the rest of D3:**
    - dataset path — readable from this clone's machine, e.g. `data/acme-catalogue/`
    - field mapping — which file/column feeds each of: sku, name, description, category, price
      (+ currency), image, variant axis (e.g. `sku: masters.csv:article_no`). Recorded as
      `data.dataset_mapping`; the wizard never guesses a column.
    **If `D1 = generate authored`, the authoring inputs:**
    - theme (free text), e.g. `women's dresses`
    - product count `(default: ~20)`
    - categories (or let the wizard propose them)
    - attribute set
    - variants? `(default: no)`
    - prices — **one of these two is required**, there's nothing to derive from otherwise:
      a price range per category **per currency**, OR one base-currency range **plus D2's rate table**.
    - **product imagery** — a folder path or URL list. Images are user-supplied, never generated.
    - **CMS / banner imagery** — a *separate* folder path or URL list, for the homepage hero /
      carousel / banner blocks. Asked separately on purpose: a single combined answer leaves
      CMS-block authoring with no images at all.
    - content language `(default: native — author directly in your locales, so nothing needs
      translating later)` or `english` for placeholders to translate later.
    - **homepage banners** — how many, and what each links to `(default: one per top-level category,
      capped at the homepage carousel's slot count)`. The wizard resolves this against the home slot
      map and the 64/128-character title/text limits so the answer is buildable as given.
    - **merchant portal users** — how many per merchant, and the email convention
      `(default: 1 per merchant, <merchant-slug>@<dev-domain>)`. The generated logins are
      **go-live debt** and are listed in the close summary.
    - merchant sellers `(default: the shipped marketplace model)` — how many sellers per product and
      their names, or say `single-seller` for a simpler shop. Each additional seller is a distinct
      merchant (not one merchant selling the same product twice in the buy box).

D4. `only if D1 = clean or generate, OR C1 removes branches` What happens to the shipped
    **non-catalogue** data? The demoshop ships far more than products, and most of it points at demo
    SKUs: those rows import cleanly against a changed catalogue and then fail **silently** — empty
    screens and 404s in exactly the B2B flows a demo opens first (quote request, shopping list,
    product list). `(default: the wizard proposes drop for SKU-bound activity, rebuild for CMS and
    navigation, keep for accounts — and shows you the table before acting)`
    **The wizard fills the counts, you fill the last column.** It runs
    `php <VALIDATE> inventory <shipped manifest>` and renders, per group: entity → row count →
    breaks when the catalogue changes? → your answer, one of `drop` / `keep` / `rebuild`:

    | group | entities (from inventory) | breaks? | drop / keep / rebuild |
    |---|---|---|---|
    | quote requests | quote_request, quote_request_version | yes | |
    | shopping lists | shopping_list, items, business-unit + company-user relations | yes | |
    | product lists | product_list, categories, merchant relation, ssp model, content lists | yes | |
    | sales orders | sales_order (+ items) | yes | |
    | customers | customer, addresses | no (demo accounts) | |
    | companies | company, business_unit, company_user, roles | no (demo accounts) | |
    | CMS | cms_page, cms_block (+ slot relations) | partly (product/category links) | |
    | navigation | navigation, navigation_node, content_navigation | partly (category targets) | |
    | discounts | discount, discount_amount, voucher | partly (SKU/category conditions) | |
    | SSP | ssp assets, inquiries, service points, files | partly | |

    `rebuild` = re-author against the final catalogue (project-data owns it); `keep` is valid only
    for a group referencing nothing the catalogue change removes. Recorded as
    `data.existing_data: { <group>: … }`; **project-data diffs it against the final manifest before
    `done`** — every `drop` absent, every `keep`/`rebuild` present and reference-clean — so a wrong
    answer fails at the gate, not at demo time.

## Group C — Catalog scope  (→ interview §6, `project-data` reduce pass)

C1. `only if D1 = adapt` Does the project sell the whole demo catalog, or only part of it?
    `(default: keep all — most projects keep everything, the demo catalog is a placeholder they
    replace later)`
    If a subset: name the **top-level categories to remove**, e.g.
    `remove: office-supplies, transport`.
    **The wizard reads `category.csv` first and offers the branches BY NAME WITH THE PRODUCT COUNT
    under each** — "Office (388)", "Transport (26)" — so you are choosing a *set*, not a label. A
    themed phrase ("only one top-level branch") is never acted on unresolved: the wizard shows you which
    branches and counts it maps to and asks you to confirm that tree. **This is the only place catalog
    scope is asked** — once the set is confirmed here the run applies it without stopping again,
    however large the drop.
    This runs **pre-boot**, so the first boot imports the reduced set (no reset needed).

## Group L — Localization  (→ interview §7, step `translate-content`)

L1. Does any storefront wording need to change — **a different language, OR a different
    register/variant of the same language** (`en_US → en_GB`, cart → basket, catalog → catalogue)?
    `(default: no — every locale stays an English copy, flagged as translation debt)`
    Both are the same glossary work and both are answered here — a same-language wording change is
    not "translation" in everyday words, so it is easily recorded elsewhere and dropped.
    Translating is slow and strictly opt-in, and it runs **after** a green boot — it never blocks setup.
    If yes: which locale(s), and scope `glossary` (UI text only) or `catalog` (glossary + product
    content).
    **Consistency rule the wizard enforces:** if your brief or any answer names specific UI wording,
    `L1` cannot be `no` — a state file with a wording requirement beside `localize: { locales: [] }`
    is refused and this question is asked.

## Group Q — Automatic quality checks (CI)  (→ interview §8, step `project-ci-generator`)

Q1. Set up an automatic quality check that runs on every change to your project — code style, static
    analysis and the functional tests — replacing the demo's heavy multi-configuration test rig
    (which exists to protect Spryker's product, not your shop)?
    `(default: set it up)` Options: `set-up` / `skip` / `developer-tunes-it`
Q2. Send check results to a chat channel? `(default: no)`
Q3. `only if Q1 = developer-tunes-it` The technical detail, for a technical answer: which suites to
    keep, version matrix, wipe scope.
    Everything else is **derived, never asked** — the CI platform (from your git remote), the single
    PHP + database version (from your own `deploy.dev.yml`), which checks to keep (fast
    code-quality + functional gating kept, heavy product-QA suites dropped), and cleanup of the demo
    CI files being replaced.

## Group R — Run configuration  (R1 and R2 are required — and asked LAST)

These decide HOW the wizard runs, not what your project is.

R1. `[REQUIRED]` Mode — `autonomous` or `collaborative`:
    - **`autonomous`** — the wizard runs all steps in one pass with no "continue?" check-ins, and at
      any **reversible, in-project** decision point (including **every question you left blank
      here**) it picks the best option and records it in `.ai-dev/decision-log.md`. It still stops
      for the hard-stops in R2. Best for a repeat or experienced run, and the mode this questionnaire
      exists to serve.
    - **`collaborative`** — asks **"continue?"** at each step boundary and surfaces **every** decision
      as a question with a recommendation. It also asks you the blanks in this file rather than
      deciding them. Best for a first run, or when you want to steer.

R2. `[REQUIRED]` Acknowledge the hard-stops (they apply in **both** modes — autonomy never overrides
    them). Answer `ack`:
    - **irrecoverable actions** — anything `git checkout` cannot undo: `sudo`, publishing outside the
      clone, deleting untracked files, and destructive operations on a project that already carries
      real data. You get the concrete blast radius (the file list, the row/column count, what the drop
      wipes) and must say go. **Recoverable** pre-boot edits and deletions — on a still-un-booted
      clone, on git-tracked paths, before any data is imported — run on their own and are logged with
      their before→after counts and a literal revert command. **Not gated during this setup:**
      `reset` / `clean-data` are normal first-installation operations (there is no customer data yet)
      — the wizard announces the blast radius in one line and proceeds.
    - **standing approval, if you grant one** ("I approve all actions except commit and push") — it is
      recorded in the state file with its scope, each covered act is still **announced** in one line,
      and once the project carries real data it **never** covers `reset`/`clean-data`/a volume drop or
      any post-boot data deletion: those are re-announced with their blast radius and re-approved
      every time.
    - **human-only prerequisites** — starting Docker/OrbStack, the `/etc/hosts` line, supplying a
      GitHub token. The wizard cannot do these for you.
    - **a step failure** — it stops with guidance rather than pressing on.
    So even a fully-filled questionnaire in `autonomous` mode is **not** a run that never speaks
    again: expect to be consulted on destructive operations, and on decisions only you can make (a store-keyed money decision, a namespace collision).
    Anything other than `ack` (or blank) counts as **not acknowledged** — the wizard asks R2 once as a
    plain question and waits. It never proceeds by treating a non-`ack` answer as consent, and it never
    weakens a hard-stop on the strength of this answer: `ack` records that you know they will fire, it
    does not pre-approve any of them.

R3. Where is the logo / image material, if you referenced paths in P4 or D3?
    `(default: n/a)` Must be readable from this clone's machine.

---

## Minimum viable answer set

**P1, R1, R2.** That's it. With just those three the wizard runs end-to-end: it takes the shipped
demoshop defaults for everything else and — in `autonomous` mode — decides any genuine fork itself,
logging each to `.ai-dev/decision-log.md` so you can audit or revert afterwards.

A realistic "I actually care about a few things" fill is **P1, P2, P3, N1, T1, D1, R1, R2** — identity,
your own code area, your stores, and what happens to the demo catalog.

## Copy-paste answer block

Fill what you care about, delete the rest, hand it over.

**One gotcha if you edit the store table:** a store, country or locale code that is a bare YAML
boolean — `NO` (Norway), `ON` (Ontario), `Y`, `N`, `OFF`, `TRUE`, `FALSE` — must be **quoted**
(`"NO"`), or a YAML parser turns Norway's store code into `false` and it silently breaks downstream.
Quote it here and the wizard keeps it quoted everywhere it lands.

```yaml
P0: demo
P1: acme-shop
P2: acme.local
P3: "#C8102E, #F0B323"
P4:                      # logo path/URL, blank = keep Spryker logo
P5:                      # reference site URL(s) to look like, blank = none
N1: Acme                 # or `keep` (the shipped namespace), or `custom`
S2: [swagger]            # dev services OFF
S4: [static]             # applications OFF
T1: |
  | store | locales | currencies | countries | timezone |
  |---|---|---|---|---|
  | US | en_US | USD | US | America/New_York |
T2: NA
D1: adapt                # or clean | generate authored | generate dataset: <path> | leave
D2: { USD: 1.08 }
D3:                      # generate only — dataset: path + field mapping; authored: theme, counts, prices, imagery
D4:                      # clean/generate, or C1 removes branches — per group: drop | keep | rebuild (wizard shows the counts first)
C1: keep all
L1: no                   # any wording change, even same-language (cart → basket) = yes
Q1: set-up
Q2: no
R1: autonomous
R2: ack
```
