---
name: php-code-style
description: Use when writing or editing any PHP file. Enforces PHP 8.4 native types over DocBlocks, minimalist DocBlock rules, no if/else (early returns instead), max nesting level 2, sprintf over concatenation, and private visibility preference for project code.
globs: "**/*.php"
---

# General Principle

**Always apply rules over existing patterns.** When writing new code, follow the rules — not what you see in the surrounding file. Existing code may predate these rules or simply be wrong. Never use "the existing code does it this way" as justification for skipping a rule. Only mirror an existing pattern if no rule covers the situation and the pattern is clearly intentional.

# PHP Code & Structure

| Rule Category                     | Directive                                                                                                                                | Details & Rationale                                                                               |
|:----------------------------------|:-----------------------------------------------------------------------------------------------------------------------------------------|:--------------------------------------------------------------------------------------------------|
| **Constants**                     | **MUST** use type hints.                                                                                                                 | `private const string STATUS = 'active';`                                                         |
| **Comments**                      | Instead of comments to describe business logic, use clear method and variable names. Code MUST be self-documenting.                      |                                                                                                   |
| **Nesting**                       | **Max nesting level is TWO.**                                                                                                            | For `if -> if -> if` or deeper logic, **extract a new method** immediately.                       |
| **Abstraction**                   | Use **abstractions/helpers** for repeated code blocks.                                                                                   | Especially for implementations of the same interface.                                             |
| **Abbreviations**                 | Never use abbreviations. Not in comments and not in code.                                                                                | E.g., use `Fully qualified class name` over FQCN                                                  |
| **String concatenation**          | Prefer sprintf.                                                                                                                          | E.g., use `sprintf('Message with data %s', $data)` over `"Message with data {$data}"`             |
| **Interfaces**                    | Interfaces are NOT required unless the class is explicitly designed for polymorphic injection or extension. Exception: Facades, Services, Clients, and Plugins MUST always have a corresponding interface. | Do not create an interface just to have one implementation.                                       |
| **Visibility**                    | Use private scope where appropriate.                                                                                                     | Project code is not required to be extendable by customers. Use private to make code more strict. |
| **Type Declarations**             | Use PHP 8.4 native types for all declarations.                                                                                           | GOOD: `private int $foo` BAD: `/** @var int */ protected $foo`                                     |

# DocBlocks

Doc-blocks MUST NOT repeat information already expressed by the method signature.

Critical instructions:
- Doc-blocks MUST NOT restate parameter types, return types, or method names that are already declared via native PHP type hints
- Doc-blocks MUST be omitted entirely when all information is captured by the native signature
- `@param` annotations MUST NOT be used when the parameter type is a scalar, object, or any non-iterable native type
- `@return` annotations MUST NOT be used when the return type is a scalar, object, or any non-iterable native type
- `@var` annotations MUST NOT be used when the variable type is declared natively
- Doc-blocks ARE allowed ONLY for array/iterable element types that cannot be expressed natively

They are only allowed to:
- Use doc-blocks for array/iterable element types: `@param array<\Generated\Shared\Transfer\OrderTransfer> $orders`, `@return array<int, \Generated\Shared\Transfer\ProductTransfer>`
- Add a doc-block with a description when explaining non-obvious business logic

They are NOT allowed to:
- Repeat native type hints in doc-blocks:
```php
// BAD
/**
 * @param int $limit
 * @param string $name
 * @return bool
 */
public function isWithinLimit(int $limit, string $name): bool
{
}

// BAD
/**
 * @param ProductTransfer $productTransfer
 * @return ProductTransfer
 */
public function expandProduct(ProductTransfer $productTransfer): ProductTransfer
{
}
```
- Use `@var` when the type is already declared natively:
```php
// BAD
/** @var string */
protected string $status;

// BAD
/** @var ProductFacadeInterface */
protected ProductFacadeInterface $productFacade;
```
- Duplicate interface doc-blocks in implementations:
```php
// BAD - implementation repeating the interface contract
/**
 * Expands the product transfer with additional data.
 *
 * @param ProductTransfer $productTransfer
 * @return ProductTransfer
 */
public function expandProduct(ProductTransfer $productTransfer): ProductTransfer
{
}
```

They are only allowed to:
```php
// GOOD - no doc-block needed, signature is self-documenting
public function isWithinLimit(int $limit): bool
{
}

// GOOD - doc-block only for iterable element type
/**
 * @param array<\Generated\Shared\Transfer\OrderTransfer> $orders
 * @return array<string, \Generated\Shared\Transfer\ProductTransfer> Key represents SKU
 */
public function indexOrders(array $orders): array
{
}

```

# Bad & Good Practices

## If/Else

**Prefer avoiding if/else constructs** when early returns, early continues, or default value overrides make the intent clearer. Use judgment — forced avoidance of if/else can make complex logic harder to read.

BAD - If/Else
GOOD - Early return

BAD - If/Else for assignment
GOOD - Default value override

BAD - If/Else in loop
GOOD - Early continue

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Redundant doc-blocks add noise without adding information, making code harder to read
- Native PHP type hints are authoritative and always up-to-date; doc-blocks can become stale
- Minimalist doc-blocks reduce maintenance burden when refactoring method signatures
- PHP 8+ native types cover all scalar and object types, making `@param`/`@return` duplication unnecessary
