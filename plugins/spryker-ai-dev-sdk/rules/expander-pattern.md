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

## Example

A correct expander fetches the related data through a Facade, returns the input transfer unchanged when that data is missing, and otherwise sets the data on the same instance and returns it.
