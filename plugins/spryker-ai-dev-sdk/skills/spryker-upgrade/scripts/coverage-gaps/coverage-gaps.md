# coverage-gaps.php

Static analyser that finds project code without meaningful tests. It does not run tests and does not need Xdebug or
PCOV. It parses `src/` and each `tests/<Org>Test/` directory with `nikic/php-parser` and follows calls the way the
Spryker kernel wires them.

`check-test-coverage.php` stays the Phase 0.5 gate (`src/Pyz`, per module, no class load). Run `coverage-gaps.php`
when a gate result needs detail per class and method.

It answers these questions:

1. Which classes extending core (overrides) and which project classes have methods that no test reaches?
2. Through which entry point (Facade, Client, Service, Plugin, Controller) should a test be written to cover them?
3. Which code has no caller in production, so it should be deleted instead of tested?
4. Which classes and files could it not analyse?

The analysis rules are in [`coverage-gaps-logic.md`](coverage-gaps-logic.md).

## Requirements

The script checks these at startup. If something is missing, it prints what to install and exits with code 2.

| Requirement | Version | Note |
|---|---|---|
| PHP | >= 8.1 | — |
| Composer dependencies | `vendor/autoload.php` in the project root | Run `composer install` |
| `nikic/php-parser` | ^5.0 | See [Install nikic/php-parser](#install-nikicphp-parser) |
| `spryker/kernel` | any current version | The analysed project must be a Spryker project |
| `symfony/form`, `spryker/gui` | optional | Used only by the `--skip-ui-definitions` rules |

### Install nikic/php-parser

Do not add `nikic/php-parser` to the project with `composer require`. A change of the project lock can block the
upgrade (see "When the new tools cannot resolve against the old code" in `references/tooling-and-docker-sdk.md`).

1. Check the version that the project has: `composer show nikic/php-parser`. Version 5 or higher is sufficient.
2. If the project has no version 5, install the parser outside the project:
   `composer require --working-dir=<tools dir> nikic/php-parser:^5.0`.
3. Set `COVERAGE_GAPS_PARSER_AUTOLOAD=<tools dir>/vendor/autoload.php` for the run. The script loads this parser
   instead of the version of the project.

The project root is the working directory, or the nearest directory above it that has `composer.json` and a Spryker
source or config tree. Set `SPRYKER_PROJECT_ROOT` to name it explicitly.

## Running

Run from the project root. `$UP` is the `scripts/` directory of the `spryker-upgrade` skill. Use a PHP that can load
the project classes: host PHP, or `docker/sdk cli php <path>` when the script is inside the project tree.

```bash
php $UP/coverage-gaps/coverage-gaps.php                  # summary of the whole project
php $UP/coverage-gaps/coverage-gaps.php --module=Cart    # one module
php $UP/coverage-gaps/coverage-gaps.php --json           # full report on stdout
```

A full run takes between 15 and 30 seconds. Progress goes to stderr.

## Flags

All flags are optional and can be combined. An unknown flag, a flag without its value, an unknown layer and a
`--layer` or `--module` that matches no project class stop the run with exit code 2.

| Flag | Effect |
|---|---|
| `--layer=Zed` | Report only one application layer: `Zed`, `Client`, `Yves`, `Glue`, `Service` or `Shared` |
| `--module=Cart` | Report only one module |
| `--only-untested` | Hide partially tested classes. Keep only classes without any test |
| `--no-entries` | Skip the "test via" lookup and the entry points. Faster |
| `--no-unused` | Skip unused-code detection |
| `--hide-unused` | Detect unused code, then drop it from all lists and counts. Wins over `--no-unused` |
| `--skip-ui-definitions` | Also skip form definitions, form expander plugins and table definitions |
| `--skip-layer=Yves,Glue` | Ignore layers that the project does not use. Comma-separated or repeated, any case. Classes of those layers are not reported or counted, are not production callers for unused code, and are not entry points |
| `--show-skipped` | List the skipped methods. By default the summary gives only their count |
| `--why=FQCN` | Print to stderr the call paths from entry points to the untested methods of a class. Write `/` instead of `\`, because `docker/sdk` removes backslashes: `--why=Pyz/Zed/Cart/Business/Model/Foo`. A class that is not in the scope of the run stops the run with exit code 2 |
| `--root=DIR` | Analyse another project directory. Its `src/<Org>` directories are autoloaded; `vendor/` stays the one of the project root |
| `--limit=N` | Findings per section in the summary. Default 10; 0 prints the counts only |
| `--json` | Print the full report as JSON on stdout instead of the summary |
| `--help` | Print the flags |

## Output

The script always writes the full report to `<state dir>/coverage-gaps-report.json` (state dir: see `bootstrap.php`,
default `.spryker-upgrade/state/`). Without `--json`, stdout is a summary:

```
coverage-gaps: FOUND core-extended without test 1, core-extended partial 0, project without test 12, project partial 2, unused methods 7, not analysed 0
Scope: 30 classes, 60 methods, 20 reached by tests (33.3%), 2 reachable but not proven, 10 skipped, 1 covered by thin delegates.

Core-extended classes with untested methods: 1 (1 without any test)
  ✗ Pyz\Zed\Cart\Business\Model\Foo extends Spryker\Zed\Cart\Business\Model\Foo: bar(); test via Pyz\Zed\Cart\…\CartFacade::addItems()

Project classes with untested methods: 14 (12 without any test)
  ✗ Pyz\Zed\Foo\Business\Model\Bar: doX(), doY() [unused]; test via Pyz\Zed\Foo\…\FooFacade::doSomething()
  ◐ Pyz\Zed\Foo\Business\Model\Baz (1/3 methods tested): run() [not proven: setDependency()]; test via directly
  (4 more in the report)

Entry points that run the untested core-extended classes: 1
Unused code: 7 methods in 4 classes
Full report: .spryker-upgrade/state/coverage-gaps-report.json
```

- The first line is the verdict with the counts. `FOUND` when the exit code is not 0.
- Each section prints at most `--limit` findings and the number of findings left out.
- `✗` = no method of the class is tested; `◐` = some methods are tested.
- `[unused]` = the method has no caller in production code. When every untested method is unused, the line says
  "delete instead of testing".
- `[not proven: <reason>]` = a test reaches the method, but the test does not prove it (see
  [Limits](#limits)). The method counts as untested.
- "test via" names the entry point to write a test through. "directly" = the class is itself an entry point. "unit test
  of …" (Glue and Yves) = the class is reached only from controller actions: test the named method with a unit test
  and create its object with the named factory method.
- A section "Could not analyse: N classes" comes before the other sections when classes or files could not be
  analysed: a class that does not load (fatal error, missing parent), a parse error in a source or test file, or a file
  whose class name does not match its path. Fix these first: the counts leave them out.
- The path of the report file is the last line.

### Exit codes

| Code | Meaning |
|---|---|
| 0 | Every class was analysed, and every core-extended class has at least one tested method |
| 1 | Core-extended classes have no test |
| 2 | Usage or requirements error |
| 3 | Classes or files could not be analysed. The report is incomplete |

### JSON report

The report file and `--json` contain one object:

| Key | Content |
|---|---|
| `verdict`, `exitCode`, `counts` | The verdict line |
| `summary` | `classes`, `methods`, `testedMethods`, `notProvenMethods`, `hiddenUnusedMethods` |
| `notAnalysed` | `[{kind, name, file, reason}]`: classes and test files that could not be analysed |
| `gaps` | Per class: `section` (`project` or `core-extended`), `core`, `file`, `untested`, `missing`, `notProven` (method → reasons), `unusedMissing`, `testVia`, `suggestedUnitTests` |
| `entryPoints` | Entry point → the core-extended classes it runs |
| `unused` | Per class: `methods`, `testsOnly` (called only from tests), `wholeClass` |
| `coveredByDelegates`, `skippedStubOnly`, `skipped` | Thin plugins counted as covered; skipped methods with their reason |
| `projectOrgs`, `ignoredLayers` | The analysed project namespaces and the layers ignored with `--skip-layer` |

## Limits

- **HTTP tests are not followed.** Glue Cest, Yves acceptance and Cypress tests call endpoints, so classes behind
  controllers look untested even when an HTTP test covers them. For Glue and Yves the report suggests a unit test of
  the class that the controller calls.
- **Mocks and skips.** A path through a factory replaced with `setFactory()`, a factory method replaced with
  `mockFactoryMethod()` or a project class replaced with `createMock()` (and similar calls) does not count. A test
  method counts only up to a `markTestSkipped()` or `markTestIncomplete()` statement. A test method with a
  conditional skip, or with `setDependency()` and a value that is not `new X()`, makes what it reaches "reachable, not
  proven". The analyser does not see mocks that a test helper outside the test class creates.
- **Traits are not reported.** Their methods count neither in the trait nor in the class that uses it.
- **Analysis is approximate.** Argument types are merged over all call sites, so "test via" can list more entry
  points than necessary. Dispatch on runtime data, such as `switch ($type)` in a factory, counts every branch as
  reachable.
- **Unresolved calls keep code alive.** A call on an untyped receiver keeps every method with that name alive, so
  unused detection misses some dead code rather than reporting live code.
- **Naming rules.** The `Abstract*` and `*Bootstrap` rules match on class names.

## Tests

The tests and their fixtures are maintainer tooling in this repository, not in the plugin:
`tests/scripts/coverage-gaps/CoverageGapsTest.php`.
