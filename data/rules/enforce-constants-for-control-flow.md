---
name: enforce-constants-for-control-flow
description: Use when writing or reviewing PHP code. Enforces that domain-meaningful string literals used in control flow, comparisons, or property assignments must be extracted into constants. Exception messages and sprintf format strings are exempt.
paths: "src/**/*.php"
---

**Architecture rule**
Hard-coded string literals MUST NOT be used in control flow or when assigning values to properties/variables that represent meaningful domain values. They must be extracted into constants

Critical instructions:
- If a string literal participates in comparison, branching logic, or variable/property assignment where the value has semantic meaning it MUST be extracted into a protected const (or configuration if reused externally)
- Strictly enforce this rule and never suppress even on low confidence
- Applies equally to:
    - if (...) === 'value'
    - $this->status = 'pending'
    - $foo = 'guest' where 'guest' is a domain-relevant key/value
- Literal strings are allowed only when they are non-semantic labels (e.g. sprintf formatting)

They are NOT allowed to:
- set $this->type = 'string';
- set $this->set*('string');
- set $value = 'string';
- compare against 'string' or other inline strings
- repeat string literals that represent domain state/keys

They ARE allowed to:
- compare to built-ins (=== null, === false, etc.)
- assign constant values using protected/public const MY_CONST
- use literal strings in exception messages and sprintf format strings when the string has no requirement to be extendable by subclasses:

```php
// ALLOWED — no constant needed
public function noConstant(): void {
    throw new Exception('This is my case specific error message.');
}

public function noConstant2(int $id, string $name): string {
    return sprintf('Argument count has to match (%d, %s)', $id, $name);
}
```

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
 - Rule in [public documentation](https://docs.spryker.com/docs/dg/dev/guidelines/coding-guidelines/coding-guidelines.html)
 - Enhances readability: constants convey domain meaning clearly, making code self-documenting.
 - Facilitates project-level overrides: allows changing values in one place instead of overriding methods fully.
 - Reduces bugs: avoids typos and inconsistent values across the codebase.
