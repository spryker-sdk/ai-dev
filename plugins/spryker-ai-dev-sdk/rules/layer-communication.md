---
name: layer-communication
description: Use when writing or reviewing any PHP code that crosses layer boundaries. Enforces the strict hierarchy Presentation → Communication → Business → Persistence — no upward access, cross-module calls via Facade only.
paths: "src/**/*.php"
---

**Architecture rule**
Layers MUST only access lower layers in the hierarchy: Presentation → Communication → Business → Persistence.

Critical instructions:
- Layers can ONLY access lower layers (Presentation → Communication → Business → Persistence)
- Business layer MUST NOT access Presentation or Communication layers
- Persistence layer MUST NOT access Business layer
- Cross-module access MUST go through Facades (never direct model access)
- Strictly enforce these rules and never suppress even on low confidence

They are only allowed to:
- Access lower layers in hierarchy
- Communication layer can call Business layer (Facade, Client, Service)
- Business layer can call Persistence layer (Repository/EntityManager)
- Cross-module calls via Facade only
- Presentation layer can call Communication layer

They are NOT allowed to:
- Access higher layers (Business → Communication, Persistence → Business)
- Business layer accessing Presentation/Communication
- Direct cross-module Business model access
- Skipping layer boundaries
- Propel entities leaving the Persistence layer — use Mapper to convert to/from Transfer Objects before returning

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Rule in [public documentation](https://docs.spryker.com/docs/dg/dev/architecture/module-api/definition-api.html)
- Maintains clear separation of concerns across layers
- Prevents circular dependencies and tight coupling
- Enforces proper encapsulation and information hiding
- Supports independent layer testing
