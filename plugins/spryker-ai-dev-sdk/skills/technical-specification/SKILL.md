---
name: technical-specification
description: Use when the user asks for a technical specification (tech spec) or a technical design document for a new Spryker feature, from a PRD or from business requirements. Creates the spec in steps with the user — a template first, then module dependency graph, ERD, transfer model, flow (sequence) diagrams, module API, test coverage outline, and risks — all as Mermaid diagrams and method signatures, with no implementation code.
user-invocable: true
disable-model-invocation: true
---

# Create a Technical Specification

## Purpose

Create a high-level technical specification for a new Spryker feature. Use a PRD or the business requirements that the user gives as input.

## Mode

**CRITICAL:** Use planning mode. Create a structured technical specification document.

- Use the template in [technical-spec-template.md](technical-spec-template.md).
- The user fills the sections with the marker `[User fills: *]`.
- The assistant fills the sections with the marker `[Assistant fills: *]`.
- Write as a software engineer: clear, short, correct and professional.
- Write the document in Markdown.
- Use Mermaid syntax for all diagrams.
- Do not repeat information that the PRD or another section already gives.

## Storage and Delivery

Use these format rules for each tech spec:

- **Path:** `resources/plan/tech-spec/<Domain>/<TICKET>-<feature-name>-tech-spec.md` is the default. `<Domain>` is the domain folder of the feature (for example `Checkout` or `Pricing`), not a module folder. The project can choose a different location. If the project already has tech specs, use their location.
- **Follow-up spec:** Put a follow-up spec in the same folder as the spec of its base feature.
- **One file for each ticket:** Update the file in place when the design changes. Do not create a second version of the file.
- **Header:** Start the file with a quote block that contains a list with these items: `Status`, `Created`, `Ticket`, `PRD` or `Base tech spec`, `Implementation` (the PR link), `Feature docs`.
- **Section 1:** When a PRD exists, remove section 1. Write this line in its place: "Section 1 is removed because the PRD is available."
- **Commits:** Commit the spec in the implementation branch, in a separate commit, so that the spec is part of the implementation PR. Commit each later update of the spec as a separate commit. Use the commit message convention of the project.
- **PR link:** Add a link to the spec in the implementation PR description: `Tech Spec: [<title>](<link to the file on the branch>)`.

## Workflow

### Step 0: Analyze the user request

- Find out if the user wants to start a new tech spec or continue an existing tech spec.
- For a new tech spec, go to Step 1.
- To continue a tech spec, go to Step 3.
- For feedback or a change request, go to Step 4.

### Step 1: Create the template (assistant)

1. Create the template file at the path in "Storage and Delivery".
2. Create only the template file in this step.
3. Ask the user to fill sections 1.1 - 1.6 and 2.1 - 2.3.

When a PRD exists, ask the user to fill only sections 2.1 - 2.3. To create a PRD first, use the `product-requirement-document` skill.

### Step 2: User input

The user fills sections 1.1 - 1.6 and 2.1 - 2.3 with information about the feature, the requirements and the technical context. The assistant needs this information to create a correct technical specification.

The user tells the assistant when the sections are ready.

### Step 3: Develop the technical specification (assistant)

1. Read and analyze the sections that the user filled. Find the feature scope, the requirements and the technical context.
2. Examine the codebase to find the related functionality, patterns and best practices. If your harness supports subagents, use a small number of read-only research subagents in parallel, each with a different scope. Optional sources:
   - The Spryker tooling MCP tools (for example `getSprykerModules`, `getSprykerModuleMap`, `getTransferStructureByName`) for modules and transfers.
   - The `spryker-docs-research` skill for the official Spryker documentation.
3. Ask clarifying questions about the requirements and the research results. Use this format:
   ```
   I read your requirements. I have some questions:

   1. [Specific question about requirement X]
   2. [Clarification for Y]
   3. [Technical decision: Option A or B?]
   ```
4. Wait for the answers of the user.
5. Fill sections 2.5 - 2.8 with the detailed architecture:
   - Use [erd.md](erd.md) to create the ERD diagram in section 2.5, if the feature changes the database.
   - Use [transfer-model.md](transfer-model.md) to create the transfer object diagram in section 2.6, if the feature adds or changes transfers.
   - Use [flow-diagram.md](flow-diagram.md) to create the flow (sequence) diagrams in section 2.7.
   - Use Mermaid for all other diagrams that the user asks for.
6. Fill section 2.4 last. Use [module-dependency-graph.md](module-dependency-graph.md).
7. Fill section 2.9 Test Coverage Outline. Do not add a build order or a slice plan.
8. Fill section 2.10 Risks and Open Questions. This is always the last section.
9. Ask the user to review the technical specification and give feedback.

### Step 4: Review and changes (user and assistant)

- The user reviews the technical specification and gives feedback.
- The assistant changes the specification until the user approves the final version.
- After the approval, the technical specification is final. Use it as the reference for the implementation.

## Content Rules

- **Diagrams:** Use Mermaid for all diagrams. Follow the rules in the guideline file of each diagram type.
- **No XML:** Do not add the schema `.xml` or the transfer `.xml` to sections 2.5 and 2.6. The diagram is the definition. Write only the file path for each module and short notes.
- **Module API:** In section 2.8, write only method signatures, specifications and descriptions. Do not write implementation code.
- **Real names:** Read the `*Interface.php` file (or the class file) of each existing Facade, Client, Service or plugin before you write its method name. Do not write a method name that you did not see in the code or define in section 2.8.
- **Release types:** Show the expected release type (major, minor or patch) of each new or changed module in the module dependency graph.
- **Test coverage:** Write a test coverage outline in section 2.9. Put the functional tests of the public API first.
- **Last section:** Risks and Open Questions is always the last section.
