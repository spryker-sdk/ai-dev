# Known traps & failure-signature triage (the whole run)

The single catalog of failure modes across every step. Each skill keeps a one-line **trigger** inline at its decision point (so it fires on a standalone invocation too); the **full triage + fix lives here**, and the cross-cutting traps live here once instead of being restated per skill. Format: **signature → cause → fix.** Grouped by area.

## Cross-cutting (any step)

- **Docker VOLUME / namespace collision.** `docker/sdk up` drops and recreates the DB but **REUSES named volumes**; the deploy `namespace:` derives from the project name, so a prior run's `<namespace>_*` volumes leave a fresh DB beside stale KV/search/broker read models — every signal reads correct while carrying foreign data (a severe false green: reversed store IDs, stores that don't exist in `spy_store`). Pre-flight checks `docker volume ls`. **Ranked fix (never force a rename):** (1) developer removes their own confirmed-stale volumes (`docker volume rm <namespace>_*`); (2) override only the deploy `namespace:` token; (3) a different project name. All three beat `clean-data`/`reset`.
- **mutagen daemon down after a container restart** (`up`/`up --assets`/`up --build`/`restart`) → host edits silently never reach the container, invalidating every verification in that window (green console output run against code that isn't there). `mutagen sync list` must show the project session `Connected: Yes` on both alpha and beta; running the command also restarts a dead daemon. Verify a file's presence in the CONTAINER (`docker exec <ns>_cli_1 ls /data/<path>`), not on the host.
- **Diagnose your own delta first.** When something that worked in the fresh demoshop breaks after the transformation, your own data/config change is the prime suspect — NOT vendor/core. Diff the delta (`git diff -- data/import config src`) and re-verify completeness (`preflight`, `refs`, per-store×locale counts) before opening a vendor file. "It worked before my changes" is ground truth.
- **Destructive / irreversible actions are gated in BOTH run modes.** Deleting files/dirs (`rm`, `git clean`), removing rows/columns in place (`csv delete`/`filter`/`drop-columns --in-place` — these don't prompt at the OS level), any DB/volume drop (`reset`/`clean-data`), `sudo`, or publishing outside the clone: announce the blast radius, get an explicit yes first. Autonomous performs and logs reversible edits itself, never a deletion/wipe/`sudo` unattended.
- **Never mutate the developer's Docker/host env to "help"** (memory/CPU limits, `docker system prune`, `docker volume rm`, daemon restart) — recommend, never do. Never edit project config (`deploy.dev.yml`, `docker.mount`/`mutagen`, recipes) to force a boot past a TTY/mount/timeout limit; switching macOS off `mutagen` cripples the Mac dev env.

## Boot — flow gotchas

- **The shipped demoshop is NOT preflight-clean** — it carries pre-existing quirks (URL dups "not yours to fix", tax-name mismatches) that read as self-inflicted damage. Capture a `preflight` baseline on the untouched clone and gate only NEW findings against it.
- **Demo-data-once gate.** Demo data loads only into a table-less DB; after a partial/aborted boot, a plain re-`up` logs `[LOADED]` and **silently skips the entire demodata section**. Verify from the log that the section actually ran; if not, re-init with `clean-data` (gated) — never a plain re-`up`. First iterate a data-import error with `data:import -c` before spending a `clean-data` re-boot.
- **Don't arm a `tail -f | grep` monitor on `boot.log`** — `script` fills it with BuildKit ANSI redraw frames, so a filtered tail fires dozens of useless notifications and gets rate-limited. Wait for the boot's own completion re-invocation.

## Boot aborts — environment limits (the developer acts; "run plain `docker/sdk up`" fixes none)

| Signature | Cause | Fix (developer's, not yours) |
|---|---|---|
| `port is already allocated` / `address already in use`, minute 0 | another project holds 80/443 | developer stops those containers (pre-flight probes this) |
| composer death ~min 40 — `Could not authenticate against github.com`, `API rate limit exceeded`, `401`/`403` | missing/exhausted composer auth token | developer adds the token (pre-flight probes this) |
| `no space left on device` | disk full | developer frees space — never prune for them |
| exit 137 / a service repeatedly restarting | OOM | recommend more Docker memory — never change their settings |

## Boot aborts — build phase

- **`Cannot find module '<x>'` during any `frontend:*:build`** → `node_modules` never populated; the shipped `package-lock.json` can be out of sync (`npm ci` fails `Missing: <pkg> from lock file`). Run `npm ci` directly in the `cli` container to see the real error, then `npm install` to repair the lock — a dependency-manifest fix, not a project-config edit. (A demoshop shipping a stale lockfile can't `npm ci` out of the box — worth reporting upstream.)
- **A recipe step `cmd | tail -N` cannot fail the install** — its exit status is `tail`'s and the error text is discarded. When an abort surfaces in a build step, check the *preceding* step for pipe-masking; re-run it without the pipe. Durable recipe fix: `set -o pipefail` or drop the `| tail`.

## Boot aborts — data import

- **The named importer is usually NOT the culprit — an EARLIER one is.** A `… on null` / `not found` abort surfaces where a downstream writer dereferences a missing row while the real defect was an earlier importer loading 0 rows silently. Diagnose with a single-importer run: `docker/sdk console data:import <importer> --config=<config>` (prints the per-row reason the install log swallows).
- **Grep the real strings.** Success = `Overall Import status: OK` + no `Aborted install`; failure = `Product with SKU … not found` / `not found in permanent storage` / `Overall Import status:*Failed` / `Aborted` / `SQLSTATE`. One substring is a false green.
- **Empty `message_glossary_key` is not an exemption** — Sales-Order-Threshold derives its key from type+store+currency (`sales-order-threshold.<type>.<store_lc>.<cur_lc>.message`) and looks it up unconditionally; a miss throws `MissingTranslationException` at add-to-cart. The key needs a translation in **every project locale of the store** (the value is per-locale). `merchant-relationship-sales-order-threshold` is **auto-generated — never seed those**. Pre-boot gate: `validate.php threshold-glossary <manifest> --locales <project locales>`.
- **`spy_url` is globally unique across every entity** — one un-rewritten `url.<locale>` (classic: `merchant.csv`) aborts the 30–60-min install. Run `unique` on every url-family file discovered by a `columns --plain` scan, not a fixed list.
- **A shared-bucket file with a `store` column** (e.g. `common/common/sales_order.csv` with `store=DE`) → `Store not found` at import even though the per-store dirs were remapped. Scan ALL of `data/import/**` for store columns, not just the per-store dirs.
- **A `locale-store` row for a locale absent from base `locale.csv`** → `Locale not found`. Add exotic project locales to the base locale set first.

## Post-boot — verification false signals & probe traps

- **`rabbitmqctl list_queues` against the default `/` vhost returns an empty list** = a false "drained". Target the project vhost: `-p <broker-namespace>`.
- **A small, STABLE `publish.*.error` count** is usually a benign duplicate-key publish race (two listeners insert the same `*_page_search` row), not a failure — verify the underlying rows exist; only worry if the count grows across reboots.
- **An aggregate total hides a broken slice.** A store with a full `product_abstract_store` count can still have one locale at **zero** localized rows (skipped-locale) or a few products with no price (net-only seed). Assert the full grid — per store × each locale, per store × product — and name the grid you checked (`<stores> × <locales> × <products>`), never a total.
- **URL contract is `/<STORE>/<lang>/…`.** A bare `/<lang>/…` silently falls back to the default store+locale → convincing false negatives ("store unreachable", "wrong `<html lang>`"). Always probe with the store prefix.
- **Glue selects the store from the `Store` request header (or `?_store=<STORE>`)** — probe each store with `-H 'Store: <STORE>'` on the single host; no per-store hosts needed. A same-currency-for-every-store result means a missing/wrong `Store` header or a stale read model, not a host limit. Source: `vendor/spryker/stores-api/.../StoreResolver.php` (`HEADER_STORE_NAME='Store'`).
- **ES `_cat/indices docs.count` lags the segment merge** → false "index nearly empty". Assert search health with `/<index>/_count` or `/_search`, never `_cat`.
- **KV `db 0` is empty** because the store is namespaced into a numbered Redis DB (often `db 1`) → a false "missing data". Query the namespaced index; `valkey-cli` lives in the KV container, not `cli`.
- **add-to-cart curl false-fails** for harness reasons, not project defects: the action is guest-restricted (log in first); `login_check` 500s without a `Referer`; company users 1–2 hold no role ("forbidden"); the URL needs the **CONCRETE** sku; the CSRF field is namespaced (`add_to_cart_form[_token]`) but payload fields are plain; a **separate** fetch of the redirect target destroys the flash message. Follow the recipe, `-L` on one cookie jar, read the flash first, and use anchored selectors (`kr` matches "bac**kr**est").

## Post-boot — "green but empty" (every server signal reads correct, the page/feature is empty)

- **`src/Generated/Shared/DataBuilder` empty after a green boot** → `cy:run` `/dynamic-fixtures` 500 `ClassNotFoundError … Generated\Shared\DataBuilder\*`. Fix: `docker/sdk console transfer:databuilder:generate`. Not a data or Cypress-config defect.
- **BO product list empty on a project with no en_US/de_DE store locale** → the admin locale has no localized-attribute rows and `VariantTable` inner-joins on it → the `getBackofficeUILocales()` override is required.
- **search returns 0 docs while import/queues/DB all read green** → missing `product-approval-status` → the `page_product_abstract` publisher writes nothing. Assert per-store `*_page` `/_count` > 0 as a named gate.
- **Theming/logo green-but-empty** (empty `:root { }`, a logo that renders at 0×0, BO on the Spryker teal) → see **Branding & theming**.
- **A rendered widget is NOT evidence its backing data imported** — a selector (e.g. packaging-unit) renders with zero backing data. Confirm entities by row count, never by the DOM.

## Post-boot — read-model / recovery / iteration ladder

- **A direct SQL mutation orphans the read model.** SQL `DELETE`/`UPDATE` bypasses the event-behaviour that queues publish events, so `publish:trigger-events` does NOT rebuild the stale storage — only a DB drop + re-import restores coherence. `publish:trigger-events` is a recovery tool, not a routine resync.
- **Rebuild the RIGHT resource** — `publish:trigger-events -r price_product_abstract` for price KV, NOT `product_abstract`.
- **Not every importer upserts.** `discount-amount` and `cms-block` are INSERT-only; re-importing existing rows fails `Unable to execute INSERT …`. A unique-constraint INSERT error on an entity you didn't add rows to = insert-only → needs a `reset`, not a CSV fix. **A value change to an already-imported row duplicates** (`spy_price_product_store` has no unique key on price_product×store×currency) → also needs a `reset`.
- **`docker/sdk reset` needs a TTY** — a plain background shell dies `ERROR: failed to get console` at the rebuild, AFTER already wiping the data. Run it via `script -q .ai-dev/reset.log docker/sdk reset` in background, and confirm what `reset` does in this SDK (light re-import vs full teardown) first.
- **A post-boot `.scss`/`.ts` change** re-applies with `docker/sdk up --assets`, NOT `console frontend:*:build` (dies `Cannot find module` — `node_modules` isn't in the running `cli`). Never `npm install` in `cli` — mutagen pushes `node_modules` onto the host sync. Twig-only changes need nothing.
- **A new project class override, strict order:** write file → confirm it exists in the container → `cache:class-resolver:build` → confirm the FQCN is in the resolver cache. Rebuilding the cache before the mutagen sync lands bakes a cache WITHOUT the class.

## Branding & theming (brand-project)

- **`default_value` only renders after `configuration:sync` runs against an *initialized* DB.** The stock recipe runs `configuration-sync` in the `build` section, before `setup:init-db`, so it processes nothing there — empty `:root { }` in the rendered storefront is the tell; re-run `configuration:sync` post-init. (`default_value` in `data/configuration/*.yml` is the load-bearing mechanism — not a DB-seeded value.)
- **`configuration:sync` aborts: `Schema validation failed … Setting "<key>" is missing required "name" field`** → a project override wrote a *partial* setting definition. Mirror the vendor tab/group/setting **in full** (every required field + the parent tab/group), changing only `default_value`. Applies to the storefront `logos` tab AND the BO/MP ymls.
- **BO/MP ship on the Spryker teal even though you set colours** → the base clone ships **no** project-level `gui`/`zed_ui` theming yml, so "create-if-absent" reads as a satisfied no-op. This step must **always create** `data/configuration/gui.configuration.yml` (`bo_main_color`) and `zed_ui.configuration.yml` (`spy_primary_color`), mirroring the vendor tab in full. `configuration_value.csv` rows are **not** a substitute — the importer stores a blank global-scope `scope_identifier` as `''` while the reader matches `IS NULL`, so imported global values never resolve. Verify the **rendered** value (`curl <bo-host>/security-gui/login | grep -A6 ':root'`), not a DB row.
- **Storefront logo renders at 0×0** → the theme's configured-logo branch in `logo.twig` emits a bare `<img>` with no class; only the SVG-icon fallback is sized. Fix: add a `logo__image` class + explicit `width`/`height` in `logo.scss` (an `.scss` change → `docker/sdk up --assets`). The verify gate must assert `getBoundingClientRect > 0`, not just a 200 asset.
- **Back Office shows no logo at all** → `backoffice_logo_url`/`merchant_portal_logo` have no committable served-asset path (`frontend/static/` is Yves-only; the BO/MP asset dirs are gitignored + rebuilt), and `.zed-logo` has no CSS fallback. Set the value as a **base64 `data:` URI** in the yml. **Match the backdrop:** the BO login + sidenav are dark → supply a **reversed** lockup; MP chrome is light → standard lockup. Known vendor gap: `.zed-logo-sm` (collapsed sidebar) reads `--zed-spryker-logo-small-url`, which no setting sets — not brandable this way.
- **Only the horizontal lockup is wirable on a stock clone** (`yves_logo_url` + the BO/MP settings). A favicon/app-tile variant has no project-level `<head>` template — a post-boot Yves head-template override, not a config value; mark it rather than silently producing-and-forgetting it.
- **Contrast for text/icon roles is not a fixed darken-%** — a light/warm primary (`#F0B323` → `darken 20%` = 2.9:1) fails WCAG AA. Darken `text_brand`/`icon_brand` until ≥ 4.5:1 on white; if the brand colour can't carry a white button label, flag it.

## Codebase & tests (configure-codebase)

- **`StoreNotFoundException: Store with name "DE" not found`, deep inside a plugin (e.g. the tax calculator) during PHP suites, before any project code runs** → the core `StoreDependencyHelper` hardcodes `DEFAULT_STORE='DE'`. Project override (DB-resolved) + repoint every suite `codeception.yml`.
- **PHP suites go green but exercise Pyz config, not your `src/<Ns>` overrides** → the suite's `projectNamespaces: ['Pyz']` overwrites the runtime `PROJECT_NAMESPACES`. Sweep every `projectNamespaces:` in `tests/` and prepend `<Ns>`. Nothing fails; the values under test are just the demoshop's.
- **`config/Shared/config_default-*.php` env overlays re-assign `PROJECT_NAMESPACES = ['Pyz']`** in exactly the docker.dev/ci boot env → registration silently reverts there. Patch every overlay, not just the base file.
- **A `src/<Ns>` override extending the CORE class (when a Pyz counterpart exists) silently drops the Pyz overrides** → extend the **Pyz** class, not core. Never edit a `src/Pyz` class in place when a custom namespace is set.
- **CI bare-runner store trap:** on a non-DE/AT/US DMS project the kept static-analysis / `transfer:generate` jobs fatal `Missing setup for store: <STORE>` on the runner (no docker, `stores.php` fallback) → add `SPRYKER_DYNAMIC_STORE_MODE: true` to those jobs' `env:` (sibling of the codeception `DE` trap).
- **`npm run yves:lint` (CI `js-validation`) flips red post-boot after the eslint Yves-TS glob fix** → the fix exposes a pre-existing Pyz backlog. Record it as a post-boot gate with a named rollback (revert that one glob line); can't be validated pre-boot (no `node_modules`).
- **`tsconfig.yves.json` `compilerOptions.paths` aliases** — do NOT prepend `src/<Ns>`; the build resolves each alias to its first entry, so with an empty `src/<Ns>` skeleton `frontend:yves:build` aborts. Leave them Pyz; wire the namespace via `settings.js` dirs.
- **Glue API Platform `sourceDirectories([… 'src/Pyz' …])` in 3 config files** → `src/<Ns>` API resources are invisible until `<Ns>` is appended in all three.

## Stores & region (define-stores)

- **A store code in the YAML-boolean set (`NO`, `yes`, `on`, `off`, …) behaves as `false`/missing** → written bare, a YAML 1.1 parser coerces it. Emit such tokens **quoted** everywhere they land in YAML (workflow env, deploy, recipes, store manifests, Cypress config).
- **Boot aborts `Store not found: DE/AT` at the stripe import** → the vendor `stripe.yml` hardcodes `payment_method_store` = DE/AT. Repoint the recipe at a project-local `stripe.yml` whose `payment-method-store` lists the project stores (import the store-agnostic entities from the vendor paths). Omitting the `payment-method-store` action instead imports the method assigned to no store → Stripe never appears at checkout.
- **`CodeBucketConfig` fatal *before any DB work*** → an old store/region token left in a hardcoded literal. Run the `absent` sweep over `config/` + `src/Pyz` and set `CodeBucketConfig`/`default_store.php` to the project's.
- **`SalesPaymentMerchant*` store-keyed behaviour silently falls to defaults after a store rename** (invisible at boot) → surface as a decision and redefine the store key in `src/<Ns>`.
- **Two same-language locales in one store** share the 2-char URL prefix → the second is silently unreachable. Reject in the interview.

## Project data (project-data)

- **Green boot, no prices rendered, `gross=0` on many rows** → a store seeded from a net-only source (the demo `US` dir); `scale` skips empty cells so an empty gross imports as 0. Treat empty-OR-`0` as missing; derive gross from net by the store's VAT factor.
- **NEVER blind-delete a row of an unassigned currency** — it may be a SKU's ONLY price (four products in the `US` dir). Convert to a project currency instead, after checking the SKU has a price in a kept currency.
- **`manifest-refs` green but the import fails on a dangling reference** (`product-label-store`→label by `name`, `product-measurement-sales-unit-store`→sales unit, `stock-address`→warehouse) → these are generic-column relations `manifest-refs` can't represent. For every entity whose parent set the project **replaced**, run a targeted `refs --ref-file`; use `refs --composite` for parent-in-store tuples.
- **After an order or on the login page, a link 307s to `/<STORE>/error-page/404`** (e.g. `/NO/en/customer/order`, `/en/gtc`) → a glossary value that is a navigational path kept the source locale's language prefix. Re-prefix locale-ROW path values per target locale (exempt `/assets/`).
- **Reordering the import manifest** → empty store (a store's `locale-store` after the catalog binds no locale) or `Currency not found` (`currency-store` hoisted above `currency`). Keep every source in its shipped relative position.
- **Navigation/content nodes referencing dropped categories/pages** → dead/empty menu; the nodes import fine, so a green boot misses it. Prune/regenerate `navigation_node`/`content_navigation` and assert every target ⊆ your keys.
- **`product_image` is a hybrid** — locale is BOTH a row value AND `.<locale>` columns; classifying it as one → missed columns or rows. Handle both passes; deleting a non-project locale's ROWS can delete a definition (bundle image sets → `Could not find product image set`) — recreate under a kept locale first.
- **Store-bound demo activity orphans on a rename** (orders/carts/comments); `quote_request`'s store binding is inside the serialized `quote` JSON, not a `store` column, so a column scan passes it clean. Scan JSON cells too; remove the file + its `source:`, warn the developer.
- **`drop-columns` must be symmetric with the `duplicate-columns` add pass** — the classic miss is `product_image` `alt_text_*.de_DE` left in a live file. Derive both lists from one `columns --plain` scan. Scope the `absent` "clean" sweep to the ACTIVE manifest bucket, not blindly `data/import/**`.
- **Split a multi-locale glossary but don't delete the interleaved original** → every non-project locale's rows stay on disk as dead data in an active bucket. Delete the original after repointing the config. Generalizes: when you split a shipped multi-value source into per-value files, delete the original.
- **`define-stores` seeds a whole canonical dir per store** (`cp -r`) → stale demo files the manifest never imports = a second source of truth. Finish with the consolidate pass + `orphan-files` (on-disk tree = active manifest).

## Demo-data strategies (clean / reduce / cleanup / minimal-baseline)

- **`product_label.csv` must be exactly `NEW`+`SALE`** — the label importers are updater plugins with no self-installer; a missing label aborts the install.
- **Filtering `cms_block.csv` to `cms-block-email--*` also deletes content-BLOCK template definitions** → empty homepage. Blocks (`@CmsBlock/`) are defined by the csv, not `cms_template.csv` (`@Cms/`); keep the email blocks AND re-author the content blocks/templates.
- **Shipped `tax.csv` covers DE/AT only** → any other project country breaks checkout tax. Regenerate per country.
- **Reduce, list-valued assignment column** (`abstract_product_skus`, …): pruning the list to empty must NOT drop the entity DEFINITION row — emptying `product_option` while `product_option_price` still references it → `Product option SKU not found` abort. Prune the list, keep the row.
- **Reduce, orphan scan:** use the broad scanner, not a hand-picked file list (you miss sku-bearing importers → a boot-abort per miss); a 100%-orphan column is usually NOT a product ref (`product_option.sku`) — `--exclude-column` it rather than deleting its rows.
- **A product sold ONLY via an offer loses its price when offers are removed** (cleanup) — check before dropping merchant/offer data.

## Generate strategy (project-data/references/generate.md)

- **A category tree with no `category_store` rows shows in no store** — categories are store-assigned exactly like products.
- **Price authored at CONCRETE only, not ABSTRACT** → catalog/PLP/search read the abstract price → 0 products in listings. Author the abstract price too.
- **Navigation is a 4-layer chain** (`navigation`/`navigation_node`/`content_navigation` + the nodes' targets) — carrying one file → empty menus. The home PAGE must be store-assigned via `cms_page_store`, and its CMS blocks must survive the keep-list, or the homepage is empty.
- **`content_banner` title > 64 / subtitle > 128 codepoints** → the whole import fails (`This value is too long`). Author within the limits.
- **Selection-by-intuition drops behavioural plumbing.** The demo ships far more entities than any keep-list you would write by hand, and a near-miss like `product-shipment-type` (~1-per-concrete = required) breaks silently. Enumerate the shipped manifest's `data_entity` list (`grep -hoE "data_entity: *[a-z-]+" data/import/local/full_<REGION>.yml | sort -u`), diff it with `manifest-diff`, and rank omissions by coverage density (rows ≈ population = required; rows ≪ population = opt-in garnish).
- **Do NOT run url-uniqueness on `navigation_node.csv`** — a nav node's `url` is a link target, legitimately repeated across menus; flagging it is a false positive.
- **C1 — `color_code` required** or the row imports as **0 rows silently**, surfacing ~10 steps later as `getIdProductAbstract() on null`.
- **C2 — `merchant-stock` DERIVES the stock name** and ignores the `stock_name` you supply; downstream references must use the derived string.
- **C3 — `merchant-user` only LINKS an existing Zed user** — seed the user (`getInstallerUsers()`) first.
- **C4 — `product_management_attribute.visibility` is an ENUM** (PDP/PLP/Cart), not a boolean; `1` fails every row.
- **C6 — `merchant-product` is separate from `merchant-product-offer`** — without it the PDP shows no seller.
- **C7 — a variant differentiator needs `is_super=1`** or the variants are inert (no variant selector) while every count/refs check passes.
- **C8 — `attribute_key_N.<locale>` is a MACHINE KEY despite the suffix** — it must equal the unsuffixed key; a label there 500s the BO product-attribute page for every product. The label lives only in `product_management_attribute.csv` `key_translation.<locale>`.
- **C9 — `product-concrete` needs the full localized-attribute triplet** (name + description + is_searchable) per store-locale, all-or-nothing; the error names a different column than the one actually missing.

## Localization (translate-content)

- **`attribute_key_N.<locale>` is a machine key — never translate it** (the C8 trap): translating it breaks the localized-attributes map and 500s the BO product-attribute page. `key_translation.<locale>` in `product_management_attribute.csv` IS the display label — translate that (easy to miss → English label beside a translated value).
- **Glossary values that are navigational paths** (`page.terms.url`, `main_slider_*.url`, …) are locale-specific — re-prefix them per target locale, don't leave the source prefix (see the project-data 307 trap). Exempt `/assets/`.
- **The SEO half of localization** (localized URL slugs, sitemap, `hreflang`, robots) is owned by NO skill — a "localized" store ships English slugs and no locale signals until flagged. Name it in the close.

## E2E / Cypress (cypress-migration)

- **`cy:run` dies `spawn Xvfb ENOENT`** → the Alpine/arm64 CLI container has no display. Run `cy:run` on the host or a CI runner with a display; `npm ci` in the container is fine.
- **A storefront checkout spec hangs on a payment radio that never renders** → it selects `dummyMarketplacePaymentInvoice`, filtered out until a merchant offer is in the cart. Storefront checkout specs on a plain-product fixture must use a non-marketplace method (`dummyPaymentInvoice`).
- **`/dynamic-fixtures` DSL constraints** (invisible until a `#key` reference explodes): a `#key` must reference a helper returning an `AbstractTransfer`/`ArrayObject` (a scalar-returning helper is silently discarded → `Undefined array key`); references are one level deep and can't index arrays; resolve store facts via **glue-backend** `/dynamic-fixtures` (`getAllowedStore`), never storefront Glue (it's an optional app); send `Content-Type: application/vnd.api+json` (else a bare 404 that reads as a missing route).
- **Store facts must NOT live in `.envs`** — they're resolved from the shop; a hand-set value is a second source of truth. `GLUE_BACKEND_URL` is mandatory. `PROJECT_LOCATION` set only for CI silently no-ops CLI-exec steps locally. Fixtures created mid-run need a **continuous** `queue:worker:start` (search isn't synchronous). A B2B purchasing-control customer makes Glue-API setup 422.
- **`code:check` (`eslint . ; prettier .`) masks a real ESLint failure** — the `;` makes prettier's exit status win. Run lint and prettier separately.

## Services (configure-services)

- **A disabled app still burns boot time** (e.g. Storybook building after `static` was disabled) → the install recipe runs its build/asset/cache steps unconditionally. For each app in `services.applications_disabled`, also remove its recipe steps, not just its deploy endpoint.
- **`applications_disabled` prunes must target the region group PRESENT NOW** (the shipped `EU`), not the project's target region — this step runs before `define-stores` renames it.

## CI generation (project-ci-generator)

- **A dropped test-suite's support files** — near-identical kept-vs-dropped filenames (`…cypress-boilerplate.yml` kept vs `…cypress.yml` dropped: delete by which surviving job references it), and a `*_ROBOT.yml` fixture config that may be shared with the regular demodata pipeline (grep the literal filename and read `source:`/`command:` before deleting).
- **The kept `.github/deploy/*.yml` region tokens** must be written to `## Required follow-ups` in `.ai-dev/project-setup.md` for `define-stores`/`brand-project` to sweep — otherwise the workflow retargets and the deploy files silently don't.
