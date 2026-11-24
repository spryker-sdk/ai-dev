---
name: php-code-style
description: Use when writing or editing any PHP file. Enforces PHP 8.4 native types over DocBlocks, minimalist DocBlock rules, no if/else (early returns instead), max nesting level 2, sprintf over concatenation, and private visibility preference for project code.
paths: "{src,tests}/**/*.php"
---

# General Principle

**Always apply rules over existing patterns.** When writing new code, follow the rules — not what you see in the surrounding file. Existing code may predate these rules or simply be wrong. Never use "the existing code does it this way" as justification for skipping a rule. Only mirror an existing pattern if no rule covers the situation and the pattern is clearly intentional.

# PHP Code & Structure

These rules MUST be applied to ALL code you write or modify, regardless of the style used in the surrounding or existing code.
Do NOT follow patterns from legacy code that conflict with these rules.

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

They are NOT allowed to: repeat native type hints in `@param`/`@return`, use `@var` on a natively typed property, or copy the interface doc-block into an implementation.

# Bad & Good Practices

## If/Else

**Prefer avoiding if/else constructs** when early returns, early continues, or default value overrides make the intent clearer. Use judgment — forced avoidance of if/else can make complex logic harder to read.
