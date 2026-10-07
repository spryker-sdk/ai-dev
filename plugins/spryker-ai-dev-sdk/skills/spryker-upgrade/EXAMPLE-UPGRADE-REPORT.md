# Example upgrade report — `<project>` → Spryker 202606.0

An illustrative report in the shape Phase 7 asks for. The project is a placeholder, the counts are
rounded examples, and the module names and release facts are the kind a 202410 → 202606 upgrade meets.
Copy the section headings; replace the content. Each section says which phase produces it.

Branch: `upgrade/202606.0`, one commit per phase or lane. **Status:** resolves, boots, all checks at
or better than baseline; two items open for the developer (see *Open items*).

## Starting point (Phase 0, Phase 1)

| Fact | Value |
|---|---|
| Release before (from `composer.lock`) | mixed: most features on `202410.0`, a few on `202507.0` |
| Divergence `composer.json` vs lock | 9 features with `^` constraints; 1 feature in the lock only |
| Target release (gate #2) | `202606.0` — latest; the developer chose one jump |
| `src/Pyz` PHP classes | ~2 800 |
| Vendor-method overrides mapped | ~1 800 (about 60 business-logic, the rest wiring) |
| Shadowed frontend/presentation files | ~800 across ~140 module scopes |

### Baselines (Phase 0, before any constraint moved)

| Baseline | Result |
|---|---|
| `check-dead-overrides.php snapshot` | overrides recorded, 0 unloadable classes |
| `check-plugin-usage.php` | 0 MISSING, 54 deprecated, 17 project plugins on deprecated interfaces |
| `check-config-constants.php` | 0 problems |
| PHPStan (`phpstan-baseline-run.txt`) | 312 errors, all environment-explained or pre-existing |
| Sniffer (`sniff-baseline.txt`) | 41 violations |
| Evaluator (`evaluator-baseline.txt`) | 6 findings |
| Tests (`codecept-baseline.txt`) | 630 tests, 12 red, 3 suites skipped (no webdriver) — invocation pinned with `SPRYKER_TESTING_ENABLED=1` |
| Back Office crawl (`backoffice-smoke-baseline.json`) | 214 pages / 96 table endpoints, 2 failing |
| `check-baselines.php` | exit 0 |

### Verifiability (gate #1)

63 business-logic overrides; 41 of them in modules with no test. The developer chose **cover a subset**:
pricing, cart and checkout. Nine functional tests were added through `PriceProductFacade`,
`CartFacade` and `CheckoutFacade` — one per behaviour the overrides change — and committed green
before the upgrade branch diverged. No Factory, Config or DependencyProvider tests. The remaining
lanes are reported as statically verified only.

## Tooling, Docker SDK and deploy files (Phase 1.2)

Reference: `b2b-demo-marketplace` at `202606.0`, cloned into `.spryker-upgrade/state/reference/`.

| Item | Before | After | Note |
|---|---|---|---|
| Docker SDK (`.git.docker`, `docker/` pointer) | 1.58.0 | 1.66.0 | release notes read for the whole range |
| `deploy.*.yml` image tag | `spryker/php:8.2` | `spryker/php:8.3` | `config.platform.php` set to `8.3.2` |
| Search / broker / key-value versions | as reference | as reference | `opensearch` 1 → 2 per the Docker SDK notes |
| Database engine | MariaDB 10.6 | MariaDB 10.6 | **kept** — matches production; recorded project choice |
| `phpstan/phpstan` | 1.10 | 1.12 | own commit |
| `spryker/code-sniffer` | 0.17.18 | 0.17.27 | own commit |
| `spryker-sdk/evaluator`, `codeception/*`, `phpunit/phpunit` | aligned to the reference | | own commit |

The tooling resolved against the pre-upgrade modules, so it moved in Phase 1.2. The PHPStan, sniffer,
evaluator and test baselines were re-taken right after the tooling commit: PHPStan gained 18 findings
from new rules, the sniffer 7 — none of them counted as upgrade damage.
`check-tooling-alignment.php` is clean apart from the database engine above.

## Constraint blockers (Phase 1.5)

| Blocker | Resolution |
|---|---|
| 130 `~x.y.z` module constraints | relaxed to `^` (`check-constraint-style.php --relax`) |
| 22 major bumps surfaced in waves (`gui` 3 → 5, `gui-table` 3 → 4, `zed-ui` 3 → 4, the `*-merchant-portal-gui` cohort, `product-management` 0.19 → 0.20, …) | `resolve-constraints.php`; the Angular 20 cohort via `unpin-feature-driven-modules.php` |
| `"twig/twig": "3.20"` exact pin vs security advisories on `<3.27.0` | pin raised to `^3.27.1` — a safe version existed; advisory blocking untouched |
| Root constraints merged in from `<upstream-repo>` via composer-merge-plugin | fixed upstream, providing package installed alone first, then the release group |
| `spryker-eco/product-management-ai` — no release supports `spryker/gui` 5 | Lane 5: the developer chose **drop** (see Lane 5) |

## Composer update (Phase 2)

Full `composer update` in the container, 0 problems. Lock diff against the baseline: 168 major,
614 minor/patch, 18 new, 2 removed Spryker packages.

## Migration guides (Lane 0)

The majors collapse into a few coordinated platform migrations plus one functional module major:

| Migration | Guide | Applied | Deliberately not applied (new capability) |
|---|---|---|---|
| Bootstrap 3 → 5 (Back Office) | docs.spryker.com "Upgrade the Back Office to Bootstrap 5" | `data-bs-*` renames in 2 templates, grid classes in 9 | — |
| Angular 18 → 20 | docs.spryker.com "Upgrade to Angular 20" | `standalone: false` on 45 components, Node ≥ 20.19 | — |
| INSPINIA theme v2 (`gui` 4 → 5) | docs.spryker.com "Update the INSPINIA theme" | composer/npm/cache steps | — |
| MerchantProductOfferDataImport 1 → 2 | module guide, 1.\* → 2.0.0 section | new importer wiring, `propel:install`, `transfer:generate` | combined product-offer importer UI |
| ShopUi 2.0.0 | **none published** — only "upcoming major module releases" | `frontend/settings.js` → `frontend/yves.settings.mts`, `yves:*` scripts re-pointed, Node 24+ | — |

Guide discrepancies: the ProductManagement `0.19 → 0.20` section describes a change that is not in the
tag diff (the real diff is Twig/JS only) — the diff was followed. Two JS library majors ride along in
`gui` 4 → 5 without a guide: `sweetalert` → `sweetalert2` and `datatables.net` 1.11 → 2.x; 5 project
JS files were ported.

## Damage found and resolved (Lanes 1–4)

| Lane | Found | Resolved |
|---|---|---|
| 1 — typed members | 2 constants, 2 properties untyped against PHP 8.3-typed core | types added / narrowing redeclarations deleted |
| 1 — constructor arity (PHPStan) | 4 factories | argument lists mirrored, delegating to `parent::` |
| 1 — signature changes | 4 overrides (e.g. `CustomerPageFactory::getSessionClient()` narrowed by core) | raw client under a `PYZ_*` key with its own accessor |
| 1 — boot lifecycle (`spryker/application`) | `Application::boot()` a no-op; boot-time plugins silently skipped | guarded shim, `upgrade-debt:` docblock with removal condition and vendor issue |
| 2 — shadowed files | 163 changed, 13 removed, 63 new vendor files | 15 clean merges, 148 resolved by hand; `npm run yves` clean |
| 3 — MISSING plugins | `CustomerReorderWidget` removed: 5 plugins | rewired to the CartReorder feature the project already had |
| 4 — config / transfers | 0 constant problems; 3 `strict` mismatches | `strict` matched to core at both levels |

Remaining `upgrade-debt` markers: 1 (the boot-lifecycle shim above).

## Deprecations (Lane 3, gate #3)

| Item | Replacement | Difference | Consequences | Developer's choice |
|---|---|---|---|---|
| `<OldCartExpanderPlugin>` | `<NewCartExpanderPlugin>` | same interface, adds bundle items | none beyond the swap | applied |
| `<OldCheckoutPreConditionPlugin>` ×2 | one `<NewCheckoutValidatorPlugin>` | two plugins consolidate into one | picked the checkout pre-condition list to keep it | applied |
| `<OldOrderSavePlugin>` | `<NewOrderPostSavePlugin>` | different extension point (post-save) | runs after persistence; order of side effects changes | deferred — needs a business check |
| `<OldPriceDimensionPlugin>` | none named | — | behaviour still used | kept |

Applied: 12. Deferred: 9. Kept: 3. Deferred and kept items stay listed here for the next upgrade.

## Static checks against baseline (Phase 5)

| Check | Result |
|---|---|
| Detectors | all clean against the baseline |
| PHPStan | 0 regressions against the post-tooling baseline |
| Sniffer | changed files auto-fixed (`-f`); 0 new violations |
| Evaluator | 0 new findings |
| `check-added-comments.php` | exit 0 |
| `propel:diff` | 2 migrations pending — answered against a database imported from the pre-upgrade lock |

## Tests (Phase 5.5)

630 tests in the container with the pinned invocation: 618 green, 12 red. 3 suites skipped (no
webdriver). Every test added at gate #1 is green. Failures that are not in the baseline:

| Kind | Count | Note |
|---|---|---|
| App damage | 0 | — |
| Harness damage | 2 | a moved `SprykerTest` helper; test-side usage fixed |

### Tests already red before the upgrade

These 12 fail identically on `codecept-baseline.txt` and are not upgrade damage (most assert core
behaviour the project customises):

- `PyzTest\Zed\<Module>\...` — 7 tests
- `PyzTest\Yves\<Module>\...` — 5 tests

## Back Office crawl and verifier (Phase 4.9, Phase 6.5)

| Check | Result |
|---|---|
| First crawl after boot | every table data endpoint 500 — `spryker/application` newer than `spryker/silexphp` allowed; the lagging package was bumped |
| Final crawl | 0 problems outside the baseline; the 2 `inBaseline` pages still fail (pre-existing) |
| `spryker-verifier` | 14 criteria: 13 PASS, 1 BLOCKED (a Merchant Portal page needs a merchant user not in the seed data) |

## New features (gate #4) and removed packages

18 NEW packages listed; the developer chose none for now. REMOVED: `spryker-shop/customer-reorder-widget`
(replaced by the CartReorder feature), `spryker-eco/product-management-ai` (dropped, Lane 5).

## Lane 5 — dropped: `spryker-eco/product-management-ai`

No release supports `spryker/gui` 5, and it calls a gui form type that 5.x removed, so widening
constraints upstream would not help. Footprint: one project module, 3 plugin registrations, AI slices
in two Back Office modules and their templates. Removed in one commit (revertable). Kept: the product
image `alt_text` field, a project feature whose only AI link was a `template_path` hook; the
name/description fields were unwrapped from the AI translate embed with their markup intact.

## What is proven and what is not

- Proven in the container: composer resolves, the console boots, the Zed and Glue functional suites
  match the baseline, every Back Office page answers as in the baseline.
- Proven statically only: the modules outside pricing, cart and checkout (the developer declined
  tests there at gate #1).
- Not run: acceptance suites (no webdriver) — matrix #23 behaviour changes in the storefront remain
  unproven there; the verifier covered the pages listed in its report.

## Open items

1. The deferred `<OldOrderSavePlugin>` replacement (post-save semantics).
2. The BLOCKED verifier criterion — needs a merchant user.

Suggested PR: one for this release group.
