# coverage-gaps.php fixture project

A tiny Spryker-like project used by `../CoverageGapsTest.php`. Every class is an example of one rule of
`../../coverage-gaps.php`. The code is parsed and reflected, never executed.

Run the analyser on it, from the project root (`$UP` is the `scripts/` directory of the `spryker-upgrade` skill):

```bash
php $UP/coverage-gaps/coverage-gaps.php --root=$UP/coverage-gaps/coverage-gaps/fixture
```

Run the tests:

```bash
vendor/bin/phpunit --no-configuration $UP/coverage-gaps/coverage-gaps/CoverageGapsTest.php
```

## Layout

| Path | Purpose |
|---|---|
| `src/Example/`, `src/ExampleExtra/` | Two project dirs: every dir in `src/` is project code |
| `src/Generated/` | Ignored, like `Orm` and `SprykerConfig` |
| `tests/PyzTest/Zed/Gizmo/Business/GizmoFacadeTest.php` | The test that sets the expected results; it calls the facade through `$this->tester->getFacade()` |
| `tests/ExampleTest/Zed/Gizmo/Business/GizmoFacadeCalculateTest.php` | A second test namespace: every `tests/<Org>Test/` directory is read. It calls a facade method that the first test also calls, so it changes no expected result |

## Examples

Classes are in `src/Example/Zed/Gizmo/` unless stated otherwise.

| Class | Rule | Expected result |
|---|---|---|
| `Business/GizmoFacade` | Facade methods called from the test count as tested | Partial: `format`, `runOtherSteps`, `addItems` missing |
| `Business/Model/Calculator` | Reached via facade → factory → `new Calculator()` | Partial: `unusedHelper` missing and unused; the protected `unusedProtected()` is not counted but is reported as unused |
| `Business/Model/Rounder` | Constructor dependency (promoted property) | Tested |
| `Business/Model/Formatter` | Only a mock (`createMock`) is used in the test; `pad()` is a pure protected helper | Untested, test via `GizmoFacade::format()`; only `format()` is counted |
| `Business/Model/DefaultStep` | `$importer->addStep(...)` in the tested factory method | Tested |
| `Business/Model/OtherStep` | Same `StepImporter` class, but configured by an untested factory method (setter injection is tracked per call site) | Untested, test via `GizmoFacade::runOtherSteps()` |
| `Communication/Plugin/GizmoExpanderPlugin` | Plugin stack resolved via `GizmoDependencyProvider` | Tested |
| `Business/Model/HelloGreeter` | Template method called from the abstract project parent `AbstractGreeter` | Tested, not unused |
| `Business/Model/ExtendedStorageProvider` | Extends core `NonPersistentProvider` | Section 2, entry point `GizmoFacade::addItems()` |
| `Communication/Plugin/CalculatorItemExpanderPlugin` | Thin plugin delegating to tested code | Counted as covered by delegates |
| `Communication/Plugin/FormatterItemExpanderPlugin` | Thin plugin, but the delegate is untested | Reported |
| `Communication/Plugin/ConditionalItemExpanderPlugin` | Delegate is tested, but the plugin has its own logic (`if`) | Reported |
| `src/Example/Client/Gizmo/GizmoClient::getRemoteGizmo()` | Client method that only calls a Zed stub | Skipped |
| `src/Example/Client/Gizmo/GizmoClient::readLocal()` | Client method with a client-side dependency | Reported |
| `Business/Model/Orphan` | Referenced nowhere | Unused, whole class |
| `GizmoFacade::legacyOnlyInTests()` and `Business/Model/Legacy` | Called only by tests | Unused, `[tests only]` |
| `Communication/Console/GizmoConsole` | `execute()` is called by the framework; production caller of the facade | Not unused |
| `Communication/Controller/IndexController` | Controllers are excluded from the report but are production callers | Not reported |
| `*BusinessFactory`, `*DependencyProvider`, `*Stub`, `*Interface` | Infrastructure files | Not reported |
| `Business/Model/MandatoryColumns` | `getMandatoryColumns()` returns an array of constants; `getDefaultQuote()` builds a transfer from constants | Both skipped as declarative; `isSatisfiedBy()` reported |
| `src/Example/Glue/GizmosRestApi/Plugin/GizmosResourceRoutePlugin` | Implements `ResourceRoutePluginInterface` | Whole class skipped (routing) |
| `src/Example/Yves/GizmoPage/Plugin/Router/GizmoPageRouteProviderPlugin` | Implements `RouteProviderPluginInterface` | Whole class skipped (routing) |
| `src/Example/Glue/GizmoApplication/Bootstrap/GizmoBootstrap` | `*Bootstrap` | Whole class skipped |
| `Communication/Form/GizmoForm` | Symfony form | Default: only `getBlockPrefix()` skipped (declarative). With `--skip-ui-definitions`: `buildForm()`, `getBlockPrefix()` and `addNameField()` skipped; `addTypeField()` (has an `if`) and `normalizeName()` stay |
| `src/Example/Glue/GizmosRestApi/Processor/GizmoProcessor` | Reached only from `GizmosResourceController::getAction()` (through a controller helper) | `unit test of this class`, create with `GizmosRestApiFactory::createGizmoProcessor()` |
| `src/Example/Glue/GizmosRestApi/Mapper/GizmoMapper` | Behind the processor | `unit test of GizmoProcessor::process() — it runs this class` |
| `Communication/Plugin/GizmoProductAbstractFormExpanderPlugin` | Form expander plugin (`*FormExpanderPluginInterface`), has a loop and an `if` | Reported by default; whole class skipped with `--skip-ui-definitions` |
| `Communication/Plugin/GizmoProductAbstractFormDataProviderExpanderPlugin` | Form data provider expander (loads data) | Always reported |
| `src/Example/Yves/GizmoPage/Controller/GizmoController` | The only production caller of `GizmoClient::readLocal()` | With `--skip-layer=Yves`, `readLocal()` and `LocalReader` become unused; no `Example\Yves` or `Example\Glue` class is reported |
| `Communication/Table/GizmoTable` | Gui `AbstractTable` | With `--skip-ui-definitions`: `configure()` skipped; `prepareData()` stays |
