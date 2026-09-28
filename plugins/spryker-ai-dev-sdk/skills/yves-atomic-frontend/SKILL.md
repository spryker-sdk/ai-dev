---
name: yves-atomic-frontend
description: >
  Use when creating, extending, or overriding Spryker Yves storefront frontend code in a project — atomic
  components (atoms, molecules, organisms), templates and views, Widgets (PHP AbstractWidget + view Twig),
  and their Twig, SCSS, and TypeScript. Invoke whenever the user asks to build a Twig component, add or
  change a storefront widget, create or modify UI elements in a Yves theme, override or extend a core
  component in src/Pyz, style a component, add JS behaviour to a component, or fix Yves stylelint/eslint
  errors. This includes "create a new molecule", "override the product card", "add a custom atom for X",
  "extend the cart item component", "create a widget", "pass data to the template", "add a JS hook",
  "why isn't my style applied", "the component isn't initializing", "eslint max-lines error", or "how do
  I add a new Twig template to the storefront". Always use this skill for any Yves Theme/ work.
---

# Spryker Yves Atomic Frontend

## Overview

Yves uses atomic design: **atoms** → **molecules** → **organisms**, composed by **templates** (page
layouts) and **views** (controller and Widget templates). Everything lives under a Yves module's
`Theme/{theme}/` directory.

| Where | Path | You may |
|---|---|---|
| Project | `src/Pyz/Yves/{Module}/Theme/default/...` | create, override, extend |
| Core (read-only) | `vendor/spryker-shop/{module}/src/SprykerShop/Yves/{Module}/Theme/default/...`, `vendor/spryker-feature/{module}/src/SprykerFeature/Yves/{Module}/Theme/default/...` | read, extend from Pyz |

Never edit `vendor/` and never create code in the `SprykerShop`/`SprykerFeature` namespaces. The
reference for "how is this done" is the nearest existing component — first in `src/Pyz/Yves`, then in
`vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default`. Copy its structure; do not
invent one.

## Component file structure

One kebab-case folder per component, sitting **directly** under `components/{atoms|molecules|organisms}/`,
`templates/` or `views/` — the builder only discovers entry points one level deep:

```
{component-name}/
├── index.ts              # Webpack entry point — imports the styles, registers the TS class
├── {component-name}.scss # BEM styles as a mixin
├── style.scss            # Core-style mixin invocation (see step 3 — projects usually omit it)
├── {component-name}.ts   # TypeScript class — only when the component is interactive
└── {component-name}.twig # Twig template — omit for CSS-only utilities
```

---

## Creating a new component

### 1. Choose the module and tier

Put it in the project module that owns the feature: `src/Pyz/Yves/{Module}/Theme/default/components/{tier}/{component-name}/`.

| Tier | Use for | Examples |
|---|---|---|
| `atoms/` | Smallest primitive, no dependency on other components | `button`, `icon`, `badge` |
| `molecules/` | A few atoms with one clear responsibility | `collapsible-block`, `toggler-checkbox` |
| `organisms/` | Self-contained section composed of molecules/atoms | `side-drawer`, `section` |

Entry points are keyed by `{tier}/{component-name}` across **all** modules, and a project entry replaces
a core one. A new name that already exists in another module's same tier silently takes over that
component — check `vendor/spryker-shop` and `vendor/spryker-feature` first.

The folder name == `register()` tag == Twig `config.name` (== `config.tag` when there is a TS class) ==
mixin suffix.

### 2. Twig template

```twig
{% extends model('component') %}

{% define config = {
    name: 'my-component',
    tag: 'my-component',  {# required and equal to name when there is a TS class #}
} %}

{% define data = {
    title: required,      {# mandatory input #}
    items: [],            {# optional, defaulted #}
} %}

{% define attributes = {
    'target-selector': '',
} %}

{% block body %}
    {% set itemClassName = config.name ~ '__item' %}

    {% block title %}
        <h2 class="{{ config.name }}__title">{{ data.title }}</h2>
    {% endblock %}

    {% for item in data.items %}
        {% block item %}
            <div class="{{ itemClassName }}">{{ item }}</div>
        {% endblock %}
    {% endfor %}

    <button type="button" class="{{ config.name }}__trigger {{ config.jsName }}__trigger" {{ qa('my-component-trigger') }}>
        {{ 'my_component.trigger' | trans }}
    </button>
{% endblock %}
```

Key rules:
- `config.name` drives BEM classes; `config.jsName` (`js-{name}`, auto-derived — never set it) is for TS hooks only.
- `required` marks mandatory `data`; new keys on an existing component must be optional with a default.
- `with { data: {...} } only` on every include; pass the module as the second argument for anything outside ShopUi.
- Wrap each overridable region in a named `{% block %}` and hoist reused class names into `{% set %}` variables.
- `| trans` takes a **glossary key** (`cart.item.add`), **never a human sentence**. `{{ 'Add to cart' | trans }}` renders fine in English (the translator echoes unknown keys) and silently stays English in every other locale. Add the glossary rows (`key,translation,locale`) in the same change.
- `{{ qa('id') }}` for test hooks; semantic elements and accessible names (no tool checks accessibility).

Full Twig and Widget conventions: `references/twig-widgets.md`.

### 3. SCSS

`{component-name}.scss` defines a mixin named `{module-kebab}-{component-name}` whose first parameter is
the dot-prefixed selector, and ends the root block with `@content`:

```scss
@mixin my-module-my-component($name: '.my-component') {
    #{$name} {
        display: flex;
        padding: map.get($setting-spacing, 'default');

        &__title {
            color: $setting-color-main;
        }

        &--compact {
            padding: 0;
        }

        @content;
    }
}

@include my-module-my-component;
```

The trailing `@include` is the project convention: `index.ts` imports `./{component-name}.scss` directly.
Core components instead put the `@include` in a separate `style.scss` wrapped in
`@include helper-import({tier}, {component-name}) { ... }`. Follow what the neighbouring components in
the same project module do. `$setting-*` tokens, `helper-*` mixins and `map.get` are injected by the
builder — no import needed.

Full SCSS conventions and enforced Stylelint rules: `references/scss-patterns.md`.

### 4. TypeScript class (interactive components only)

```typescript
import Component from 'ShopUi/models/component';

export default class MyComponent extends Component {
    protected triggers: HTMLElement[];

    protected init(): void {
        this.triggers = Array.from(this.querySelectorAll<HTMLElement>(`.${this.jsName}__trigger`));
        this.mapEvents();
    }

    protected mapEvents(): void {
        this.triggers.forEach((trigger: HTMLElement) => {
            trigger.addEventListener('click', (event: Event) => this.onTriggerClick(event));
        });
    }

    protected onTriggerClick(event: Event): void {
        event.preventDefault();
    }
}
```

Key rules:
- `export default` a PascalCase class extending `Component`; set up in `init()`, never the constructor.
- Query via `` `.${this.jsName}__element` `` — never a styling class, never a hardcoded string.
- Read Twig `attributes` with `this.getAttribute('attr-name')` inline; add a getter only when reused or parsed.
- `protected` over `private`, so the class stays extensible.
- No magic numbers (hoist to `protected readonly` fields), no `console.*`, `===` only, ≤ 200 lines per file.

Full lifecycle, events and ESLint rules: `references/typescript-patterns.md`.

### 5. index.ts

```typescript
import './my-component.scss';
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

CSS-only component: `import './my-component.scss';` alone. Always write the `.scss` extension.

---

## Using components in templates

```twig
{% include atom('icon') with {
    data: { name: 'cart' },
} only %}

{% include molecule('product-item') with {
    data: { product: data.product },
    modifiers: ['compact'],
    class: 'my-extra-class',
} only %}

{% include [
    molecule('filter-' ~ filterName, 'CatalogPage'),
    molecule('filter-' ~ filterType, 'CatalogPage'),
] ignore missing with { data: {...} } only %}
```

`modifiers` adds `{name}--{modifier}` classes; `class` appends classes to the root element. One argument
resolves from ShopUi only.

---

## Customizing a core component

### Extend (preferred) — override only the blocks you change

```twig
{# src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-item/product-item.twig #}
{% extends molecule('product-item', '@SprykerShop:ShopUi') %}

{% block title %}
    <h3 class="{{ config.name }}__title">{{ data.product.name }}</h3>
{% endblock %}

{% block labels %}{% endblock %}  {# remove a block #}

{% block body %}
    <div class="{{ config.name }}__banner">...</div>
    {{ parent() }}                 {# keep the original #}
{% endblock %}
```

Use `@SprykerShop:{Module}` or `@SprykerFeature:{Module}` to target the core file. A plain `'{Module}'`
resolves to your own override and would extend itself.

### What to ship next to the Twig

Because the project entry point replaces the core one:

| You change | Ship |
|---|---|
| Markup only | The `.twig` only — no `index.ts`, the core entry keeps loading the core TS and styles. |
| Styles | `{name}.scss` + an `index.ts` that imports it **and** re-registers the core TS class (if any). |
| Behaviour | `{name}.ts` extending the core class + `index.ts` registering `./{name}`. |

Re-registering the core class with project styles (real project pattern):

```typescript
import './toggler-checkbox.scss';
import register from 'ShopUi/app/registry';

export default register(
    'toggler-checkbox',
    () =>
        import(
            /* webpackMode: "lazy" */
            /* webpackChunkName: "toggler-checkbox" */
            'ShopUi/components/molecules/toggler-checkbox/toggler-checkbox'
        ),
);
```

Extending the core SCSS mixin: call it with your rules in the `@content` slot (see
`references/scss-patterns.md`). Extending the core TS class: see `references/typescript-patterns.md`.

### Import aliases

| Target | Twig | TypeScript / SCSS |
|---|---|---|
| Core ShopUi | `atom('x')`, `'@SprykerShop:ShopUi'` | `ShopUi/...` (`~ShopUi/...` in SCSS) |
| Other core module | `molecule('x', '@SprykerShop:{Module}')` / `'@SprykerFeature:{Module}'` | `{Module}/components/...` — needs a `paths` entry in `tsconfig.yves.json` |
| Project ShopUi | `atom('x')` (project wins) | `src/ShopUi/...` (`~src/ShopUi/...` in SCSS) |

Webpack aliases are generated from `tsconfig.yves.json` `paths`. If `{Module}/*` is missing, add
`"{Module}/*": ["./vendor/spryker-shop/{module}/src/SprykerShop/Yves/{Module}/Theme/default/*"]`.
Never import with a relative path into `vendor/`.

---

## Widgets

A Widget is a PHP class that pushes server-computed data into a `views/` template:
`src/Pyz/Yves/{Module}/Widget/{Name}Widget.php` (extends `Spryker\Yves\Kernel\Widget\AbstractWidget`,
`addParameter()` in the constructor, static `getName()`/`getTemplate()`), plus
`Theme/default/views/{view}/{view}.twig` that extends `template('widget')` and reads `_widget.{param}`.
Register new widgets in `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider::getGlobalWidgets()`
and render them with `{% widget 'NameWidget' args [...] only %}{% endwidget %}`. To change a core widget's markup,
override its view Twig on project level. Details: `references/twig-widgets.md`.

---

## Build and validate

```bash
docker/sdk cli npm run yves          # one-off build
docker/sdk cli npm run yves:watch    # watch mode
docker/sdk cli console twig:cache:warmer   # when a new Twig override is not picked up
```

Then run the checks from `references/validation.md` — stylelint, eslint, prettier — and render the
page. A green webpack build is **not** verification: it transpiles TypeScript without type-checking,
and Twig is never checked by any tool.

## Checklist

- [ ] Code lives in `src/Pyz/Yves/{Module}/Theme/default/...`; nothing under `vendor/` edited
- [ ] Folder is one level under its tier dir; name is kebab-case and unique within its tier across modules
- [ ] Folder name == `register()` tag == `config.name` (== `config.tag` when there is a TS class)
- [ ] BEM classes via `config.name`, JS hooks via `config.jsName`; no styling of `js-` classes
- [ ] New `data` keys optional and defaulted; `required` only for truly mandatory input
- [ ] `| trans` uses glossary keys and the glossary rows are added
- [ ] Core overrides extend `@SprykerShop:`/`@SprykerFeature:` and override only the changed blocks
- [ ] `index.ts` present only when styles or behaviour change; `.scss` imported with explicit extension
- [ ] Accessibility reviewed by hand (semantic elements, accessible names, keyboard, not colour-only)
- [ ] Stylelint, ESLint (coverage confirmed) and Prettier clean; page rendered in the running shop

## Reference files

- `references/examples.md` — worked examples: CSS-only atom, interactive molecule, layout organism, project extension of a core component
- `references/twig-widgets.md` — component model, define blocks, overridable blocks, `qa()`, module resolution, Widget PHP + view, BC-safe `data`
- `references/scss-patterns.md` — mixin pattern, `@content`, BEM, tokens and helpers, breakpoints, overriding core SCSS, Stylelint rules
- `references/typescript-patterns.md` — lifecycle, queries, attributes, events, extending core TS, ESLint rules, TS config facts
- `references/validation.md` — commands, autofix, the ESLint coverage trap, `tsc` as advisory, triage loop, what no tool checks
