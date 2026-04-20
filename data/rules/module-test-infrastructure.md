---
name: module-test-infrastructure
description: Use when adding tests to a module that does not yet have a test suite. Before planning test files, verify that codeception.yml exists, composer.json require-dev includes spryker/testify, autoload-dev maps SprykerTest\\ correctly, and required transfer XML is declared.
---

**Architecture rule**
When a user story adds tests to a module that does not yet have a test suite, check and create the required infrastructure before planning the test files.

Check:
- `{ModuleName}/codeception.yml` — must exist for test runner to discover the suite
- `composer.json` `require-dev` — must include `spryker/testify`
- `composer.json` `autoload-dev` — must map `PyzTest\\` to `tests/PyzTest/`
- Transfer XML — any transfers used only in tests may need to be declared
