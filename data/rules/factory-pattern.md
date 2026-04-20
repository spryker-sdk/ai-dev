---
name: factory-pattern
description: Use when writing or reviewing a Factory class. Enforces create* prefix for new instances, get* prefix for shared dependencies, no business logic, and access to dependencies via getProvidedDependency().
globs: "src/Pyz/**/*Factory.php"
---

**Architecture rule**
Factories MUST only create objects using create*/get* prefixes without business logic.

Critical instructions:
- Factory methods MUST be prefixed with create* (new instance) or get* (shared dependency)
- Factories MUST NOT contain business logic, only object instantiation
- Use getProvidedDependency() to retrieve from DependencyProvider
- Constructor injection for dependencies, never property assignment in methods
- Strictly enforce these rules and never suppress even on low confidence

They are only allowed to:
- Define create* methods (return new instances)
- Define get* methods (return shared dependencies)
- Use getProvidedDependency() for DependencyProvider dependencies
- Use constructor injection for dependencies
- Create Mappers, Models, Expanders, etc.

They are NOT allowed to:
- Contain business logic or calculations
- Use method prefixes other than create*/get*
- Perform data transformations

WHY (this part of the instruction MUST be skipped by Agent, this block is for humans only):
- Clear create*/get* pattern communicates instantiation strategy
- No business logic keeps factories focused on object creation
- Constructor injection ensures all dependencies are available
