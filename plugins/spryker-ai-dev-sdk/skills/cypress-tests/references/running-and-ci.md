# Running tests and what CI enforces

Commands, environment selection, and the two CI jobs that gate a PR. Run the same commands
locally that CI runs and most failures surface before you push.

## Contents

- [Commands](#commands)
- [Environments](#environments)
- [Prerequisites](#prerequisites)
- [CI jobs](#ci-jobs)
- [Triaging a failure](#triaging-a-failure)

## Commands

All of these run from `<e2e-dir>` — the suite directory located in SKILL.md Step 0
(`tests/cypress-boilerplate/` by default). It's a self-contained npm project, not a workspace of
the root repo, so running them from the repo root will not work:

```bash
cd <e2e-dir>

npm ci                      # first time, and after any package.json/package-lock.json change
npm run cy:open             # interactive runner (local environment)
npm run cy:run              # headless, all specs, local environment
npm run cy:run:docker       # headless, from inside the Docker network

npm run lint:check          # eslint
npm run prettier:check      # formatting
npm run code:check          # both checks
npm run code:fix            # autofix both, then re-run code:check
```

Target a single spec or directory while iterating — a full run is slow enough that it
discourages the tight loop you want while authoring:

```bash
npx cypress run --spec "cypress/e2e/storefront/cart/storefront-cart-smoke.cy.ts"
npx cypress run --spec "cypress/e2e/storefront/cart/*.cy.ts"
npx cypress run --env environment=ci --spec "cypress/e2e/storefront/**/*.cy.ts"
```

## Environments

Selected with `--env environment=<name>`, defaulting to `local`. `cypress.config.ts` validates
the name against `ci`, `local`, `testing`, `staging`, `production` and throws on anything else;
only two files ship today:

| File | Used by |
|---|---|
| `.envs/.env.local` | local runs against your `docker/sdk` stack |
| `.envs/.env.ci` | the `cypress-e2e` CI job |

These files carry the app URLs (`STOREFRONT_URL`, `BACK_OFFICE_URL`, `GLUE_URL`, `MP_URL`,
`GLUE_BACKEND_URL`), `PROJECT_LOCATION` (the relative path up to where `docker/sdk` lives), and
the configuration-derived fixture placeholders (`DEFAULT_PASSWORD`,
`PRODUCT_PRICE_ABOVE_THRESHOLD`, …). `GLUE_URL` also becomes Cypress's `baseUrl`.

No `.env` setup step is needed to start — the committed `.envs/` files cover both environments.
`isLocal()`, `isCI()`, and `isDocker()` from `support/e2e.ts` are available when a spec genuinely
must branch on environment.

### Where a value comes from

Three sources, applied in this order — later wins, which matters when a value seems to ignore
your edit:

1. **`.envs/.env.<environment>`** — the committed per-environment defaults.
2. **A project-root `.env`** (optional, loaded first and *not* overwritten by `.envs/`) — use it
   only for a secret that shouldn't be committed, and remember it takes precedence.
3. **Store context resolved from the running shop** — assigned last, so it always wins.

That third source is easy to miss and explains a whole class of confusion:
`STORE_NAME`, `LOCALE_NAME`, `LOCALE_PREFIX`, `CURRENCY_CODE`, and `COUNTRY_ISO2` are **never
configured**. At startup the config POSTs a `getAllowedStore` operation to
`$GLUE_BACKEND_URL/dynamic-fixtures` and derives them from the shop's own default store, so the
suite follows whatever store/locale/currency the environment actually has. Don't add them to
`.envs/` — a value there would be discarded.

The practical consequence: that endpoint is a hard dependency of *every* run, since the same
endpoint also builds all dynamic fixtures. If it's unreachable the config throws before any spec
starts, with the message *"Could not resolve the store context from … The same endpoint creates
every fixture, so no spec can run without it."*

## Prerequisites

A running Spryker stack the URLs point at. If containers are down, start them with the
`spryker-docker-sdk` skill. Dynamic fixtures are created through the shop, so a spec cannot
pass against a stopped environment — an immediate failure in the global `before()` hook
(rather than in an `it`) usually means the stack is unreachable or still booting.

## CI jobs

Two jobs in `.github/workflows/ci.yml` block the PR. Their `working-directory:` is the
authoritative record of `<e2e-dir>` on this project — if you're unsure which directory CI
actually runs, read it from there:

| Job | What it runs |
|---|---|
| `cypress-quality-gate` | `npm run lint:check` and `npm run prettier:check` |
| `cypress-e2e` | `npx cypress run --env environment=ci --headless --browser chrome` against a `docker/sdk`-booted acceptance stack |

`cypress-e2e` needs `js-validation`, `validation`, and `cypress-quality-gate` to pass first —
so a lint or formatting slip blocks the E2E run entirely and costs you a full cycle. Running
`npm run code:check` locally is the cheapest way to avoid that.

On failure the job uploads a `cypress-e2e-failed-artifacts` bundle containing
`cypress/data/screenshots/**` and `cypress/data/reports/**`. The screenshot of the failing step
is usually faster to read than the log.

**Retries hide flake.** `cypress run` retries a failing test twice (`retries.runMode: 2`;
`openMode: 0`, so the interactive runner doesn't). A test that fails on attempt 1 and passes on
attempt 2 is reported as passing, so a green CI run can still contain a genuinely flaky spec.
When the mochawesome report shows retried attempts, treat it as a defect to fix now — usually a
fixed `cy.wait`, or a missing `"synchronize": true`.

## Triaging a failure

| Symptom | Likely cause |
|---|---|
| `Could not resolve the store context from …` before any spec | `GLUE_BACKEND_URL` unreachable — stack down or still booting. Nothing can run until it answers |
| Fails in the global `before()`, before any test | Stack unreachable/booting, or a fixture operation is invalid |
| `Fixture placeholder {{X}} could not be resolved` | Missing key in `.envs/.env.<environment>` — **unless** it's `STORE_NAME`/`LOCALE_NAME`/`LOCALE_PREFIX`/`CURRENCY_CODE`/`COUNTRY_ISO2`, which come from the shop; then the store lookup is what failed |
| Fixture field is `undefined` in the spec | Operation has no `key`, or the fixture type doesn't match the JSON |
| Product not found when searching the storefront | `"synchronize": true` missing, so publish & sync hadn't landed |
| Passes locally, fails on CI | Timing (a fixed `cy.wait`), or a value hardcoded instead of read from fixtures/env — but check the runner row below before either |
| Format/locale string differs by environment | **Different browsers, different ICU.** `cy:open`/`cy:run` are Electron, which ships its own ICU; CI runs `--browser chrome`. Any `Intl` / `toLocaleString` / date-format assertion can differ between them — UAH renders `грн` under Electron and `₴` under Chrome and the app's PHP formatter. Reproduce with `npx cypress run --browser chrome` before blaming the app, and pass explicit `Intl` options (`currencyDisplay: 'narrowSymbol'`) instead of relying on defaults |
| Passes alone, fails in a full run | Cross-spec state — the spec mutates data it didn't create |
| Assertion passes but the resulting order/record is wrong | The spec asserted an optimistic DOM value before the ajax mutation persisted — see `conventions.md` § Optimistic UI vs. persisted state |

Run any spec that asserts a formatted value (price, date, number) with `--browser chrome` at
least once before calling it done — that's what CI uses, and it's the cheapest way to catch the
ICU divergence above.

### Is this the app or the harness?

The two most expensive failures on record were both **harness** defects reported as product bugs,
each costing a full developer bug-report round trip. Before filing an app bug, rule out all four:

1. **A shared formatting helper.** `cy.formatDisplayPrice` and friends are project-local suite
   code, not builtins (`conventions.md` § Specs). A mismatch that differs only in currency symbol
   form, separator, or casing — same amount — is the helper.
2. **Runner ICU.** Reproduce with `--browser chrome` before trusting an Electron-only failure.
3. **Optimistic DOM vs. persisted state.** Re-`visit()` and re-read the value; if it changes, the
   spec was asserting the optimistic DOM, not the server.
4. **A retried-attempt flake.** Check the mochawesome report for a test that passed on attempt 2
   or 3.

Only after all four are ruled out: reproduce the flow by hand in a browser and quote *that* in the
bug report, not the Cypress assertion.
