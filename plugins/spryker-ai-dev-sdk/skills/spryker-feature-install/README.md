# spryker-feature-install

Install a Spryker feature into a project **end to end**, from a capability card or a feature name: resolve the feature and its required features, confirm scope, fetch the official install guide, `composer require`, apply the guide's integration steps in the project layer, run codegen and setup, verify, and report what was done and skipped.

Where `find-existing-capability` decides "install it instead of building it", this skill does the install - always after the user confirms the feature, packages, version constraint and optional integrations.

## When it triggers

"Install the X feature", "add `spryker-feature/x`", "integrate feature X", "install the X Glue API", or after `find-existing-capability` proposed an install (`not_installed` / `partially_installed`).

## Flow schema

```mermaid
flowchart TD
    A([Feature name or capability card]) --> B["1 - Resolve<br/>getSprykerCapability: Install section<br/>feature, requires features, docs guides,<br/>Glue API, Storefront API"]
    B --> RQ{"Required features<br/>installed?"}
    RQ -- "no" --> RQ1["Offer to install them first<br/>(recursively)"] --> C
    RQ -- "yes" --> C
    C{"2 - Confirm with the user<br/>feature, packages, version constraint,<br/>optional APIs and demo data, branch"}
    C -- "no / change" --> C
    C -- "yes" --> D["3 - Fetch the install guide<br/>spryker-docs-research or docs markdown<br/>match the project's release line<br/>build the step checklist"]
    D --> E["4 - composer require<br/>docker/sdk cli composer require<br/>conflict: stop and report"]
    E --> F["5 - Integration steps, project layer only<br/>config - plugin wiring - transfers - schema<br/>navigation - routes - glossary - data import"]
    F --> G["6 - spryker-refresher<br/>codegen, caches, setup commands<br/>+ the guide's own setup commands"]
    G --> H["7 - Verify<br/>guide's verify steps - boot-and-verify<br/>spryker-verifier agent"]
    H --> R(["8 - Report<br/>installed - done / skipped checklist<br/>follow-ups - unstaged changes"])
```

## Tools

Matched by tool name: `searchSprykerCapabilities`, `getSprykerCapability`, `searchAlgoliaDocumentation`, `getSprykerModules`, `getSprykerModuleMap`, `getTransferStructureByName`. Skills it hands to: `spryker-docs-research`, `spryker-refresher`, `boot-and-verify`, `project-data` / `data-import`; agents: `spryker-verifier`, `spryker-issue-diagnoser`.

## Output

A feature install report: what was installed (packages, versions, required features), the guide followed, the step checklist with `done` / `skipped (reason)`, verification evidence, follow-ups (for example the Glue API integration not done) and the changed files, left unstaged.

## Design decisions baked in

- **Confirm before changing.** Nothing is required or edited before the user agrees to the feature, constraint and optional integrations.
- **The guide is the source of truth for steps; the installed code is the source of truth for names.** Identifier spellings are confirmed against `vendor/` (read-only) before they are written.
- **Project layer only.** Never `vendor/`, `src/Generated/` or `src/Orm/`; existing plugin stacks keep their order.
- **No silent gaps.** Every guide step ends done or skipped with a reason; a failing step is diagnosed, not dropped.
- **No destructive commands, no auto-commit.** A rebuild (`docker/sdk up`) or `reset` is reported, not run.
