# Narrowing test execution (Part 3)

This file is the detail reference for **narrowing test runs** with `docker/sdk testing`. Useful when debugging through tests (you almost never want the full suite) — but also useful any time you want to run one method instead of the whole class.

`docker/sdk testing` accepts both Codeception's positional `<Suite> <ClassName>:<method>` form and `-g <Group>`. Pick the smaller hammer.

## Where do project tests live?

The test root depends on what kind of code you're testing:

| You're testing… | Test root | Notes |
|---|---|---|
| Project code (this project's `src/<Namespace>/`) | `tests/<NamespaceTest>/<Layer>/<Module>/` — typically `tests/PyzTest/Zed/Foo` | The test namespace comes from `composer.json` `autoload-dev.psr-4` (this project: `PyzTest\`). If the project has more than one project namespace, each may have its own test root — check `autoload-dev`. |
| Core Spryker module (`vendor/spryker/<module>/`) | `vendor/spryker/<module>/tests/SprykerTest/<Layer>/<Module>/` | Only relevant if you're working inside a core package — uncommon in a project workflow. |

The examples below use `tests/PyzTest/Zed/Foo` for the project case. Substitute your real path.

## Single test or method (positional)

```bash
# Single class
docker/sdk testing codecept run -c tests/PyzTest/Zed/Foo Business FooFacadeTest

# Single method
docker/sdk testing codecept run -c tests/PyzTest/Zed/Foo Business FooFacadeTest:testFooBehavesCorrectly
```

Syntax notes:
- `-c <dir>` is **required** — `<dir>` is the directory containing `codeception.yml`. Without it you get `Suite was not loaded`.
- The first positional is the **suite name** (the key under `suites:` in `codeception.yml`, e.g. `Business`, `RestApi`).
- The second positional is `<ClassName>:<method>` — **single colon**, no `.php`. PHPUnit-style `::` does not work.
- `--filter` / `--grep` conflict with the wrapper's auto-added suite arg ("--filter and --grep can't be used with a test name"). Use positional filters or `-g` instead.

## The `@depends` gotcha (applies to both positional and `-g` filters)

Filtering to a single method excludes any method it `@depends` on, so Codeception **skips** the target with `SKIPPED: This test depends on … to pass`. Two ways out:

**Option A — run the whole class.** The `@depends` chain stays intact; use `--steps` to locate the method you care about in the output.

**Option B — `@group AITestCase` workflow.** Tag the target method AND every method in its `@depends` chain (transitively), then filter by group:

```php
/**
 * @group AITestCase
 */
public function testCreateFoo(): FooTransfer { /* ... */ }

/**
 * @depends testCreateFoo
 * @group AITestCase
 */
public function testUpdateFoo(FooTransfer $fooTransfer): void { /* ... */ }
```

```bash
docker/sdk testing codecept run -c tests/PyzTest/Zed/Foo -g AITestCase
docker/sdk testing -x codecept run -c tests/PyzTest/Zed/Foo -g AITestCase  # with Xdebug
```

## Cleanup

Before declaring work done, strip the temporary tags:

```bash
grep -rn '@group AITestCase' src/ tests/
```

Treat any remaining match as instrumentation you forgot to remove.

## Pitfalls (test-narrowing-specific)

| Symptom | Cause | Fix |
|---|---|---|
| `codecept run <file>.php` fails with "Suite was not loaded" | `-c` is required to point at the directory containing `codeception.yml` | Use `-c <dir> <Suite> <Class>:<method>` (single colon, no `.php`), or `-g <Group>`. PHPUnit-style `::` does not work. |
| Filtered test is skipped with "depends on … to pass" | Codeception's filter applies per-method, not transitively — `@depends` target gets excluded | Run the whole class, or tag every method in the `@depends` chain with the same `@group` |
