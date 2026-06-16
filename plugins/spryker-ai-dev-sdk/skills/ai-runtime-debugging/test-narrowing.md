# Narrowing test execution (Part 3)

This file is the detail reference for **narrowing test runs in a Spryker project**. It assumes:

- You are working in a **project** (e.g. a B2B/B2C demoshop based on Spryker), NOT inside a core Spryker module under `vendor/spryker/`.
- Project tests live under `tests/<NamespaceTest>/<Layer>/<Module>/` — typically `tests/PyzTest/Zed/Foo` (test namespace comes from `composer.json autoload-dev.psr-4`; common form is `PyzTest\\`, but the project may use a different namespace).
- The runner is `docker/sdk testing` (the host-side wrapper for codecept-in-the-cli-container).

If you're working inside a core Spryker module (rare on project teams), the conventions differ — test root is `vendor/spryker/<module>/tests/SprykerTest/...` and you run codecept directly inside that package. This skill is **not** for that case.

`docker/sdk testing` accepts both Codeception's positional `<Suite> <ClassName>:<method>` form and `-g <Group>`. Pick the smaller hammer.

The examples below use `tests/PyzTest/Zed/Foo` for the project case. Substitute your project's actual test namespace path.

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
