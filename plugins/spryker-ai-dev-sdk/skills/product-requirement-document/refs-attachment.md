# Code-reference attachment — `{feature-name}.refs.md`

The PRD body is **code-free** (no FQCNs, no `Class::method()`, no controller/action/plugin/facade/transfer/repository class names, no file paths). But the code references you discover during Phase 0 research are exactly what the **planner** needs next. Don't throw them away — capture them in a sibling attachment.

## Where it lives
Same directory as the PRD, named after the feature:
- `resources/plan/PRD/Features/{FeatureName}/{feature-name}.refs.md` (global), or
- `src/{Org}/{Module}/resources/plan/PRD/{feature-name}.refs.md` (module-specific).

The PRD's research header links to it. Save **both files together** at Phase 8 and tell the user about both.

## What goes in it
Everything code-shaped that you confirmed during research, organized so a planner can cross-walk each PRD story to real symbols. Only include references you actually verified via the Spryker tooling MCP (`getSprykerModules`/`getSprykerModuleMap`/`getTransferStructureByName`), the running app, or direct file reads — never guessed.

- **Module/feature map** — feature name → real module name(s) and namespace(s).
- **Endpoints per story** — for each story's URL path, the controller + action FQCN and the route source (Zed controller / Yves route provider / Glue resource).
- **Facade/API methods** — the methods the capability builds on (e.g. create/validate/read methods), with their interface FQCN. Note transfer types at the boundary (see the facade-method-signatures rule).
- **Transfers** — transfer names and the exact fields a story touches (from `getTransferStructureByName`).
- **Configuration** — configuration keys and their resolver/config method names; feature-flag names; default values observed.
- **Plugins / extension points** — plugin and plugin-interface FQCNs the feature hooks into or must register.
- **File paths** — concrete files touched/overridden (e.g. Twig overrides, JS entries) when known.
- **Running-app observations** — real URLs hit, HTTP statuses, DB/Redis/queue facts from `spryker-runtime` validation.

## Template

```markdown
# Implementation References: [Feature Name]

> Attachment to [`{feature-name}.prd.md`](./{feature-name}.prd.md). NOT part of the requirements — these are the code references discovered during PRD research, for the planner. Each item was verified (tooling MCP / running app / file read), not guessed.

## Module / feature map
- [Feature name] → [Module name(s)] — [namespace(s)]

## Endpoints (per story)
| PRD story | URL path (in PRD) | Controller::action (FQCN) | Status | Route source |
|-----------|-------------------|---------------------------|--------|--------------|
| Story 1 — [title] | `/...` | `Org\Layer\Module\...\Controller::action` | existing/greenfield | Zed controller / Yves route provider / Glue resource |

## Facade / API methods
- `Org\Zed\Module\Business\ModuleFacadeInterface::method()` — [what it does; boundary transfer type]

## Transfers
- `XyzTransfer` — fields used: `field1` (type), `field2` (type), …

## Configuration
- `config:key:path` — [meaning]; resolver `Config::getXyz()`; default `…`; feature flag `isXyzEnabled()`

## Plugins / extension points
- `Org\…\SomePlugin` (registered in `SomeDependencyProvider::getXyzPlugins()`) — [role]
- `Org\…\SomePluginInterface` — [contract to implement]

## File paths
- `src/…/Presentation/…/index.twig` — [override/injection point]
- `src/…/assets/…/entry.js` — [JS entry]

## Running-app observations
- `GET /…` → [status]; [what was confirmed]
- DB/Redis/queue: [fact]
```

## Rule of thumb
If a token has a backslash, `::`, parentheses, or a slash-path — it belongs **here**, not in the PRD body. If it's a plain feature/module name or a configuration key referred to by name, it can appear in the PRD.

**Exception: composer package names + version constraints** (`spryker/quote-requests-rest-api`, `^1.2`). The slash is incidental — a package name is a **product identifier** (same category as a module name), not a code reference. It is allowed in the body and **required** in any story whose scope includes adding a dependency (as a "Packages to add" table: package · version constraint · installed-or-not). The FQCNs, plugin interfaces and resource-config constants shipped *inside* those packages still belong here.
