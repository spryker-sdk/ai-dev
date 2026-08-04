# Interview & state file (wizard §1–§2 — the decision catalog)

The wizard's interview phase: how to ask (AskUserQuestion mechanics), the eight decision sections, and the state-file template the answers are written to. Read this BEFORE the first question; the flow, pre-flight, execution steps, and cross-cutting rules live in `../SKILL.md`.

Ask section by section. Always show the demoshop default and let the developer accept it. Accepting every default is valid.

## How to ask (AskUserQuestion mechanics)

**Conduct the interview with the AskUserQuestion tool — this is what makes it a wizard, not a form.** Present each decision as a structured question with selectable options; do **not** dump a markdown list of defaults and wait for a freeform reply. For each question:
- Put the **default first** as a selectable option (e.g. "Keep default: b2b-demo-marketplace", "Keep `Pyz`").
- List the real alternatives when the answer is a small closed set (DB engine, which apps to disable [multiSelect], store mode, mail engine).
- For **free-text values** (project name, dev domain, hex colors, custom namespace, store codes, currencies, rates): **offer 2–4 concrete PRE-COMPUTED candidates** as options — derived from the repo dir name, the store geography, common hex shades — with the built-in **"Other"** field as the escape hatch, in the **same** step. Be ready to map a prose answer to tokens in a single follow-up (`golden colors`→`#F0B323`; `Swedish, Norwegian and Polish`→`SE/NO/PL`). **NEVER** a yes/no "do you want to customise X?" gate followed by a separate "now type it" prompt, and never a bare "type it in Other" as the only path — it unreliably returns the typed value (real regression: "Custom namespace"/"Type my store set" came back with only the label, or with prose instead of the token — costing exactly the extra turn the inline design exists to avoid).
- **Batch a section's related decisions into one AskUserQuestion call** (it takes up to 4 questions) — e.g. name + domain + colors as one identity screen. A section should be one exchange, not many.

Picking the default option is always a valid answer, for every question.

## The eight decision sections

1. **Project identity** — name (→ composer name, docker namespace, README); local dev domain (e.g. `acme.local`; else it stays `spryker.local`); 1–2 brand colors (full palette derived); **optional custom logo** — ask for a logo file path/URL; if provided, `brand-project` places it and sets the storefront logo config; if not, the shipped Spryker logo stays (flagged as a go-live follow-up). Never fabricate a logo.
2. **Namespace** — keep `Pyz`, or a custom namespace (CamelCase, must not collide with `Pyz`/core).
3. **Services** — the clone already ships every service and application **enabled**; ask what to **turn off or swap**, never what to "enable". Accepting the shipped set as-is is valid. Read the actual `deploy.dev.yml` first — present what is really there, not a memorised list.
   - **Infra engines** (keep unless the project needs otherwise): database (`mariadb` shipped — the Cloud-recommended default; warn on a swap), search (`opensearch` shipped; may swap to Elasticsearch), broker (`rabbitmq`), key-value store + session (`valkey`). Record only swaps. **Show the PHP image tag read-only** (read it from `deploy.dev.yml` `image:`, e.g. `spryker/php:8.4`) — changing it is out of scope for this wizard; if the customer environment pins a different PHP, flag it as a follow-up rather than editing.
   - **Optional dev services** shipped **on** (`mail_catcher`→mailpit, `swagger`, `dashboard`, `redis-gui`, `webdriver`, `scheduler`): ask which to **disable**, and whether to **swap an engine** (e.g. mail catcher `mailpit` → `mailhog`/`mailcatcher`). Default keeps all.
   - **Applications / APIs** — grep `deploy.dev.yml` for EVERY `application:` entry under `groups.*.applications` and offer **each one except the two fixed apps, `backend-gateway` and `backoffice`** (see below). Default keeps all the rest. Three rules:
     - **Never curate to a subset or silently drop "obvious keeps"** — omitting an app from the options removes the developer's choice entirely (real regression: a run offered only `merchant-portal`/`glue`/`glue-backend`, dropping `yves`, `backoffice`, and `static`/Storybook — so Storybook was never a decision and shipped by default).
     - **Each application is its OWN independent toggle — never bundle two into one choice** (e.g. `glue` and `glue-backend` are separate apps, offered separately).
     - **Never offer the two fixed apps** (a deliberate, stated exclusion — NOT the silent curation the rule above forbids): **`backend-gateway`** (the Zed RPC / `HOST_ZED_API` gateway every Yves *and* Glue business call routes through — the one hard boot dependency) and **`backoffice`** (the admin UI). `backoffice` is kept mandatory because the vendored Cypress E2E baseline exercises Back Office admin/checkout flows, and a demoshop-derived project realistically always wants an admin panel — a genuinely admin-headless setup is an advanced manual deviation, not a wizard option. Every OTHER app in the file is an informed choice (a UI/API onto Zed — disabling it doesn't break boot or data import).
     - **The known set** (verify against the real file — it evolves):
       - `yves` — server-rendered storefront; disabling = **headless** (frontend consumes Glue; boot-verify shifts to the API). Like every frontend app, keeping it **costs boot time** — `frontend:yves:build` + Yves `assets:install` + router/twig cache warm run in the install recipe — so a genuinely headless project saves all of that by dropping it. (Frontend/build-heavy apps — `yves`, `merchant-portal`, `static` — each add install-recipe build steps; note the cost when offering them.)
       - `merchant-portal` — marketplace merchant self-service admin; drop it if the project isn't a real marketplace or manages merchants via BackOffice/import (won't be present on a non-marketplace clone).
       - `backoffice` — the BackOffice admin UI; **fixed on, not offered** (see the two-fixed-apps rule above): the E2E baseline needs it and a project always wants the admin panel.
       - `glue` — **Storefront API** (headless/PWA/mobile/frontend consumes it). A **separate, independent** app — disable it on its own.
       - `glue-backend` — **Backend API** (programmatic back-office / management / integrations). Also **separate and independent** — offer it as its own toggle, NOT bundled with `glue`. You can keep either without the other (e.g. a headless storefront wants `glue` only; a management-only integration wants `glue-backend` only). Both route through `backend-gateway`, but that's the shared Zed gateway, not a link between the two APIs.
       - `static` — **Storybook** (component / design-system explorer; endpoint `storybook.<domain>`). Most customer projects don't ship it, and keeping it also costs boot time (`frontend:storybook:build` runs in the install recipe) — so it's a **common disable**. Offer it like every other app; never drop it from the options.
4. **Stores** — **ask stores first; the region is proposed after** (the developer need not know regions up front).
   - Store mode: DMS is the shipped default (keep).
   - Per store: name (DMS rule `^(?!.*_{2})[A-Z][A-Z_]*[A-Z]$`), locales + default, currencies + default, countries, timezone. **Reject two locales sharing a language for one store** (spike V1: same 2-char URL prefix → the second is unreachable).
   - **Region** — *you* propose it from the entered stores: a region is the deploy-file deployment group the stores live in (infra grouping), not something the developer must name up front. Propose a token (default: one region grouping all stores; suggest by geography, e.g. `NA` for US+CA), **reject collisions** with shipped region/store tokens from the inventory (don't reuse `EU`/`US`). Let the developer accept or override. **Scope: this wizard creates exactly ONE new region** — genuinely multi-region infrastructure (separate EU + US deployments) is a manual follow-up on `deploy.dev.multi-region.yml`'s pattern; say so here if the store set implies it, not during define-stores.
5. **Demo data** — choose `data.mode`. **The options depend on the step-4 store decision** (data mode is gated by stores — changing a store *is* adaptation):
   - If step 4 **changed** stores/locales (the usual case), offer three: **`adapt`** (reshape the shipped demo catalog to the project), **`clean`** (no demo catalog — a minimal shop that boots green: empty catalog, working email/tax/payment/shipment config, project stores), or **`generate`** (author a small themed catalog in the project's own vertical — **⚠ experimental / supervised: unstable, interaction-heavy, and NOT hands-off; the developer must stay present and validate the result. Present `adapt`/`clean` as the stable defaults and flag `generate` as a test mode when offering it**).
   - If step 4 **kept the demo stores/locales unchanged**, a fourth option unlocks: **`leave`** (leave the demo data exactly as shipped — a rebrand-only project; skips all store + data transformation). `leave` is valid **only** when nothing about stores/locales changed.
   - **adapt** — collect **only** the currency→rate table (offer bundled defaults; drives price conversion). No other questions: adapt carries the demo data as-is. It only **warns** the developer that store-bound demo activity (orders/carts, tied to the old stores) can't be persisted under the new stores and was removed.
   - **clean** — nothing extra to collect; it's shape-driven from the stores (see `project-data`'s clean strategy). Rate table / catalog reduction do not apply.
   - **generate** — collect (see `project-data`'s generate strategy); stores/locales/currencies come from step 4:
     - **theme** (free text), **product count** (default ~20), **category list** (or let it propose), **attribute set**, **variants?** (default no).
     - **prices** — either a price range per category **per assigned currency**, or one base-currency range **plus a currency→rate table** (same shape as adapt's) to convert the authored prices. One of the two is required — without a rate source, multi-currency prices have nothing to derive from.
     - **two separate image sources** (images are user-supplied, never generated; each a folder path or URL list): **product imagery** (the catalog photos) and, distinctly, **CMS / banner imagery** (homepage hero/carousel/banner blocks). Ask for them separately — a single combined `image_source` answer hid that two different deliverables were expected (CMS-block authoring got no images of its own, and the one folder was copied to `frontend/static/` and never used).
     - **content language** (generate authors *fresh* content, so it can write in-language from the start): author the generated names/descriptions/CMS **directly in the project's locale(s)** (default — free for generated content, avoids re-translating), or in **English placeholders** to translate later via `localize-content`. Generate-only — `adapt` can't author in-language; it must translate shipped demo afterward.
     - **merchant sellers** — this is a marketplace by default, so author merchants and offers as the shipped norm. (A product is still buyable from its own price+stock, so offers aren't a checkout prerequisite — but the default model is a marketplace, not a single-seller shop.) Ask how many sellers per product and their names (default: the shipped marketplace model), or whether the developer wants a simpler single-seller setup. Each additional seller is a distinct merchant — unless explicitly asked, not the same merchant selling a product both directly and via an offer (one merchant twice in the buy box).
   - **leave** — nothing to collect; the demo data (and demo stores) stay as-is.
6. **Catalog scope (adapt mode only — skip for clean/generate/leave; optional, default: keep the whole demo catalog)** — ask whether the project sells the entire demo catalog or only part of it. Most projects keep everything (the demo catalog is a placeholder they'll replace later). If the developer wants a subset now, record what to **keep or remove** at the level they state (usually category — e.g. "remove office-supplies and transport"). This drives `project-data`'s **reduce** strategy, which runs **pre-boot** (so the first boot imports the reduced set — no reset). Default: keep all.
7. **Localization (optional, default off)** — offer to actually translate a project locale's storefront content (via `localize-content`, after boot). **Default: no** — every locale stays an English copy (translation debt, flagged); localizing is slow and strictly opt-in. If yes, record which locale(s) and scope (glossary only, or glossary + catalog content).
8. **CI** — the decisions `project-ci-generator` executes (collected HERE so the post-interview run keeps its no-further-configuration-questions promise; only the destructive wipe itself is confirmed at execution time). Base the options on the pre-flight CI inventory (the jobs/suites actually found in `.github/workflows/`), per that skill's discover-first rule:
   - **Target platform** — keep the current host (default) or port (GitLab, Bitbucket).
   - **Suites to keep** [multiSelect] — offer only the suites found; recommend keeping lightweight gating suites, dropping heavy product-QA suites.
   - **Version matrix** — single version (recommended for a project) or keep the matrix.
   - **Notifications** — drop chat/ticket steps + their secrets (default) or keep.
   - **Wipe scope** — remove unreferenced CI/support files (default) or annotate-in-place, keeping everything.
   The suites decision doubles as the **robot/acceptance-fixture lane decision** other steps read (whether the `b2b_robot` import fixtures are dead weight).

## Confirm & write state (the state-file template)

Summarise every answer. Get explicit confirmation. Then write `.ai-dev/project-setup.md` in this format:

```markdown
---
version: 1
project: { name: acme-shop, dev_domain: acme.local, brand_colors: { primary: "#C8102E", accent: null }, logo: null }   # logo: path/URL to a developer-supplied logo, or null = keep the shipped Spryker logo (flagged)
namespace: { mode: custom, name: Acme }        # or { mode: keep-pyz }
services:                               # deviations from the shipped deploy.dev.yml — omit/empty = keep demoshop defaults
  engines: { search: elasticsearch }    # infra engine swaps (database/search/broker/key_value_store/session)
  dev_services_disabled: [swagger]      # optional dev services shipped ON, turned OFF ([] = keep all)
  dev_services_engine: { mail_catcher: mailhog }   # swap a kept dev service's engine
  applications_disabled: [glue]         # apps/APIs shipped ON, turned OFF ([] = keep all)
store_mode: dms
region: NA                              # proposed from the stores below, developer-confirmed
stores:
  - { name: US, locales: [en_US], default_locale: en_US, currencies: [USD], default_currency: USD, countries: [US], timezone: America/New_York }
  - { name: CA, locales: [en_US, fr_CA], default_locale: en_US, currencies: [CAD], default_currency: CAD, countries: [CA], timezone: America/Toronto }
data: { mode: adapt, rate_table: { USD: 1.08, CAD: 1.47 } }   # adapt: reshape demo to project stores/locales/currencies (rate_table drives price conversion). Demo data carried as-is; store-bound orders/carts removed with a warning. No keep/drop edge policies.
# clean alternative     → data: { mode: clean }                # no demo catalog; nothing else to collect
# generate alternative  → data: { mode: generate, theme: "women's dresses", product_count: 20, categories: [...], attributes: [...], variants: false, price_range: {...}, rate_table: { PLN: 4.3 }, product_image_source: "path/or/urls", cms_image_source: "path/or/urls", content_language: native }   # price_range: per category per currency, OR base-currency ranges + rate_table to convert; product vs CMS/banner imagery are separate deliverables; content_language: native = author directly in the project locale(s) (default; no later translation), english = English placeholders to translate later via localize-content
# leave alternative      → data: { mode: leave }                # only if stores/locales unchanged; rebrand-only, skips store + data transformation
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
| boot-and-verify | pending | |
| localize-content | pending | (optional; only if localize.locales non-empty; runs after boot) |
```
