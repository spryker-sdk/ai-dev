# Example upgrade report — `<project>` → Spryker 202606.0

An illustrative report in the shape Phase 7 asks for. The project is a placeholder, the counts are
rounded examples, and the module names and release facts are the kind a 202410 → 202606 upgrade meets.
Copy the section headings; replace the content. Each section says which phase produces it.

Branch: `upgrade/202606.0`, one commit per phase or lane. **Status:** resolves, boots, all checks at
or better than baseline; three items open for the developer (see *Open items*).

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
| `check-plugin-usage.php` | 0 MISSING, 50 deprecated, 15 project plugins on deprecated interfaces |
| `check-config-constants.php` | 0 problems |
| PHPStan (`phpstan-baseline-run.txt`) | 300 errors, all environment-explained or pre-existing |
| Sniffer (`sniff-baseline.txt`) | 40 violations |
| Evaluator (`evaluator-baseline.txt`) | 5 findings |
| Tests (`codecept-baseline.txt`) | 600 tests, 10 red, 3 suites skipped (no webdriver) — invocation pinned with `SPRYKER_TESTING_ENABLED=1` |
| Back Office crawl (`backoffice-smoke-baseline.json`) | 200 pages / 100 table endpoints, 2 failing |
| `check-constant-overrides.php --snapshot` | 20 project constants redeclare a vendor class or interface constant, 10 with a different value |
| `storage-search-counts.php --snapshot before` | 60 tables, 1 200 000 rows — full import, all stores, queues drained |
| `check-baselines.php` | exit 0 |

### Verifiability (gate #1)

60 business-logic overrides; 40 of them in modules with no test. The developer chose **cover a subset**:
pricing, cart and checkout. Ten functional tests were added through `PriceProductFacade`,
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
evaluator and test baselines were re-taken right after the tooling commit: PHPStan gained 20 findings
from new rules, the sniffer 5 — none of them counted as upgrade damage.
`check-tooling-alignment.php` is clean apart from the database engine above.

## Constraint blockers (Phase 1.5)

| Blocker | Resolution |
|---|---|
| 130 `~x.y.z` module constraints | relaxed to `^` (`check-constraint-style.php --relax`) |
| 20 major bumps surfaced in waves (`gui` 3 → 5, `gui-table` 3 → 4, `zed-ui` 3 → 4, the `*-merchant-portal-gui` cohort, `product-management` 0.19 → 0.20, …) | `resolve-constraints.php`; the Angular 20 cohort via `unpin-feature-driven-modules.php` |
| `"<vendor>/<package>": "x.y"` exact pin vs security advisories on its range | pin raised to the first safe version — a safe version existed; advisory blocking untouched |
| Root constraints merged in from `<upstream-repo>` via composer-merge-plugin | fixed upstream, providing package installed alone first, then the release group |
| `<eco-package>` — no release supports `spryker/gui` 5 | Lane 5: the developer chose **drop** (see Lane 5) |

## Composer update (Phase 2)

Full `composer update` in the container, 0 problems. Lock diff against the baseline: 170 major,
600 minor/patch, 20 new, 2 removed Spryker packages.

## Migration guides (Lane 0)

The majors collapse into a few coordinated platform migrations plus one functional module major:

| Migration | Guide | Applied | Deliberately not applied (new capability) |
|---|---|---|---|
| Bootstrap 3 → 5 (Back Office) | docs.spryker.com "Upgrade the Back Office to Bootstrap 5" | `data-bs-*` renames in 2 templates, grid classes in 9 | — |
| Angular 18 → 20 | docs.spryker.com "Upgrade to Angular 20" | `standalone: false` on 40 components, Node ≥ 20.19 | — |
| INSPINIA theme v2 (`gui` 4 → 5) | docs.spryker.com "Update the INSPINIA theme" | composer/npm/cache steps | — |
| MerchantProductOfferDataImport 1 → 2 | module guide, 1.\* → 2.0.0 section | new importer wiring, `propel:install`, `transfer:generate` | combined product-offer importer UI |
| ShopUi 2.0.0 | **none published** — only "upcoming major module releases" | `frontend/settings.js` → `frontend/yves.settings.mts`, `yves:*` scripts re-pointed, Node 24+ | — |

Guide discrepancies: the `<Module>` `x → y` section describes a change that is not in the
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
| 2 — shadowed files | 160 changed, 10 removed, 60 new vendor files | 15 clean merges, 145 resolved by hand; `npm run yves` clean |
| 2 — `ProductGroupWidget` (`check-performance.php`, `PRODUCT_GROUP_WIDGET_REMOVED`) | `spryker-shop/shop-ui` crossed 1.103.0; product groups imported; no project molecule renders the widget | widget restored in the `groups` block of `product-card`, `product-item` and `product-list-item` |
| 3 — MISSING plugins | `CustomerReorderWidget` removed: 5 plugins | rewired to the CartReorder feature the project already had |
| 3 — publisher wiring (publish check) | `spy_<entity>_storage` emptied: `<Entity>WritePublisherPlugin` not registered | registered in `PublisherDependencyProvider` (see *Publish check*) |
| 4 — config / transfers | 0 constant problems; 3 `strict` mismatches | `strict` matched to core at both levels |

Remaining `upgrade-debt` markers: 1 (the boot-lifecycle shim above).

### Constant overrides whose core value changed

`check-constant-overrides.php`: 20 project constants redeclare a vendor constant; 10 differ from core
and are the project's existing configuration (listed in `constant-overrides-report.json`, unchanged).
Core changed or removed the value under three of them:

| Constant | Source | Lane | Core before → now | Project value | Decision |
|---|---|---|---|---|---|
| `<Module>DependencyProvider::QUEUE_NAME` | class | 3 | `'<module>.sync'` → `'<module>.sync.v2'` | `'<module>.sync'` | restated the old default; the developer confirmed the pin is not intended — constant deleted, the core value applies |
| `<Module>Config::BATCH_SIZE` | class | 4 | `100` → `200` | `500` | kept — a setting core still supports |
| `<Module>Constants::HOST_KEY` (extends the core `<Module>Constants`) | interface | 4 | `'<MODULE>:HOST'` → removed | `'<MODULE>:HOST'` | core renamed the key to `<Module>Constants::HOST_URL`; project constant deleted, `config_default.php` entries moved to the new key |

## Deprecations (Lane 3, gate #3)

| Item | Replacement | Difference | Consequences | Developer's choice |
|---|---|---|---|---|
| `<OldCartExpanderPlugin>` | `<NewCartExpanderPlugin>` | same interface, adds bundle items | none beyond the swap | applied |
| `<OldCheckoutPreConditionPlugin>` ×2 | one `<NewCheckoutValidatorPlugin>` | two plugins consolidate into one | picked the checkout pre-condition list to keep it | applied |
| `<OldOrderSavePlugin>` | `<NewOrderPostSavePlugin>` | different extension point (post-save) | runs after persistence; order of side effects changes | deferred — needs a business check |
| `<OldPriceDimensionPlugin>` | none named | — | behaviour still used | kept |

Applied: 10. Deferred: 5. Kept: 5. Deferred and kept items stay listed here for the next upgrade.

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

600 tests in the container with the pinned invocation: 590 green, 10 red. 3 suites skipped (no
webdriver). Every test added at gate #1 is green. Failures that are not in the baseline:

| Kind | Count | Note |
|---|---|---|
| App damage | 0 | — |
| Harness damage | 2 | a moved `SprykerTest` helper; test-side usage fixed |

### Tests already red before the upgrade

These 10 fail identically on `codecept-baseline.txt` and are not upgrade damage (most assert core
behaviour the project customises):

- `PyzTest\Zed\<Module>\...` — 6 tests
- `PyzTest\Yves\<Module>\...` — 4 tests

## Back Office crawl and verifier (Phase 4.9, Phase 6.5)

| Check | Result |
|---|---|
| First crawl after boot | every table data endpoint 500 — `spryker/application` newer than `spryker/silexphp` allowed; the lagging package was bumped |
| Final crawl | 0 problems outside the baseline; the 2 `inBaseline` pages still fail (pre-existing) |
| `spryker-verifier` | 14 criteria: 13 PASS, 1 BLOCKED (a Merchant Portal page needs a merchant user not in the seed data) |

## Publish check (Phase 0, Phase 4.9, Phase 5.5)

`storage-search-counts.php --compare before after`. Both snapshots meet the validity conditions: the
same `data/import` set and the same stores, a full import (`docker/sdk up --data`), and the
publish queues drained before each snapshot (`queue:worker:start --stop-when-empty`, every queue at
length 0).

| Snapshot | Tables | Rows |
|---|---|---|
| `before` (Phase 0, pre-upgrade lock) | 60 | 1 200 000 |
| `after`, first (Phase 4.9) | 62 | 1 160 000 |
| `after`, final (Phase 5.5) | 62 | 1 210 000 |

| Table | Before | After, first | After, final | Status | Cause and resolution |
|---|---|---|---|---|---|
| `spy_<entity>_storage` | 50 000 | 0 | 50 000 | emptied, resolved | the `<entity>-storage` major publishes through `<Entity>WritePublisherPlugin`, which the project `PublisherDependencyProvider` did not register; registered in Lane 3, re-published with `publish:trigger-events -r <entity>`, queues drained, `after` re-taken |
| `spy_<new_entity>_storage`, `spy_<new_entity>_search` | — | 10 000 | 10 000 | appeared | tables the new release adds; informational |

Final comparison: exit 0 — no table dropped, emptied or disappeared.

## New features (gate #4) and removed packages

20 NEW packages listed; the developer chose none for now. The performance guideline fixes are not
offered at this gate; they are under *Performance fixes*. REMOVED: `spryker-shop/customer-reorder-widget`
(replaced by the CartReorder feature), `<eco-package>` (dropped, Lane 5).

## Lane 5 — dropped: `<eco-package>`

No release supports `spryker/gui` 5, and it calls a gui form type that 5.x removed, so widening
constraints upstream would not help. Footprint: one project module, 3 plugin registrations, package slices
in two Back Office modules and their templates. Removed in one commit (revertable). Kept: a project
form field whose only link to the package was a `template_path` hook; the form fields wrapped in
the package's `{% embed %}` were unwrapped with their markup intact.

## Performance fixes (Phase 6.2)

Guidelines read from `spryker/spryker-docs` at `<commit>` (`performance-fixes.json`);
`check-performance.php --verify`: exit 0 — `<N>` pages: `<n>` derived, `<n>` no-fix; `<M>` fixes:
`<n>` applied, `<n>` not-applicable (`<n>` of them `operator:`), `<n>` not-applied, `<n>` reverted.
Every applied fix is its own commit; its comparison covered guest, customer and business user on the
pages it touches. The report has one table per page, in the order of the status file; this example
shows three pages with fixes and two without.

**`<Page A title>`** — `<docs.spryker.com URL of page A>`

| ID | Fix | Status | Files or reason | Evidence |
|---|---|---|---|---|
| `<page-a>-1` | `<package set the page names>` | applied | `composer.lock` | `<package-a>` 1.47.0 → 1.49.0, `<package-b>` 1.33.0 → 1.35.0; Back Office crawl as baseline; storefront-catalog and -cart identical for 3 customer types |
| `<page-a>-2` | `<config switch the page names>` | applied | `src/Pyz/Yves/<Module>/<Module>Config.php`, `config/Shared/config_default.php` | `<console command>` exit 0; navigation identical for 3 customer types |
| `<page-a>-3` | `<smaller default page size>` | not-applied | proposal: `<n>` instead of `<m>` products per catalog page; users see fewer products per page | `config/Shared/config_default.php:<line>` |

**`<Page B title>`** — `<URL of page B>`

| ID | Fix | Status | Files or reason | Evidence |
|---|---|---|---|---|
| `<page-b>-1` | Storage reads in a loop → one multi-read | applied | `src/Pyz/Client/<Module>/Reader/<Name>Reader.php` | `--scan-code` candidate `storage-read-in-loop` confirmed at line `<n>`; new Client test green before and after; product detail page identical for 3 customer types |
| `<page-b>-2` | No key scans at request time | not-applicable | the one `getKeys()` call is in a console command | `src/Pyz/Zed/<Module>/Communication/Console/<Name>Console.php:<line>` |
| `<page-b>-3` | Optimised Composer autoloader | reverted | `classmap-authoritative` left `src/Generated` classes unresolvable in the install recipe | `Class "Generated\Shared\Transfer\<X>Transfer" not found` during `docker/sdk up` |

**`<Page C title>`** — `<URL of page C>`

| ID | Fix | Status | Files or reason | Evidence |
|---|---|---|---|---|
| `<page-c>-1` | `<hosting resources>` | not-applicable | operator: CPU, RAM and `pm.max_children` sized to the data | no setting in the repository |
| `<page-c>-2` | Exact-match criteria as filters | reverted | the clause contributes to relevance: result order changed on the search page | search "`<term>`": positions 3 and 4 swapped for all customer types |

**`<Page D title>`** — no-fix: the page describes a profiling procedure and names no project change.

**`<Page E title>`** — no-fix: linked page not published (`git ls-tree`: no such file).

Proposals for the developer (the `not-applied` fixes): `<page-a>-3`, … — each with the change it
proposes, as in the rows above. To pass on (`operator:`): `<page-c>-1`, …

## What is proven and what is not

- Proven in the container: composer resolves, the console boots, the Zed and Glue functional suites
  match the baseline, every Back Office page answers as in the baseline, and every storage and search
  table holds at least the rows it held before on the same data.
- Proven statically only: the modules outside pricing, cart and checkout (the developer declined
  tests there at gate #1).
- Not run: acceptance suites (no webdriver) — matrix #23 behaviour changes in the storefront remain
  unproven there; the verifier covered the pages listed in its report.

## Open items

1. The deferred `<OldOrderSavePlugin>` replacement (post-save semantics).
2. The BLOCKED verifier criterion — needs a merchant user.
3. The `not-applied` performance proposals and the `operator:` items (see *Performance fixes*).

Suggested PR: one for this release group.
