---
name: mapper-pattern
description: Use when writing or reviewing a Mapper class. Enforces that Mappers transform data only — both source AND target are passed as parameters, stateless, no queries or lazy loading, no business logic.
globs: "src/Pyz/**/*Mapper.php"
---

**Architecture rule**
Mappers MUST only transform data between representations without business logic, accepting target object as parameter.

Critical instructions:
- Mappers MUST only perform data transformation (no business logic, validation, or queries)
- Mapper methods MUST accept both source AND target objects as parameters
- Use fromArray() for Transfer-to-Entity mapping efficiency
- Mappers MUST be stateless (no mutable properties)
- Any lazy loading or data fetching is prohibited in the mapper and data retrieval MUST be done prior to calling the Mapper
- Method naming: map[Source]To[Target]
- Strictly enforce these rules and never suppress even on low confidence

They are only allowed to:
- Transform data between Entities/Transfer and Transfer Objects
- Accept source and target objects as parameters
- Remain stateless with immutable dependencies only
- Provide single and/or collection mapping methods

They are NOT allowed to:
- Contain business logic, validation, or calculations
- Create target object internally (caller MUST provide it)
- Perform database queries or persistence operations, hidden data fetching also prohibited
- Store mutable state between calls
- Skip target object parameter

## Examples

### ✅ Correct Implementation

```php
<?php
namespace Pyz\Zed\Product\Persistence\Mapper;

use Generated\Shared\Transfer\ProductTransfer;
use Orm\Zed\Product\Persistence\SpyProduct;

class ProductMapper
{
    public function mapProductTransferToEntity(
        ProductTransfer $productTransfer,
        SpyProduct $productEntity  // ← Target provided by caller
    ): SpyProduct {
        // Use fromArray() for efficiency
        $productEntity->fromArray($productTransfer->toArray());

        // Map specific fields if needed
        $productEntity->setSku($productTransfer->getSku());
        $productEntity->setIsActive($productTransfer->getIsActive());

        return $productEntity;
    }

    public function mapEntityToProductTransfer(
        SpyProduct $productEntity,
        ProductTransfer $productTransfer  // ← Target provided by caller
    ): ProductTransfer {
        $productTransfer->fromArray($productEntity->toArray(), true);

        return $productTransfer;
    }
}
```

### ❌ Incorrect Implementation

```php
<?php
// ❌ WRONG: Creating target object internally
class ProductMapper
{
    public function mapProductTransferToEntity(
        ProductTransfer $productTransfer
    ): SpyProduct {
        $productEntity = new SpyProduct();  // ❌ Don't create target internally
        $productEntity->fromArray($productTransfer->toArray());

        return $productEntity;
    }

    // ❌ WRONG: Contains business logic
    public function mapWithValidation(
        ProductTransfer $productTransfer,
        SpyProduct $productEntity
    ): SpyProduct {
        // ❌ Validation is business logic, not mapping
        if ($productTransfer->getPrice() < 0) {
            throw new \Exception('Invalid price');
        }

        $productEntity->fromArray($productTransfer->toArray());

        return $productEntity;
    }
}
```

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Rule in [public documentation](https://docs.spryker.com/docs/dg/dev/backend-development/zed/persistence-layer/entity-manager.html)
- Isolates data transformation from business and persistence logic
- Accepting target object enables reuse and testing with mocks
- Stateless design ensures predictable, reusable transformations
- Single responsibility makes mappers easy to test and maintain
