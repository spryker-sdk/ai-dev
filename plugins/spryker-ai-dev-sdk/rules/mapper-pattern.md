---
name: mapper-pattern
description: Use when writing or reviewing a Mapper class. Enforces that Mappers transform data only — both source AND target are passed as parameters, stateless, no queries or lazy loading, no business logic.
paths: "src/**/*Mapper.php"
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

## Example

A correct mapper method such as `mapProductTransferToEntity(ProductTransfer $productTransfer, SpyProduct $productEntity): SpyProduct` receives the target from the caller, copies the data (for example with `fromArray()`), and returns the target.
