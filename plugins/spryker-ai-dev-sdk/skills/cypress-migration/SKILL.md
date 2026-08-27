---
name: cypress-migration
description: >
  Use this skill whenever the user wants to onboard a Spryker project onto a project-owned
  Cypress E2E baseline in place of Spryker's internal demo-shop test suites. Trigger on
  phrases like "migrate off spryker/cypress-tests", "remove the demo-shop cypress/robot
  suites", "set up project-owned cypress testing", "bootstrap cypress-boilerplate for this
  project", "onboard this project onto cypress", "integrate cypress-boilerplate", or any
  request to replace `spryker/cypress-tests` / `spryker/robotframework-suite-tests` with a
  project's own Cypress setup. This is a one-time setup/migration skill — it removes the old
  suites, vendors in the already-adapted `tests/cypress-boilerplate/` implementation from the
  spryker-shop/b2b-demo-marketplace repository (the proven reference implementation), wires up
  CI, and generates the companion
  day-to-day `cypress-tests` skill for the target project. It is written to be project-agnostic
  for the *target* project: every step discovers the target's actual conventions (hostnames,
  fixtures, CI patterns) before acting rather than assuming they match the reference project.
---

# Cypress Migration: Demo-Shop Suites → Project-Owned Baseline

Failure-signature triage for the E2E steps lives in the Known-traps catalog: `../project-starter-wizard/references/pitfalls.md`.

Every step below discovers facts about the **target project** before acting. Do not assume
file names, job names, hostnames, or conventions from any other project — grep and verify in
the repo you're actually working in. Several steps below exist specifically because assuming
instead of verifying caused real mistakes the first time this migration was done; don't skip
the verification sub-steps even though they look like extra work.

### What is proven vs. what is not

Be honest with yourself about this while working, because the two halves of this migration have
very different track records in the reference repo (`spryker-shop/b2b-demo-marketplace`):

- **Proven (Steps 6–11):** the vendored `tests/cypress-boilerplate/` suite, its CI jobs, and the
  companion skill were built and debugged against a live Spryker B2B Marketplace instance.
- **NOT yet executed anywhere (the removal half — Step 2 here, plus the CI/config removal now owned
  by `project-ci-generator`):** the reference repo is Spryker's own *product* repo — it still needs
  its demo-shop suites, so it never deleted them. Its cypress-boilerplate work was purely
  **additive**: `spryker/cypress-tests` and `spryker/robotframework-suite-tests` are still in its
  `composer.json`/`composer.lock`, and the old Robot/Cypress CI jobs are still live there. So the
  removal instructions have never been run end-to-end — treat them as a careful plan, not a proven
  script; verify each deletion with the greps given rather than trusting the step.

Instead of deleting its own suites, the reference repo **labels everything an adopting project
should delete** — that inventory is the authoritative removal list. Under the wizard,
`project-ci-generator` consumes it (it owns the CI + deploy/install-pipeline/fixture removal, per
its `keep_suites` decision); this skill's own removal is limited to the Composer packages (Step 2).

## Step 1 — Confirm scope

```bash
grep -n "spryker/cypress-tests\|spryker/robotframework-suite-tests" composer.json
```

If neither package is present, this project doesn't need the Composer removal (Step 2) — skip
ahead to Step 6 (detect the locator convention) and Step 7 (vendoring) if the goal is just
adding a Cypress baseline to a project that never had these suites.

If the project uses a different combination (only one of the two packages, or additional
similar internal test packages), adapt the steps below accordingly rather than assuming both
are always present together.

## Step 2 — Remove Composer entries

> **Under the project-starter wizard this is pre-boot, where there is no `vendor/` yet.** So Step 2 there **edits `composer.json` only** (remove the `require-dev`/repository/installer entries); the lock-file + `vendor/` sync happens at the first boot's in-container `composer install`. The `composer update --lock` / `composer install` / `composer remove` commands below are for a **standalone, already-installed** project — don't run them pre-boot when `composer`/`vendor/` isn't there.

In `composer.json`, remove:
- The `require-dev` entries for the packages found in Step 1.
- Any `repositories` entries whose `url` points at these packages' git repos — but only if
  no other required package uses the same repository entry.
- `extra.installer-types` / `extra.installer-paths` entries **only if** they exist solely to
  support these packages. Verify first:
  ```bash
  grep -n "installer-types\|installer-paths\|spryker-test" composer.json
  ```
  Read the surrounding block — if `installer-paths` maps other packages too, only remove the
  entries specific to the removed packages, not the whole block.

Then sync the lock file and the installed tree. Note that `composer update --lock` only
rewrites `composer.lock` — it does **not** remove anything already installed under `vendor/`,
so it alone won't purge the old packages:
```bash
composer update --lock   # sync composer.lock with the edited composer.json
composer install         # actually remove the packages from vendor/
```
Alternatively, skip the manual `require-dev` edit above and let Composer do all three (edit
`composer.json`, update the lock, prune `vendor/`) in one step:
```bash
composer remove --dev spryker/cypress-tests spryker/robotframework-suite-tests
```
If `composer`/PHP isn't available in your environment, tell the user this step still needs to
be run and why (the lock file must reflect the require-dev removal).

Check for a leftover **untracked** install directory from a custom installer path (e.g. a
`tests/cypress-tests/`-style directory that was checked out by Composer via
`installer-paths`, not committed to git):
```bash
git status --porcelain <path-from-installer-paths>
```
If it shows `??` (untracked), it's a stale local build artifact — safe to delete. If it's
tracked, investigate before touching it; it may be legitimate project content.

## Step 3 — CI + deploy/install-pipeline removal is owned by `project-ci-generator`

The old suites' CI jobs and the deploy/install-pipeline/fixture configs those jobs referenced are
removed by **`project-ci-generator`**, not here. It is the CI skill, it decides the
robot/acceptance-fixture lane from the interview `keep_suites`, and it already rebuilds the
pipeline — so keeping this removal here too would re-edit the same files (`.github/workflows`,
`config/install/*.yml`, `.github/deploy/*.yml`) at a second point in the run.

- **Under the wizard:** ci-generator (step 1) has already done it. Verify rather than redo —
  `grep -rln "cypress\|robot" .github/workflows/ config/install/ .github/deploy/ 2>/dev/null`
  should surface only the KEPT new stack (`…cypress-boilerplate.yml`); an old-suite job or config
  that survives is a ci-generator gap to report, not something to fix here.
- **Standalone (no ci-generator run):** do the removal per ci-generator's "a dropped suite's job
  pulls support files out with it" guidance — remove the old suites' CI jobs and the
  `.github/deploy/*.yml` / `config/install/*.yml` configs (and fixture dirs) they alone reference,
  honouring its two traps: near-identical kept-vs-dropped filenames (`…cypress-boilerplate.yml` kept
  vs `…cypress.yml` dropped — delete by which surviving job references it), and a `*_ROBOT.yml`
  fixture config that may be shared with the regular demodata import (grep the literal filename
  repo-wide and read the actual `source:`/`command:` lines before deleting).

## Step 4 — (folded into Step 3)

Deploy and install-pipeline config removal is part of Step 3's `project-ci-generator` ownership
above — nothing separate here. (Step number kept so later step references don't shift.)

## Step 5 — Clean up `.gitignore`

Remove now-dead ignore entries tied to the removed suites (old install path, old result-output
directories). Don't remove ignore entries you're not sure are dead — grep first.

## Step 6 — Detect the project's existing locator convention

**Do not assume any locator attribute convention.** Check what the project's own templates
already use:
```bash
grep -rl "data-qa=" src/ --include="*.twig" 2>/dev/null | wc -l
grep -rl "data-testid=" src/ --include="*.twig" 2>/dev/null | wc -l
grep -rl "data-cy=" src/ --include="*.twig" 2>/dev/null | wc -l
```
(Adjust the template glob for the project's actual templating — Twig, JSX, Blade, etc.)

- If one convention already has real usage in the project's templates, use that one — adding
  a second, competing convention creates long-term maintenance drift.
- If none exist yet, `data-qa` is a reasonable default — it's what the b2b-demo-marketplace
  reference implementation (Step 7) already uses throughout — but **confirm this choice with
  the user explicitly** before proceeding — it's a project-wide convention that's expensive to
  reverse once tests and templates are built on it.

## Step 7 — Vendor the `tests/cypress-boilerplate/` implementation from b2b-demo-marketplace

The source of truth is the **already-adapted, already-battle-tested** copy committed at
`tests/cypress-boilerplate/` in `spryker-shop/b2b-demo-marketplace`. That copy has had real
bugs found and fixed against a live Spryker B2B Marketplace instance (locator fixes, OMS
transition timing/race fixes, search-index sync timing, DataTables-based list filtering, etc.).
Vendoring from it means the target project starts from a working baseline instead of
rediscovering the same bugs from scratch.

```bash
git clone --depth 1 https://github.com/spryker-shop/b2b-demo-marketplace.git <scratch-dir>

# ALWAYS verify the source directory is actually present before copying. If this fails, the
# clone landed on a branch that predates the boilerplate — see the fallback below.
ls <scratch-dir>/tests/cypress-boilerplate/package.json

rsync -a --exclude='.git' --exclude='node_modules' \
  <scratch-dir>/tests/cypress-boilerplate/ <project>/tests/cypress-boilerplate/
```

**If that `ls` fails**, `tests/cypress-boilerplate/` has not been merged into the default branch
yet and you must clone the branch that carries it (it was developed on
`add-cypress-boilerplate`). Don't skip the check and let `rsync` fail with a bare "No such file
or directory":
```bash
git ls-remote --heads https://github.com/spryker-shop/b2b-demo-marketplace.git   # find the branch
git clone --depth 1 --branch <branch-with-the-boilerplate> \
  https://github.com/spryker-shop/b2b-demo-marketplace.git <scratch-dir>
```

Pick a destination path that is **not** the old (now-removed) installer-path directory and is
**not** covered by a stale `.gitignore` rule from Step 5 — `tests/cypress-boilerplate/` is the
established convention; only deviate from it with an explicit reason. Verify:
```bash
git check-ignore -v <project>/tests/cypress-boilerplate   # must produce no output
```

Then adapt, discovering each value rather than assuming a default is correct:
- `package.json`: set `name`/`description` to the project's own.
- **Store facts are resolved from the running shop, not configured — this is what makes the suite
  portable with no per-helper edits.** `cypress.config.ts`'s `resolveStoreContext()` POSTs
  `getAllowedStore` to glue-backend `/dynamic-fixtures` once per run and writes
  `STORE_NAME`/`LOCALE_NAME`/`LOCALE_PREFIX`/`CURRENCY_CODE`/`COUNTRY_ISO2` into `config.env`; specs
  read them via `Cypress.env()`, fixtures via `{{PLACEHOLDER}}`. So do **not** put
  store/locale/currency/country in `.envs` — a hand-set value reintroduces a second source of truth.
- `.envs/.env.local` / `.envs/.env.ci` / etc.: set the endpoint URLs — `BACK_OFFICE_URL`/
  `STOREFRONT_URL`/`GLUE_URL`/`MP_URL` and **`GLUE_BACKEND_URL`** (mandatory — the store-context
  resolution above dies without it) — to the project's actual local/CI hostnames; grep existing CI
  workflows or install configs for `*.spryker.local` (or the project's domain convention) rather
  than trusting the reference project's hostnames. Plus the values that are genuinely not store
  properties, each with a one-line reason it can't be resolved:
  - `PROJECT_LOCATION` — relative path from the vendored Cypress dir back to the repo root (e.g.
    `../..` when Cypress runs with `tests/cypress-boilerplate` as its cwd, two dirs deep). Set it in
    **every** `.envs/.env.<environment>`, including local — a value only set for CI silently no-ops
    any CLI-exec step (OMS transitions, etc.) when run locally instead of failing loudly.
  - `DEFAULT_PASSWORD` — the password every fixture-created customer/admin/merchant user is given.
  - `PRODUCT_PRICE_ABOVE_THRESHOLD` — a fixture price above the store's hard-minimum and below its
    soft-minimum `spy_sales_order_threshold`; no API exposes those thresholds, so it lives here.
- **Verify `cy.formatDisplayPrice` exists and is store-derived** — post-vendor, before any spec
  asserts a price. A naive implementation gets this wrong in ways that only surface on non-EUR
  stores: hardcoding `€` with `toLocaleString('en-US')` breaks on any non-EUR store, and calling
  `Intl.NumberFormat` with no `currencyDisplay` misses the narrow-symbol requirement below. Grep
  `cypress/support/cy-commands/` for it and require all three: it exists;
  it takes currency and locale from the resolved `CURRENCY_CODE`/`LOCALE_NAME` rather than
  literals; it passes `currencyDisplay: 'narrowSymbol'`. Without that last option ICU's default
  differs per runtime — UAH renders `грн` under Electron's bundled ICU and `₴` under
  Chrome (what CI uses) and the app's own PHP formatter. Signature of skipping this: a price
  assertion failing on a string that differs only in symbol form, read as an app bug; it cost a
  full false-failure investigation.
- **One fact the shop can't expose — ship a helper in the project test namespace.** Fixtures need the
  currency's DB id (`fkCurrency`), which no API returns. Add a ~10-line `CurrencyDataHelper` extending
  `SprykerTest\Shared\Currency\Helper\CurrencyDataHelper` that overrides `getCurrencyByIsoCode()` →
  `$this->getCurrencyFacade()->fromIsoCode($isoCode)`, in the **project** test namespace
  (`tests/<Ns>Test/Shared/Currency/_support/Helper/`, or `tests/PyzTest/Shared/Currency/_support/Helper/`
  for keep-Pyz), and enable it in `tests/PyzTest/Zed/TestifyBackendApi/codeception.dynamic.fixtures.yml`.
  The helper file and that enable line are a pair — one without the other is a dangling reference. (No
  `codecept build` needed — the fixtures endpoint runs helpers directly, not via the generated actor.)
- **BO/MP specs depend on the `getBackofficeUILocales()` override** on any project whose store locales
  exclude `en_US`/`de_DE` — without it a BO admin on the project locale sees empty product lists. It's a
  general Back Office requirement owned by `define-stores` (see its Back Office interface-language rule),
  not a cypress one; if BO/MP specs are kept it's a prerequisite for them to pass.
- If Step 6 determined the project's convention differs from `data-qa` (what this reference
  copy already uses throughout), update the vendored page-object selectors accordingly.
- **Fixture data:** the converted specs provision their own data per run via `POST /dynamic-fixtures`
  (merchant/warehouse/product/price/customer created with the resolved store facts), so they do **not**
  assert on the project's demodata — nothing to match against `data/import`. The demodata check below
  applies **only** to any spec still on the legacy static path (reading `cypress/fixtures/*-data.json`):
  ```bash
  grep -rl "<fixture-customer-email>" data/import/common/ 2>/dev/null
  grep -rl "<fixture-product-sku>" data/import/common/ 2>/dev/null
  ```
  If a static spec's values aren't in the project's demodata, replace them with values that are — or
  convert the spec to a dynamic fixture. (B2B Purchasing Control has no Glue REST support — a customer
  whose business unit requires cost-center/budget selection makes Glue-API setup 422 regardless of the
  code under test; avoid such customers in fixtures.)

## Step 8 — Add representative smoke tests

At minimum, prove the baseline works end-to-end with:
1. A storefront homepage → search/PDP → add-to-cart flow.
2. A full checkout flow.

Reuse whatever page objects/scenarios the vendored `tests/cypress-boilerplate/` copy already
ships (it already includes working examples for common Spryker flows — check
`cypress/support/page-objects/` and `cypress/e2e/` before writing new ones). Every test must
reset any state it mutates in a `before`/`beforeEach` hook (deterministic setup/cleanup) and
assert on specific, meaningful content rather than mere presence/visibility.

**When authoring or porting a dynamic fixture, three non-obvious constraints of the `/dynamic-fixtures` DSL** (`testify-backend-api`) — invisible until a `#key` reference explodes at runtime:
- **A `#key` reference can only point at a helper that returns an `AbstractTransfer`/`ArrayObject`.** A scalar-returning helper is silently discarded and a later `#key` dies with `Undefined array key`. This rules out chaining on `haveCurrency`/`haveLocaleStore`/… (they return `int`) — resolve the store via `getAllowedStore` (returns a `StoreTransfer`) instead.
- **References are one level deep and can't index arrays** — `#store.countries.0` yields the whole array, not element 0.
- **Resolve shop facts through glue-backend `/dynamic-fixtures`, never storefront Glue `/stores`** — `glue` is an optional app (`configure-services` can disable it), so a suite that reads storefront Glue becomes unrunnable on a headless project; glue-backend is the one backend the suite can't run without. (Required header `Content-Type: application/vnd.api+json`; `application/json` returns a bare 404 that looks like a missing route.)

**Storefront checkout specs must use a NON-marketplace payment method** (e.g. `dummyPaymentInvoice`) as long as their fixtures put a plain product in the cart — marketplace payment (`dummyMarketplacePaymentInvoice`) is filtered out of the form until a merchant **offer** is in the cart, so a spec selecting it hangs waiting for a radio that never renders. State this next to the `paymentMethodKey` fixture, or the next person "corrects" it back to the marketplace method. A spec that wants to exercise marketplace payment must add an offer to the cart first.

Run locally before moving on:
```bash
cd <project>/tests/cypress-boilerplate && npm ci
npm run lint:check && npm run prettier:check   # NOT `code:check` — see below
npm run cy:run
```

Do **not** use `npm run code:check` as a pass/fail gate. The boilerplate defines it as
`eslint . ; prettier . --check` — the `;` means the script exits with *prettier's* status, so a
real ESLint failure is silently reported as success. Always run `lint:check` and
`prettier:check` as separate commands (this is why the CI job in Step 9 runs them as two
separate steps). `code:check` is fine for eyeballing all issues at once, just not for gating.
(`cy:run` requires a locally booted instance of the target project — if one isn't available
in your environment, say so explicitly rather than claiming this step passed.)

## Step 9 — Wire CI, reusing the project's existing acceptance stack

Find the project's existing CI job that boots a full Docker/SDK stack for its other UI/API
tests (Codeception acceptance/functional, etc.) — reuse its `docker/sdk boot <deploy>.yml` /
`docker/sdk up` pattern rather than inventing a new deploy config:
```bash
grep -rn "docker/sdk boot" .github/workflows/*.yml
```

Add two jobs:
1. A fast, Docker-independent lint/format job (`npm ci && npm run lint:check && npm run
   prettier:check` in the Cypress directory) that runs on every push/PR.
2. A Docker-dependent E2E job that `needs:` the lint job (fail fast, avoid booting the full
   stack on a trivial style violation), boots the reused acceptance stack, sets up Node,
   installs the Cypress project's dependencies, and runs `npx cypress run --env
   environment=ci ...`, uploading screenshots/reports as artifacts on failure.

Those two jobs are **not on the default branch** — they ship in the boilerplate's own workflow
(`…cypress-boilerplate.yml`) on the `add-cypress-boilerplate` branch, the same branch Step 7 names
as the boilerplate's source (`git ls-remote` to confirm it still exists). If you can reach that
branch, copy the two jobs from there; if not, the inline shape above is authoritative — author the
two jobs to it. Place them **above** the `REMOVE FOR PROJECT` banner deliberately, so the old-suite
CI removal (owned by `project-ci-generator`) doesn't take them with it. The `cypress-e2e`
step order is worth reproducing,
because two of the steps exist to fix real flakiness rather than as boilerplate:

1. `actions/checkout` → `ramsey/composer-install` → `actions/setup-node` (Node 24, npm cache
   keyed on `tests/cypress-boilerplate/package-lock.json`) → `npm ci`
2. `docker/sdk boot <deploy>.yml`, add the stack's hostnames to `/etc/hosts`, `docker/sdk up -t`
3. **`docker/sdk console queue:worker:start --stop-when-empty`** — drain the queue, otherwise
   asynchronously-published data isn't visible to the UI yet. This drains what was published
   **before** the run; fixtures the specs create mid-run publish *during* it, and `synchronize: true`
   does not make search indexing synchronous — if a spec searches for its just-created SKU and gets 0
   results, run a **continuous** `queue:worker:start` (no `--stop-when-empty`) alongside the Cypress run.
4. **`docker/sdk console sync:data merchant` + drain the queue again** — warm the search index,
   otherwise merchant/product listings are empty on the first assertions
5. `npx cypress run --env environment=ci --headless --browser chrome` (`--browser chrome` relies
   on Chrome being preinstalled on the GitHub-hosted runner image; nothing in the repo installs
   or version-pins it, and the Cypress binary itself is downloaded on first use because only the
   npm cache is cached, not `~/.cache/Cypress`)
6. Upload `cypress/data/screenshots/**` and `cypress/data/reports/**` as artifacts, `if:
   always() && steps.<id>.outcome == 'failure'`

If you removed `spryker/cypress-tests` in Step 2, make sure you do **not** carry over any
workaround step that deletes its installer path (e.g. `rm -rf tests/cypress-tests`) — such a
step only exists while that package is still installed, and is dead weight afterwards.

Match the existing workflow file's formatting/indentation style exactly, then validate:
```bash
python3 -c "import yaml; yaml.safe_load(open('<workflow-file>')); print('OK')"
```

## Step 10 — Generate the companion day-to-day skill

Write `.claude/skills/cypress-tests/SKILL.md` in the target project (check
`.claude/skills/` for any existing skills first and match their frontmatter/structure
convention). It should document, using the values actually discovered/decided in this
migration (not generic placeholders):
- The vendored directory path and its naming conventions (spec/fixture/page-object naming).
- The **actual** locator convention decided in Step 6.
- The actual npm scripts available (`cy:open`, `cy:run`, etc. — confirm against the vendored
  `package.json`, don't assume names).
- The actual CI job names added in Step 9, so Claude can point developers at them.
- A quality-gate checklist matching what CI enforces: tests pass, lint/format clean, no
  brittle selectors (no raw `cy.get()` outside page objects; no positional/XPath selectors),
  deterministic setup/cleanup, and clear/specific assertions.

## Step 11 — Update project documentation

If the project's README (or equivalent) documents the old demo-shop suites, update it to
describe the new project-owned setup, its location, and a pointer to the new
`cypress-tests` skill.

## Final checklist (acceptance criteria)

- [ ] 1. `composer.json` and `composer.lock` no longer contain `spryker/cypress-tests` or
      `spryker/robotframework-suite-tests`.
      ```bash
      grep -n "spryker/cypress-tests\|spryker/robotframework-suite-tests" composer.json composer.lock
      ```
      must return nothing.
- [ ] 2. No active project command, configuration, or CI job depends on the removed demo-shop
      Cypress or Robot Framework suites. Repo-wide grep for the removed packages' names and
      deleted file names (deploy configs, docker-compose files, install pipelines) returns
      nothing outside historical git log; no remaining CI job's `needs:` references a job you
      deleted; data-import fixture configs still used by *other* pipelines were verified as
      still referenced (by `project-ci-generator`'s removal, which owns deploy/install-config
      pruning), not deleted by mistake. Every test-suite block the reference workflow marked for
      removal is gone, and the installer path is gone:
      ```bash
      grep -rn "REMOVE FOR PROJECT" .github/workflows/*.yml   # banner + its jobs should be gone
      ls -d tests/cypress-tests 2>/dev/null                   # must not exist
      ls .github/deploy/ config/install/ | grep -i "robot\|cypress"   # only cypress-boilerplate may remain
      ```
- [ ] 3. The target project's repository contains its own Cypress boilerplate — vendored
      under `tests/cypress-boilerplate/` (per Step 7), committed to the repo, not a Composer
      dependency and not re-fetched at CI/runtime.
- [ ] 4. Cypress can be installed and executed using documented project commands (`npm ci`,
      `npm run cy:run`, `npx cypress open`, etc.) without any Composer-based Cypress
      dependency. `npm run lint:check` **and** `npm run prettier:check` each pass in the
      vendored Cypress directory (run separately — `code:check` masks ESLint failures, Step 8).
- [ ] 5. At least one project-specific Cypress smoke test runs successfully against a
      configured target environment (or the limitation is stated explicitly if no environment
      was available to test against).
- [ ] 6. Test selectors, fixtures, test users, and assertions used by the initial tests are
      project-owned — verified against the target project's own demodata/templates (Step 6/7),
      not copied from the reference project's fixtures unexamined; no demo-shop-specific
      dependencies or locators remain.
- [ ] 7. A Claude Skill for Cypress (`cypress-tests`, generated in Step 10) is available in
      the project repository and contains clear, actionable instructions for Claude to create,
      run, review, and validate Cypress tests.
- [ ] 8. That Claude Skill defines the project's actual conventions for test structure,
      naming, locators, fixtures/test data, environment variables, and supported test
      commands — the real, discovered values, not generic placeholders.
- [ ] 9. That Claude Skill applies an executable quality gate that, at minimum, verifies tests
      pass, lint/format is clean, no brittle selectors are used (no raw `cy.get()` outside
      page objects, no positional/XPath selectors), setup/cleanup is deterministic, and
      assertions are clear and specific.
- [ ] 10. Cypress setup, initial tests, the Claude Skill, and quality-gate automation are all
      committed to the main repository — including the new/edited CI workflow YAML, verified
      to parse and to follow the existing file's formatting.
