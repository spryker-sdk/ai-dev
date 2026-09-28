---
name: yves-atomic-frontend
description: >
  Use when creating, extending, or overriding Spryker Yves storefront frontend code in a project — atomic
  components, templates, views, Widgets — and their Twig, SCSS, and TypeScript. Triggers: "create a new
  molecule", "override the product item", "extend the cart item component", "create a widget", "style this
  component", "why isn't my style applied", "the component isn't initializing", fixing Yves lint errors.
  Always use for any Yves Theme/ work; not for linting a diff (static-validation) or rebrands (brand-project).
---

# Spryker Yves Atomic Frontend

The always-loaded rule `.claude/rules/yves-frontend.md` holds the non-negotiables (override policy,
review-only Twig conventions, validation commands). This skill is the create/override workflow.

## Where code lives

| | Path |
|---|---|
| Project (write here) | `src/Pyz/Yves/{Module}/Theme/default/{components/{atoms,molecules,organisms},templates,views}/{name}/` |
| Core (read-only) | `vendor/spryker-shop/{module}/src/SprykerShop/Yves/{Module}/Theme/default/...`, same under `vendor/spryker-feature/` |
| ShopUi base, settings, helpers | `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/{models,app,styles/settings,styles/helpers}/`; project overrides in `src/Pyz/Yves/ShopUi/Theme/default/styles/` |

Copy the nearest existing component (project first, then core ShopUi). The builder scans `src/Pyz/Yves` only; a custom
namespace needs `frontend/yves.settings.mts` `paths.sources` (see `configure-codebase`).

## How the builder resolves components

- Entry points are `{tier}/{name}/index.ts`, **one level deep** under `atoms|molecules|organisms|templates|views`.
- They are keyed by `{tier}/{name}` **across all modules**, and the project wins. So:
  - a project `index.ts` **replaces** the core entry — it must re-import styles and re-register the TS class;
  - a new component whose name exists in another module's same tier **silently shadows** it — check
    `vendor/spryker-shop` and `vendor/spryker-feature` before naming.
- Mixin names resolve project-last too: redefining a core mixin name replaces it everywhere.
- Webpack aliases come from `tsconfig.yves.json` `paths`: `ShopUi/...` (core ShopUi), `src/ShopUi/...`
  (project ShopUi), `{Module}/components/...` for other modules. Add a missing one as
  `"{Module}/*": ["./vendor/spryker-shop/{module}/src/SprykerShop/Yves/{Module}/Theme/default/*"]`.

## Create a new component

Folder `src/Pyz/Yves/{Module}/Theme/default/components/{tier}/{name}/`; folder == `register()` tag == `config.name` (== `config.tag` with a TS class) == mixin suffix.

**`{name}.twig`**
```twig
{% extends model('component') %}

{% define config = {
    name: 'my-component',
    tag: 'my-component',   {# must equal name when a TS class exists; defaults to div #}
} %}

{% define data = {
    title: required,       {# mandatory; new keys on existing components must be optional #}
    items: [],
} %}

{% define attributes = { 'target-selector': '' } %}   {# root attrs; true = bare, false/null = omitted #}

{% block body %}
    <button type="button" class="{{ config.name }}__trigger {{ config.jsName }}__trigger" {{ qa('my-component-trigger') }}>
        {{ 'my_component.trigger' | trans }}
    </button>
{% endblock %}
```

**`{name}.scss`** — project convention is a single file: mixin + trailing include (core uses a separate
`style.scss` with `helper-import`; match the neighbouring components). `$setting-*`, `helper-*` and
`map.get` are injected — no import.
```scss
@mixin my-module-my-component($name: '.my-component') {
    #{$name} {
        padding: map.get($setting-spacing, 'default');

        @content;
    }
}

@include my-module-my-component;
```

**`{name}.ts`** (interactive only) — lifecycle and base API in `references/components.md`.
```typescript
import Component from 'ShopUi/models/component';

export default class MyComponent extends Component {
    protected init(): void {
        this.querySelector(`.${this.jsName}__trigger`).addEventListener('click', () =>
            this.classList.toggle(`${this.name}--active`),
        );
    }
}
```

**`index.ts`** — CSS-only components keep just the first line.
```typescript
import './my-component.scss';
import register from 'ShopUi/app/registry';

export default register(
    'my-component',
    () => import(/* webpackMode: "lazy" */ /* webpackChunkName: "my-component" */ './my-component'),
);
```

Include from another module by name: `{% include molecule('my-component', '{Module}') with { data: {...} } only %}`.

## Override or extend a core component

Mirror the core path under `src/Pyz/Yves/{Module}/Theme/default/...` and extend the core file explicitly —
a plain `'{Module}'` resolves to your own file and extends itself:

```twig
{# src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-item/product-item.twig #}
{% extends molecule('product-item', '@SprykerShop:ShopUi') %}   {# @SprykerFeature:{Module} for features #}

{% block price %}
    <span class="{{ config.name }}__badge">{{ 'product.loyalty' | trans }}</span>
    {{ parent() }}
{% endblock %}

{% block colors %}{% endblock %}   {# block names must exist in the core template — read it first #}
```

| You change | Ship |
|---|---|
| Markup only | `.twig` only — **no `index.ts`**, the core entry keeps loading core TS and styles |
| Styles | `{name}.scss` (call the core mixin, rules in its `@content`) + `index.ts` importing it **and** re-registering the core class |
| Behaviour | `{name}.ts` extending the core class via its alias + `index.ts` registering `./{name}` |

To restyle without changing behaviour, use the `index.ts` above with `./{name}.scss` as the style import
and the core class alias as the lazy target:
`register('toggler-checkbox', () => import('ShopUi/components/molecules/toggler-checkbox/toggler-checkbox'))`.
A replacing `index.ts` must mirror every import of the core `index.ts`, or those styles stop loading.

## Widgets

PHP `src/Pyz/Yves/{Module}/Widget/{Name}Widget.php` + `Theme/default/views/{view}/{view}.twig`. Register
in `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider::getGlobalWidgets()` — unregistered
widgets render nothing. The tag needs `{% endwidget %}`. Details: `references/components.md`.

## Build and verify

`docker/sdk cli npm run yves` (or `yves:watch`); `docker/sdk cli console twig:cache:warmer` when a
new or moved Twig override is not picked up. Then lint per `references/validation.md` (mind the ESLint coverage trap) and render the page in the
running shop. A green webpack build proves nothing: no type-checking, no Twig checks.

## References

- `references/components.md` — Component lifecycle and base API, extending core TS, SCSS tokens/helpers and overriding core SCSS, design tokens, Widget PHP + view + tag
- `references/validation.md` — where checks run, autofix, the ESLint coverage trap with a covering config, `tsc` as advisory
