---
name: naming-conventions
description: Use when naming any class, method, variable, or constant. Enforces Spryker suffix conventions (Facade, Factory, Repository, Controller, Plugin), forbids Executor/Handler/Worker, discourages Manager/Helper, and locks method and variable patterns (find/get/has, idX, isX/hasX).
paths: "src/**/*.php"
---

**Architecture rule**
All components MUST follow strict naming conventions identifying architectural role, layer, and purpose.

Critical instructions:
- Component names MUST include appropriate suffix (Facade, Factory, Repository, Controller, Plugin, etc.)
- When an interface is created, its name MUST be the implementation name + "Interface" (ProductReader → ProductReaderInterface)
- Method names MUST follow verb+noun pattern (find*, get*, create*, update*, delete*, has*, is*)
- Factory methods MUST use create* (new instance) or get* (shared dependency) prefixes
- Transfer names MUST be singular + "Transfer" suffix
- Controller public actions MUST have "Action" suffix
- Constants MUST use SCREAMING_SNAKE_CASE
- Boolean properties MUST start with is/has/can prefix
- ID properties MUST start with "id" prefix

## Forbidden Class Suffixes

The following suffixes MUST NOT be used as they convey no architectural meaning (per official [architectural convention](https://docs.spryker.com/docs/dg/dev/architecture/architectural-convention.html)):
- `Executor` — MUST NOT be used
- `Handler` — MUST NOT be used
- `Worker` — MUST NOT be used

The following suffixes SHOULD NOT be used as they are generic and usually indicate a missing single-responsibility split. Prefer one of the approved business suffixes below:
- `Manager` — SHOULD NOT be used
- `Helper` — SHOULD NOT be used in business logic context

## Approved Business Class Suffixes

Use these suffixes to communicate the single responsibility of a class:
- `Reader` — reads/fetches data
- `Creator` — creates new entities
- `Updater` — updates existing entities
- `Deleter` — removes entities
- `Expander` — adds data to an existing transfer
- `Mapper` — converts between data types

## Method Naming

| Pattern | Meaning |
|---------|---------|
| `find*()` | Nullable — returns null if not found |
| `get*()` | Non-nullable — throws exception if not found |
| `has*()` | Existence check only — returns bool |
| `getXXXIndexedByYYY()` | Returns indexed array (one key → one value) |
| `getXXXGroupedByYYY()` | Returns grouped array (one key → multiple values) |

## Variable Naming

| Variable | Expected type |
|----------|--------------|
| `$userEntity` | Propel entity (`SpyUser`) |
| `$userEntityTransfer` | Transfer of Propel entity (`SpyUserEntityTransfer`) |
| `$userTransfer` | Custom transfer object |
| `$idUser` | Integer ID |
| `$userIds` | Array of integer IDs |
| `$isActive`, `$hasPassword` | Boolean |

They are only allowed to:
- Use standard component suffixes (Facade, Factory, Repository, etc.)
- Follow verb+noun method naming (findProductById, createProduct)
- Use create*/get* for factory methods
- Use SCREAMING_SNAKE_CASE for constants
- Use is/has/can for boolean properties
- Use id prefix for identifiers

They are NOT allowed to:
- Omit required component suffixes
- Use forbidden suffixes: Executor, Handler, Worker
- Use discouraged suffixes: Manager, Helper (in business logic)
- Use non-standard suffixes or prefixes
- Name methods without clear verbs
- Use lowercase or mixed case for constants
- Skip is/has/can prefix on booleans
- Skip id prefix on identifiers

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Rule in [public documentation](https://docs.spryker.com/docs/dg/dev/architecture/architectural-convention.html)
- Makes code self-documenting through clear, consistent names
- Enables IDE autocomplete and navigation via predictable patterns
- Enforces architectural boundaries by making layer explicit
- Prevents naming collisions through standardized suffixes
- Communicates component lifecycle (find vs get, create vs update)
