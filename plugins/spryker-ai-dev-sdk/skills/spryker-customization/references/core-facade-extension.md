# Dependencies without new Bridges, and extending a core facade

Companion of the `dependency-provider` rule.

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
