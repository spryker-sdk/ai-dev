# [Feature Name] - Technical Specification

> - **Status**: Draft - Requirements Phase
> - **Created**: [date]
> - **Ticket**: [ticket link, if available]
> - **PRD**: [link to the Product Requirements Document, if available] or **Base tech spec**: [link]
> - **Implementation**: [implementation PR link]
> - **Feature docs**: [path to the feature docs]

## 1. Overview & Business Context

**Remove section 1 if a PRD is available.**

### 1.1 Problem Statement
[User fills: Which problem does the feature solve?]

### 1.2 Business Goals
[User fills: What are the business objectives?]

### 1.3 Target Users
[User fills: Who uses this feature?]

### 1.4 Functional Requirements
[User fills: What must the feature do?]
- [ ] Requirement 1
- [ ] Requirement 2

### 1.5 Non-Functional Requirements
[User fills: Performance, security and scalability requirements]
- Performance:
- Security:
- Scalability:

### 1.6 Acceptance Criteria
[User fills: Which conditions show that the feature is complete?]
1.
2.

## 2. Technical Specification (Assistant fills after user input)

### 2.1 Technical Context and Hints
[User fills: Technical context and hints that show the assistant the correct direction]

### 2.2 Module Scope
[User fills: A new module or existing modules? Which modules?]
- New modules:
- Existing modules to change:

### 2.3 Technical Constraints
[User fills: Technical limits or requirements]

---
# STOP HERE - The user fills the sections above and then tells the assistant to continue
---

### 2.4 Module Dependency Graph
[Assistant fills: Module dependency graph per module-dependency-graph.md]

### 2.5 Database Schema
[Assistant fills: Database Entity Relationship Diagram (ERD) as Mermaid per erd.md, the schema file path for each module, and short notes for constraints and behaviors. Do not add the schema `.xml`.]

### 2.6 Transfers and Transfer Object Domain Model Diagram
[Assistant fills: Transfer Object Domain Model diagram as Mermaid per transfer-model.md, the transfer file path for each module, and short notes for property semantics. Do not add the transfer `.xml`.]

### 2.7 Component Structure
[Assistant fills: Module and component breakdown (Mermaid diagram or list). One Data Flow Sequence Diagram for each flow per flow-diagram.md: plugins and Facade, Client, Service and Config calls with their names, Gateway and external calls, storage access at a high level.]

### 2.8 Module API Design
[Assistant fills: Spryker module API]

**Facade, Client and Service methods, plugin interfaces, plugins to implement, module or shared config, controllers, console commands, endpoints, events.**
**No implementation details. Write only method signatures, specifications and descriptions.**

### 2.9 Test Coverage Outline
[Assistant fills: A table with one row for each tested class or flow and its test type (Functional, Unit, Cypress). Put the functional tests of the public API (Facade, Client, Glue resource, Yves controller, plugin) first. No build order and no slice plan.]

### 2.10 Risks and Open Questions
[Assistant fills: Risks and open questions]
