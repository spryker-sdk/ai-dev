---
name: upgradability
description: Use when writing or reviewing any Spryker project code that extends or customizes core modules — including plugins, facades, models, configuration, and DependencyProviders. Enforces upgrade-safe customization strategies to keep the project compatible with the Spryker Code Upgrader.
---

**Upgradability rule**
Project code SHOULD NOT directly override or copy core Spryker classes. All customization SHOULD go through officially supported extension points so that Spryker core updates can be applied without breaking project code.

## Customization strategy (preferred order)

Use the least invasive strategy that fulfills the requirement:

1. **Module configuration** — override config values via `ModuleConfig` or environment config; never edit core config files directly
2. **Plugin replacement** — swap or add plugins via DependencyProvider; never modify core plugin stacks directly
3. **Class extension** — extend the core class on the project level and override only the method that needs to change
4. **Module replacement** — copy the full module to the project namespace only as a last resort; accept full ownership of future upgrades for that module

## DependencyProvider and plugins

- DependencyProvider methods MUST return plain plugin arrays — no conditional logic, loops, or dynamic resolution
- Plugins registered in DependencyProvider MUST only accept scalar constructor arguments (`int`, `float`, `string`, `bool`, `const`) or `new` statements without complex expressions
- Plugin stacks MUST NOT contain inline anonymous classes or closures

## Core class extension

- Never copy-paste core class bodies into project namespace — extend and override only the specific method
- Do not use `private` methods or properties from core classes in project extensions; rely only on `public` and `protected` API
- Avoid extending `@internal` or `@deprecated` core classes — these are not covered by semantic versioning and may break on minor updates
- Remove dead code: project extensions that override core methods which no longer exist in core MUST be cleaned up

## Module and schema customization

- Extend database schemas via project-level `schema.xml` files — never modify core schema files directly
- Extend transfer objects via project-level `transfer.xml` — never modify core transfer definitions
- Use `config_default.php` and environment-specific config files for runtime configuration — never hardcode values in extended classes

## Keeping upgrades safe

- Keep Spryker packages on the latest minor within a major — delayed updates compound upgrade effort significantly
- Avoid locking transitive Spryker dependencies in `composer.json`; let Composer resolve compatible versions
- Dev packages (`spryker/*-dev`) MUST NOT be present in production `require` — use `require-dev` only
