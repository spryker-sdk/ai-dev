# Region token + hardcoded literal sweep (verified: playbook Step 3 + Step 6, boot failures #1)

When the project's region/stores differ from the demoshop's (EU/DE/AT), the region token and store/locale literals thread through more places than the ACs first assumed. Miss one and boot fails — `CodeBucketConfig` is a hard fatal *before any DB work*. Do these as anchored, formatting-preserving edits.

> **The spots below are a known set, not exhaustive.** They are the literals found in the demoshop at scan time. After editing them, run the `absent` sweep over `config/` + `src/Pyz` for the old store/locale tokens to catch any others that moved or were added — then classify each hit (some are intentional keeps; see "Leave alone"). Don't assume this list is complete.

## Region token — full surface (old token e.g. `EU` → new e.g. `NA`)
`deploy.dev.yml` only (project dev deploy; other deploy files are out of scope):
- `SPRYKER_YVES_HOST_<REGION>` env name + value (host also carries the dev domain — see brand-project)
- `regions.<REGION>` key, `groups.<REGION>` key, every endpoint `region:` entry, all endpoint hosts
- application group names (`yves_<region-lc>`, `glue_<region-lc>`, `boffice_<region-lc>`, …)
- `database:` name (`<region-lc>-docker`), broker namespace, search namespace, `docker.testing.region`

> **In the `docker:` block, `docker.testing.region` is the ONLY thing you may change** — a single-line, anchored value edit. **Never touch `docker.mount`** (the `native` / `docker-sync` / `mutagen` platform blocks): those are the per-OS file-sync strategy, not region config. macOS **must** stay on `mutagen` (native bind-mounts are unusably slow for Spryker on Mac); moving macOS to `native` or deleting `mutagen` breaks/cripples the Mac dev environment. Leave every other key in `docker:` exactly as shipped.

Install recipes: `SPRYKER_CURRENT_REGION` in the four active recipes (`destructive.yml`, `dynamic-store.yml`, `pre-deploy.yml`, `production.yml`); **rename** the primary shipped region dir to `config/install/<REGION>/` (`mv`, not `cp -r` — in-place, no duplicate EU/US/<REGION> clutter), rewrite its tokens, and **remove the other shipped region dir(s)** (they point at demo stores that no longer exist) — repointing every recipe region reference to `<REGION>`; repoint the `stripe` demodata step in `config/install/{docker,destructive,production}.yml` to `data/import/common/stripe/stripe.yml` (project-local stripe copy in an existing bucket — see minimal-baseline; vendor is read-only).

Scope note: **other deploy files** (`deploy.dev.multi-region.yml`, the aws templates) are out of scope and keep their `EU`/`US` tokens — only the project's `deploy.dev.yml` + the active recipes change. Within `config/install/` the primary region dir is **renamed** and the other shipped region dir(s) **removed**, per the paragraph above (they are not "left untouched"). And note the recipes' import paths: the rewritten `SPRYKER_CURRENT_REGION` makes them reference `data/import/local/store_<REGION>.yml` / `full_<REGION>.yml` — `define-stores` renames the store-definition manifest; the catalog manifest belongs to `project-data`. **`config/install/docker.yml` itself needs no region-token edit** — it derives the region from the deploy file's `SPRYKER_REGION` at runtime; only the stripe repoint (below) touches it.

**Stripe (and any vendor import carrying store/locale/currency data) — boot-blocker #2.** The active `docker.yml` recipe imports `--config=vendor/spryker-eco/stripe/data/import/stripe.yml`, whose `payment_method_store.csv` hardcodes `DE`/`AT` → the boot aborts (`Store not found: DE/AT`).

**Critical: `vendor/` is NOT on disk on a fresh clone** — composer materializes it *inside the container during boot*. So the vendor stripe files don't exist pre-boot. **Do NOT copy the vendor files, and NEVER hunt the filesystem / composer cache for them** (`find /`, searching `~/.composer`, etc. — dead ends and rabbit holes). The only broken thing is the **store assignment**, and it can be overridden without any vendor content:

Pre-boot, on the host, edit the recipe:
1. Author `data/import/common/stripe/payment_method_store.csv` — header `payment_method_key,store`, one row per project store, all with key `stripe`:
   ```
   payment_method_key,store
   stripe,<STORE1>
   stripe,<STORE2>
   …
   ```
   (This is the per-store activation — Stripe is only offered at checkout in the stores listed here.)
2. Author `data/import/common/stripe/stripe.yml` that imports the store-agnostic entities **straight from the vendor paths** (`vendor/spryker-eco/stripe/data/import/payment_method.csv`, `…/glossary.csv` — these resolve *in-container at import time*, when vendor exists) and imports `payment-method-store` from the local file in step 1. Skeleton:
   ```yaml
   version: 0
   actions:
     - data_entity: payment-method
       source: vendor/spryker-eco/stripe/data/import/payment_method.csv
     - data_entity: payment-method-store
       source: data/import/common/stripe/payment_method_store.csv
     - data_entity: glossary
       source: vendor/spryker-eco/stripe/data/import/glossary.csv
   ```
3. Repoint the `stripe` step in `config/install/{docker,destructive,production}.yml` at the local `stripe.yml`.

Only the store-assignment file is project-local; nothing is copied from vendor. **Do NOT omit the `payment-method-store` action** — omitting it (as the first run did, before the key was known) imports the method but assigns it to no store, so Stripe never appears at checkout. **Captured values (from a booted env, 2026-07-27 — a dated starting point, not a permanent truth; if the vendor file's actual shape differs at import time, re-derive from the vendor file in-container rather than forcing these):** the vendor `payment_method.csv` is a single row `payment_method_key=stripe, payment_method_name=Multiple Methods, payment_provider_key=Stripe, is_active=1`; `payment_method_store.csv` has exactly the columns `payment_method_key,store` (vendor ships `stripe,DE` + `stripe,AT`). So the only project-specific edit is swapping the DE/AT store rows for the project stores. (Note: `glossary.csv` ships Stripe labels + OMS state names in **en_US/de_DE only** — non-en/de project locales fall back to English until translated; and actually charging cards needs real Stripe API credentials via env config — a go-live concern, not setup.) **Generalize** the recipe-repoint pattern to any recipe step importing store/locale/currency data from `vendor/**`.

**Per-store `<STORE>.yml` manifests on a renamed dir (leftover #8).** Each shipped store dir contains a per-store `<STORE>.yml` (e.g. `common/DE/DE.yml`). When you rename `common/DE`→`common/PL`, that file rides along as `common/PL/DE.yml` with `source:` paths still pointing at the old `common/DE/…` (now gone) — orphaned and internally broken. Since the project uses the consolidated `full_<REGION>.yml`, **delete these orphaned `<OLD_STORE>.yml` files** (or rename to `<NEW_STORE>.yml` and rewrite their paths if you deliberately keep per-store manifests). A finished project must not carry them with old names/dangling paths.

## Hardcoded store/locale literals (judgment edits)

> **Namespace placement (`namespace.mode = custom`).** When `configure-codebase` has registered `src/<Ns>/` and `PROJECT_NAMESPACES = ['<Ns>','Pyz']` (so `<Ns>` wins resolution), **every** project config-class override in this document must be created in **`src/<Ns>/…`** (extending the Pyz class) — **leave `src/Pyz/` pristine, never edit a Pyz class in place.** This applies to the table below (`StockConfig`, `CheckoutPageConfig`, `DataImportConfig`), **to the store-keyed behaviour classes flagged as a post-boot decision (`SalesPaymentMerchant*` — see that section), and to any further `src/Pyz/…Config.php` the final `absent` sweep turns up.** It is not a per-class list — it is the rule for any `src/Pyz/…` class you would otherwise change. (Real regression: `SalesPaymentMerchant*` were edited in `src/Pyz` in place while `StockConfig`/`CheckoutPageConfig` were correctly created in `src/<Ns>` — the same run, two different placements.)
> - **CRITICAL — extend the PYZ class when one exists, not the core class.** These Pyz config classes usually carry **other project overrides already** (real regression: `StockConfig::isConditionalStockUpdateApplied`, `CheckoutPageConfig::getExcludedPaymentMethodKeys`, `DataImportConfig`'s whole const block + importer config). If `<Ns>\…Config` extends the **core** class, it wins resolution and **silently drops every one of those Pyz overrides**. So: `class <Ns>\…\StockConfig extends \Pyz\…\StockConfig` and override only the one store/region method (document why in a docblock). Extend the **core** class only when there is genuinely no Pyz class to preserve. First check whether a `src/Pyz/…` counterpart exists.
> - What does **NOT** move: `src/SprykerConfig/CodeBucketConfig.php` (the `SprykerConfig` namespace, not a project code namespace) and the `config/Shared/*` files (shared, non-namespaced) — those stay put regardless. When `mode = keep-pyz`, edit `src/Pyz/` as written.

| Spot | Change |
|---|---|
| `src/SprykerConfig/CodeBucketConfig.php::getCodeBuckets()` | **hard boot blocker** — set to the project buckets (region + stores, e.g. `['NA','US','CA']`); drop demo EU/DE/AT. Stays in `SprykerConfig` (never a project namespace) |
| `config/Shared/default_store.php` | fallback `'DE'` → a declared project store (read even in DMS). Shared file — stays put |
| `config/Shared/config_default.php` `STORE_TO_YVES_HOST_MAPPING` + `REGION_TO_YVES_HOST_MAPPING` | map project stores/region to `SPRYKER_YVES_HOST_<REGION>`. Shared — stays put |
| `config/Shared/config_default.php` `TRANSLATION_ZED_FALLBACK_LOCALES` (Zed = Back Office translator fallback, NOT storefront) | add `<project_locale> => ['en_US']` for each project locale. **Keep `en_US`** (universal source/fallback). **Keep `de_DE` too** — German is one of the two Back-Office UI languages Spryker ships translations for (see the BO interface-language note above); its `en_US` fallback is the legitimate German-BO fallback, inert if German BO is unused, and it does NOT touch the storefront or boot. **Only remove the `de_DE` line if the project also drops German from the Back Office** (i.e. overrides `getBackofficeUILocales()` to exclude it) — otherwise leaving it is the safe default. Shared file — stays put |
| `Zed/Stock/StockConfig.php` `getStoreToWarehouseMapping()` | map project stores → warehouse; drop unused DE/AT. **`src/<Ns>/` if custom namespace**, else `src/Pyz/` |
| `Yves/CheckoutPage/CheckoutPageConfig.php` | T&C link locales → project locales. **`src/<Ns>/` if custom namespace**, else `src/Pyz/` |
| `Zed/DataImport/DataImportConfig.php` region fallback | project region. **`src/<Ns>/` if custom namespace**, else `src/Pyz/` |
| `src/Pyz/Zed/User/UserConfig.php::getInstallerUsers()` — the `admin_de@spryker.com` installer user has `'localeName' => 'de_DE'` | a locale literal that survives a locale swap. If `de_DE` is not a project locale: re-point to a project locale or drop the German admin (harmless otherwise — classify, don't blind-fix). **`src/<Ns>/` if custom namespace**, else `src/Pyz/` |

## Optional project override — Back Office interface language (NOT a boot concern; offer, don't force)
The Back Office **"Interface language"** picker (BO user create/edit form) is driven by `LocaleConfig::getBackofficeUILocales()`, **hard-coded in core to `['en_US','de_DE']`** and **decoupled by design** from the storefront store locales — it is the set of languages the *admin UI itself* is offered in, and Spryker ships BO translations only for English/German. **Adding project stores/locales never changes it** — so a project with `cs_CZ`/`pl_PL`/`uk_UA` stores still shows only en/de in that dropdown. This is expected, not a defect, and does **not** affect boot or the storefront.
- **If the developer wants their project locales selectable as BO interface language:** add a `LocaleConfig` override — `src/<Ns>/Zed/Locale/LocaleConfig.php` (custom namespace) or `src/Pyz/Zed/Locale/LocaleConfig.php` (keep-Pyz) — extending the core class and overriding `getBackofficeUILocales()` to include the project locales. (Class-resolver override → `docker/sdk console cache:class-resolver:build`; hard-refresh the cached form.)
- **Honest caveat to state when offering it:** this only makes the locales *selectable*. Because no BO translations ship for them, picking one renders the admin with **English-fallback labels**. Actually translating the Back Office UI is a separate effort — Zed translation CSVs under `src/<Ns>/Zed/Translator/data/…` (the dir `configure-codebase` creates), distinct from the storefront glossary.
- **Default: flag it as an optional follow-up in the close summary; do not apply it automatically** (selectable-but-untranslated is a half-feature the developer should choose).

## Leave alone (classic-mode / unused in DMS)
`config/Shared/stores.php` DE/AT templates (DMS sources stores from data import), punchout demo consts. Record but don't edit — not boot-relevant in DMS.

## Flag as a required POST-BOOT decision (store-keyed behaviour, not a boot blocker)
Some Pyz configs key **behaviour** by store name, so after the store rename none of the project stores match and they silently fall through to defaults — a correctness gap on a marketplace project, invisible at boot:
- `src/Pyz/Zed/SalesPaymentMerchant/SalesPaymentMerchantConfig.php` (store-keyed price mode, e.g. `'AT'`) and `src/Pyz/Zed/SalesPaymentMerchantSalesMerchantCommission/SalesPaymentMerchantSalesMerchantCommissionConfig.php` (`'DE'`/`'AT'`/`'US'` → expense types).
Don't silently rename the literals (you can't know the intended per-store mode); **surface them to the developer as a decision** — "what price mode / expense types apply per project store?" — and apply their answer. Not a boot blocker; do not leave silently mismatched.
- **Placement (custom namespace): apply the answer in `src/<Ns>/Zed/…`, NOT by editing `src/Pyz/…` in place** (per the Namespace-placement rule above — this section is included in it). Create `src/<Ns>/Zed/SalesPaymentMerchant/SalesPaymentMerchantConfig.php extends \Pyz\Zed\SalesPaymentMerchant\SalesPaymentMerchantConfig` (and the commission one likewise) and **redefine the store-keyed `const`** with the project stores — `static::` late static binding makes the override win, exactly like `StockConfig` overrides a method. Leave the `src/Pyz/` classes pristine. (`keep-pyz` mode: edit `src/Pyz/` directly.)

## Final sweep
`validate.php absent` over `config/` + `src/Pyz` for the old store/locale tokens (**directories are recursed** by `absent` — see spryker-import-tools); classify each remaining hit matters/harmless. Known-harmless: JSON-schema example text, classic `stores.php`. **Also sweep `codeception.yml`** — its `coverage.c3_url` carries a shipped demo literal (`http://backoffice.de.suite.local` — `suite.local` domain + `de` store this project won't have); rewrite it to the project domain/store or flag it (test-tooling, not a boot blocker).
