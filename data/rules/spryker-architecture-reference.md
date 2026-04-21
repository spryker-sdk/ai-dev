---
name: spryker-architecture-reference
description: Use as a reference when navigating Spryker module structure, layer organization, or file-path patterns. Explains Zed/Yves/Client/Service/Shared/Glue layers, the src/Pyz project namespace, and where different file types belong.
paths: "**/*.{php,xml,json,twig,js,ts,scss}"
---
# Spryker Architecture Reference

## Project Structure

All project code lives in `src/Pyz/`. Core modules are in `vendor/` — never modify them.

Project modules use a flat structure: `src/Pyz/{Layer}/{ModuleName}/`

```
src/Pyz/Yves/AgentPage/
src/Pyz/Zed/Sales/
src/Pyz/Zed/Sales/Business/
src/Pyz/Zed/Sales/Communication/
src/Pyz/Zed/Sales/Persistence/
src/Pyz/Client/Cart/
src/Pyz/Service/UtilText/
src/Pyz/Shared/Sales/
```

## Layer Organization

### Zed (Backend)
Located in `src/Pyz/Zed/{ModuleName}/`

- **`Business/`** — business logic, facades, models
- **`Communication/`** — controllers, forms, tables, plugins
- **`Persistence/`** — repository, entity manager, schema, query objects

### Yves (Storefront)
Located in `src/Pyz/Yves/{ModuleName}/`

- **`Theme/`** — Twig templates, TypeScript, SCSS (atomic design components)
- **`Plugin/`** — storefront plugins

### Client
Located in `src/Pyz/Client/{ModuleName}/` — communication between Yves and Zed

### Service
Located in `src/Pyz/Service/{ModuleName}/` — stateless utilities used across all layers

### Shared
Located in `src/Pyz/Shared/{ModuleName}/` — constants and transfer XML used across all layers

### Glue (API)
Located in `src/Pyz/Glue/{ModuleName}/` — REST API resource controllers and processors

## Frontend Structure (Atomic Design)

Located in `src/Pyz/Yves/{ModuleName}/Theme/{ThemeName}/components/`

- **`atoms/`** — basic building blocks (buttons, inputs, labels)
- **`molecules/`** — groups of atoms (forms, navigation, cards)
- **`organisms/`** — groups of molecules (headers, product lists, footers)
- **`templates/`** — page layout structures
- **`pages/`** — specific page instances

## File Path Patterns

### PHP Files
```
src/Pyz/Zed/{ModuleName}/Business/
src/Pyz/Zed/{ModuleName}/Communication/
src/Pyz/Zed/{ModuleName}/Persistence/
src/Pyz/Client/{ModuleName}/
src/Pyz/Service/{ModuleName}/
src/Pyz/Shared/{ModuleName}/
src/Pyz/Glue/{ModuleName}/
src/Pyz/Yves/{ModuleName}/
```

### Schema and Transfer Files
```
src/Pyz/Zed/{ModuleName}/Persistence/Propel/Schema/*.schema.xml
src/Pyz/Shared/{ModuleName}/Transfer/*.transfer.xml
```

### Frontend Files
```
src/Pyz/Yves/{ModuleName}/Theme/*/components/atoms/
src/Pyz/Yves/{ModuleName}/Theme/*/components/molecules/
src/Pyz/Yves/{ModuleName}/Theme/*/components/organisms/
src/Pyz/Yves/{ModuleName}/Theme/*/styles/
```

### Configuration Files
```
config/Shared/config_default.php             — main application config (all environments)
config/Shared/config_default-docker.*.php    — environment-specific overrides
config/Shared/config_local.php               — local developer overrides (not committed)
config/Shared/stores.php                     — store definitions (only relevant when Dynamic Multi-Store is disabled)
config/Zed/oms/                              — OMS process XML definitions
config/Zed/StateMachine/                     — state machine XML definitions
config/Zed/cronjobs/jenkins.php              — cron job schedules
config/Zed/navigation.xml                    — Backoffice navigation structure
config/Yves/                                 — Yves application services and bundles
config/Glue/                                 — Glue API application services and routes
config/GlueBackend/                          — Glue Backend API config
config/GlueStorefront/                       — Glue Storefront API config
config/install/                              — install recipes (bootstrap, deploy steps)
```

## Dependency Wiring Rules

- New Bridge classes MUST NOT be created. When wiring a dependency from another module, use `$container->getLocator()` directly in the `DependencyProvider`.

## Namespace Convention

Project code uses the `Pyz` namespace: `Pyz\{Layer}\{ModuleName}\`
