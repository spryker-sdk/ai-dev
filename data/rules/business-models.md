---
name: business-models
description: Use when writing or reviewing Business models in Zed, Client, or Service layers. Enforces single responsibility, statelessness, constructor injection, and module boundary discipline.
---

**Architecture rule**
Models in Business, Service, and Client layers MUST have a single responsibility, be stateless, and never directly access other modules.

## All layers

- Each model MUST have one well-defined responsibility — split complex operations into focused, composable models
- Models MUST be stateless — no mutable properties between method calls; all data passed through parameters and returned as results
- Only immutable dependencies injected via constructor are allowed as properties

## Zed Business layer

- Use Repository/EntityManager from the same module
- Cross-module calls MUST go through injected Facades wired via DependencyProvider and Factory — never direct model access
- Caching MUST be at infrastructure level (Repository), not in business models
