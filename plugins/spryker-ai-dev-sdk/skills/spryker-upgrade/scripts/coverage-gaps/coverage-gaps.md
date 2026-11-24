# coverage-gaps.php

Static analyser that finds project code without meaningful tests. It doesn't run tests and doesn't need Xdebug or
PCOV: it parses `src/` and each `tests/<Org>Test/` directory with `nikic/php-parser`, loads classes with Reflection and follows calls
the way the Spryker kernel wires them.

It answers four questions:

1. Which project classes and methods are not reached by any test?
2. Which classes extending core (overrides) are not reached by any test?
3. Through which entry point (Facade, Client, Service, Plugin, Controller) should a test be written to cover them?
4. Which code has no caller in production at all, so it should be deleted instead of tested?

## Requirements

The script checks these at startup. If something is missing it prints what to install and exits with code 2.

| Requirement | Version | Why | Install |
|---|---|---|---|
| PHP | >= 8.1 (tested on 8.3 and 8.4) | `match`, nullsafe calls, `ReflectionClass::isEnum()`. Below 8.0 the file can't even be parsed, so the check can't run | — |
| Composer dependencies | `vendor/autoload.php` in the project root | autoloads project, core and parser classes | `composer install` |
| `nikic/php-parser` | ^5.0 (tested on 5.7) | parses PHP files; uses the v5 API `ParserFactory::createForNewestSupportedVersion()` | `composer require --dev nikic/php-parser:^5.0` |
| `spryker/kernel` | any current version | the analysed project must be a Spryker project; the kernel's naming conventions are used to resolve classes | — |
| `symfony/form`, `spryker/gui` | optional | only used by the `--skip-ui-definitions` rules; without them those rules don't match | — |
| `phpunit/phpunit` | >= 10 (tested on 11.5) | only for the tests (attributes such as `#[DataProvider]`) | `composer require --dev phpunit/phpunit` |

The `spryker-sdk/ai-dev` package requires `nikic/php-parser` (>= 5.0) and `spryker/kernel`, so a project that has the
package installed has both.

The project root is the working directory, or the nearest directory above it that has `composer.json` and a Spryker
source or config tree. Set `SPRYKER_PROJECT_ROOT` to name it explicitly. The location of the script is never used:
`bootstrap.php`, one level above the script, finds the root, as it does for the other `spryker-upgrade` scripts.

## Running

From the project root. `$UP` is the `scripts/` directory of the `spryker-upgrade` skill. The script loads project
classes, so use a PHP that can load them: host PHP, or `docker/sdk cli php <path>` when the script is inside the
project tree. Progress goes to stderr, the report to stdout.

```bash
php $UP/coverage-gaps/coverage-gaps.php                        # full report
php $UP/coverage-gaps/coverage-gaps.php --module=Cart          # one module
php $UP/coverage-gaps/coverage-gaps.php --hide-unused --skip-ui-definitions > report.txt
php $UP/coverage-gaps/coverage-gaps.php --json > report.json
```

A full run takes about 30 seconds.

## Flags

All flags are optional and can be combined.

| Flag | Effect |
|---|---|
| `--layer=Zed` | Report only one application layer: `Zed`, `Client`, `Yves`, `Glue`, `Service` or `Shared` |
| `--module=Cart` | Report only one module |
| `--only-untested` | Hide partially tested classes and keep only classes without any test |
| `--no-entries` | Skip the "test via" lookup and section 3. Faster |
| `--no-unused` | Skip unused-code detection and section 4 |
| `--hide-unused` | Detect unused code, then drop it from all lists and counts. Section 4 is not printed. Wins over `--no-unused` |
| `--skip-ui-definitions` | Also skip form definitions, form expander plugins and table definitions (see [Skip rules](#skip-rules)) |
| `--skip-layer=Yves,Glue` | Ignore layers the project doesn't use, for example a project without a storefront or without Glue. Comma-separated or repeated, any case. Classes of those layers are not reported or counted, are not production callers for the unused-code check (code only they call becomes unused), and are never suggested as "test via". An unknown layer name exits with code 1 |
| `--show-skipped` | List the skipped methods per reason in the summary. By default only the count per reason is printed |
| `--why=FQCN` | Print to stderr the call paths from entry points to the untested methods of a class. Write `/` instead of `\`, because `docker/sdk` strips backslashes: `--why=Pyz/Zed/Cart/Business/Model/Foo` |
| `--root=DIR` | Analyse another project directory. Its `src/<Org>` dirs are autoloaded; `vendor/` is still this project's. Used by the tests |
| `--json` | Print the result as JSON instead of the text report |
| `--help` | Print the flags |

## Report

### 1. Project classes

Classes without a core parent, grouped by `Layer/Module`.

```
  ✗ Pyz\Zed\Foo\Business\Model\Bar                      ← ✗ no test at all, ◐ partially tested (n/m methods)
      file:      src/Pyz/Zed/Foo/Business/Model/Bar.php
      untested:  doX(), doY() [unused]                    ← [unused] = no production caller
      test via:  Pyz\Zed\Foo\…\FooFacade::doSomething()  ← entry point to test it through
```

The "test via" line can also say:

- `directly (public API of this class); registered in XDependencyProvider::getYPlugins()`: the class is itself an entry point.
- `— unused in production code: delete instead of testing`: every untested method is unused.
- `— no entry point found`: nothing in the module API creates it. This is dead code or wired dynamically.
- `unit test of …` (Glue and Yves only): see [Glue and Yves: unit-test suggestions](#glue-and-yves-unit-test-suggestions).

### 2. Classes extending core

The same layout, plus `extends: <core class>`. A class counts as core-extended when its nearest non-project ancestor
is a real core class, from `SprykerShop`, `SprykerEco`, `Spryker`, `SprykerSdk` or `SprykerFeature`. Kernel classes
(`Spryker\*\Kernel\*`) and core bases named `Abstract*` don't count, so a plugin extending `AbstractPlugin` is a
project class.

### 3. Core entry points that execute the untested overrides

Section 2 inverted: for each entry point, the untested overrides it executes, sorted by how many it covers. For
Facade, Client and Service entry points it also lists the registered plugins that call that method, with the
DependencyProvider method that registers them.

### 4. Unused code

Methods and classes with no caller in production code. Tests are excluded, so a method used only by tests is unused.

- `(whole class unused)`: every method of the class is unused.
- `[tests only]`: only tests call it. The test keeps dead code alive.

### Summary

The summary has:

- the number of classes and methods in scope and the percentage reached by tests;
- the counts of untested and partially tested classes, for project classes and for core-extended classes;
- the number of unused methods, or how many were hidden with `--hide-unused`;
- the thin plugins counted as covered through their delegates, with the list;
- the number of skipped methods per reason; the list of methods only with `--show-skipped`.

## Logic

### Scope: what is analysed

- **Project dirs:** every directory in `src/` except `Generated`, `Orm` and `SprykerConfig`. Each one is a project
  namespace that resolves before core, in the order of `KernelConstants::CORE_NAMESPACES`.
- **Excluded paths:** `/Dependency/` (bridges and plugin interfaces), `/Controller/`, `/Persistence/Propel/`, `/Theme/`.
- **Excluded files:** `*Config.php`, `*DependencyProvider.php`, `*Factory.php`, `*Interface.php`, `*Constants.php`,
  `*Controller.php`, `*Stub.php`.
- **Excluded classes:** interfaces, traits, abstract classes, enums, and classes without methods of their own.
- **Counted methods:** methods declared in the class itself, except `__construct`:
  - public methods;
  - protected and private methods **only when they override** a parent or interface method, i.e. the new behaviour of
    an override (for example `PriceManager::applyOriginUnitPrices()` or a Console `execute()`).

  Pure protected/private helpers are not counted: a test of the public method of the same class covers them. Methods
  inherited from a parent or a trait are counted in the class that declares them.
- **Unused detection checks every method,** helpers included: a dead protected method is still reported in section 4.

Excluded classes are still analysed: calls are followed through them. They just aren't reported.

### Coverage: what "reached by a test" means

Every method of every test class in a `tests/<Org>Test/` directory is a starting point: `tests/PyzTest/` by default,
another name in a project with its own namespace. `_support`, `_generated`, `_data` and
`_output` are skipped. From there the analyser builds a call graph. A method is tested when the graph reaches it.

To resolve calls, the analyser infers types:

| Source | How |
|---|---|
| `$this->tester->getFacade()`, `getClient()`, `getService()`, `getFactory()`, `getRepository()`, `getEntityManager()` | Module taken from the test namespace `<Org>Test\<Layer>\<Module>\…`; `getFacade('Other')` uses the given module |
| `getLocator()->foo()->facade()` / `client()` / `service()` / `queryContainer()` | Resolved for module `Foo` |
| Kernel getters inside classes: `getFactory()`, `getFacade()`, `getRepository()`, `getConfig()`, … | Resolved for the class's own module and layer, project namespaces first |
| `getProvidedDependency(XDependencyProvider::CONST)` | Follows the `$container->set(CONST, closure)` in the module's DependencyProvider, including plugin stack getters overridden in Pyz |
| `new X(...)` | Constructor arguments are mapped to properties, including promoted properties and arguments forwarded to `parent::__construct()` |
| `$x->addStep($y)`, `setPlugins(...)` on a local object | Setter arguments are mapped to properties **per factory method**. A step added in `createTaxImporter()` doesn't leak into other importers built from the same class |
| Method arguments | Argument types flow into the callee's parameters |
| Properties without a known value | Declared or `@var` type; `XInterface` resolves to `X` in the same module, project first |
| `foreach`, `catch (X $e)`, local variables | Tracked within the method |
| `$this->stub->call('/module/gateway/action')` | Resolved to `GatewayController::actionAction()` in Zed |
| `create(XForm::class)` | Edges to the form's `buildForm()`, `configureOptions()`, … |
| `parent::m()`, `self::m()`, `static::m()`, `$this->m()` | Resolved against the runtime class, so template methods in an abstract parent reach the subclass implementation |

**Mocks don't count.** `createMock()` returns no type, so calling a method on a mock doesn't mark anything as tested.

### Thin plugins: covered through their delegates

A `*Plugin` method counts as covered without its own test when all of these hold:

- it has no logic: at most 3 statements and no `if`, loops, `switch`, `match`, ternary or `try`;
- it calls at least one project method that is in the report's scope;
- every such method is tested.

Calls to factories, config and kernel getters are ignored for this check. The rule doesn't verify which arguments the
plugin passes on.

### Skip rules

Skipped methods are neither listed nor counted. The summary prints how many were skipped per reason; `--show-skipped`
also lists them.

| Reason | Rule |
|---|---|
| application bootstrap | Class name ends with `Bootstrap`. The whole class is skipped |
| routing/resource configuration plugin | Class implements `ResourceInterface`, `JsonApiResourceInterface`, `ResourceRoutePluginInterface`, `ProtectedRouteAuthorizationConfigProviderPluginInterface` (Glue) or `RouteProviderPluginInterface` (Yves). The whole class is skipped; routing is verified by HTTP tests |
| client method only calls a Zed stub | `*Client` method in the Client layer without logic whose only project callees are `*Stub` methods (factory calls aside). The logic lives in Zed |
| declarative: only returns constant data | The whole body is `return <data>;`, where data is a scalar, a constant, `X::class`, an array of those, `new X(<data>)` or fluent setters with data on it, such as `(new XTransfer())->setA('a')->setB(Y::B)` |
| form/table definition (`--skip-ui-definitions` only) | In classes implementing Symfony `FormTypeInterface`: `buildForm()`, `configureOptions()`, `getBlockPrefix()`, `getName()` and `add*()` methods without branches, loops or closures. Form expander plugins (any interface named `*FormExpanderPluginInterface`, e.g. `ProductAbstractFormExpanderPluginInterface`, `MerchantFormExpanderPluginInterface`): the whole class, logic included. In classes extending Gui `AbstractTable`: `configure()`. Form **data provider** expanders (`*FormDataProviderExpanderPluginInterface`) are not skipped: they load data |

The rules are checked in this order: class rules (bootstrap, routing, form expander plugins), stub-only, UI definitions,
declarative. A method
gets the first reason that matches.

### Entry points ("test via" and section 3)

A second call graph is built for the modules that have gaps, starting from:

- the module's Facade, Client and Service, public methods;
- plugins and event subscribers of the module that are registered with `new X()` in any project DependencyProvider;
- project classes of the module named `*Plugin`, `*Console`, `*Widget`, `*Facade`, `*Client` or `*Service`;
- project and core controllers of the module, `*Action()` methods.

For each untested method the analyser walks the graph backwards and stops at the first entry points it meets.
`--why` prints those paths.

### Glue and Yves: unit-test suggestions

Glue and Yves modules have no Facade, so a class there is often reached only from a controller action, i.e. only over
HTTP. Pointing at the controller would suggest an HTTP test, which this script can't see. So when **every** entry point
of a Glue or Yves class is a controller action, the report suggests a unit test instead:

```
  ✗ Pyz\Glue\CustomerCompanySyncBackendApi\Mapper\CustomerCompanySyncMapper
      untested:  mapRequestAttributesToRequestTransfer(), …
      test via:  unit test of Pyz\Glue\CustomerCompanySyncBackendApi\…\CustomerCompanySyncProcessor::assignCustomerToCompany() — it runs this class
                   create it with CustomerCompanySyncBackendApiFactory::createCustomerCompanySyncProcessor()
                   reached over HTTP from Pyz\Glue\CustomerCompanySyncBackendApi\…\CustomerCompanySyncResourceController::postAction()
                   (an HTTP test - Glue API Cest, Cypress - also covers it, but HTTP tests are not counted by this script)
```

- **What to test:** the first method the controller calls on the path to the untested class. Factory methods and the
  controller's own helpers (such as `executeIndexAction()`) are skipped. If that method belongs to the untested class
  itself, the line says `unit test of this class: …`; otherwise `… — it runs this class`.
- **create it with:** the module factory method (project or core) that instantiates that class. Create the object the
  same way in the test, with the real factory.
- **reached over HTTP from:** the controller actions on the path, for context.

If any entry point isn't a controller (a plugin, a widget or a Facade), the normal "test via" list is shown.

### Unused code

A third call graph covers all project code, every method of every class, with tests excluded. Layers ignored with
`--skip-layer` are left out of this graph, of the class references and of the Twig scan.

A method is **live** when it is reachable from one of these roots:

- **Framework-called methods:** magic methods, controller `*Action()` methods, and methods that override or implement
  a non-project class or interface (core plugin interfaces, core overrides, Symfony and Codeception contracts).
- **Names referenced dynamically:** a method name used in a call whose receiver type is unknown, in a string literal
  (callables such as `[$this, 'x']`), or in a Twig template (`item.name` counts for `name`, `getName`, `isName` and
  `hasName`).

A class is unused as a whole when nothing in production code references it. References are `new X`, `X::m()`,
`X::class`, `instanceof X`, `catch (X)` and `extends X`, searched in the project dirs, `config/` and `public/`. Classes
the kernel resolves by naming convention are never reported as unreferenced: `<Module>Facade`, `Client`, `Service`,
`Repository`, `EntityManager`, `QueryContainer`, `Config` and controllers.

## JSON output

`--json` prints a single object:

```json
{
    "projectOrgs": ["Pyz"],
    "summary": {"classes": 476, "methods": 1293, "testedMethods": 599, "hiddenUnusedMethods": 0},
    "gaps": {
        "Pyz\\Zed\\Foo\\Business\\Model\\Bar": {
            "section": "project | core-extended",
            "core": "Spryker\\… or null",
            "file": "src/Pyz/…",
            "untested": true,
            "missing": ["doX"],
            "unusedMissing": ["doX"],
            "testVia": ["Pyz\\Zed\\Foo\\Business\\FooFacade::doSomething"],
            "suggestedUnitTests": [
                {"test": "Pyz\\Glue\\…\\BarProcessor::process", "createWith": "BarFactory::createBarProcessor()", "reachedFrom": ["Pyz\\Glue\\…\\BarResourceController::postAction"]}
            ]
        }
    },
    "entryPoints": {"Pyz\\…\\FooFacade::doSomething": ["Pyz\\…\\ExtendedBar"]},
    "unused": {"Pyz\\…\\Orphan": {"methods": ["run"], "testsOnly": [], "wholeClass": true}},
    "coveredByDelegates": {"Pyz\\…\\BarPlugin": ["expand"]},
    "skippedStubOnly": {"Pyz\\…\\FooClient": ["getRemote"]},
    "skipped": {"Pyz\\…\\FooClient": {"getRemote": "client method only calls a Zed stub"}}
}
```

## Limitations

- **HTTP tests are not followed.** Glue Cest, Yves acceptance and Cypress tests call endpoints, so classes behind
  controllers look untested even when an HTTP test covers them. For Glue and Yves the report therefore suggests a
  unit test of the class the controller calls.
- **Analysis is approximate.** Argument types are merged over all call sites, so "test via" can list more entry
  points than strictly needed. Dispatch on runtime data, such as `switch ($type)` in
  `DataImportBusinessFactory::getDataImporterByType()`, counts every branch as reachable.
- **Unresolved calls keep code alive.** A call on an untyped receiver keeps every method with that name alive, so
  unused detection misses some dead code rather than reporting live code.
- **Naming heuristics.** The `Abstract*` and `*Bootstrap` rules match on class names.

## Tests

The tests run the script as a separate process against a fixture project with one example per rule. The examples and
the expected result for each are described in [`coverage-gaps/fixture/README.md`](coverage-gaps/fixture/README.md).

```bash
vendor/bin/phpunit --no-configuration $UP/coverage-gaps/coverage-gaps/CoverageGapsTest.php
```

The tests pass the project root to the script as `SPRYKER_PROJECT_ROOT`: the value of that variable when it is set,
otherwise the nearest directory above the test file that has `composer.json` and `vendor/autoload.php`.

The fixture extends and implements core classes of `Cart`, `CartExtension`, `GlueApplicationExtension`,
`ProductManagementExtension` and `ShopApplication`. The tests for those rules fail when the `vendor/` in use does not
have these classes. Set `SPRYKER_PROJECT_ROOT` to a project that has them.

The fixture contains dead and untested code on purpose.
