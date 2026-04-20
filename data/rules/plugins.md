---
name: plugins
description: Use when writing or reviewing a Plugin class. Enforces that Plugins only wire or adapt — no business logic, no persistence access, delegate to Facade or injected dependencies.
---

**Architecture rule**
Spryker Plugins MUST NOT contain business logic.

Critical instructions:
- If business logic is found, it must be moved to a Model in the Business layer
- From `spryker/kernel` 3.76+, plugins can access the Business layer directly via `getBusinessFactory()` — use this to avoid adding unnecessary Facade methods just to expose a model to a plugin
- Prefer `getBusinessFactory()->createModel()` over adding a new Facade method when the functionality is only needed by the plugin itself

They are only allowed to:
- Act as wiring / adapters / entry points
- Receive data and forward it to the Facade or directly to a Business model via `getBusinessFactory()`
- Transform method signatures

They are NOT allowed to:
- Perform calculations or validations
- Call repositories or entities directly
- Contain domain decision logic
- Use any condition statements to control business flow
- Instantiate new business objects (except transfer creation)

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Improves maintainability: Centralizing logic avoids duplication and scattered domain decisions across multiple executors.
- Supports reusability: Logic in Facades/Business layer can be reused by multiple Plugins or other consumers.
- Reduces coupling: Prevents Plugins from directly accessing repositories or entities, keeping the domain layer encapsulated.
- Aligns with Spryker best practices: Keeps Plugins simple, predictable, and focused on integration rather than decision-making.

