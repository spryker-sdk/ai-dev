---
name: controller
description: Use when writing, reviewing, or modifying any Controller class. Enforces the thin-controller pattern — delegate to Facade/Client/Service only, no business logic, no direct persistence access.
paths: "src/**/*Controller.php"
---

**Architecture rule**
Controllers MUST only adapt requests and delegate to Facade or Client, containing no business logic

Critical instructions:
- Controllers MUST delegate to Facade or Client, never call Repository or EntityManager directly
- Use castId() for integer ID extraction from request parameters
- All request parameters MUST be validated before use; pass only validated parameters to Facade or Client
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
- Add translatable flash messages with placeholders passed as parameters (`addSuccessMessage('Saved %count% items', ['%count%' => $count])`)

They are NOT allowed to:
- Build flash messages with `sprintf()` or string concatenation — the result has no glossary key and cannot be translated
- Call Repository or EntityManager directly
- Access Query Objects or Entities
- Contain complex data transformations
