---
name: cypress-tests
description: >
  Use this skill whenever the user wants to create, run, review, or validate Cypress end-to-end
  tests in this project. Trigger on phrases like "add a cypress test", "write an e2e test",
  "run the cypress tests", "run e2e tests", "check my cypress test", "review this cypress spec",
  "does this test pass the quality gate", "cypress smoke test", or any request to test the
  storefront, backoffice, merchant portal, or Glue API end-to-end.
---

# Cypress E2E Tests

This project's Cypress suite is a self-contained Node project — its own `package.json`, not a
workspace of the root repo and not a Composer dependency. It replaces Spryker's internal
demo-shop Cypress/Robot Framework packages, which are for Spryker's own core-feature testing
and aren't guaranteed to work on a customized project.

## Step 0 — Locate the suite (`<e2e-dir>`)

The suite was vendored in by the `cypress-migration` skill, which is free to choose the
directory name per project. `tests/cypress-boilerplate/` is the default and the common case,
but never assume it — locate the directory first and use it as `<e2e-dir>` everywhere this
skill (and its reference files) writes a path:

```bash
ls -d tests/*/cypress.config.ts 2>/dev/null || \
  find . -maxdepth 4 -name cypress.config.ts -not -path '*/node_modules/*'
```

Disambiguation, if more than one directory matches:

- The project-owned suite is **git-tracked** (`git ls-files <dir> | head -1` prints something).
  A Composer-installed `tests/cypress-tests/` leftover is untracked — that one belongs to
  Spryker's internal packages and is **not** what this skill covers.
- When still in doubt, the suite CI runs is the one named in `.github/workflows/ci.yml` under
  the Cypress jobs' `working-directory:`.

Everything inside `<e2e-dir>` is convention-fixed regardless of what the directory itself is
called — `cypress/e2e/`, `cypress/fixtures/`, `cypress/support/`, `.envs/` keep their names and
all inner paths below are relative to `<e2e-dir>`.

Two properties of this suite drive almost every decision below, so it's worth knowing them
before you write anything:

- **Specs never touch the DOM directly.** Selectors live in page objects, so a markup change
  is a one-line fix instead of a sweep across specs.
- **Test data is created per run, not assumed.** Each spec declares the records it needs and
  the shop builds them before the spec starts. Specs don't depend on demodata and don't
  collide with each other.

## Reference files

Read the one that matches what you're doing — they hold the detail and the real code examples,
so this file stays a workflow:

| File | Read it when |
|---|---|
| `references/conventions.md` | Writing or reviewing a spec, page object, or scenario — layout, naming, locators, page-object shape |
| `references/test-data.md` | Anything involving fixtures — the dynamic/static pair, placeholders, `#key.field` references, `synchronize` |
| `references/running-and-ci.md` | Running tests, environment selection, what CI enforces, triaging a failure |

The suite's own `<e2e-dir>/README.md` documents setup and the available fixture helper
operations in more depth; prefer it over guessing at a helper name.

## Step 1 — Orient before writing

Look at what already exists before adding anything. This suite is small enough to read, and
matching a neighbour is faster than deriving conventions from scratch:

1. **Find the closest existing spec** in `cypress/e2e/<app>/` — `<app>` is `storefront`,
   `backoffice`, `merchant-portal`, or `glue`. Read it end to end; it shows the current house
   style better than any description.
2. **Inventory reusable pieces** in `cypress/support/page-objects/` and
   `cypress/support/scenarios/`. Extending an existing page object is almost always right;
   duplicating selector logic is the thing this structure exists to prevent.
3. **Skim `references/conventions.md`** for the naming and locator rules if you're unsure
   where a new file goes or what to call it.
4. **Record a baseline before you author anything.** `npm run cy:run` once, or at minimum the
   specs that share the page objects, cy-commands and scenarios you plan to touch, and write
   down which ones are already red. On an already-red suite "fails before, passes after" proves
   nothing, and a pre-existing failure gets reported as a bug against your new work — that has
   happened. The baseline is what lets you name the pre-existing failures in the final report.

## Step 2 — Create a test

1. **Add or extend page objects** for any page the flow touches. Extend `AbstractPage`, build
   `PAGE_URL` from `Cypress.env(...)` values rather than a hardcoded host, expose getters that
   return elements and actions that return `void`. Getters take domain arguments (a SKU), not
   selectors.
2. **Declare the test data** as a `dynamic-*.json` / `static-*.json` fixture pair named after
   the spec and sitting beside it under `cypress/fixtures/<app>/<feature>/`. The loader resolves
   them from the spec's own path, so the names must match. Set `"synchronize": true` when the
   spec browses or searches a catalog, or the data won't be in Redis/Elasticsearch yet. See
   `references/test-data.md`.
3. **Type the fixtures** in `cypress/support/types/<app>/` and read them via
   `getFixtures<TDynamic, TStatic>()` in a `before` hook — that turns a fixture typo into a lint
   error instead of a runtime `undefined`. Re-export the new type file from that directory's
   `index.ts` barrel, like its neighbours; skipping it is a separate broken-import fix later.
4. **Write the spec** using `@support/*` and `@fixtures/*` aliases, with page-object calls for
   the steps and assertions on meaningful values taken from the fixtures (product name, price,
   order status) — not `.should('exist')` alone. An assertion should say what broke.
5. **Add a reset hook only if you need one.** Because fixtures are created per run the starting
   state is normally empty; reach for a scenario like
   `new GlueCartsScenarios().deleteAllShoppingCarts(email, password)` only when the spec mutates
   state it didn't create.

## Step 3 — Run it

From `<e2e-dir>` (see `references/running-and-ci.md` for the full set):

```bash
cd <e2e-dir>                              # tests/cypress-boilerplate unless renamed — see Step 0
npm ci                                    # first time / after package.json changes
npm run cy:open                           # interactive, local environment
npx cypress run --spec "cypress/e2e/storefront/cart/storefront-cart-smoke.cy.ts"
npm run cy:run                            # headless, everything, local environment
```

Iterate with `--spec` on the one file rather than full runs. Environment comes from
`--env environment=<name>` (default `local`), mapping to `.envs/.env.<environment>` — `local`
and `ci` ship committed; see `references/running-and-ci.md` for the full valid set. No `.env`
setup needed to start. A failure in the global `before()` rather than inside an `it`
usually means the stack isn't up: start it with the `spryker-docker-sdk` skill.

## Step 4 — Review before calling it done

This mirrors what CI blocks the PR on (`cypress-quality-gate`, then `cypress-e2e`). Check each
item yourself — don't report a test as complete without having run these:

- [ ] **Passes** — a targeted `--spec` run is green, and green when re-run (a spec that only
  passes the first time is holding state it should be creating). `cypress run` retries a failing
  test twice (`retries.runMode: 2`), so check the report for a test that passed on attempt 2 or 3:
  it's reported green but is really flake, and it will eventually fail CI.
- [ ] **Lint and formatting clean** — `npm run code:check`, or `npm run code:fix` then re-check.
  These gate the E2E job, so a formatting slip costs a whole CI cycle.
- [ ] **No selectors in the spec** — no `cy.get()`/`cy.visit()` in a `.cy.ts` file; no
  positional CSS, generated class hashes, or XPath anywhere.
- [ ] **No fixed `cy.wait(<number>)`** — raise the timeout on the specific slow getter, or wait
  on an intercepted alias. Fixed sleeps are the main source of CI-only flake. The inverse is
  mandatory too: every **ajax state mutation** (cart quantity, wishlist, shopping list) waits on
  an intercepted 2xx, and any value money or checkout depends on is re-read after a `visit()` —
  see `references/conventions.md` § Optimistic UI vs. persisted state. Skipping it produces a
  passing assertion and a wrong order.
- [ ] **Data is declared, not assumed** — no data whose existence the spec doesn't control:
  values come from the fixture pair, `.envs/`, or the resolved store context. A project-seeded
  catalog SKU is allowed only under the three conditions in `references/test-data.md`
  § Choosing dynamic vs. static, and never inline in the spec.
- [ ] **Assertions are specific** — each checks a real value, and a failure message would tell
  you what actually broke.
- [ ] **Blast radius covered** — a change under `support/cy-commands/`, `support/page-objects/`,
  `support/scenarios/` or `support/fixture-helper/` is not a one-spec change. `grep -rl <symbol>
  cypress/e2e` and run **every** spec that uses it (`--spec "a.cy.ts,b.cy.ts,…"`), not only the
  spec you wrote. One currency-helper fix reached 6 specs across all four apps and none of them
  were re-run.

If an item fails, fix it before reporting the test as done.

Then **report the choices, not just the result** — this phase silently decides things that have
more than one supportable answer, so state them:

- The **fixture strategy**: generated data or a project-catalog SKU, and if the latter, the
  coupling risk it carries (a catalog change breaks the spec).
- Every **shared primitive** touched, and which specs you re-ran for it.
- Which failures **pre-existed** your change, from the Step 1 baseline.

A report that omits these hands the reviewer a test they can't evaluate.
