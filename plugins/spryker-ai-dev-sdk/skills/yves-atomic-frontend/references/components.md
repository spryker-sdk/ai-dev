# Yves Component Internals

Project-specific mechanics only. Verified against `vendor/spryker-shop/shop-ui` in a project; re-check
the installed version when behaviour differs. `{ProjectNamespace}` is defined in `SKILL.md`; paths marked
"e.g." are real files in b2b-demo-marketplace.

## TypeScript — the `Component` base class

`vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/models/component.ts`:

- Custom element extending `HTMLElement`. The constructor sets `this.name` (tag, lowercased) and
  `this.jsName` (`js-{name}`) — they match Twig `config.name` / `config.jsName`.
- Lifecycle: `register()` in `index.ts` → tag appears in the DOM → class lazy-loaded and defined →
  on `DOMContentLoaded` the app calls `mountCallback()` → **`init()`**. Do setup in `init()`, never the
  constructor; other components are defined by then. Native `connectedCallback()` only when you don't
  depend on other components.
- **Not in the installed ShopUi:** `readyCallback()`. Older ShopUi versions declare it abstract and
  deprecated — if the installed `component.ts` still has it, every subclass must declare
  `protected readyCallback(): void {}`, and no build step will tell you.
- `dispatchCustomEvent(name, detail = {}, options?)` does **not** bubble by default — pass
  `{ bubbles: true }` when an ancestor listens.
- `export default` the class (the registry uses the default export); `protected` over `private` so
  projects can extend it; query via `` `.${this.jsName}__element` ``, never a styling class.

### Extending a core class

```typescript
// e.g. src/Pyz/Yves/CatalogPage/Theme/default/components/molecules/window-location-applicator/window-location-applicator.ts
import WindowLocationApplicatorCore from 'CatalogPage/components/molecules/window-location-applicator/window-location-applicator';

export default class WindowLocationApplicator extends WindowLocationApplicatorCore {
    protected init(): void {
        // project setup
        super.init();
    }
}
```

The `CatalogPage/*` alias must exist in `tsconfig.yves.json` `paths`. Pair it with an `index.ts` that
registers `./window-location-applicator` under the same tag.

## SCSS — tokens, helpers, overrides

- The builder injects ShopUi `styles/shared.scss` (the copy in the `project` source — `./src/Pyz/Yves` by
  default — under `ShopUi/Theme/default/styles/` when present, which forwards core) into every component
  file: `$setting-*`, `helper-*`, `map.get` and all core component mixins work without imports.
- Look up tokens before using them — projects add and override many:
  - core: `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/styles/{settings,helpers}/`
  - project: `ShopUi/Theme/default/styles/{settings,helpers}/` in the `project` source (`src/Pyz/Yves` by default).
    ShopUi `app.ts`, `vendor.ts` and `styles/` are read from that one source only, not from extra `paths.sources`.
- Core token families: `$setting-color-*` (main, alt, white, black, light/lighter/lightest,
  dark/darker/darkest, text, bg, shadow, overlay, `actions` map), maps `$setting-spacing`
  (`big|default|small|reset`), `$setting-font-size`, `$setting-font-weight`, `$setting-breakpoints`,
  `$setting-zi-*`.
- Core helpers: `helper-font-size(key)`, `helper-font-weight(key)`, `helper-effect-transition(...)`,
  `helper-breakpoint(md)` / `helper-breakpoint-media-min|max|between(...)`, `helper-ui-clearfix`,
  functions `helper-color-dark|light(...)`, and `helper-import(tier, name) { ... }` (skipped when the
  keyword is in `$setting-import-blacklist` — the way to switch off a core component's CSS).
- Design tokens: if `frontend/assets/global/{theme}/design-tokens/design-tokens.json` exists, the builder
  generates `design-tokens.css` in the `project` source's `ShopUi/Theme/{theme}/styles/` (gitignored). Edit the JSON,
  never the CSS. Brand colours are Back Office Configuration settings (`theme:storefront:colors`),
  emitted at runtime as `:root` custom properties over the tokens — change the palette with the
  `brand-project` skill. So style with the custom properties the neighbouring project components use
  (`var(--text-brand)`, `var(--scale-8)`, …): `$setting-color-*` are build-time literals and do **not**
  follow a Back Office palette change.

### Overriding core SCSS

Simplest: `@include shop-ui-toggler-checkbox { /* project rules */ }` — the builder injects the core
mixin, and the rules land in its `@content`. To change the core rules themselves, redefine the mixin
under its core name with a copy of its body, as below (it replaces the core one everywhere, so it must
not call itself). Many core mixins also expose an optional `{mixin}-base-hook` mixin you can define.
Either way, import the file from a project `index.ts` that also re-registers the core TS class:

```scss
// e.g. src/Pyz/Yves/ShopUi/Theme/default/components/molecules/toggler-checkbox/toggler-checkbox.scss
@mixin shop-ui-toggler-checkbox($name: '.toggler-checkbox') {
    @include shop-ui-checkbox($name) {
        &__input:checked ~ &__label {
            @include helper-font-weight(regular);
        }

        @content;
    }
}

@include shop-ui-toggler-checkbox;
```

## Twig — resolution details

- `atom()`, `molecule()`, `organism()`, `template()`, `view()` take `(name, module = 'ShopUi')`
  (`ShopUiTwigExtension::DEFAULT_MODULE`). Anything outside ShopUi needs the module argument.
- The base model (`Theme/default/models/component.twig`) renders the root `<{{ config.tag }}>`, adds the
  `custom-element` class only when `config.name == config.tag` and the tag contains `-`, puts `qa()` on
  the root, and exposes the `class`, `extraClass`, `attributes` and `body` blocks.
- `modifiers: [...]` → `{name}--{modifier}` classes; `class: '...'` → extra root classes.
- `{{ qa('id') }}` renders `data-qa` (there is also a `qa_*` variant).

## Widgets

```php
// e.g. src/Pyz/Yves/CustomerFullNameWidget/Widget/CustomerFullNameWidget.php
class CustomerFullNameWidget extends AbstractWidget   // Spryker\Yves\Kernel\Widget\AbstractWidget
{
    public function __construct()
    {
        $customerTransfer = $this->getFactory()->getCustomerClient()->getCustomer();
        $this->addParameter('customerFullName', $customerTransfer->getFirstName() . ' ' . $customerTransfer->getLastName());
    }

    public static function getName(): string
    {
        return 'CustomerFullNameWidget';
    }

    public static function getTemplate(): string
    {
        return '@CustomerFullNameWidget/views/customer-full-name-widget/customer-full-name-widget.twig';
    }
}
```

- Register in `src/{ProjectNamespace}/Yves/ShopApplication/ShopApplicationDependencyProvider::getGlobalWidgets()`.
  In a custom namespace, extend the existing project provider and `array_merge(parent::getGlobalWidgets(), [...])`.
  Unregistered widgets silently render nothing (or the `{% nowidget %}` branch).
- The view extends `template('widget')` and reads `_widget.customerFullName` (⇔ `addParameter` key).
- The tag always closes; `args` map positionally to the constructor; blocks of the widget view can be
  overridden inside the tag:

```twig
{% widget 'RecurringOrderSelectorWidget' args [data.cart] only %}{% endwidget %}

{% widget 'ProductDiscontinuedWidget' args [data.listItem.sku] only %}
{% nowidget %}
    {{ 'customer.account.shopping_list.not_available' | trans }}
{% endwidget %}
```

- Change a core widget's markup by overriding its view at the same path in `src/{ProjectNamespace}/Yves` with
  `{% extends view('{view}', '@SprykerShop:{Module}') %}` — no PHP change. Change the PHP only when the
  data changes: extend the core widget in `src/{ProjectNamespace}/Yves` and register the project class instead.
