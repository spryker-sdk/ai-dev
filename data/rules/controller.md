---
name: controller
description: Use when writing, reviewing, or modifying any Controller class. Enforces the thin-controller pattern — delegate to Facade/Client/Service only, no business logic, no direct persistence access.
---

**Architecture rule**
Controllers MUST only adapt requests and delegate to Facade or Client, containing no business logic

Critical instructions:
- Controllers MUST delegate to Facade or Client, never call Repository or EntityManager directly
- Use castId() for integer ID extraction from request parameters
- Controllers MUST NOT contain business logic, validation, or data transformation
- Form handling logic is allowed within Controllers
- Call models from the same layer if necessary to map or prepare data
- Strictly enforce these rules and never suppress even on low confidence

They are only allowed to:
- Define public action methods with 'Action' suffix
- Extract data from Request object
- Call Facade or Client methods for business operations
- Call models from the same layer if necessary to map or prepare data
- Return view responses or redirects
- Handle forms within the controller
- Use castId() for ID parameters

They are NOT allowed to:
- Call Repository or EntityManager directly
- Access Query Objects or Entities
- Contain complex data transformations

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Rule in [public documentation](https://docs.spryker.com/docs/dg/dev/backend-development/zed/communication-layer/controller.html)
- Ensures clear separation between request handling and business logic
- Maintains consistent request/response patterns across application
- Enables testability through thin controller actions that simply delegate
- Prevents business logic duplication across multiple controllers
- Supports security through proper input validation in Business layer
