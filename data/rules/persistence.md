---
name: persistence
description: Use when writing or reviewing any persistence layer class (Repository, EntityManager, Entity, Query Object) or a Propel schema file.
paths: "src/**/Persistence/**/*.php,src/**/Persistence/Propel/Schema/*.schema.xml"
---

**Architecture rule**
The persistence layer is the only place that touches the database. Everything outside it works with Transfer Objects — never Propel entities.

## General Rules

- Public methods MUST accept and return Transfer Objects or primitives only
- Prefer bulk operations over single-record operations for performance

## Repository (read-only)

- Methods MUST be read-only: `find*`, `get*`, `has*`, `count*`
- Access Query Objects through the Factory only
- MUST NOT be called directly from other modules

## EntityManager (write-only)

- Methods MUST be write-only: `create*`, `update*`, `delete*`, `save*`
- Use Mapper to convert Transfer Objects to Entities before persistence
- After saving, Propel sets the auto-increment ID on the entity; copy it back to the returned Transfer Object so callers receive the new record ID
- MUST NOT be called directly from other modules

## Query Objects

- MUST be accessed via Factory using `Container::factory()` in DependencyProvider, or via the Propel static `create()` method (e.g. `SpyCurrencyQuery::create()`) — never `new`
- MUST NOT be returned from public methods or leak outside the Persistence layer

## Schema

- Primary keys: INTEGER with `id_[table_name]` pattern
- Foreign keys: `fk_[referenced_table]` pattern with `<foreignTable>` elements including `onDelete`/`onUpdate`
- Table and column names: snake_case
- Define explicit field lengths — `VARCHAR(255)` is forbidden unless the field genuinely requires that length
- Column length can only be increased, never decreased
- Define indexes for performance-critical columns
