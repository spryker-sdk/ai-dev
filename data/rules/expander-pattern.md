---
name: expander-pattern
description: Use when writing or reviewing an Expander class. Enforces that Expanders enrich incoming Transfer Objects in place, remain stateless, handle null or missing data gracefully, and return the same instance they received.
paths: "src/**/Zed/*/Business/**/*Expander.php"
---

**Architecture rule**
Expanders MUST enrich Transfer Objects by modifying and returning the same instance without heavy computation.

Critical instructions:
- Expanders MUST return the modified input Transfer Object (not void or new instance)
- Expanders MUST be stateless (no mutable properties)
- Expanders MUST handle missing data gracefully (return unchanged if data unavailable)
- Often used with plugin pattern for extensibility
- Strictly enforce these rules and never suppress even on low confidence

They are only allowed to:
- Modify input Transfer Object and return it
- Remain stateless with immutable dependencies only
- Delegate to Facades, Models, Clients or Services  for fetching related data
- Handle null/missing data gracefully

They are NOT allowed to:
- Return void or new Transfer instances
- Store mutable state between calls
- Fail when related data is missing
- Create circular expansion dependencies

## Examples

### ✅ Correct Implementation

```php
<?php
namespace Pyz\Zed\Product\Business\Product;

use Generated\Shared\Transfer\ProductTransfer;

class ProductExpander
{
    public function __construct(
        protected ProductImageFacadeInterface $productImageFacade
    ) {
    }

    public function expandWithImages(ProductTransfer $productTransfer): ProductTransfer
    {
        // Fetch related data via Facade
        $images = $this->productImageFacade->findImagesByIdProduct(
            $productTransfer->getIdProduct()
        );

        // Handle missing data gracefully
        if ($images === null) {
            return $productTransfer;  // Return unchanged
        }

        // Modify and return same instance
        $productTransfer->setImages($images);

        return $productTransfer;
    }
}
```

### ❌ Incorrect Implementation

```php
<?php
// ❌ WRONG: Returns void instead of modified transfer
class ProductExpander
{
    public function expandWithImages(ProductTransfer $productTransfer): void  // ❌ Wrong return type
    {
        $images = $this->productImageFacade->findImagesByIdProduct(
            $productTransfer->getIdProduct()
        );

        $productTransfer->setImages($images);
        // ❌ No return statement
    }
}

// ❌ WRONG: Creates and returns new instance
class ProductExpander
{
    public function expandWithImages(ProductTransfer $productTransfer): ProductTransfer
    {
        $enrichedProduct = new ProductTransfer();  // ❌ Don't create new instance
        $enrichedProduct->fromArray($productTransfer->toArray(), true);

        $images = $this->productImageFacade->findImagesByIdProduct(
            $productTransfer->getIdProduct()
        );

        $enrichedProduct->setImages($images);

        return $enrichedProduct;  // ❌ Wrong - should return input instance
    }
}
```

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Rule in [public documentation](https://docs.spryker.com/docs/dg/dev/backend-development/data-manipulation/data-enrichment.html)
- Implements Open/Closed Principle through plugin-based extensibility
- Enables step-by-step data enhancement pipelines
- Separates data enrichment from core business logic
- Supports project-level extensions without modifying core code
