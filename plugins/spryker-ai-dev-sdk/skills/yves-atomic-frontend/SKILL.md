---
name: yves-atomic-frontend
description: >
  Use when creating, extending, or overriding Spryker Yves atomic frontend components (atoms, molecules, organisms).
  Invoke this skill whenever the user asks to build a Twig component, add a frontend widget, create or modify UI elements
  in the Yves storefront theme, override a core component in Pyz, extend a component with custom blocks, or work with
  SCSS/TypeScript in the SprykerShop frontend layer. This includes tasks like "create a new molecule", "override the
  product card", "add a custom atom for X", "extend the cart item component", "build a new frontend component",
  or "how do I add a new Twig template to the storefront". Always use this skill for any Yves Theme/ component work.
---

# Spryker Yves Atomic Frontend

## Overview

Spryker's storefront (Yves) uses atomic design: **atoms** (basic blocks) → **molecules** (groups of atoms) → **organisms** (groups of molecules). All components live inside `Theme/default/components/` in a Yves module.

## Component File Structure

Every component is a folder with up to 5 files (all in kebab-case):

```
{component-name}/
├── index.ts              # Webpack entry point — imports style, registers TS class
├── style.scss            # Style entry point — applies the mixin
├── {component-name}.scss # Mixin definition with BEM styles
├── {component-name}.ts   # TypeScript class (optional for pure-HTML atoms)
└── {component-name}.twig # Twig template
```

**When TypeScript is NOT needed** (simple display atoms), omit `.ts` — the `index.ts` only imports style.

---

## Step-by-step: Creating a New Component

### 1. Choose the module and type

Decide where it lives and its atomic type:
- **Core/Shop module** → `src/SprykerShop/{Module}/src/SprykerShop/Yves/{Module}/Theme/default/components/{atoms|molecules|organisms}/{component-name}/`
- **Project override** → `src/Pyz/{Module}/src/Pyz/Yves/{Module}/Theme/default/components/{atoms|molecules|organisms}/{component-name}/`

### 2. Create the Twig template

Every component extends the component model:

```twig
{% extends model('component') %}
{% import model('component') as component %}

{% define config = {
    name: 'my-component',   {# CSS class name (BEM block) + jsName auto-set to 'js-my-component' #}
    tag: 'my-component',    {# HTML tag; use custom element tag for TS-backed components #}
} %}

{% define data = {
    title: required,            {# required = throws if not passed #}
    description: '',            {# optional with default #}
    items: [],
} %}

{% define attributes = {
    'some-attr': required,      {# rendered as HTML attribute on the root element #}
} %}

{% define modifiers = [] %}     {# BEM modifiers: adds config.name--modifier CSS classes #}

{% block body %}
    <h2 class="{{ config.name }}__title">{{ data.title }}</h2>

    {% if data.description %}
        <p class="{{ config.name }}__description">{{ data.description }}</p>
    {% endif %}

    {% for item in data.items %}
        {% block item %}
            <div class="{{ config.name }}__item">{{ item }}</div>
        {% endblock %}
    {% endfor %}

    {# Include child components with isolated scope #}
    {% include atom('icon') with {
        data: { name: 'arrow' },
    } only %}
{% endblock %}
```

**Key rules for Twig:**
- Always `{% extends model('component') %}` at the top
- `config.name` is the BEM block — use it for all CSS classes
- `config.jsName` is `js-{config.name}` — use for JS selectors, never CSS
- Use `required` for mandatory props; it throws a helpful error if missing
- Use `only` in includes to prevent scope leakage
- Use `{% block body %}` as the main content block
- Reference siblings: `atom('name')`, `molecule('name', 'ModuleName')`, `organism('name', 'ModuleName')`
- `| trans` takes a **glossary key** (`cart.item.add`), **never a human sentence**. `{{ 'Add to cart' | trans }}` renders perfectly in English — the translator echoes the unknown key — and silently renders English in every other locale, so it is a latent i18n bug that no glossary or translation work can fix. Failure signature: a non-English storefront page showing fluent English strings with no raw dot-notation keys visible. Author the glossary row (`key,translation,locale`) alongside the component, in the same change.

### 3. Create the SCSS

Two-file pattern:

**`{component-name}.scss`** — mixin definition:
```scss
@mixin {module-name}-{component-name}($name: '.{component-name}') {
    #{$name} {
        display: flex;

        &__title {
            font-weight: bold;
        }

        &__item {
            padding: map-get($setting-spacing, 'default');

            &--active {
                color: $setting-color-main;
            }
        }

        &--compact {
            padding: 0;
        }

        @content;  // Always include @content for downstream customization
    }
}
```

**`style.scss`** — entry point that applies the mixin:
```scss
@include helper-import(molecule, {component-name}) {
    @include {module-name}-{component-name};
}
```

Replace `molecule` with `atom` or `organism` as appropriate.

### 4. Create the TypeScript class (if interactive)

```typescript
import Component from 'ShopUi/models/component';

export default class MyComponent extends Component {
    protected triggers: HTMLElement[];

    protected init(): void {
        this.triggers = Array.from(this.getElementsByClassName(`${this.jsName}__trigger`)) as HTMLElement[];
        this.mapEvents();
    }

    protected mapEvents(): void {
        this.triggers.forEach((el: HTMLElement) => {
            el.addEventListener('click', (event: Event) => this.onTriggerClick(event));
        });
    }

    protected onTriggerClick(event: Event): void {
        event.preventDefault();
        // logic here
    }

    // Read HTML attributes declared in `{% define attributes %}`:
    protected get targetSelector(): string {
        return this.getAttribute('target-selector');
    }
}
```

**Key rules for TypeScript:**
- Extend `Component` from `ShopUi/models/component`
- Use `init()` for setup (called after DOM is ready), NOT the constructor
- Use `this.jsName` (`js-{component-name}`) for DOM queries — keeps CSS and JS separate
- Use `this.getAttribute('attr-name')` to read values declared in `{% define attributes %}`
- Prefer `protected` over `private` for extensibility
- Use `readyCallback()` instead of `init()` only when you need all components already loaded

### 5. Create index.ts

**With TypeScript class:**
```typescript
import './style.scss';
import register from 'ShopUi/app/registry';

export default register(
    'my-component',
    () =>
        import(
            /* webpackMode: "lazy" */
            /* webpackChunkName: "my-component" */
            './my-component'
        ),
);
```

**Without TypeScript class (style-only atom):**
```typescript
import './style.scss';
```

---

## Using Components in Templates

```twig
{# Atom — simple, no module needed if in ShopUi #}
{% include atom('icon') with {
    data: { name: 'cart' },
} only %}

{# Molecule — specify module for non-ShopUi components #}
{% include molecule('product-card', 'ProductWidget') with {
    data: {
        product: data.product,
    },
    modifiers: ['compact'],
    class: 'my-extra-class',
} only %}

{# Organism — same pattern #}
{% include organism('filter-section', 'CatalogPage') with {
    data: { facets: data.facets },
} only %}

{# Fallback: try specific first, fall back to generic #}
{% include [
    molecule('filter-' ~ filterName, 'CatalogPage'),
    molecule('filter-' ~ filterType, 'CatalogPage'),
] ignore missing with { data: {...} } only %}
```

**Passing modifiers** adds BEM modifier classes: `molecule--compact`.
**Passing `class`** appends extra CSS classes on the root element.

---

## Extending a Component (Pyz)

To customize a component without fully replacing it, extend it and override specific blocks:

```twig
{# src/Pyz/{Module}/src/Pyz/Yves/{Module}/Theme/default/components/molecules/{component-name}/{component-name}.twig #}

{% extends molecule('product-card', '@SprykerShop:ProductWidget') %}

{# Override a specific block #}
{% block title %}
    <h3 class="{{ config.name }}__title">{{ data.product.name | upper }}</h3>
{% endblock %}

{# Remove a block entirely #}
{% block badges %}{% endblock %}

{# Add content before a block (use parent() to keep original) #}
{% block body %}
    <div class="{{ config.name }}__custom-header">Custom banner</div>
    {{ parent() }}
{% endblock %}
```

The `@SprykerShop:ModuleName` namespace syntax tells Twig where to find the parent template.

For atoms: `{% extends atom('icon', '@SprykerShop:ShopUi') %}`
For organisms: `{% extends organism('name', '@SprykerShop:ModuleName') %}`

---

## Overriding a Component (Pyz — full replacement)

Create the same path in `src/Pyz/` with the exact same component name. Spryker's theme resolver picks up `Pyz` first, so it completely replaces the core version:

```
src/Pyz/{Module}/src/Pyz/Yves/{Module}/Theme/default/components/molecules/{component-name}/
├── index.ts           # May be a simple re-export of original or fully custom
├── {component-name}.twig  # Your full replacement template
└── style.scss         # Optional: new styles
```

For pure Twig override with original TS/SCSS intact, only create the `.twig` file and an `index.ts` that re-exports the original:

```typescript
// Re-use original component logic
export { default } from 'SprykerShop/{Module}/{component-name}';
```

---

## Referencing Core Components in Pyz

Use these namespaces in `{% extends %}` or when you need to import TS from core:

| Layer | Twig namespace | TS import alias |
|---|---|---|
| SprykerShop | `@SprykerShop:ModuleName` | `SprykerShop/ModuleName/...` |
| Spryker | `@Spryker:ModuleName` | `Spryker/ModuleName/...` |
| Pyz | `@Pyz:ModuleName` | `Pyz/ModuleName/...` |

---

## After Creating Components

Run the frontend build to compile assets:

```bash
docker/sdk cli npm run yves
# or for watch mode during development:
docker/sdk cli npm run yves:watch
```

---

## Detailed Reference Files

- `references/examples.md` — Full worked examples for each component type
- `references/scss-patterns.md` — SCSS conventions, variables, mixins, BEM guide
- `references/typescript-patterns.md` — Component lifecycle, event patterns, attribute communication