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

Non-negotiables live in the always-loaded rule `.claude/rules/yves-frontend.md`; this is the workflow.
`{ProjectNamespace}` = the namespace the project writes to — its custom namespace if one is defined (listed
first in `KernelConstants::PROJECT_NAMESPACES`, `config/Shared/config_default.php`), otherwise `Pyz`.

## Where code lives

| | Path |
|---|---|
| Project (write here) | `src/{ProjectNamespace}/Yves/{Module}/Theme/default/{components/{atoms,molecules,organisms},templates,views}/{name}/` |
| Core (read-only) | `vendor/spryker-shop/{module}/src/SprykerShop/Yves/{Module}/Theme/default/...`, same under `vendor/spryker-feature/` |
| ShopUi base, settings, helpers | `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/{models,app,styles/settings,styles/helpers}/`; project overrides in the `project` source's `ShopUi/Theme/default/styles/` (`src/Pyz/Yves/...` by default) |

Copy the nearest existing component (`src/*/Yves` first, then core ShopUi). The builder scans only `paths.sources`
(default `./src/Pyz/Yves`): assets in a custom namespace build only once it is added to `frontend/yves.settings.mts`
`paths.sources` (after `project` — later wins) and `tsconfig.yves.json` `include` (`configure-codebase` skill).

## How the builder resolves components

- Entry points are `{tier}/{name}/index.ts`, **one level deep** under `atoms|molecules|organisms|templates|views`.
- They are keyed by `{tier}/{name}` **across all modules**, and the project wins. So:
  - a project `index.ts` **replaces** the core entry — it must re-import styles and re-register the TS class;
  - a new component whose name exists in another module's same tier **silently shadows** it — check vendor first.
- Mixin names resolve project-last too: redefining a core mixin name replaces it everywhere.
- Webpack aliases = `tsconfig.yves.json` `paths`: `ShopUi/...` (core), `{Module}/components/...`; add a missing one as
  `"{Module}/*": ["./vendor/spryker-shop/{module}/src/SprykerShop/Yves/{Module}/Theme/default/*"]`.

## Create a new component

Folder `src/{ProjectNamespace}/Yves/{Module}/Theme/default/components/{tier}/{name}/`; folder == `register()` tag == `config.name` (== `config.tag` with a TS class) == mixin suffix.

**`{name}.twig`**
```twig
{% extends model('component') %}

{% define config = { name: 'my-component', tag: 'my-component' } %}  {# tag == name with a TS class; default div #}
{% define data = { title: required, items: [] } %}  {# new keys on existing components must be optional #}
{% define attributes = { 'target-selector': '' } %}  {# root attrs; true = bare, false/null = omitted #}

{% block body %}
    <button type="button" class="{{ config.name }}__trigger {{ config.jsName }}__trigger" {{ qa('my-component-trigger') }}>
        {{ 'my_component.trigger' | trans }}
    </button>
{% endblock %}
```

**`{name}.scss`** — project convention: one file, mixin + trailing include (core splits it into `style.scss` +
`helper-import`; match the neighbours). `$setting-*`, `helper-*` and `map.get` are injected — no import.
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

export default register('my-component', () => import(/* webpackMode: "lazy" */ './my-component'));
```

## Override or extend a core component

**First `ls src/*/Yves/{Module}/Theme/default/...`** (every project namespace). If the project already overrides the component (demo shops
override most ShopUi ones, e.g. `product-item`), edit those files — never replace them with a fresh
override, which silently drops the project's markup, styles and TS. Otherwise mirror the core path under
`src/{ProjectNamespace}/Yves/{Module}/Theme/default/...` and extend the core file explicitly — a plain `'{Module}'`
resolves to your own file and extends itself:

```twig
{# src/{ProjectNamespace}/Yves/ShopUi/Theme/default/components/molecules/product-item/product-item.twig #}
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
| Styles | `{name}.scss` that `@include`s the core mixin with project rules in its `@content` + `index.ts` importing it **and** re-registering the core class. Redefine a mixin under the core name only with a copy of its body — it replaces the core one and cannot call it (Sass `Stack Overflow`) |
| Behaviour | `{name}.ts` extending the core class via its alias + `index.ts` registering `./{name}` |

Write a replacing `index.ts` from the core one: keep every import, the tag and `webpackMode`, change only
the style import and the lazy target (core alias to restyle, `./{name}` to extend). The tag must equal
the Twig `config.tag` — core can disagree (`cart-items-list` renders `<cart-items-list>` but registers
`product-cart-items-list`, so its TS never binds); compare both before copying.

## Widgets

PHP `src/{ProjectNamespace}/Yves/{Module}/Widget/{Name}Widget.php` + `Theme/default/views/{view}/{view}.twig`, plus
`{Module}Factory` / `{Module}DependencyProvider` when it calls `getFactory()`. Register in
`src/{ProjectNamespace}/Yves/ShopApplication/ShopApplicationDependencyProvider::getGlobalWidgets()` — unregistered
widgets render nothing. The tag needs `{% endwidget %}`. Details: `references/components.md`.

## Build and verify

`docker/sdk cli npm run yves` (or `yves:watch`); `console twig:cache:warmer` for a new/moved Twig override,
`console cache:class-resolver:build` for a new PHP class. Confirm the builder picked your component up
(`grep -l '{name}' public/Yves/assets/current/*/js/*.js`), lint per `references/validation.md` (mind the
ESLint coverage trap) and render the page — a green build or lint proves nothing about the failures below.

## Troubleshooting

- **Style not applied:** the `.scss` defines a mixin but never `@include`s it (single-file convention);
  no `index.ts` imports it; a project `index.ts` replaced the core entry and dropped its style import;
  the selector targets a `js-` class; stale build or browser cache — rebuild, hard-reload.
- **Component not initializing:** the Twig lacks `config.tag` (renders a `div`) or it differs from the
  `register()` tag; `index.ts` is missing or nested deeper than `{tier}/{name}/`; setup is in the
  constructor instead of `init()`; markup injected by AJAX needs `await mount()` from `ShopUi/app`.

## References

- `references/components.md` — Component lifecycle and base API, extending core TS, SCSS tokens/helpers and overriding core SCSS, design tokens, Widget PHP + view + tag
- `references/validation.md` — where checks run, autofix, the ESLint coverage trap with a covering config, `tsc` as advisory
