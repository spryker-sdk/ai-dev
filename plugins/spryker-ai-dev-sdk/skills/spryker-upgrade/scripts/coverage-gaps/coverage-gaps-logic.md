# coverage-gaps.php: analysis rules

This document is for maintainers of `coverage-gaps.php`. How to run the script and read its report:
[`coverage-gaps.md`](coverage-gaps.md).


## Scope: what is analysed

- **Project dirs:** every directory in `src/` except `Generated`, `Orm` and `SprykerConfig`. Each one is a project
  namespace that resolves before core, in the order of `KernelConstants::CORE_NAMESPACES`.
- **Excluded paths:** `/Dependency/` (bridges and plugin interfaces), `/Controller/`, `/Persistence/Propel/`, `/Theme/`.
- **Excluded files:** `*Config.php`, `*DependencyProvider.php`, `*Factory.php`, `*Interface.php`, `*Constants.php`,
  `*Controller.php`, `*Stub.php`.
- **Excluded classes:** interfaces, traits, enums, and classes without methods of their own. Abstract classes are
  reported: a method with a body counts in the abstract class that declares it. Abstract methods are not counted.
- **Counted methods:** methods declared in the class itself, except `__construct`:
  - public methods;
  - protected and private methods **only when they override** a parent or interface method, i.e. the new behaviour of
    an override (for example `PriceManager::applyOriginUnitPrices()` or a Console `execute()`).

  Pure protected/private helpers are not counted: a test of the public method of the same class covers them. Methods
  inherited from a parent are counted in the class that declares them. Methods of a trait are not counted: traits are
  not reported, and the class that uses a trait does not count the trait methods.
- **Unused detection checks every method,** helpers included: a dead protected method is still reported as unused.

Excluded classes are still analysed: calls are followed through them. They just aren't reported.

## Coverage: what "reached by a test" means

Every public method of every test class in a `tests/<Org>Test/` directory is a starting point (a seed):
`tests/PyzTest/` by default, another name in a project with its own namespace. `_support`, `_generated`, `_data` and
`_output` are skipped. Protected and private helpers of a test class are reached through the seeds that call them. From
the seeds the analyser builds a call graph. A method is tested when a seed reaches it on a path that the rules in
[Mocks and skips](#mocks-and-skips) do not stop.

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

## Mocks and skips

The analyser reads each seed, the test helpers it calls and the `setUp()`, `_before()` and `_setUp()` methods of its
class. These calls change what the seed proves:

| Call in the test | Effect |
|---|---|
| `markTestSkipped()` or `markTestIncomplete()` as a statement of the method body | The statements after it are ignored |
| `setFactory(...)` | Factory methods of the test module and the classes they return count as not reached from this seed |
| `mockFactoryMethod('createX', ...)` | `createX()` of any factory and the classes it returns count as not reached from this seed |
| `createMock(X::class)`, `createStub()`, `createPartialMock()`, `createConfiguredMock()`, `createTestProxy()`, `getMockBuilder()`, `getMockForAbstractClass()`, Codeception `Stub::make*()` / `construct*()`, for a project class `X` | `X` (for `XInterface`: also `X`) counts as not reached from this seed |
| `markTestSkipped()` or `markTestIncomplete()` inside a condition | The seed proves nothing: what it reaches is "reachable, not proven" |
| `setDependency(KEY, value)` where the value is not `new X()` or an array of `new X()` | The seed proves nothing: what it reaches is "reachable, not proven" |
| `mockFactoryMethod($name, ...)` with a name that is not a string literal | The seed proves nothing: what it reaches is "reachable, not proven" |

A method is tested when a seed without any of these calls reaches it, or when a seed with mocks reaches it on a path
that does not go through a replaced factory, factory method or class. A "reachable, not proven" method stays in the
untested list with its reason; it does not count as tested. Calls on a mock object return no type, so they reach
nothing.

## Class loading

The analyser uses Reflection for parents, interfaces and methods, so it loads the classes it analyses. It never loads
a project class that it did not first load in a child PHP process:

1. A child process loads every class of the project namespaces, one after the other, and prints the result per class.
2. A fatal error (for example an incompatible signature) stops the child process. The class it was loading is
   recorded with the error, and a new child process goes on with the next class.
3. A class that throws while it loads (for example a missing parent: "Class not found") is recorded with the message.
4. The analyser process loads only the classes that the child process loaded without error. Each recorded class is
   reported under "Could not analyse".

An autoloader for `src/<Org>/` of every project namespace is registered before Composer, in the child process and in
the analyser process. A stale or partial Composer class map does not hide project classes.

A file whose class name does not match its path is reported under "Could not analyse" too. Parse errors in source and
test files are recorded when the files are parsed.

## Thin plugins: covered through their delegates

A `*Plugin` method counts as covered without its own test when all of these hold:

- it has no logic: at most 3 statements and no `if`, loops, `switch`, `match`, ternary or `try`;
- it calls at least one project method that is in the report's scope;
- every such method is tested.

Calls to factories, config and kernel getters are ignored for this check. The rule doesn't verify which arguments the
plugin passes on.

## Skip rules

Skipped methods are neither listed nor counted. The summary prints how many were skipped; `--show-skipped`
also lists them.

| Reason | Rule |
|---|---|
| application bootstrap | Class name ends with `Bootstrap`. The whole class is skipped |
| routing/resource configuration plugin | Class implements `ResourceInterface`, `JsonApiResourceInterface`, `ResourceRoutePluginInterface`, `ProtectedRouteAuthorizationConfigProviderPluginInterface` (Glue) or `RouteProviderPluginInterface` (Yves). The whole class is skipped; routing is verified by HTTP tests |
| client method only calls a Zed stub | `*Client` method in the Client layer without logic whose only project callees are `*Stub` methods (factory calls aside). The logic lives in Zed |
| declarative: only returns constant data | The whole body is `return <data>;`, where data is a scalar, a constant, `X::class`, an array of those, `new X(<data>)` or fluent setters with data on it, such as `(new XTransfer())->setA('a')->setB(Y::B)` |
| form/table definition (`--skip-ui-definitions` only) | In classes implementing Symfony `FormTypeInterface`: `buildForm()`, `configureOptions()`, `getBlockPrefix()`, `getName()` and `add*()` methods without branches, loops or closures. Form expander plugins (any interface named `*FormExpanderPluginInterface`, e.g. `ProductAbstractFormExpanderPluginInterface`, `MerchantFormExpanderPluginInterface`): the whole class, logic included. In classes extending Gui `AbstractTable`: `configure()`. Form **data provider** expanders (`*FormDataProviderExpanderPluginInterface`) are not skipped: they load data |

The rules are checked in this order: class rules (bootstrap, routing, form expander plugins), stub-only, UI definitions,
declarative. A method gets the first reason that matches.

## Entry points ("test via")

A second call graph is built for the modules that have gaps, starting from:

- the module's Facade, Client and Service, public methods;
- plugins and event subscribers of the module that are registered with `new X()` in any project DependencyProvider;
- project classes of the module named `*Plugin`, `*Console`, `*Widget`, `*Facade`, `*Client` or `*Service`;
- project and core controllers of the module, `*Action()` methods.

For each untested method the analyser walks the graph backwards and stops at the first entry points it meets.
`--why` prints those paths.

## Glue and Yves: unit-test suggestions

Glue and Yves modules have no Facade, so a class there is often reached only from a controller action, i.e. only over
HTTP. Pointing at the controller would suggest an HTTP test, which this script can't see. So when **every** entry point
of a Glue or Yves class is a controller action, the report suggests a unit test instead:

```
✗ Pyz\Glue\CustomerCompanySyncBackendApi\Mapper\CustomerCompanySyncMapper: mapRequestAttributesToRequestTransfer(); unit test of Pyz\Glue\CustomerCompanySyncBackendApi\…\CustomerCompanySyncProcessor::assignCustomerToCompany(), create it with CustomerCompanySyncBackendApiFactory::createCustomerCompanySyncProcessor()
```

The JSON report also lists the controller actions on the path (`suggestedUnitTests[].reachedFrom`).

- **What to test:** the first method the controller calls on the path to the untested class. Factory methods and the
  controller's own helpers (such as `executeIndexAction()`) are skipped. If that method belongs to the untested class
  itself, the line says `unit test of this class: …`; otherwise `unit test of …`.
- **create it with:** the module factory method (project or core) that instantiates that class. Create the object the
  same way in the test, with the real factory.
- **reachedFrom** (JSON only): the controller actions on the path, for context.

If any entry point is not a controller (a plugin, a widget or a Facade), the normal "test via" is shown.

## Unused code

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
