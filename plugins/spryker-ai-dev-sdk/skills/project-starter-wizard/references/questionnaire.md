# Project setup questionnaire (the fillable question list)

This is the canonical list of everything the wizard needs to turn a fresh demoshop clone into your
project **without interviewing you live**. It is the same nine decision sections the live interview
walks through (`interview.md`), flattened into a file you can fill at your own pace.

**Every question has a default.** Leaving a line blank means "take the default" — it does NOT mean
"ask me". A file where you answered nothing at all is a valid, complete answer set: it produces a
rebrand-only project on the shipped demoshop defaults. That is the whole point — you fill only what
you care about.

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
- `[REQUIRED]` means the wizard cannot proceed without it — there are only **three** (P1, R1, R2).
  Everything else has a working default.
- **"decide for me" is a valid answer to any question.** In `autonomous` mode the wizard picks and
  logs it; in `collaborative` mode it asks you.
- Some sections are **gated** by an earlier answer (marked `only if …`). If the gate does not apply,
  skip the whole section — do not answer it "just in case".
- Answer with **tokens where a token is asked for** (`SE`, `USD`, `#F0B323`), prose where prose is
  asked for. Prose in a token field costs a clarification round-trip.

---

## Group P — Project identity  (→ interview §1, step `brand-project`)

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

## Group N — Code namespace  (→ interview §2, step `configure-codebase`)

N1. Your project's own private code area, kept separate from the demo code **so Spryker updates
    don't overwrite your customizations**. `(default: Pyz — the shipped shared area)`
    Answer either `Pyz` (keep) or a CamelCase name, e.g. `Acme`. Recommended if you expect to
    customize much. If you want one but don't care about the name, write `custom` and the wizard
    derives it from P1.

## Group S — Services & applications  (→ interview §3, step `configure-services`)

The clone ships **every service and application enabled**. These questions only ask what to turn
**off** or **swap** — there is nothing to turn on.

S1. Infra engines to swap? `(default: keep all — mariadb / opensearch / rabbitmq / valkey)`
    Only name a swap if your hosting requires it, e.g. `search: elasticsearch`.
    (The database/search/cache your shop runs on. The demo's choices are the Cloud-recommended ones.)
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

T1. Store mode? `(default: DMS — the modern shipped default; keep unless you have a specific reason)`
T2. Your stores. `(default: keep the shipped demo stores — EU: DE, AT)`
    **Leaving this blank is what unlocks `leave` mode in D1** (a rebrand-only project).
    One row per store — a small table is ideal:

    | store | locales (default first) | currencies (default first) | countries | timezone |
    |---|---|---|---|---|
    | US | en_US | USD | US | America/New_York |
    | CA | en_US, fr_CA | CAD | CA | America/Toronto |

    Rules the wizard enforces for you (you don't need to memorize them): store names match
    `^(?!.*_{2})[A-Z][A-Z_]*[A-Z]$`; **two locales sharing a language in one store is rejected**
    (they'd share a 2-char URL prefix, so the second is unreachable).
T3. Region token — the deploy-file deployment group your stores live in.
    `(default: the wizard proposes one from your stores' geography, e.g. NA for US+CA)`
    Two collisions are rejected: the shipped region tokens (`EU`, and the dormant `US`), **and any of
    your own store names** — a region named `US` alongside a store named `US` is ambiguous in the
    deploy file and later config. So a single `US` store gets region `NA`, not `US`.
    If the proposed token also collides (a store literally named `NA`), keep proposing — next-widest
    geography, then a project-derived token (`ACME_NA`) — until one collides with nothing. Only if
    that runs out do you ask.
    **Scope: exactly ONE new region** —
    genuinely multi-region infrastructure (separate EU + US deployments) is a manual follow-up.

## Group D — Demo data  (→ interview §5, step `project-data`)

D1. What to do with the shipped demo catalog? `(default: adapt if T2 changed stores; leave if not)`
    - `adapt` — reshape the shipped demo catalog to your stores/locales/currencies. **The usual
      choice.** Demo data is carried as-is; store-bound orders/carts are removed (they can't be
      re-persisted under new stores) — the wizard warns, it isn't a defect.
    - `clean` — no demo catalog: a minimal shop that boots green (empty catalog, working
      email/tax/payment/shipment config, your stores).
    - `generate` — author a small themed catalog in your own vertical.
      **⚠ experimental / supervised: unstable, interaction-heavy, NOT hands-off.** You must stay
      present and validate the result. Do not pick this for an autonomous run.
    - `leave` — leave the demo data exactly as shipped (rebrand-only; skips all store + data work).
      **Valid only if T2 is blank / unchanged.**

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

D3. `only if D1 = generate` The generate inputs (stores/locales/currencies come from Group T):
    - theme (free text), e.g. `women's dresses`
    - product count `(default: ~20)`
    - categories (or let the wizard propose them)
    - attribute set
    - variants? `(default: no)`
    - prices — **one of these two is required**, there's nothing to derive from otherwise:
      a price range per category **per currency**, OR one base-currency range **plus D2's rate table**.
    - **product imagery** — a folder path or URL list. Images are user-supplied, never generated.
    - **CMS / banner imagery** — a *separate* folder path or URL list, for the homepage hero /
      carousel / banner blocks. Asked separately on purpose: one combined answer previously left
      CMS-block authoring with no images at all.
    - content language `(default: native — author directly in your locales, so nothing needs
      translating later)` or `english` for placeholders to translate later.
    - merchant sellers `(default: the shipped marketplace model)` — how many sellers per product and
      their names, or say `single-seller` for a simpler shop. Each additional seller is a distinct
      merchant (not one merchant selling the same product twice in the buy box).

## Group C — Catalog scope  (→ interview §6, `project-data` reduce pass)

C1. `only if D1 = adapt` Does the project sell the whole demo catalog, or only part of it?
    `(default: keep all — most projects keep everything, the demo catalog is a placeholder they
    replace later)`
    If a subset: say what to **keep or remove**, at the level you think in — usually category, e.g.
    `remove: office-supplies, transport` or `keep: heat-recovery`.
    This runs **pre-boot**, so the first boot imports the reduced set (no reset needed).

## Group L — Localization  (→ interview §7, step `translate-content`)

L1. Actually translate a locale's storefront content? `(default: no — every locale stays an English
    copy, flagged as translation debt)`
    Translating is slow and strictly opt-in, and it runs **after** a green boot — it never blocks setup.
    If yes: which locale(s), and scope `glossary` (UI text only) or `catalog` (glossary + product
    content).

## Group Q — Automatic quality checks (CI)  (→ interview §8, step `project-ci-generator`)

Q1. Set up an automatic quality check that runs on every change to your project — code style, static
    analysis and the functional tests — replacing the demo's heavy multi-configuration test rig
    (which exists to protect Spryker's product, not your shop)?
    `(default: set it up)` Options: `set-up` / `skip` / `developer-tunes-it`
Q2. Send check results to a chat channel? `(default: no)`
Q3. `only if Q1 = developer-tunes-it` The technical detail, for a technical answer: which suites to
    keep, version matrix, wipe scope.
    Everything else is **derived, never asked** — the CI platform (from your git remote), the single
    PHP + database version (from your own `deploy.dev.yml` and S1), which checks to keep (fast
    code-quality + functional gating kept, heavy product-QA suites dropped), and cleanup of the demo
    CI files being replaced.

## Group R — Run configuration  (always answer these two)

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
    - **deletions and data-destroying actions** — the CI file wipe, removing demo region dirs and
      dangling manifests, data strip passes, any DB/volume drop, `sudo`. You get the concrete blast
      radius (the file list, the row/column count) and must say go.
    - **human-only prerequisites** — starting Docker/OrbStack, the `/etc/hosts` line, supplying a
      GitHub token. The wizard cannot do these for you.
    - **a step failure** — it stops with guidance rather than pressing on.
    So even a fully-filled questionnaire in `autonomous` mode is **not** a run that never speaks
    again: expect to be consulted on destructive operations and on any data defect the boot surfaces.
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

A realistic "I actually care about a few things" fill is **P1, P2, P3, N1, T2, D1, R1, R2** — identity,
your own code area, your stores, and what happens to the demo catalog.

## Copy-paste answer block

Fill what you care about, delete the rest, hand it over.

**One gotcha if you edit the store table:** a store, country or locale code that is a bare YAML
boolean — `NO` (Norway), `ON` (Ontario), `Y`, `N`, `OFF`, `TRUE`, `FALSE` — must be **quoted**
(`"NO"`), or a YAML parser turns Norway's store code into `false` and it silently breaks downstream.
Quote it here and the wizard keeps it quoted everywhere it lands.

```yaml
P1: acme-shop
P2: acme.local
P3: "#C8102E, #F0B323"
P4:                      # logo path/URL, blank = keep Spryker logo
N1: Acme                 # or Pyz, or `custom`
S1:                      # engine swaps, blank = keep all
S2: [swagger]            # dev services OFF
S4: [static]             # applications OFF
T1: dms
T2: |
  | store | locales | currencies | countries | timezone |
  |---|---|---|---|---|
  | US | en_US | USD | US | America/New_York |
T3: NA
D1: adapt
D2: { USD: 1.08 }
C1: keep all
L1: no
Q1: set-up
Q2: no
R1: autonomous
R2: ack
```
