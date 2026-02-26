---
name: codecept-functional
description: Use the skill to create, update, fix, edit or run functional, unit, codecept tests in "src/**/tests/**, tests/**"
---

**Testing rule**
Codeception tests MUST follow Spryker conventions: expressive naming, AAA structure, entry point focus, and helpers for reusable logic.

## Critical Instructions

**Test Naming**: Use `test` prefix with given/when/then pattern or outcome-focused names
**Structure**: Arrange/Act/Assert comments, max 3 lines per section
**Focus**: Test entry points (Facades, Controllers, Commands), verify outcomes not flows
**Setup**: Per-test only, never global setUp for whole class
**Data**: Use DataBuilders for transfers, tester helpers for entities
**Tester**: Access facade via `$this->tester->getFacade()`, factory via `$this->tester->getFactory()`
**Mocking**: Mock external dependencies only (3rd party APIs), not internal components
**Focus:** Test **entry points** (Controllers, Facades, Console Commands).
**Coverage Goal:** Add as **less tests as possible** to cover core functionality.
**Test Depth:** **Do not test method flows.** Focus on outcomes.
**Body Structure:** Use the following four-part structure:
  ```php
  // Arrange
  // Act
  // Assert
  // Expect (Optional: for exceptions/assertions that require setup)
  ```
**Readability:** Use **helper methods** and classes when possible to keep tests clean.
**Setup:** **NEVER** use global framework `setup` methods for the whole test class. Setup must be done **per test method** (locally) when required.

## Test Naming

```php
// ✅ Good
testGivenValidQuoteWhenPlacingOrderThenOrderIsCreatedSuccessfully()
testCheckoutResponseContainsErrorIfCustomerAlreadyRegistered()
testCanWithCentAmountLessThanConfigurationReturnsTrue()

// ❌ Bad
testPlaceOrder(), testSuccess(), testValidateCustomerEmailFormat()
```

## Test Structure (Unit Tests)

```php
public function testCheckoutSuccessfully(): void
{
    // Arrange
    $productTransfer = $this->tester->haveProduct();
    $quoteTransfer = $this->buildQuoteWithProduct($productTransfer);

    // Act
    $result = $this->tester->getFacade()->placeOrder($quoteTransfer);

    // Assert
    $this->assertTrue($result->getIsSuccess());
}
```

Extract complex setup to helper methods when >3 lines needed.

## Data Builders & Helpers

```php
// Use builders for transfers
$quote = (new QuoteBuilder())
    ->withItem($itemBuilder)
    ->withCustomer()
    ->withTotals()
    ->build();

// Use tester helpers
$product = $this->tester->haveProduct();
$store = $this->tester->haveStore([StoreTransfer::NAME => 'DE']);
$this->tester->haveAvailabilityConcrete($sku, $store);

// Constants for test data
protected const string STORE_NAME_DE = 'DE';
```

**Generate data builders** when a transfer has no existing builder:
```bash
docker/sdk testing console transfer:databuilder:generate
```

## Setup & Teardown

```php
// ✅ Per-test setup
protected function setUp(): void
{
    parent::setUp();
    $this->tester->setDependency(Key::PLUGINS, [new Plugin()]);
}

// ❌ Never do this
public static function setUpBeforeClass(): void // DON'T
```

## Data Cleanup

When `TransactionHelper` cannot be used (e.g. tests with `@disableTransaction`), register cleanup callbacks in helper `have*()` methods to prevent DB pollution across tests:

```php
public function havePriceProduct(array $seed = []): PriceProductTransfer
{
    $transfer = $this->getFacade()->createPriceForProduct(
        (new PriceProductBuilder($seed))->build()
    );

    $this->getDataCleanupHelper()->_addCleanup(function () use ($transfer): void {
        $this->cleanupPriceProductStore($transfer->getIdPriceProduct());
        $this->cleanupPriceProduct($transfer->getIdPriceProduct());
    });

    return $transfer;
}
```

`DataCleanupHelper` must be enabled in `codeception.yml` for this to work.

## DO NOT Mock Internal Components

**NEVER mock**: Facade, Plugins, Factory, Repository, EntityManager
**ONLY mock**: Config (when needed), External 3rd party APIs

```php
// ✅ Use real facade
$result = $this->tester->getFacade()->processEntity($transfer);

// ✅ Use real plugin with factory
$plugin = new MyPlugin();
$plugin->setFactory($this->tester->getFactory());

// ✅ Mock config only when needed
$configMock = $this->createMock(Config::class);
$configMock->method('getSomething')->willReturn('value');
$this->tester->getFactory()->setConfig($configMock);

// ✅ Set dependencies via tester
$this->tester->setDependency(DependencyProvider::PLUGINS, []);

// ❌ Never mock facade
$facadeMock = $this->createMock(FacadeInterface::class); // DON'T

// ❌ Never mock plugin
$pluginMock = $this->createMock(PluginInterface::class); // DON'T
```

## Mocking External Dependencies

Mock 3rd party APIs to avoid real network calls.

```php
// Mock external provider
protected function createFacadeWithMockedProvider(): FacadeInterface
{
    $mockProvider = $this->createMock(ExternalProviderInterface::class);
    $mockProvider->method('execute')->willReturn(new Response('Test'));

    $adapter = new VendorAdapter(
        provider: $mockProvider,
        mapper: $this->tester->getFactory()->createDataMapper(),
        config: $this->createMockConfig()->getConfigurations(),
    );

    $plugin = $this->createMock(ProviderPluginInterface::class);
    $plugin->method('getAdapter')->willReturn($adapter);

    $this->tester->setDependency(DependencyProvider::PLUGIN, $plugin);

    return $this->tester->getFacade();
}
```

## Test Class Structure

```php
namespace SprykerTest\Zed\Checkout\Business;

use Codeception\Test\Unit;

/**
 * @group SprykerTest
 * @group Zed
 * @group Checkout
 * @group Business
 * @group Facade
 * @group CheckoutFacadeTest
 * Add your own group annotations below this line
 */
class CheckoutFacadeTest extends Unit
{
    protected CheckoutBusinessTester $tester;
}
```

## Module-Specific Tester

**Location**: `tests/SprykerTest/[Layer]/[Module]/_support/[Module][Layer]Tester.php`

```php
namespace SprykerTest\Zed\ExampleModule;

use Codeception\Actor;

/**
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method \Spryker\Zed\[Module]\Business\[Module]BusinessFactory getFactory()
 * @method \Spryker\Zed\[Module]\Business\[Module]FacadeInterface getFacade()
 * @SuppressWarnings(PHPMD)
 */
class ExampleModuleBusinessTester extends Actor
{
    use _generated\ExampleModuleBusinessTesterActions;

    public function haveEntityWithItems(string $id, array $items): void
    {
        foreach ($items as $item) {
            $this->getFacade()->createEntity(
                (new EntityTransfer())->setId($id)->setItem($item)
            );
        }
    }

    public function seeEntityExists(string $id): void
    {
        $collection = $this->getFacade()->getEntityCollection(
            (new CriteriaTransfer())->setConditions(
                (new ConditionsTransfer())->setIds([$id])
            )
        );

        $this->assertGreaterThan(0, $collection->count());
    }
}
```

**Custom methods**: `have[Entity]()` for data, `see[Entity]()` for assertions

**Use unique IDs** to avoid conflicts:
```php
public function haveEntity(): int
{
    $parent = $this->haveParentEntity([
        'name' => sprintf('Parent_%s', uniqid()),
    ]);

    $entity = $this->haveChildEntity([
        'name' => sprintf('Child_%s', uniqid()),
        'fkParent' => $parent->getId(),
    ]);

    return $entity->getId();
}
```

**After creating**: Run `docker/sdk testing codecept build -c path/to/codeception.yml`

## Communication Layer Plugin Tests

```php
// Test plugin with real facade and factory
public function testPluginMethod(): void
{
    // Arrange
    $this->tester->setDependency(DependencyProvider::PLUGINS, []);
    $plugin = new MyPlugin();
    $plugin->setFactory($this->tester->getFactory());

    // Act
    $result = $plugin->doSomething();

    // Assert
    $this->assertTrue($result);
}

// Test with config mock
public function testPluginWithConfig(): void
{
    // Arrange
    $configMock = $this->createMock(Config::class);
    $configMock->method('getValue')->willReturn('test');
    $this->tester->getFactory()->setConfig($configMock);

    $plugin = new MyPlugin();
    $plugin->setFactory($this->tester->getFactory());

    // Act & Assert
    $this->assertSame('test', $plugin->getConfigValue());
}
```

## Codeception Configuration

**Location**: `tests/SprykerTest/[Layer]/[Module]/codeception.yml`

```yaml
namespace: SprykerTest\Zed\[Module]
paths:
    tests: .
    data: ../../../_data
    support: _support
    output: ../../../_output

coverage:
    enabled: true
    remote: false
    whitelist: { include: ['../../../../src/*'] }

suites:
    Business:
        path: Business
        actor: [Module]BusinessTester
        modules:
            enabled:
                - Asserts
                - \SprykerTest\Shared\Testify\Helper\{Environment,ConfigHelper,LocatorHelper,DependencyHelper}
                - \SprykerTest\Shared\Testify\Helper\DataCleanupHelper
                - \SprykerTest\Zed\Testify\Helper\Business\BusinessHelper
                - \SprykerTest\Shared\Propel\Helper\TransactionHelper
```

## Creating Custom Helpers

**Location**: `tests/SprykerTest/[Layer]/[Module]/_support/Helper/[Module]Helper.php`

```php
namespace SprykerTest\Zed\[Module]\Helper;

use Codeception\Module;
use SprykerTest\Shared\Testify\Helper\LocatorHelperTrait;

class [Module]Helper extends Module
{
    use LocatorHelperTrait;

    public function have[Entity](array $seed = []): [Entity]Transfer
    {
        return $this->getLocator()->[module]()->facade()
            ->create[Entity]((new [Entity]Builder($seed))->build());
    }
}
```

**Setup**: Add to `codeception.yml`, run `docker/sdk testing codecept build`, use `$this->tester->have[Entity]()`

## Directory Structure

```
tests/{PyzTest,SprykerTest}/
├── Zed/Module/
│   ├── Business/
│   │   └── Facade/          # Facade tests
│   ├── Communication/        # Controller/Plugin tests
│   ├── Persistence/          # Repository/EntityManager tests
│   ├── _support/
│   │   ├── Helper/           # Custom helpers
│   │   └── PageObject/       # Page objects for UI tests
│   └── codeception.yml
├── Client/Module/
│   ├── Business/             # Client method tests
│   └── codeception.yml
├── Service/Module/
│   ├── Business/             # Stateless service tests (no DB needed)
│   └── codeception.yml
├── Glue/Module/
│   ├── Business/             # API endpoint tests
│   └── codeception.yml
├── Shared/Module/
│   ├── Business/             # Cross-layer utility tests
│   └── codeception.yml
└── Yves/Module/
    ├── Presentation/         # UI/JavaScript tests
    └── codeception.yml
```

## Testing Console Commands

Enable `ConsoleHelper` in codeception.yml:
```yaml
- \SprykerTest\Zed\Console\Helper\ConsoleHelper
```

**Test**:
```php
$command = new MyConsoleCommand();
$commandTester = $this->tester->getConsoleTester($command);
$commandTester->execute([
    MyConsoleCommand::ARGUMENT_FOO => 'value',
    '--' . MyConsoleCommand::OPTION_BAR => 'value',
]);

$this->assertSame(MyConsoleCommand::CODE_SUCCESS, $commandTester->getStatusCode());
$this->assertStringContainsString('Expected output', $commandTester->getDisplay());
```

## Key Test Helpers

**Enable in codeception.yml** `modules.enabled`:

**Testify helpers** (Shared):
- `ConfigHelper` - Mock configs: `$this->tester->getModuleConfig()`, `mockConfigMethod()`
- `DependencyHelper` - Mock dependencies: `$this->tester->setDependency()`
- `LocatorHelper` - Access modules: `$this->tester->getLocator()->module()->facade()`
- `DataCleanupHelper` - Auto cleanup test data

**P&S helpers** (Zed):
- `PublishAndSynchronizeHelper` - `assertEntityIsPublished()`, `assertEntityIsSynchronizedToStorage()`
- `EventBehaviorHelper` - `triggerRuntimeEvents()`
- `QueueHelper` - `assertMessagesConsumedFromEventQueue()`, `cleanupInMemoryQueue()`
- `StorageHelper` - `assertStorageHasKey()`, `cleanupInMemoryStorage()`
- `SearchHelper` - `assertSearchHasKey()`, `cleanupInMemorySearch()`

**Business/Communication** (Zed):
- `BusinessHelper` - Mock facade: `$this->tester->mockFacadeMethod()`
- `CommunicationHelper` - Mock communication layer
- `ConsoleHelper` - Test console commands: `$this->tester->getConsoleTester()`

**Database**:
- `TransactionHelper` - Wrap tests in transactions, auto rollback

## P&S Testing (Storage/Search)

**Enable helpers**: PublishAndSynchronizeHelper, EventBehaviorHelper, QueueHelper, StorageHelper, SearchHelper

**Test flow**:
```php
// Save entity
$entity = $this->tester->haveEntity();
$this->tester->assertEntityIsPublished('event.name', 'publish.queue');
$this->tester->assertEntityIsSynchronizedToStorage('sync.queue');
$this->tester->assertStorageHasKey('storage:key');

// Update entity
$this->tester->updateEntity($entity);
$this->tester->assertEntityIsPublished('event.name', 'publish.queue');
$this->tester->assertEntityIsUpdatedInStorage('sync.queue');

// Delete entity
$this->tester->deleteEntity($entity);
$this->tester->assertEntityIsPublished('event.name', 'publish.queue');
$this->tester->assertEntityIsRemovedFromStorage('sync.queue');
$this->tester->assertStorageNotHasKey('storage:key');
```

**Troubleshooting**: Add `@disableTransaction` if `PropelException` about transactions

## New Module Test Setup

When adding tests to a new module, follow this checklist:

1. Create the directory structure (see above)
2. Create `codeception.yml` with suites and helpers
3. Create the tester class in `_support/`
4. Create helper classes in `_support/Helper/` if needed
5. **Build** to generate tester actions: `docker/sdk testing codecept build -c tests/SprykerTest/Zed/YourModule`
6. Write test classes following the AAA pattern
7. Run tests to verify

**Always run `codecept build` after**: creating a new module, adding helpers, or changing `codeception.yml`.

## Running Tests

**Build tests** (after adding helpers/changes):
```bash
docker/sdk testing codecept build -c path/to/codeception.yml
```

**Run all tests in a module:**
```bash
docker/sdk testing codecept run -c path/to/codeception.yml
```

**Run a specific suite:**
```bash
docker/sdk testing codecept run -c path/to/codeception.yml -g Business
```

**Run a specific test file:**
```bash
docker/sdk testing codecept run tests/SprykerTest/Zed/ModuleName/Business/SomeFacadeTest.php
```

**Run a single test method:**
```bash
docker/sdk testing codecept run tests/SprykerTest/Zed/ModuleName/Business/SomeFacadeTest.php::testSpecificMethod
```

**Verbose output:**
```bash
docker/sdk testing codecept run -c path/to/codeception.yml -vvv
```
