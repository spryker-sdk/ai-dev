# technical-specification

Create a **technical specification (tech spec)** for a new Spryker feature, together with the user. The
input is a PRD or the business requirements of the user. The output is one Markdown file with Mermaid
diagrams and module API signatures. The file contains no implementation code.

A PRD tells WHAT to build and WHY. The tech spec tells HOW the feature fits into the Spryker
architecture: which modules change, which plugins and extension points the feature uses, which tables
and transfers it adds, and how the data flows. Use the approved tech spec as the reference for the
implementation.

## When it triggers

The user asks for a tech spec or a technical design document for a new Spryker feature
(`/spryker-ai-dev-sdk:technical-specification`). The `spryker-customization` skill also starts it at
Step 0c (MVP only), when the user wants a reviewed tech spec before the build. The approved tech spec is
then a design input for the solution design and the build.

Feature path: `product-requirement-document` → `technical-specification` (optional) →
`spryker-customization`.

**Not for:** business requirements (use `product-requirement-document`), implementation, task
breakdown, or a build order.

## Flow schema

```mermaid
flowchart TD
    A([Tech spec requested]) --> S0{"Step 0<br/>New, continue<br/>or feedback?"}
    S0 -- "new" --> S1["Step 1<br/>Create the template file"]
    S0 -- "continue" --> S3
    S0 -- "feedback" --> S4
    S1 --> S2["Step 2<br/>User fills 1.1 - 1.6<br/>and 2.1 - 2.3"]
    S2 --> S3["Step 3<br/>Research the codebase<br/>Ask clarifying questions"]
    S3 --> S3B["Fill 2.5 - 2.8<br/>ERD · transfers ·<br/>flows · module API"]
    S3B --> S3C["Fill 2.4 dependency graph<br/>2.9 test coverage<br/>2.10 risks (last)"]
    S3C --> S4{"Step 4<br/>User review<br/>Approved?"}
    S4 -- "no" --> S3B
    S4 -- "yes" --> DONE([Final tech spec])
```

## Files

| File | Role |
|---|---|
| [`SKILL.md`](SKILL.md) | The workflow (Steps 0 - 4), the storage and delivery rules, and the content rules. |
| [`technical-spec-template.md`](technical-spec-template.md) | The tech spec template with the sections for the user and for the assistant. |
| [`module-dependency-graph.md`](module-dependency-graph.md) | Rules for the module dependency graph (section 2.4): relation types, release types, no self-references, color codes. |
| [`erd.md`](erd.md) | Rules for the database ERD (section 2.5). |
| [`transfer-model.md`](transfer-model.md) | Rules for the transfer domain model diagram (section 2.6). |
| [`flow-diagram.md`](flow-diagram.md) | Rules for the flow (sequence) diagrams (section 2.7). |

## Critical requirements

- **User input first.** The user fills the business and context sections before the assistant
  designs the architecture.
- **Mermaid only.** All diagrams use Mermaid. No schema or transfer `.xml` in the spec.
- **Real names.** Each method name in a diagram or in section 2.8 comes from the code or from the new
  API in section 2.8.
- **Release types.** The module dependency graph shows the expected release type of each new or
  changed module.
- **Test coverage outline.** Section 2.9 lists the tests. Functional tests of the public API come first.
- **Risks last.** Risks and Open Questions is always the last section.

## Output

One file for each ticket. The default path is
`resources/plan/tech-spec/<Domain>/<TICKET>-<feature-name>-tech-spec.md`. The project can choose a
different location.
