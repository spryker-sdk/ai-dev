# Upgrade report — sc-b2b-mp-industry-demo → Spryker 202606.0

Branch: `upgrade/202606.0`, 9 commits. The upgrade resolves and generates cleanly; see **Final
status** at the end for what remains.

## Starting point

| Fact | Value |
|---|---|
| Release before | mixed: 94 features on `202410.0`, 13 on `202507.0` (one `~202507.0`) |
| Target release | `202606.0` (latest product release) |
| Product releases crossed | 202410 → 202507 → 202512 → 202602 → 202604 → 202606 (~5 groups) |
| `src/Pyz` PHP classes | 2 838 |
| Dependency providers | 423 |
| Yves twig | 663 · Zed Presentation twig | 187 |
| Propel schema XML | 207 · transfer XML | 91 |
| Vendor-method overrides mapped | 1 845 |
| Shadowed frontend/presentation files | 810 across 145 module scopes |
| Vendor plugins wired in dependency providers | 2 767 |

## Phase 0 — baseline (on 202410.0, before touching composer)

All detectors green, i.e. this project had **no pre-existing damage** to confuse post-upgrade
reports:

| Detector | Baseline result |
|---|---|
| `check-dead-overrides.php snapshot` | 1 845 overrides recorded, **0 unloadable classes** |
| `twig-shadow-map.php snapshot` | 810 shadowed files, vendor merge-bases captured |
| `check-plugin-usage.php` | **0 MISSING**, 54 deprecated, 17 project plugins on deprecated interfaces |
| `check-config-constants.php` | 361 types / 752 constants checked, **0 real problems** |

## Phase 1.5 — constraint blockers (the hard part)

Bumping only the `spryker-feature/*` meta-packages **cannot resolve** on this project. Three
distinct classes of blocker, found and cleared in order:

### 1. Patch-locked module constraints — 130 packages
130 direct Spryker module constraints used `~x.y.z` (patch-only), so the 202606 feature packages
could not pull the minors they require. First composer attempt: **49 unresolvable conflicts**.
Relaxing `~` → `^` (recorded in `state/tilde-pinned-packages.json`) cut it to 24.

### 2. Major bumps required — 22 packages
With minors open, the remaining conflicts were genuine **major** boundaries. 18 found in the
first pass, 4 more surfaced in later waves by `resolve-constraints.php`:

| Package | From | To |
|---|---|---|
| spryker/gui | ^3.53.2 | ^5.3.0 (**two majors**) |
| spryker/gui-table | ^3.1.0 | ^4.0.0 |
| spryker/zed-ui | ^3.2.0 | ^4.1.0 |
| spryker/merchant-gui | ^3.13.0 | ^4.1.0 |
| spryker/product-merchant-portal-gui | ^4.4.1 | ^5.1.0 |
| spryker/product-offer-merchant-portal-gui | ^3.0.1 | ^4.0.0 |
| spryker/price-product-merchant-relationship-merchant-portal-gui | 2.0.0 | ^3.1.0 |
| spryker/security-merchant-portal-gui | ^3.2.0 | ^4.3.0 |
| spryker/user-merchant-portal-gui | ^3.0.0 | ^4.1.0 |
| spryker/product-management | ^0.19.52 | ^0.20.0 (0.x minor = breaking) |
| spryker/product-attribute-gui | ^1.7.0 | ^2.3.0 |
| spryker/product-set-gui | ^2.12.1 | ^3.1.0 |
| spryker/content-gui | ^2.7.0 | ^3.1.0 |
| spryker/file-manager-gui | ^2.8.1 | ^3.1.0 |
| spryker/availability-gui | ^6.10.0 | ^7.1.0 |
| spryker/shipment-gui | ^2.10.1 | ^3.3.0 |
| spryker/stock-gui | ^2.1.0 | ^3.1.0 |
| spryker/payment-gui | ^1.3.1 | ^2.1.0 |
| spryker/price-product-offer-gui | ^1.2.0 | ^2.1.0 |
| spryker/merchant-product-offer-data-import | ^1.2.0 | ^2.1.0 |
| spryker/company-unit-address | 1.17.0 | ^1.18.0 |
| spryker/development | ~3.40.1 | ^3.53.0 |

Conflicts arrive in **waves** — each root bump reveals the next transitive layer — which is why
this needed an automated loop rather than one pass.

### 3. Security advisory — `twig/twig`
The project pinned `"twig/twig": "3.20"` exactly. All twig 3.x below 3.27.0 are covered by 26
sandbox-bypass advisories, so composer refused every candidate. **A safe version exists** —
advisories affect `<3.27.0`, so the fix was bumping the root pin to `^3.27.1`, not disabling
composer's advisory blocking. (For reference, the 202606 demoshop locks `twig/twig v3.27.1`.)

### 4. BLOCKER — `spryker-eco/product-management-ai` has no compatible release
| Version | requires spryker/gui |
|---|---|
| 0.5.0 (latest) | ^4.0.0 |
| 0.4.0 | ^4.0.0 |
| 0.3.0 | ^3.45.0 |
| 0.2.1 (project had) | ^3.45.0 |

Release 202606.0 requires `spryker/gui ^5.3.0`. **No published version of this package supports
gui 5.x**, and widening the constraint would not help — it calls a gui class that 5.3.2 removed.
**Resolved by dropping the feature**; see *The AI product-management feature: dropped* below.

### 5. HARD STOP — an external repository owns 216 of this project's root constraints

This is the finding that ends the upgrade, and it is **not fixable inside this repository.**

`composer.json` enables `wikimedia/composer-merge-plugin`:

```json
"extra": { "merge-plugin": { "include": ["vendor/spryker/spryker-demo/Bundles/*/composer.json"] } }
```

`spryker/spryker-demo` is a `dev-main` metapackage from the private repo
`spryker-projects/demo-packages`. It ships **59 bundles**, and the merge plugin folds **42 of their
composer.json files — 216 Spryker constraints — into the root package** at resolve time. That is
why composer kept reporting *"conflicts with your root composer.json require (^3.47.1)"* for
`spryker/gui` long after every `gui` pin had been removed from this project's composer.json: the
constraint is real and is a root constraint, it just lives in another repository.

Six merged bundles block release 202606.0, which needs `spryker/gui ^5.3.0`:

| Bundle (in `spryker-projects/demo-packages`) | Pins |
|---|---|
| ImportProcessGui | `spryker/gui ^3.47.1` |
| ImportProcessGoogleSheetsGui | `spryker/gui ^3.47.1` |
| ProductAttributeSetGui | `spryker/gui ^3.47.1` |
| ShopThemeGui | `spryker/gui ^3.47.1` |
| MerchantReviewGui | `spryker/gui ^3.47.0` |
| MerchantReviewMerchantPortalGui | `spryker/gui-table ^3.0.0` |

This project could not reach 202606.0 until `spryker-projects/demo-packages` was updated — no
amount of constraint editing here substitutes. **Resolved**: those seven constraints were widened
and two code fixes applied in
[demo-packages#149](https://github.com/spryker-projects/demo-packages/pull/149), and this project
now points at that branch. Note the sequencing trap: the merge plugin reads from the *installed*
`vendor/` copy, so the providing package must be updated in its own composer step before the
release group is bumped.

`check-constraint-style.php` now reports merged constraints, so this surfaces in preflight instead
of after six composer rounds.

## Lane 0 — migration guides (mandatory for every major)

Researched every crossed major boundary. **The headline: the ~31 major bumps collapse into three
coordinated platform migrations plus one genuinely functional module major.** Chasing them
per-module would have been the wrong unit of work.

| Migration | Guide | Modules it covers |
|---|---|---|
| **Bootstrap 3 → 5** (Back Office) | [Upgrade the Back Office to Bootstrap 5](https://docs.spryker.com/docs/pbc/all/back-office/latest/base-shop/install-and-upgrade/upgrade-the-back-office-to-bootstrap-5.html) | `gui` 3→4 and the whole `*Gui` wave released 2025-11-16: product-attribute-gui, product-set-gui, content-gui, file-manager-gui, availability-gui, shipment-gui, stock-gui, payment-gui, price-product-offer-gui, merchant-gui, product-management |
| **Angular 18 → 20** | [Upgrade to Angular 20](https://docs.spryker.com/docs/dg/dev/upgrade-and-migrate/upgrade-to-angular-20.html) | `gui-table` 4, `zed-ui` 4, and the 15-module `*-merchant-portal-gui` cohort |
| **INSPINIA theme v2** | [Update the INSPINIA theme](https://docs.spryker.com/docs/pbc/all/back-office/latest/base-shop/install-and-upgrade/update-inpinia-theme-version-at-back-office.html) (thin — composer/npm/cache steps only) | `gui` 4→5 and every `x.1.0` compat minor |
| **MerchantProductOfferDataImport 1→2** | [module guide with a real 1.\*→2.0.0 section](https://docs.spryker.com/docs/pbc/all/offer-management/latest/marketplace/install-and-upgrade/upgrade-modules/upgrade-the-merchantproductofferdataimport-module.html) | the only functional major: new combined product-offer importer, ~30 new classes, new `FILE_SYSTEME_NAME` constant, requires `propel:install` + `transfer:generate` |
| **ShopUi 2.0.0** (2026-08-03) | **NO GUIDE PUBLISHED** — breaking changes listed only on [upcoming major module releases](https://docs.spryker.com/docs/about/all/releases/upcoming-major-module-releases.html) | Yves frontend builder moves into ShopUi: `frontend/settings.js` → `frontend/yves.settings.mts`, `yves:*` npm scripts re-pointed, **Node 24+** |

Per-module pages are mostly stale or absent: `MerchantGui` stops at 2→3 (2021), `ProductMerchantPortalGui`
at 1→2 (2023), `ProductSetGui`/`ContentGui`/`ShipmentGui` cover only 1→2, `AvailabilityGui` stops at
6.0.0, and `product-attribute-gui`, `file-manager-gui`, `product-offer-merchant-portal-gui`,
`price-product-*-gui`, `payment-gui`, `gui`, `gui-table` have **no published guide at all**.

### Corrections found while verifying

1. **Two entries in my own worklist were not majors.** `spryker/development` 3.40→3.53 and
   `spryker/company-unit-address` 1.17→1.18 stay within the same major — no guide applies. My
   first-pass extraction over-reported because it read composer's conflict lines without checking
   the boundary. `resolve-constraints.php` classifies correctly.
2. **The published ProductManagement guide's `0.19→0.20` section is wrong.** It describes a
   locale/money-form change and is dated Jun 2021, while 0.20.0 shipped Nov 2025; the real diff is
   11 Twig/JS files with zero PHP. Following that section would have produced pointless work.
3. **Most churn lives in the `x.1.0` minors, not the majors.** e.g. availability-gui 7.0.0 changes
   *zero* files while 7.1.0 changes 28; product-set-gui 3.0.0 changes 4 while 3.1.0 changes 33;
   product-management 0.20.0 changes 11 while 0.20.1–0.20.11 change 157. Scoping a Twig review to
   major boundaries would miss ~90% of it — which is why the shadow-map detector diffs actual file
   content over the whole range rather than reasoning about version numbers.
4. **Two undocumented JS library majors** ride along in `gui` 4→5, mentioned in no guide or release
   note — found only in the `assets/Zed/package.json` diff: `sweetalert ~1.1.3` → **`sweetalert2 ~11.x`**
   (different API) and `datatables.net 1.11` → **2.3.7**. Project JS calling `swal(...)` or
   DataTables 1.x APIs must be rewritten.

### This project's actual exposure (measured, not assumed)

| Check | sc-b2b-mp-industry-demo |
|---|---|
| Pyz classes extending `Gui\...\AbstractTable` | **8** — review against Bootstrap 5 table markup |
| Uses removed `isSymfonyHttpFoundationVersion5OrHigher()` | 0 — safe |
| References `FORM_DEFAULT_TEMPLATE_FILE_NAMES` / `bootstrap_3_layout` | 0 — safe |
| Pyz twig with Bootstrap 3 JS data attributes | **2 files** — need `data-bs-*` rename |
| Pyz twig with Bootstrap 3 grid classes | **9 files** — need grid-class migration |
| Pyz JS/TS calling `swal(` or `DataTable(` | **5 files** — hit by the undocumented JS majors |
| Pyz TS with `@Component`/`@Directive`/`@Pipe` | **45 files** — each needs `standalone: false` for Angular 20 |
| `package.json` engines | `node >=18.13.0`, `npm >=9.0.0` — **must reach ≥20.19 for Angular 20, and 24 for ShopUi 2.0** |

The Bootstrap-5 compatibility shims (`commons-bootstrap-compatibility.js`, `_bootstrap5-update.scss`)
were **deleted** in gui 4.0.0, so Bootstrap 3 attribute names are now dead rather than degraded.

## Tooling added during this run

Running against a real project exposed seven gaps in the POC detectors, all fixed and
back-ported to the reference repo:

| Gap found | Fix |
|---|---|
| Module code ships from `spryker-eco`/`spryker-feature`, not just `spryker-shop` | vendor roots now glob-resolved across all four namespaces (+17% more shadowed files found) |
| `Generated\` transfers absent before `transfer:generate` → 40 false positives | reported as a separate note, not a problem |
| Patch-locked (`~`) module constraints silently block the upgrade | new `check-constraint-style.php` |
| Conflicts arrive in waves | new `resolve-constraints.php` (iterative, logs every bump, flags majors) |
| Caret on a `0.x` package is major-locked | resolver's `crossesMajorBoundary()` treats `^0.19→^0.20` as major |
| `dev-main` branch constraints misreported as pins | floating-constraint detection |
| Exact pins on third-party packages block Spryker modules | separate `thirdPartyPinned` report category |
| Greedy per-package bumping **oscillated forever** (`zed-ui` ^4↔^3) on a cohort migration | monotonic constraints + oscillation detection; reports the cohort instead of looping |
| Cohort migrations can't be resolved by per-package bumps at all | new `unpin-feature-driven-modules.php` — lets feature meta-packages govern, as the reference demoshop does |
| A *filtered* `composer update` re-uses the lock's root requirements, so removed pins still appear as conflicts | resolver now always runs a **full** update |

Eight scripts in `$UP/`; the coverage matrix in
`.claude/skills/spryker-upgrade/SKILL.md` grew from 24 to **37** use cases. All fixes were
back-ported to the reference repo.

## Phase 2 RESOLVED — and Phase 3 detector results

After fixing `spryker-projects/demo-packages` (branch `upgrade/202606.0-gui5-angular20-compat`)
and one further project pin (`spryker/customer-user-connector-gui ^1.5.0 → ^2.1.0`, gui-5 support
arrived in 2.1.0), **composer resolved with 0 problems**: 1 589 packages locked, `spryker/gui 5.3.2`,
`gui-table 4.0.0`, `zed-ui 4.1.1`, `product-management 0.20.11`, `locale 4.14.0`,
`twig/twig v3.28.0`, `spryker-feature/* 202606.0`.

Lock diff vs the 202410 baseline: **168 major, 614 minor/patch, 18 new, 2 removed** Spryker packages.
`composer audit` reports one low-severity advisory (`firebase/php-jwt < 7.0.0`, CVE-2025-45769).

### Real upgrade damage found (this is what the POC exists for)

**Broken classes — 24 conflicts across 17 classes** (5 are collateral from the parked
`product-management-ai`, leaving **12 genuine**). Every one is a hard fatal at class load, i.e. the
shop would not boot:

| Cause | Classes |
|---|---|
| Core added a **typed class constant**; the Pyz override is untyped | `Zed\MerchantProductGui\MerchantProductGuiDependencyProvider::FACADE_MERCHANT`, `Yves\ServicePointCartPage\ServicePointCartPageConfig::QUOTE_ITEM_FIELDS_ALLOWED_FOR_RESET` |
| Core added a **typed property**; the Pyz override is untyped | `Yves\CheckoutPage\Process\Steps\SummaryStep::$checkoutPageConfig`, `Zed\PriceProduct\Business\Model\Reader::$priceProductMapper` |
| **Signature change** (params or return type) | `Yves\CustomerPage\CustomerPageFactory::getSessionClient()`, `Zed\MerchantGui\...\ListMerchantController::indexAction(): array`, `Zed\PriceCartConnector\...\PriceManager::addPriceToItems()`, `Zed\ProductMerchantPortalGui\Persistence\ProductMerchantPortalGuiRepository(+Interface)::getProductsDashboardCardCounts(int, int)` |
| **Vendor class removed entirely** | `Yves\CustomerReorderWidget\CustomerReorderWidgetDependencyProvider` — `SprykerShop\Yves\CustomerReorderWidget\...` no longer exists |

Typed constants and typed properties are a whole *class* of damage worth calling out: core adopted
PHP 8.3 `const string`/typed properties, and every untyped project override of those members is now
a fatal. Grep-style review would not have predicted it.

**Shadowed frontend/presentation files — 176 conflicts + 63 informational**, out of the 810 mapped:

| Type | Count | Meaning |
|---|---|---|
| `VENDOR_FILE_CHANGED` | 163 | vendor changed the file but the project override shadows it — the change is **not live**; each carries a ready `git merge-file` three-way merge command |
| `VENDOR_FILE_REMOVED` | 13 | the shadowed vendor template is gone (renamed/removed) — the override is now detached |
| `NEW_VENDOR_FILE` | 63 | new vendor files inside overridden scopes — overridden parents may need to include them |

**Plugin stacks — 8 newly-missing wired plugins** (baseline was 0), plus project plugins on
deprecated interfaces rising 17 → 19:

- **`CustomerReorderWidget` was removed as a module** in 202606, taking 5 wired plugins with it
  (`CustomerReorderWidgetPlugin`, `CustomerReorderFormWidget`, `CustomerReorderItemsFormWidget`,
  `CustomerReorderItemCheckboxWidget`, `CustomerReorderWidgetRouteProviderPlugin`). This is exactly
  the "plugin stack replaced by another plugin stack" case — the replacement is the **CartReorder**
  feature, which this project already has. The Pyz dependency providers and the
  `CustomerReorderWidget` override must be rewired to it.
- 3 more are collateral from the parked `product-management-ai`.

**Config constants — clean.** 361 types and 752 constants still resolve; the only note is the 40
`Generated\` transfers awaiting `transfer:generate`.

### A detector bug this run exposed

The dead-override detector originally died on the first fatal — a compile-time error like
"Type of ::FACADE_MERCHANT must be compatible with…" cannot be caught by `try/catch`, so one broken
class aborted the whole scan. It now reflects in **child processes** (batches of 100, bisecting to
single classes on failure), so a poisoned class costs its batch rather than the run. Full scan of
2 838 Pyz classes: ~49 s.

## Final status

Branch `upgrade/202606.0`, 5 commits, composer resolves clean and
`transfer:generate` exits 0 with 2 376 transfer objects.

| Phase | Result |
|---|---|
| Phase 0 baselines | captured (all detectors green at 202410.0) |
| Phase 1.5 constraints | **done** — 130 tilde pins relaxed, 24 majors bumped, 155 feature-governed pins removed, twig security pin fixed, php raised to >=8.3 |
| Phase 2 composer | **resolved, 0 problems** — 1 589 packages; gui 5.3.2, gui-table 4.0.0, zed-ui 4.1.1, twig v3.28.0 |
| Lane 0 migration guides | **done** — 3 platform migrations + 1 functional major identified and applied |
| Lane 1 broken classes | **done** — 12 fatals fixed (typed constants/properties, 4 signature changes, 4 constructor arities) |
| Lane 3 plugin stacks | **done** — CustomerReorderWidget fully migrated to CartReorder |
| Lane 4 config constants | **clean** — 361 types / 752 constants resolve |
| Phase 5 artifacts | `transfer:generate` ✔; `propel:migration:diff` and `search:setup:index-map` need a DB / search backend, unavailable on this host |
| Lane 2 frontend | **15 of 163 merged**; 148 need design decisions — see `LANE2-WORKLIST.md` |
| Gate #2 new features | 18 NEW packages listed in `state/lock-diff-report.json`, none wired |

### Detector state after the work

| Detector | Result |
|---|---|
| `check-dead-overrides.php` | **clean** — all 1 845 recorded overrides still anchored |
| `check-config-constants.php` | **clean** — 361 types / 752 constants resolve |
| `check-plugin-usage.php` | **0 missing**; deprecated 49 → 39 after 10 swaps; 19 project plugins on deprecated interfaces |
| `twig-shadow-map.php` | 148 conflicts + 11 removed templates remain (`LANE2-WORKLIST.md`) |
| PHPStan level 1 | **no error that is not explained by this host lacking a database, a search backend or the Spryker bootstrap** |
| `transfer:generate` | exit 0, 2 376 transfer objects |

### The AI product-management feature: dropped

`spryker-eco/product-management-ai` has **no release compatible with 202606.0**, and it is not a
constraint problem: it uses `Spryker\Zed\Gui\Communication\Form\Type\Select`, which gui 5.3.2
**removed**. Its latest release (0.5.0) caps at `gui ^4.0.0`, so it needs upstream code changes.

Per the decision to drop it, the feature was removed in full: the project's
`Pyz\Zed\ProductManagementAi` module (21 files), the three SprykerEco plugin registrations,
ProductCreationWizardGui's AI price-suggestion slice (controller, data provider, facade dependency,
JS), Gui's AI request builder, and every AI twig include, stylesheet link and `data-trans-ai-*`
attribute — 195 files, ~19 300 lines removed.

Deliberately kept: the product image `alt_text` form field, which is a project feature rather than
an AI one — only its `template_path` hook into the AI partial is gone. The name/description fields
were unwrapped from the AI translate embed with their markup intact.

If the eco package later supports gui 5, this is a revert of one commit rather than a rebuild.

### Also fixed upstream

Two commits were needed in `spryker-projects/demo-packages`
([PR #149](https://github.com/spryker-projects/demo-packages/pull/149)) beyond the constraint
widening: `GenerateSalesInvoicePdfConsole::CODE_SUCCESS`/`CODE_ERROR` had to become `const int` to
match core's `Console`, otherwise **every** console command aborts on startup — including
`transfer:generate` during install. That repo's CI was also broken independently (no `setup-php`
step, so PHP 8.1 against a `code-sniffer` needing 8.3) and is fixed in the same PR.

