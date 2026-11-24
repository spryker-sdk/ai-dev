---
name: dependency-provider
description: Use when writing or modifying a DependencyProvider or wiring dependencies between modules.
paths: "src/**/*DependencyProvider.php"
---

**Architecture rule**
DependencyProviders MUST use late-binding closures for all dependencies and organize by layer with descriptive constants.

Critical instructions:
- ALL dependencies MUST be wrapped in late-binding closures (function(Container $container) { return ...; })
- Each layer MUST have its own provide method (provideBusinessLayerDependencies, providePersistenceDependencies, etc.)
- Dependency constants MUST be descriptive (FACADE_CATEGORY, CLIENT_STORAGE, PLUGINS_PRODUCT_EXPANDER)
- MUST call parent provide methods (parent::provideBusinessLayerDependencies($container))
- Strictly enforce these rules and never suppress even on low confidence

They are only allowed to:
- Define public constants for dependency keys in SCREAMING_SNAKE_CASE
- Wrap dependencies in late-binding closures
- Use Container::factory() for Query Objects
- Organize dependencies by layer (Business, Persistence, Communication)
- Call parent provide methods
- Return modified Container

They are NOT allowed to:
- Instantiate dependencies directly without closure
- Use $container->set() without closure wrapper
- Skip parent provide method calls in provide... methods
- Mix dependencies from different layers in one method
- Use non-descriptive or ambiguous constant names
- Add new Bridge classes to wire dependencies — use the existing Bridge from the dependency module's `Dependency/` folder instead

## Adding a dependency or a facade method

- Use `$container->getLocator()` directly in the DependencyProvider; never create a new Bridge class just to register a dependency.
- To add a method to a core facade that another module consumes through a bridge interface: extend the bridge interface in the consuming module, extend the facade in the providing module, and wire it via the locator. Never extend or subclass a core Bridge class.
- Steps and code examples: load the `spryker-customization` skill and read its `references/core-facade-extension.md`.
