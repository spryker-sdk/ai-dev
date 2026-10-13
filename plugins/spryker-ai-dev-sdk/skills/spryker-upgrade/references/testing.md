# Writing the Phase 0.5 tests

Read this when the developer chose to write tests at gate #1, before writing the first one. Hand the
writing itself to the `codecept-functional` skill (and `cypress-migration` for storefront paths); this
file says what those tests must cover and what they must not.

## What a test here is for

A test written before the upgrade pins what a customisation does today, so it goes red if the
upgrade changes that. It counts only when it fails as soon as the behaviour changes and passes
whatever else the upgrade touches.

## What is never written

- **No tests for `*Factory`, `*Config` or `*DependencyProvider` classes.** A test that a factory
  method returns an instance of some class, that a config method returns some value, or that a
  dependency provider's stack contains some plugin pins wiring, not behaviour — it stays green while
  the behaviour behind the wiring changes. Dependency-provider stacks are covered by
  `check-plugin-usage.php` (MISSING, DEPRECATED, PORTING), which runs in every lane, and
  `check-test-coverage.php` rates a module with only wiring overrides as LOW risk with no test hint.
- **No getter or wiring tests** anywhere: nothing that only asserts which class or value a method
  returns.
- **No mocked host-PHP unit tests of models as the default.** A unit test that mocks the model's
  constructor dependencies exercises the mocks, while the upgrade changes the real collaborators.
- **No test per method or per class.** The unit is a behaviour an override changes.

There is no separate cap on the number of tests; the exclusions above bound it.

## What is written

- **Business behaviour through the public API of its layer**: the Facade for Zed, the public Client
  or Service for Yves, Glue and shared code. Call the Facade method a shopper's or an operator's action
  reaches, with realistic transfers, and assert the output the override changes.
- **One test per behaviour an override changes** — the hint `check-test-coverage.php` prints for a
  logic override says the same ("called through `<Module>Facade`"). Read the override, name the observable difference
  it makes against core (a price rounded differently, an item filtered out, a field copied into the
  order), and assert exactly that difference. Two overrides serving one behaviour share one test.
- **Functional tests, run in the booted Docker environment.** The collaborators are real, the database
  and the search backend are real, and the PHP minor is the deployed one:

  ```bash
  script -q /dev/null docker/sdk cli vendor/bin/console transfer:generate   # run first — see below
  script -q /dev/null docker/sdk testing codecept build
  script -q /dev/null docker/sdk testing codecept run -c tests/PyzTest/Zed/<Module>
  ```

- **Overridden templates**: one acceptance or Cypress path per customised page, asserting the
  project's own additions are on the page rather than re-testing core markup. The Back Office crawl
  (`backoffice-smoke.php`) already covers "every Back Office page renders", so do not write tests for
  that.

Keep them characterization tests: assert what the code does today, including quirks. When a test
fails on first run, assume the assumption was wrong before assuming the code is — an absent
array-typed transfer field yields `[]`, not `null`, and that is the behaviour to pin.

## Order and commit

1. Modules with HIGH risk and business-logic overrides, ranked by `check-test-coverage.php`.
2. Modules the developer named as business-critical (checkout, pricing, cart, order flow) even if the
   score ranks something else higher.
3. Overridden templates, if the developer asked for them.

Commit them on their own, before the upgrade branch diverges — they must be provably green against the
current version. A test written after the upgrade pins whatever the upgrade produced and cannot detect
a change.

## Practical notes

- `src/Generated` is gitignored, so a fresh checkout fails every test with
  `Class Generated\Shared\Transfer\* not found` until `transfer:generate` has run (matrix #71).
- Pin the exact suite invocation, environment variables included, next to the baseline numbers —
  `SPRYKER_TESTING_ENABLED=1` missing makes suites fail in a way that looks like upgrade damage
  (matrix #66).
- Use the project's existing Tester and helpers (`tests/PyzTest/**/_support`) and follow the suite's
  `codeception.yml` conventions; a new module suite copies the nearest existing functional suite's
  configuration rather than inventing one.
- No explanatory comments in the tests either. A test method name states the behaviour
  (`testCartTotalExcludesGiftCardItemsFromDiscount`), which is what the comment would have said.
