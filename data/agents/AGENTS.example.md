# AI Agent — Spryker Engineer

You are part of the **Project team**. Strictly follow Spryker architectural guidelines and coding standards.

## Commands

```bash
# Docker CLI
docker/sdk cli console [<command>]                    # List/run commands
docker/sdk cli console cache:clear                    # Clear application cache
docker/sdk cli console navigation:cache:remove        # Remove navigation cache
docker/sdk cli console twig:cache:warmer              # Warm Twig template cache
docker/sdk cli console transfer:generate              # Generate transfers
docker/sdk cli console propel:install                 # Apply DB schema
docker/sdk cli npm install                            # Install npm deps
docker/sdk cli run yves:watch                         # Watch Yves assets
docker/sdk cli "mariadb -h database -u spryker -psecret -D eu-docker -e 'SELECT 1;'"
docker/sdk cli redis-cli -h key_value_store -n 1      # EU store (db1 = AT/DE)
docker/sdk cli "redis-cli -h key_value_store -n 1 --scan --count 1000 | grep 'product_abstract:de:de_de'"
docker exec spryker_broker_1 rabbitmqadmin -u spryker -p secret list queues
docker exec spryker_broker_1 rabbitmqctl list_queues
```

## Architectural Rules
- Single responsibility per class; favor composition over inheritance.
- Ensure backward compatibility for public APIs and transfers.
- Follow Spryker naming conventions; write testable code with automated tests.

## General Principles
- Stateless classes; dependencies via constructor only.
- OOP, PSR-4 compliant. Never throw exceptions. Guard Clauses (Return Early): validate first, return on failure, happy path last.
- Never use `new` in business logic — always use dependency injection.
- Do not modify input transfers; create new ones for changes.
- No static methods or global state. Short, focused methods.
- Explicit over magic. No hidden behaviors or implicit conversions.
- Document public methods and transfers. Avoid unnecessary DB calls.

---

# Project Organization

## Namespace / Organization (`[Org]`)

| Org               | Path | Purpose                                                   |
|-------------------|---|-----------------------------------------------------------|
| `Pyz`             | `src/Pyz/{Layer}/{Module}/` | Project-level overrides/extensions of core modules        |
| `CustomNamespace` | `src/CustomNamespace/{Layer}/{Module}/` | Project-level overrides/extensions of Pyz or core modules |

> Note: `[Org]` can also be other custom namespaces under `src/`.

## Vendor Modules — Examples & Patterns Source

Spryker modules reside in `vendor/` and are the primary reference for examples and patterns:

| Vendor path | Namespace | Purpose |
|---|---|---|
| `vendor/spryker/` | `Spryker\*` | Core backend modules |
| `vendor/spryker-shop/` | `SprykerShop\*` | Storefront modules |
| `vendor/spryker-feature/` | `SprykerFeature\*` | Feature integration modules |
| `vendor/spryker-eco/` | `SprykerEco\*` | Third-party eco-system integrations |

**CRITICAL**: Look in `vendor/spryker*` as well as in `src` for existing patterns, conventions, and implementation examples before writing new code.
Core modules are the authoritative source of truth for Spryker architecture.

## Tests

- `tests/PyzTest/{Layer}/{Module}/` — project-level tests for `Pyz` customizations

## Configuration Files

| Path                              | Purpose                                          |
|-----------------------------------|--------------------------------------------------|
| `config/Shared/config_default.php` | Environment-specific application config          |
| `config/Shared/config_local.php`  | Local overrides                                  |
| `config/Zed/oms/`                 | OMS process/state/transition definitions         |
| `config/Zed/StateMachine/`        | State machine configurations                     |
| `config/Zed/cronjobs/`            | Cron job definitions and schedules               |
| `config/Zed/navigation*.xml`      | Backoffice navigation/menu structure             |
| `data/import/`                    | Data import files for setup or testing           |
| `data/export/`                    | Data export files for backup or migration        |
| `data/configuration/`             | Project level Backoofice configuration managment |

---

# Application Layers

## Zed (Business logic, persistence, backend UI)
**Layers**: Presentation → Communication → Business → Persistence

**Structure** (`[Org]/Zed/[Module]/`):
```
Presentation/Layout/[layout].twig
Presentation/[controller]/[action].twig
Communication/Controller/{Index,Gateway,[Name]}Controller.php
Communication/Plugin/[Consumer]/[Name]Plugin.php
Communication/Form/[Name]Form.php, DataProvider/[Name]FormDataProvider.php
Communication/Table/[Name]Table.php
Communication/navigation.xml
Communication/[Module]CommunicationFactory.php
Business/[Dir]/{[Model]Interface,[Model]}.php
Business/{[Module]BusinessFactory,[Module]Facade,[Module]FacadeInterface}.php
Persistence/{[Module]EntityManager[Interface],[Module]Repository[Interface],[Module]QueryContainer,[Module]PersistenceFactory}.php
Persistence/Propel/ Mapper/[Module]Mapper.php, Schema/[org]_[domain].schema.xml}
Dependency/{Client,Facade,QueryContainer,Service}/[Module]To[Module]Interface.php
Dependency/Plugin/[Name]PluginInterface.php
[Module]{Config,DependencyProvider}.php
```
**Components**: Config, Controller, Dependency Provider, Entity, Entity Manager, Facade, Factory, Gateway Controller, Layout, Mapper/Expander/Hydrator, Models, navigation.xml, Plugin/Interface, Query Container, Query Object, Repository, Schema

---

## Yves (Lightweight storefront)
**Structure** (`[Org]/Yves/[Module]/`):
```
Controller/{Index,[Name]}Controller.php
Plugin/Provider/[Name]ControllerProvider.php
Plugin/[Consumer]/{[Name]Plugin,[Router]Plugin}.php
Theme/[default|theme]/components/{atoms,molecules,organisms}/[name]/[name].twig
Theme/[default|theme]/templates/{page-layout-[name],template-name}/[name].twig
Theme/[default|theme]/views/[controller]/[action].twig
Widget/[Name]Widget.php
Dependency/{Client,Service}/[Module]To[Module]Interface.php
Dependency/Plugin/[Name]PluginInterface.php
[Module]{Config,DependencyProvider,Factory}.php
```
**Components**: Config, Controller, Dependency Provider, Factory, Layout, Mapper/Expander/Hydrator, Model, Provider/Router, Plugin/Interface, Templates, Theme, Widget

---

## Glue (API data access)
**Structure** (`[Org]/Glue/[Module]/`):
```
Controller/{Index,[Name]}Controller.php
Plugin/Provider/[Name]ControllerProvider.php, Plugin/[Consumer]/[Name]Plugin.php
Dependency/{Client,Facade,QueryContainer,Service}/[Module]To[Module]Interface.php
Dependency/Plugin/[Name]PluginInterface.php
[Module]{Config,DependencyProvider,Factory}.php
```
**Components**: Config, Controller, Dependency Provider, Factory, Mapper/Expander/Hydrator, Model, Provider/Router

---

## Client (Redis, Elasticsearch, Zed RPC)
**Structure** (`[Org]/Client/[Module]/`):
```
[Dir]/{[Model]Interface,[Model]}.php
Plugin/[Consumer]/[Name]Plugin.php
Zed/{[Module]Stub,[Module]StubInterface}.php
Dependency/{Client,Service}/[Module]To[Module]Interface.php
Dependency/Plugin/[Name]PluginInterface.php
[Module]{ClientInterface,Client,Config,DependencyProvider,Factory}.php
```
**Components**: Client Facade, Config, Dependency Provider, Factory, Mapper/Expander/Hydrator, Model, Plugin/Interface, Zed Stub

---

## Service (Stateless reusable logic, all layers)
**Structure** (`[Org]/Service/[Module]/`):
```
[Dir]/{[Model]Interface,[Model]}.php
[Module]{Config,DependencyProvider,ServiceFactory,ServiceInterface,Service}.php
```
**Components**: Config, Dependency Provider, Factory, Mapper/Expander/Hydrator, Model, Service Facade

---

## Shared (Cross-layer constants & transfers)
**Structure** (`[Org]/Shared/[Module]/`):
```
Transfer/[module].transfer.xml
[Module]{Constants,Config}.php
```
**Convention**: No layer-specific elements; no factories.
**Components**: Config, Constants, Transfer

---

# Layer Responsibilities

| Layer | Responsibility |
|---|---|
| **Presentation** | UI (Twig/JS/CSS), user interactions, client-side validation; retrieves data from Communication |
| **Communication** | HTTP request/response, controllers, plugins, console commands, forms, routing; calls Business |
| **Business** | Core logic, rules, data manipulation, validation; calls Persistence |
| **Persistence** | CRUD via Entity Manager/Repository, entities, schema; maps entities to transfers |

---

# Component Rules & Conventions

## Transfer Object
- Pure DTOs; can be instantiated anywhere (not only via Factory).
- Suffix `Attributes` → Glue/RestApi modules only; `ApiAttributes` → Storefront API; `BackendApiAttributes` → Backend API.
- `Entity` suffix reserved for auto-generated EntityTransfers — never use manually.
- Define in `transfer.xml`.


## Plugin & Plugin Interface
- Implements Inversion of Control; instantiated via Dependency Provider.
- Name must be unique and descriptive. Interface must explain usage and use cases.
- Avoid single-item operations in plugin stack methods unless unavoidable.
- Core development: interface must live in an Extension module (e.g., `CompanyExtension`).

## Controller
- Action methods must be `public` and suffixed with `Action`.
- Use inherited `castId()` for numeric IDs, `getFactory()`, `getFacade()`, `getClient()`.

## Dependency Provider
- Use `container::set()` with late-binding closure.
- Use `Container::factory()` for per-injection instances (e.g., Query Objects).
- Always call parent provide method first.
- Wire by layer method: `provideBusinessLayerDependencies`, `provideCommunicationLayerDependencies`, `providePersistenceLayerDependencies`, `provideDependencies`, `provideBackendDependencies`, `provideServiceLayerDependencies` overriding parent methods.
- Constant naming: `[COMPONENT_NAME]_[MODULE_NAME]` or `PLUGINS_[PLUGIN_INTERFACE_NAME]`.
- **CRITICAL**: Bridges are deprecated. Never create or extends Bridges, never use Bridges to pass dependencies in Dependency Provider
Correct set dependecy example:
```php
$container->set(static::FACADE_ANY_MODULE, function (Container $container) {
      return $container->getLocator()->anyModule()->facade(); // never use Bridges
});
```

## Entity
- Generated via Persistence Schema; never leaked beyond persistence layer / facade level.
- Use `preSave()`/`postSave()` for hooks; prefer manager classes over complex entity logic.

## Entity Manager
- Persists entities via their saving mechanism or Query Objects.
- Accessible from the same module's business layer.

## Facade
- All methods use Transfer Objects or native types for arguments and return values.
- Avoid single-item-flow methods (not scalable).

## Mapper / Expander / Hydrator
- **Mapper**: transforms one structure to another using only provided input (no external data).
- **Persistence Mapper**: Mapper in Persistence layer (Propel entities ↔ transfers).
- **Expander**: sources additional data into the input; may also restructure.

## Gateway Controller
- Actions: single Transfer Object as argument, single Transfer Object as return.
- Follows Controller conventions.

## Model
- Dependencies: facades, or same-module Models, Repository, Entity Manager, Config.
- No cross-module Model interaction (inheritance, constants, instantiation).

## Persistence Schema
- Primary key: `id_[domain_entity_name]` (integer).
- Foreign key: `fk_[remote_entity]`; multiple refs: `fk_[custom_connection_name]`.
- Table names: Business `[org]_[domain]`, Relation `[org]_[a]_to_[b]`, Search `[org]_[domain]_search`, Storage `[org]_[domain]_storage`.
- PhpName = CamelCase SQL name: `<table name="spy_customer" phpName="SpyCustomer">`.

## Provider / Router
- Controller Provider extends `\SprykerShop\Yves\ShopApplication\Plugin\Provider\AbstractYvesControllerProvider`.
- Router extends `\SprykerShop\Yves\ShopRouter\Plugin\Router\AbstractRouter`.

## Permission Plugin
- Implements `\Spryker\Shared\PermissionExtension\Dependency\Plugin\PermissionPluginInterface`.
- Use `\Spryker\[App]\Kernel\PermissionAwareTrait` to check permissions in models.

## Zed Stub
- Endpoints implemented in Zed Gateway Controller of the receiving module.
- Extends `\Spryker\Client\ZedRequest\Stub\ZedRequestStub`.

## Widget
- Unique name across all features. Constructor receives input/rendering parameters.
- Widget modules can contain frontend components without an actual Widget class.

## Theme
- One-level inheritance: current theme > default theme.
- Atomic design: atoms → molecules → organisms → templates → views.

## Repository
- READ only; always returns Transfer Objects or native types.
- Accessible from same module's Communication and Business layers.

## Module Configuration
- `[Module]Config.php` — module-specific, environment-independent.
- `[Module]Constants.php` — environment keys; overridden via `config_default.php`.

## Layout
- Yves: `Theme/[default|theme]/templates/page-layout-[name]/[name].twig`
- Zed: `Presentation/Layout/[name].twig`

# Abstract Classes Reference

**Inheritance for Pyz/CustomNamespace entities**:
- **New module** (no core counterpart): extend the core abstract class directly — e.g., `class SpyFoo extends AbstractSpyFoo`.
- **Existing module** (core entity exists in `Spryker`/`SprykerShop`/`SprykerEco`): extend the core entity class — e.g., `class SpyFoo extends SprykerSpyFoo`.

## Zed Layer

| Class | Namespace | Key Methods |
|---|---|---|
| `AbstractFacade` | `Spryker\Zed\Kernel\Business` | `getFactory()`, `getRepository()`, `getEntityManager()` |
| `AbstractBusinessFactory` | `Spryker\Zed\Kernel\Business` | `getConfig()`, `getRepository()`, `getEntityManager()`, `getProvidedDependency($key)` |
| `AbstractPersistenceFactory` | `Spryker\Zed\Kernel\Persistence` | `getConfig()`, `getRepository()`, `getEntityManager()`, `getProvidedDependency($key)` |
| `AbstractEntityManager` | `Spryker\Zed\Kernel\Persistence` | `getFactory()` |
| `AbstractRepository` | `Spryker\Zed\Kernel\Persistence` | `getFactory()` |
| `AbstractController` (Zed) | `Spryker\Zed\Kernel\Communication\Controller` | `getFacade()`, `getFactory()`, `getRepository()`, `castId($id)`, `jsonResponse()`, `viewResponse()` |
| `AbstractCommunicationFactory` | `Spryker\Zed\Kernel\Communication` | `getConfig()`, `getProvidedDependency($key)`, `getFacade()`, `getRepository()`, `getEntityManager()` |
| `AbstractGatewayController` | `Spryker\Zed\Kernel\Communication\Controller` | `getFacade()`, `getFactory()`, `getRepository()` |
| `AbstractPlugin` (Zed) | `Spryker\Zed\Kernel\Communication` | `getConfig()`, `getFacade()`, `getFactory()`, `getBusinessFactory()` |

## Client Layer

| Class | Namespace | Key Methods |
|---|---|---|
| `AbstractClient` | `Spryker\Client\Kernel` | `getFactory()` |
| `AbstractFactory` | `Spryker\Client\Kernel` | `getConfig()`, `getProvidedDependency($key)`, `getZedRequestClient()` |
| `AbstractPlugin` | `Spryker\Client\Kernel` | `getClient()`, `getFactory()` |

## Service Layer

| Class | Namespace | Key Methods |
|---|---|---|
| `AbstractService` | `Spryker\Service\Kernel` | `getFactory()` |
| `AbstractServiceFactory` | `Spryker\Service\Kernel` | `getConfig()`, `getProvidedDependency($key)` |
| `AbstractPlugin` | `Spryker\Service\Kernel` | `getService()`, `getConfig()`, `getFactory()` |

## Yves Layer

| Class | Namespace | Key Methods |
|---|---|---|
| `AbstractController` | `SprykerShop\Yves\ShopApplication\Controller` | `getFactory()`, `getClient()`, `jsonResponse()`, `view()` |
| `AbstractFactory` | `Spryker\Yves\Kernel` | `getConfig()`, `getClient()`, `getProvidedDependency($key)` |
| `AbstractPlugin` | `Spryker\Yves\Kernel` | `getFactory()`, `getConfig()` |
| `AbstractRouteProviderPlugin` | `Spryker\Yves\Router\Plugin\RouteProvider` | `buildRoute($path, $module, $controller, $action)`, `addRoutes($collection)` |

## Glue Layer

| Class | Namespace | Key Methods |
|---|---|---|
| `AbstractFactory` | `Spryker\Glue\Kernel` | `getConfig()`, `getProvidedDependency($key)` |
| `AbstractPlugin` | `Spryker\Glue\Kernel` | `getFactory()` |

## Config & DI (all layers)

**`AbstractBundleConfig`** — `Spryker\{Layer}\Kernel\AbstractBundleConfig`
- `get(string $key, mixed $default = null)`, `getSharedConfig()`

**`AbstractBundleDependencyProvider`** — `Spryker\{Layer}\Kernel\Abstract{Bundle}DependencyProvider`
- `container->set($key, fn)`, `container->factory(fn)`, layer-specific `provide*Dependencies()` methods

