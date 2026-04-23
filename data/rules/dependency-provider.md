---
name: dependency-provider
description: Use when writing or modifying a DependencyProvider or wiring dependencies between modules.
paths: "src/**/*DependencyProvider.php"
---

**Architecture rule**
DependencyProviders MUST use late-binding closures for all dependencies and organize by layer with descriptive constants.

Critical instructions:
- ALL dependencies MUST be wrapped in late-binding closures (function(Container $container) { return ...; })
- Each layer MUST have its own provide method (provideBusinessLayerDependencies, providePersistenceDependencies, etc.)
- Dependency constants MUST be descriptive (FACADE_CATEGORY, CLIENT_STORAGE, PLUGINS_PRODUCT_EXPANDER)
- MUST call parent provide methods (parent::provideBusinessLayerDependencies($container))
- Strictly enforce these rules and never suppress even on low confidence

They are only allowed to:
- Define public constants for dependency keys in SCREAMING_SNAKE_CASE
- Wrap dependencies in late-binding closures
- Use Container::factory() for Query Objects
- Organize dependencies by layer (Business, Persistence, Communication)
- Call parent provide methods
- Return modified Container

They are NOT allowed to:
- Instantiate dependencies directly without closure
- Use $container->set() without closure wrapper
- Skip parent provide method calls in provide... methods
- Mix dependencies from different layers in one method
- Use non-descriptive or ambiguous constant names
- Add new Bridge classes to wire dependencies — use the existing Bridge from the dependency module's `Dependency/` folder instead

## Adding a New Dependency

When adding a new dependency, use `$container->getLocator()` directly in the DependencyProvider. Never create a new Bridge class just to register a dependency.

BAD — Creating a new Bridge class in the current module
```php
// src/[Org]/Zed/Order/Dependency/Facade/OrderToCalculationBridge.php  ← do not create this
class OrderToCalculationBridge implements OrderToCalculationInterface
{
    public function __construct(protected readonly CalculationFacadeInterface $calculationFacade) {}

    public function recalculate(QuoteTransfer $quoteTransfer): QuoteTransfer
    {
        return $this->calculationFacade->recalculate($quoteTransfer);
    }
}

// OrderDependencyProvider.php
$container->set(static::FACADE_CALCULATION, function (Container $container): OrderToCalculationInterface {
    return new OrderToCalculationBridge($container->getLocator()->calculation()->facade());
});
```

GOOD — Use `$container->getLocator()` directly
```php
// OrderDependencyProvider.php
$container->set(static::FACADE_CALCULATION, function (Container $container): CalculationFacadeInterface {
    return $container->getLocator()->calculation()->facade();
});
```

## Extending a Core Module Used by Another Module (Project Level)

When you need to **add a new method** to a core facade that is already consumed by another module via a bridge interface, do NOT subclass or copy the bridge. Instead:

### Step 1 — Extend the bridge interface in the consuming module's project namespace

```php
// src/[Org]/Zed/Cart/Dependency/Facade/CartToCalculationInterface.php
namespace [Org]\Zed\Cart\Dependency\Facade;

use Spryker\Zed\Cart\Dependency\Facade\CartToCalculationInterface as SprykerCartToCalculationInterface;

interface CartToCalculationInterface extends SprykerCartToCalculationInterface
{
    public function foo(): void;
}
```

### Step 2 — Extend the facade in the providing module's project namespace and implement the new interface

```php
// src/[Org]/Zed/Calculation/Business/CalculationFacade.php
namespace [Org]\Zed\Calculation\Business;

use [Org]\Zed\Cart\Dependency\Facade\CartToCalculationInterface;
use Spryker\Zed\Calculation\Business\CalculationFacade as SprykerCalculationFacade;

class CalculationFacade extends SprykerCalculationFacade implements CartToCalculationInterface
{
    public function foo(): void
    {
        // project-level implementation
    }
}
```

### Step 3 — Remove the bridge in the consuming module's DependencyProvider; use the locator directly

```php
// src/[Org]/Zed/Cart/CartDependencyProvider.php
class CartDependencyProvider extends SprykerCartDependencyProvider
{
    public function provideBusinessLayerDependencies(Container $container): Container
    {
        parent::provideBusinessLayerDependencies($container);

        $container->set(static::FACADE_CALCULATION, function (Container $container): CartToCalculationInterface {
            return $container->getLocator()->calculation()->facade();
        });

        return $container;
    }
}
```

### Step 4 — Update the Business Factory to return the extended interface

```php
// src/[Org]/Zed/Cart/Business/CartBusinessFactory.php
use [Org]\Zed\Cart\Dependency\Facade\CartToCalculationInterface;

class CartBusinessFactory extends SprykerCartBusinessFactory
{
    public function getCalculationFacade(): CartToCalculationInterface
    {
        return $this->getProvidedDependency(CartDependencyProvider::FACADE_CALCULATION);
    }
}
```

> **Note:** Bridges are a deprecated concept. At the project level, never extend or subclass a core Bridge class — instead follow the pattern above: extend the bridge *interface* in the consuming module, extend the *facade* in the providing module, and wire directly via the locator.

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Rule in [public documentation](https://docs.spryker.com/docs/dg/dev/backend-development/zed/dependency-provider.html)
- Late-binding closures enable lazy initialization for performance reasons
- Proper layer organization maintains clear architectural boundaries
- Descriptive constants make dependencies self-documenting
- Parent calls ensure proper inheritance chain
- Bridges only exist at core level; project-level extension goes through interface + facade inheritance
