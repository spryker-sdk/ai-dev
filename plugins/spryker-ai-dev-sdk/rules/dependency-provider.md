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
- Never extend or subclass a core Bridge class.

To add a method to a core facade that another module consumes through a bridge interface:

1. In the consuming module's project namespace, create an interface with the core bridge interface's name that extends the core bridge interface and declares the new method.
2. In the providing module's project namespace, extend the core facade, implement the new interface and add the method.
3. In the consuming module's DependencyProvider, call the parent, then set the facade constant with `$container->getLocator()` and the new interface as the return type.
4. In the consuming module's business factory, change the facade getter to return the new interface.
