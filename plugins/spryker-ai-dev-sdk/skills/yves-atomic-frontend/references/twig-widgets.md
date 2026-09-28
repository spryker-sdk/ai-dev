# Twig Templates & Widgets

Two related things: **component templates** (markup for atoms/molecules/organisms) and **Widgets** (a
PHP class feeding server-computed data into a `views/` template). Base model:
`vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/models/component.twig`.

## Component skeleton and the define blocks

```twig
{% extends model('component') %}

{% define config = {
    name: 'collapsible-block',
    tag: 'details',
} %}

{% define data = {
    title: '',
    isOpen: false,
    content: '',
} %}

{% define attributes = {
    open: data.isOpen,
} %}

{% block body %}
    <summary class="{{ config.name }}__header">{{ data.title }}</summary>
    <div class="{{ config.name }}__content">{{ data.content }}</div>
{% endblock %}
```

- **`config`** — `name` (== folder, tag registered in `index.ts`, mixin suffix) and `tag`.
  - `tag` defaults to `div` and is a presentational choice for CSS-only components.
  - **A component with a TS class MUST set `tag` equal to `name`.** The custom element is registered
    under that tag; the base model only adds its `custom-element` marker class when
    `config.name == config.tag` and the tag contains `-`.
  - `jsName` is derived as `'js-' ~ config.name` — don't set it.
- **`data`** — inputs with defaults; the `required` sentinel marks mandatory ones.
- **`attributes`** — rendered on the root element: `true` → bare attribute, `false`/`null` → omitted,
  anything else → `name="value"`.
- **`modifiers`** (passed by the caller) → `{name}--{modifier}` classes; `class` → extra root classes.

The base model renders the root `<{{ config.tag }}>` and calls `{% block body %}` inside it. The `class`,
`extraClass` and `attributes` blocks are also extendable.

## BEM vs JS hooks

- Styling classes → `config.name`: `{{ config.name }}__header` → `.collapsible-block__header`.
- JS hooks → `config.jsName`: `{{ config.jsName }}__trigger` → `.js-collapsible-block__trigger`,
  queried in TS via `` `.${this.jsName}__trigger` ``.

Never let TS hook onto a `config.name` class; never style a `config.jsName` class.

## Make templates overridable

Projects override a template by extending it and replacing single blocks. Write templates so that
works:

1. **Wrap every logical region in a named `{% block %}`** — title, button, icon, each list row. Block
   names are camelCase and describe the region (`title`, `editButton`, `definitionList`). One block per
   region someone might replace, not one per DOM node.
2. **Hoist reused class names into `{% set %}` variables** at the top of the enclosing block, so an
   override can reuse them:

```twig
{% block body %}
    {% set labelClassName = config.name ~ '__label' %}
    {% set valueClassName = config.name ~ '__value' %}

    {% block definitionList %}
        <dl class="{{ config.name }}__fields">
            {% block cadence %}
                <dt class="{{ labelClassName }}">{{ 'recurring_orders.detail.sidebar.cadence' | trans }}</dt>
                <dd class="{{ valueClassName }}">{{ data.cadence }}</dd>
            {% endblock %}
        </dl>
    {% endblock %}
{% endblock %}
```

A project override then touches only what it needs:

```twig
{# src/Pyz/Yves/{Module}/Theme/default/components/molecules/{name}/{name}.twig #}
{% extends molecule('{name}', '@SprykerFeature:{Module}') %}

{% block cadence %}
    {{ parent() }}
    <dd class="{{ valueClassName }}">{{ 'my_project.cadence_note' | trans }}</dd>
{% endblock %}
```

- `{{ parent() }}` re-emits the inherited block — extend rather than replace.
- `{% embed %}` a component to override its inner blocks in place; `{{ block('name') }}` re-renders a
  block elsewhere.
- Target the core file with `@SprykerShop:{Module}` / `@SprykerFeature:{Module}`; the plain module name
  resolves to the project file and would extend itself.

## Including components — module resolution

`atom()`, `molecule()`, `organism()`, `template()`, `view()` take `(name, module = 'ShopUi')`:

```twig
{% include atom('icon') with { data: { name: 'chevron' } } only %}
{% include molecule('accept-terms-checkbox', 'CheckoutPage') with { form: data.form } only %}
```

- One argument resolves from ShopUi only — pass the module for anything else, or the lookup fails.
- Project files win over core for the same module + path.
- Always `only`, and pass presentational input via `with { data: {...} }`.

## Test hooks — `qa()`

`qa()` (registered by `ShopUiTwigExtension`) renders a `data-qa` attribute. The base model already puts
one on the root element; for inner elements:

```twig
<button type="submit" class="{{ config.jsName }}__submit" {{ qa('add-to-cart-button') }}>
```

Prefer `qa()` over hand-written `data-qa=` in new code, and never use `data-qa` as a styling or JS hook.

## Translations

`| trans` takes a glossary key (`cart.item.add`), never an English sentence — an unknown key is echoed
back, so English text "works" in English and silently stays untranslated elsewhere. Add the glossary
rows for every locale in the same change.

## Widgets

A Widget renders server-computed data: a PHP class plus a `views/` template.

```php
// src/Pyz/Yves/{Module}/Widget/{Name}Widget.php
namespace Pyz\Yves\CustomerFullNameWidget\Widget;

use Spryker\Yves\Kernel\Widget\AbstractWidget;

/**
 * @method \Pyz\Yves\CustomerFullNameWidget\CustomerFullNameWidgetFactory getFactory()
 */
class CustomerFullNameWidget extends AbstractWidget
{
    protected const PARAMETER_CUSTOMER_FULL_NAME = 'customerFullName';

    public function __construct()
    {
        $this->addCustomerFullNameParameter();
    }

    public static function getName(): string
    {
        return 'CustomerFullNameWidget';
    }

    public static function getTemplate(): string
    {
        return '@CustomerFullNameWidget/views/customer-full-name-widget/customer-full-name-widget.twig';
    }

    protected function addCustomerFullNameParameter(): void
    {
        $customerTransfer = $this->getFactory()->getCustomerClient()->getCustomer();

        $this->addParameter(
            static::PARAMETER_CUSTOMER_FULL_NAME,
            $customerTransfer->getFirstName() . ' ' . $customerTransfer->getLastName(),
        );
    }
}
```

- Extends `Spryker\Yves\Kernel\Widget\AbstractWidget`; data is pushed with `addParameter()` from the
  constructor; dependencies come from `getFactory()` (declare it with `@method`). Keep data fetching in
  protected helpers, and read from the Client layer (storage/search), not Zed.
- Static `getName()` (the registered name) and `getTemplate()` (namespaced Twig path).
- Register a new widget in `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider::getGlobalWidgets()`;
  an unregistered widget silently renders nothing (or its `{% nowidget %}` branch).

The view reads parameters through `_widget`:

```twig
{# src/Pyz/Yves/{Module}/Theme/default/views/{view}/{view}.twig #}
{% extends template('widget') %}

{% define data = {
    customerFullName: _widget.customerFullName,
} %}

{% block body %}
    <span class="customer-full-name">{{ data.customerFullName }}</span>
{% endblock %}
```

`addParameter('customerFullName', …)` ⇔ `_widget.customerFullName`.

Rendering — `args` map positionally to the constructor; the tag always closes with `{% endwidget %}`,
and `{% nowidget %}` renders a fallback when the widget is not registered:

```twig
{% widget 'RecurringOrderSelectorWidget' args [data.cart] only %}{% endwidget %}

{% widget 'ProductDiscontinuedWidget' args [data.listItem.sku] only %}
{% nowidget %}
    {{ 'customer.account.shopping_list.not_available' | trans }}
{% endwidget %}
```

Inside the tag you can override the widget view's blocks, like an `embed`.

To change a core widget's markup, override its view Twig at the same relative path in `src/Pyz/Yves`
and extend the core view (`{% extends view('{view}', '@SprykerShop:{Module}') %}`) — no PHP change
needed. Change the PHP class only when the data changes; then extend the core widget on project level
and register the project class instead.

Rule of thumb: `addParameter()` for server-computed data; `with { data: {...} }` for presentational
input.

## Backward-compatible `data`

Other templates and project overrides already include your component. Removing a `data` key or adding
a `required` one breaks them. New keys MUST be optional with a default.

## Twig is not linted

No tool checks `.twig` — Prettier does not format it and there is no Twig linter. Everything above,
plus semantic markup and accessibility, is enforced by review only. Rendering the page in the running
shop is the real test (`spryker-runtime` skill, or a Cypress flow via `cypress-tests`).
