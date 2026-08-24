# Region token + hardcoded literal sweep

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

**Stripe (and any vendor import carrying store/locale/currency data) — a boot-blocker.** The active `docker.yml` recipe imports `--config=vendor/spryker-eco/stripe/data/import/stripe.yml`, whose `payment_method_store.csv` hardcodes `DE`/`AT` → the boot aborts (`Store not found: DE/AT`).

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

Only the store-assignment file is project-local; nothing is copied from vendor. **Do NOT omit the `payment-method-store` action** — omitting it imports the method but assigns it to no store, so Stripe never appears at checkout. **Expected vendor shape (a starting point, not a permanent truth — if the vendor file's actual shape differs at import time, re-derive from the vendor file in-container rather than forcing these):** the vendor `payment_method.csv` is a single row `payment_method_key=stripe, payment_method_name=Multiple Methods, payment_provider_key=Stripe, is_active=1`; `payment_method_store.csv` has exactly the columns `payment_method_key,store` (vendor ships `stripe,DE` + `stripe,AT`). So the only project-specific edit is swapping the DE/AT store rows for the project stores. (Note: `glossary.csv` ships Stripe labels + OMS state names in **en_US/de_DE only** — non-en/de project locales fall back to English until translated; and actually charging cards needs real Stripe API credentials via env config — a go-live concern, not setup.) **Generalize** the recipe-repoint pattern to any recipe step importing store/locale/currency data from `vendor/**`.

**Per-store `<STORE>.yml` manifests on a renamed dir.** Each shipped store dir contains a per-store `<STORE>.yml` (e.g. `common/DE/DE.yml`). When you rename `common/DE`→`common/PL`, that file rides along as `common/PL/DE.yml` with `source:` paths still pointing at the old `common/DE/…` (now gone) — orphaned and internally broken. Since the project uses the consolidated `full_<REGION>.yml`, **delete these orphaned `<OLD_STORE>.yml` files** (or rename to `<NEW_STORE>.yml` and rewrite their paths if you deliberately keep per-store manifests). A finished project must not carry them with old names/dangling paths.

## Hardcoded store/locale literals (judgment edits)

> **Namespace placement (`namespace.mode = custom`).** When `configure-codebase` has registered `src/<Ns>/` and `PROJECT_NAMESPACES = ['<Ns>','Pyz']` (so `<Ns>` wins resolution), **every** project config-class override in this document must be created in **`src/<Ns>/…`** (extending the Pyz class) — **leave `src/Pyz/` pristine, never edit a Pyz class in place.** This applies to the table below (`StockConfig`, `CheckoutPageConfig`, `DataImportConfig`), **to the store-keyed behaviour classes flagged as a post-boot decision (`SalesPaymentMerchant*` — see that section), and to any further `src/Pyz/…Config.php` the final `absent` sweep turns up.** It is not a per-class list — it is the rule for any `src/Pyz/…` class you would otherwise change. (`SalesPaymentMerchant*` were edited in `src/Pyz` in place while `StockConfig`/`CheckoutPageConfig` were correctly created in `src/<Ns>` — the same run, two different placements.)
> - **CRITICAL — extend the PYZ class when one exists, not the core class.** These Pyz config classes usually carry **other project overrides already** (`StockConfig::isConditionalStockUpdateApplied`, `CheckoutPageConfig::getExcludedPaymentMethodKeys`, `DataImportConfig`'s whole const block + importer config). If `<Ns>\…Config` extends the **core** class, it wins resolution and **silently drops every one of those Pyz overrides**. So: `class <Ns>\…\StockConfig extends \Pyz\…\StockConfig` and override only the one store/region method (document why in a docblock). Extend the **core** class only when there is genuinely no Pyz class to preserve. First check whether a `src/Pyz/…` counterpart exists.
> - What does **NOT** move: `src/SprykerConfig/CodeBucketConfig.php` (the `SprykerConfig` namespace, not a project code namespace) and the `config/Shared/*` files (shared, non-namespaced) — those stay put regardless. When `mode = keep-pyz`, edit `src/Pyz/` as written.

| Spot | Change |
|---|---|
| `src/SprykerConfig/CodeBucketConfig.php::getCodeBuckets()` | **hard boot blocker** — set to the project buckets (region + stores, e.g. `['NA','US','CA']`); drop demo EU/DE/AT. Stays in `SprykerConfig` (never a project namespace) |
| `config/Shared/default_store.php` | fallback `'DE'` → a declared project store (read even in DMS). Shared file — stays put |
| `config/Shared/config_default.php` `STORE_TO_YVES_HOST_MAPPING` + `REGION_TO_YVES_HOST_MAPPING` | map project stores/region to `SPRYKER_YVES_HOST_<REGION>`. Shared — stays put |
| `config/Shared/config_default.php` `CustomerConstants::CUSTOMER_SECURED_PATTERN` | **a silent authorization hole, not a boot blocker.** The shipped regex hardcodes the language alternation to `(en|de)` (plus `/en`\|`/de` path fragments), so on any other project language **nothing matches** and `CustomerPageSecurityPlugin::addAccessRules()` never engages — leaving `customer`, `wishlist`, `shopping-list`, `quote-request`, `comment`, `company`, `multi-cart`, `shared-cart`, `cart`, `checkout`, `cart-reorder`, `order-amendment` **unguarded**. Observed: anonymous `GET /IT/it/customer/overview` → **500** (`getIdCustomer() on null`); `/IT/it/multi-cart` → **200 and renders**; control `/IT/en/customer/overview` → 302 → login (correct). **Rewrite the regex's language alternation AND its `/en`\|`/de` path fragments to the project languages.** Shared file — stays put |
| **Every Yves firewall / access-control regex** — `src/Pyz/Yves/**` + `config/Shared/config_default.php` | same class, reported independently: the Yves access-control patterns hardcode the **shipped locale/store URL prefixes**, so on a project with different locales the anonymous-access patterns stop matching and public endpoints demand login — or unguarded ones stop being guarded. Grep both trees for regex patterns carrying a shipped locale/store prefix and retarget them to the project's prefixes. **`src/<Ns>/` if custom namespace** (per the namespace-placement rule above), else `src/Pyz/`. (Making the regexes locale-agnostic upstream is the cleaner fix and a **demoshop product decision — out of scope here**.) |
| Per-locale **date/time format** config — **both surfaces**: storefront **and** Back Office / Merchant Portal | **a locale is not really added until dates, numbers and currency all follow it.** Observed: the IT storefront rendered `Aug. 18, 2026 17:18` (US format) beside correctly-formatted Italian prices (`34,90 €`), while the Merchant Portal rendered the same timestamp as `18.08.2026` — the two surfaces disagreed with each other *and* with the locale. Whenever the project's locales are (re)defined, set the date/time format for **each** new locale on **both** surfaces. **The exact config keys are not established in this plugin** — discover them in the clone (`grep -rniE "dateformat|date_time_format|datetime.*format" config/ src/Pyz | head`); if you cannot resolve them, name the requirement and write "per-locale date/time format keys (both surfaces)" to `## Required follow-ups` rather than inventing a key name. Cheap at store-definition time, easy to miss forever after — a wrong date format reads as a rendering nit, never as missing config |
| `config/Shared/config_default.php` `TRANSLATION_ZED_FALLBACK_LOCALES` (Zed = Back Office translator fallback, NOT storefront) | add `<project_locale> => ['en_US']` for each project locale. **Keep `en_US`** — the universal source/fallback; the en_US glossary layer depends on it, so never drop it. **`de_DE` here and `getBackofficeUILocales()` are ONE decision, made in ONE pass — not two.** When you apply that override (below), **drop `de_DE` from BOTH in the same pass**; a `de_DE` fallback left standing on a Czech or Spanish project is read as a defect by any expert reader. Either way, **record keep-or-drop as an explicit decision-log entry** — a silent skill default is not a decision. **Shape check first:** confirm against the clone's shipped `config_default.php` that the constant takes a `locale => [fallbacks]` map and **preserve the shipped shape** rather than assuming it. Shared file — stays put |
| `Zed/Stock/StockConfig.php` `getStoreToWarehouseMapping()` | map project stores → warehouse; drop unused DE/AT. **`src/<Ns>/` if custom namespace**, else `src/Pyz/` |
| `Yves/CheckoutPage/CheckoutPageConfig.php` | T&C link locales → project locales. **`src/<Ns>/` if custom namespace**, else `src/Pyz/` |
| `Zed/DataImport/DataImportConfig.php` region fallback | project region. **`src/<Ns>/` if custom namespace**, else `src/Pyz/` |
| `src/Pyz/Zed/User/UserConfig.php::getInstallerUsers()` — the `admin_de@spryker.com` installer user has `'localeName' => 'de_DE'` | a locale literal that survives a locale swap. If `de_DE` is not a project locale: re-point to a project locale or drop the German admin (harmless otherwise — classify, don't blind-fix). **`src/<Ns>/` if custom namespace**, else `src/Pyz/` |

## Back Office interface language — `getBackofficeUILocales()` override
The Back Office **"Interface language"** picker (BO user create/edit form) is driven by `LocaleConfig::getBackofficeUILocales()`, **hard-coded in core to `['en_US','de_DE']`** and decoupled by design from the storefront store locales — Spryker ships BO translations only for English/German, and adding project stores/locales never changes it.

**When the project's store locales exclude BOTH `en_US` and `de_DE`, the override is REQUIRED — it is a blocker, not cosmetic.** The admin can only pick `en_US`/`de_DE`, but the importer wrote localized-attribute rows only in the project's own locales, so `spy_product_(abstract_)localized_attributes` has **zero** rows for either admin locale. `ProductManagement`'s `VariantTable` reaches concretes through an **INNER** join on the admin locale (`useSpyProductLocalizedAttributesQuery()->filterByFkLocale(...)`), so the admin opens a product and sees an **empty** variant/concrete list — Back Office product administration is broken, not merely English-labelled. Any BO screen inner-joining localized attributes on the admin locale has the same exposure.
- **The override:** `src/<Ns>/Zed/Locale/LocaleConfig.php` (custom namespace) or `src/Pyz/Zed/Locale/LocaleConfig.php` (keep-Pyz), extending the core class and overriding `getBackofficeUILocales()` to include the project locales (→ `docker/sdk console cache:class-resolver:build`; hard-refresh the cached form). This is what lets an admin pick a locale that actually has rows.
- **Two different things — keep them separate.** Making the project locales *selectable* is what unblocks entity visibility (the required fix). The BO UI *strings* still render English-fallback for those locales until Zed translation CSVs exist (`src/<Ns>/Zed/Translator/data/…`, the dir `configure-codebase` creates) — that translation work is the genuinely deferrable, optional half.
- **`de_DE` (or `en_US`) left in the picker on a project with no content for it is the same trap** — it renders empty product lists too. When you add the override, drop the unused defaults from the returned set **and from `TRANSLATION_ZED_FALLBACK_LOCALES` (above) in the same pass** — one decision, both files, logged once.
- **When the project keeps `en_US` (or `de_DE`) as a store locale**, the default picker already resolves to a locale that has rows, so the override there is optional — it only adds selectability plus the eventual translation work.

## Leave alone (classic-mode / unused in DMS)
`config/Shared/stores.php` DE/AT templates (DMS sources stores from data import), punchout demo consts. Record but don't edit — not boot-relevant in DMS.

## Flag as a required POST-BOOT decision (store-keyed behaviour, not a boot blocker)
Some Pyz configs key **behaviour** by store name, so after the store rename none of the project stores match and they silently fall through to defaults — a correctness gap on a marketplace project, invisible at boot:
- `src/Pyz/Zed/SalesPaymentMerchant/SalesPaymentMerchantConfig.php` (store-keyed price mode, e.g. `'AT'`) and `src/Pyz/Zed/SalesPaymentMerchantSalesMerchantCommission/SalesPaymentMerchantSalesMerchantCommissionConfig.php` (`'DE'`/`'AT'`/`'US'` → expense types).
Don't silently rename the literals (you can't know the intended per-store mode); **surface them to the developer as a decision** — "what price mode / expense types apply per project store?" — and apply their answer. Not a boot blocker; do not leave silently mismatched.
- **Placement (custom namespace): apply the answer in `src/<Ns>/Zed/…`, NOT by editing `src/Pyz/…` in place** (per the Namespace-placement rule above — this section is included in it). Create `src/<Ns>/Zed/SalesPaymentMerchant/SalesPaymentMerchantConfig.php extends \Pyz\Zed\SalesPaymentMerchant\SalesPaymentMerchantConfig` (and the commission one likewise) and **redefine the store-keyed `const`** with the project stores — `static::` late static binding makes the override win, exactly like `StockConfig` overrides a method. Leave the `src/Pyz/` classes pristine. (`keep-pyz` mode: edit `src/Pyz/` directly.)

## Final sweep
`validate.php absent` over `config/` + `src/Pyz` for the old store/locale tokens (**directories are recursed** by `absent` — see spryker-import-tools); classify each remaining hit matters/harmless. Known-harmless: JSON-schema example text, classic `stores.php`. **Also sweep `codeception.yml`** — its `coverage.c3_url` carries a shipped demo literal (`http://backoffice.de.suite.local` — `suite.local` domain + `de` store this project won't have); rewrite it to the project domain/store or flag it (test-tooling, not a boot blocker).

### Second pass — PATTERN-shaped, explicitly distinct from the list-shaped pass above
The pass above hunts **`xx_XX` locale tokens** (`de_DE`, `en_US`) and store names. It structurally **cannot** find a **bare** language code inside a regex — which is exactly why `CUSTOMER_SECURED_PATTERN` survives every run of it: a `de_DE`-shaped search never matches an `(en|de)` alternation. So run a second grep for the **bare outgoing language codes**:
```bash
grep -nE "\((en|de)\||\|(en|de)\)|/en/|/de/|'(en|de)'" config/Shared/config_default.php
grep -rnE "\((en|de)\||\|(en|de)\)|/en/|/de/" src/Pyz/Yves src/<Ns>/Yves
```
**The `en_US`-is-a-keep rule does NOT exempt a bare `en` inside a regex.** `en_US` is kept as a *locale*; an `(en|de)` alternation in a firewall pattern is a hardcoded demo **URL prefix** and must be retargeted. Classify every hit; zero unclassified hits closes this pass.

**A *partially* correct pattern is the worst case.** Because the shipped `en` locale usually stays active, the firewall keeps working on `/en/` and the breakage is **invisible** — green run, correct `/en/` control, unguarded `/it/`. Test the **new** locale specifically, or you have not tested this at all.

## Generated artifacts (POST-BOOT obligation)
Every sweep above is scoped to `config/` + `src/Pyz`. **`src/Generated/**` and `data/cache/**` are gitignored build output that does not exist pre-boot**, so they appear in no sweep and no gate — and a stale artifact keyed by the OLD region/store/bucket token survives a fully green boot. Observed: `validationEU.cache` outlived a region rename and **500'd every API Platform request** after a green boot.

**After the first boot** — this is the delivery point, not "eventually" — list both trees for filenames carrying the old token, and for each hit **run its regenerating command AND DELETE the stale sibling** (regeneration writes the new name; it does not remove the old one):
```bash
find src/Generated data/cache -iname "*<OLD_REGION>*" -o -iname "*<OLD_STORE>*" -o -iname "*<OLD_BUCKET>*"
# must return nothing at close-out
```
Known members and their commands: `Glue/Validator/validation<BUCKET>.cache` → `rest-api:build-request-validation-cache`; `resolvableClassCache*.php` → `cache:class-resolver:build`; `*/Twig/codeBucket/*.pathCache` → `twig:cache:warmer` (the pathCache mechanic is documented in `../../spryker-runtime/SKILL.md` — read it there, don't re-derive it). **Discover by token match, not by this list** — it is a class of defect, not three files.
