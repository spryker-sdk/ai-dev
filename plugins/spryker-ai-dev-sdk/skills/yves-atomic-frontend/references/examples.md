# Full Worked Examples

All paths are project paths. Tokens used here exist in core ShopUi; check the project's
`src/Pyz/Yves/ShopUi/Theme/default/styles/` for project tokens before styling.

## Atom: Status Badge (CSS-only)

`src/Pyz/Yves/ShopUi/Theme/default/components/atoms/status-badge/`

**`status-badge.twig`**
```twig
{% extends model('component') %}

{% define config = {
    name: 'status-badge',
    tag: 'span',
} %}

{% define data = {
    status: required,
    label: '',
} %}

{% block body %}
    {{- data.label ?: data.status | capitalize -}}
{% endblock %}
```

**`status-badge.scss`**
```scss
@mixin shop-ui-status-badge($name: '.status-badge') {
    #{$name} {
        display: inline-block;
        padding: map.get($setting-spacing, 'small') map.get($setting-spacing, 'default');
        border-radius: 0.25rem;
        text-transform: uppercase;

        @include helper-font-size(small);
        @include helper-font-weight(bold);

        &--active {
            background-color: $setting-color-main;
            color: $setting-color-white;
        }

        &--inactive {
            background-color: $setting-color-lighter;
            color: $setting-color-dark;
        }

        @content;
    }
}

@include shop-ui-status-badge;
```

**`index.ts`**
```typescript
import './status-badge.scss';
```

**Usage:**
```twig
{% include atom('status-badge') with {
    data: { status: 'active', label: 'product.status.in_stock' | trans },
    modifiers: ['active'],
} only %}
```

---

## Molecule: Notification Banner (with TS)

`src/Pyz/Yves/CatalogPage/Theme/default/components/molecules/notification-banner/`

**`notification-banner.twig`**
```twig
{% extends model('component') %}

{% define config = {
    name: 'notification-banner',
    tag: 'notification-banner',
} %}

{% define data = {
    message: required,
} %}

{% define attributes = {
    'hidden-class-name': config.name ~ '--hidden',
} %}

{% block body %}
    {% block message %}
        <p class="{{ config.name }}__message">{{ data.message }}</p>
    {% endblock %}

    {% block close %}
        <button
            type="button"
            class="{{ config.name }}__close {{ config.jsName }}__close"
            aria-label="{{ 'general.close' | trans }}"
            {{ qa('notification-banner-close') }}>
            {% include atom('icon') with {
                data: { name: 'cross' },
            } only %}
        </button>
    {% endblock %}
{% endblock %}
```

**`notification-banner.ts`**
```typescript
import Component from 'ShopUi/models/component';

const EVENT_CLOSE = 'notification-banner:close';

export default class NotificationBanner extends Component {
    protected closeButton: HTMLButtonElement;

    protected init(): void {
        this.closeButton = this.querySelector<HTMLButtonElement>(`.${this.jsName}__close`);
        this.mapEvents();
    }

    protected mapEvents(): void {
        this.closeButton.addEventListener('click', () => this.onCloseClick());
    }

    protected onCloseClick(): void {
        this.classList.add(this.getAttribute('hidden-class-name'));
        this.dispatchCustomEvent(EVENT_CLOSE, {}, { bubbles: true });
    }
}
```

**`notification-banner.scss`**
```scss
@mixin catalog-page-notification-banner($name: '.notification-banner') {
    #{$name} {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: map.get($setting-spacing, 'default');
        border-left: 4px solid $setting-color-main;
        background-color: $setting-color-lightest;

        &__message {
            flex: 1;
            margin: 0;
        }

        &__close {
            padding: map.get($setting-spacing, 'small');
            border: 0;
            background: none;
            cursor: pointer;
        }

        &--hidden {
            display: none;
        }

        @content;
    }
}

@include catalog-page-notification-banner;
```

**`index.ts`**
```typescript
import './notification-banner.scss';
import register from 'ShopUi/app/registry';

export default register(
    'notification-banner',
    () =>
        import(
            /* webpackMode: "lazy" */
            /* webpackChunkName: "notification-banner" */
            './notification-banner'
        ),
);
```

**Usage** (from another module, so the module name is passed):
```twig
{% include molecule('notification-banner', 'CatalogPage') with {
    data: { message: 'catalog.notification.new_arrivals' | trans },
} only %}
```

---

## Organism: Product Showcase (layout composer, no TS)

`src/Pyz/Yves/ProductWidget/Theme/default/components/organisms/product-showcase/product-showcase.twig`

```twig
{% extends model('component') %}

{% define config = {
    name: 'product-showcase',
    tag: 'section',
} %}

{% define data = {
    title: required,
    products: required,
    maxItems: 4,
} %}

{% block body %}
    {% block header %}
        <header class="{{ config.name }}__header">
            <h2 class="{{ config.name }}__title">{{ data.title }}</h2>
        </header>
    {% endblock %}

    <div class="{{ config.name }}__grid">
        {% for product in data.products | slice(0, data.maxItems) %}
            {% block product %}
                <div class="{{ config.name }}__item">
                    {% include molecule('product-item') with {
                        data: { product: product },
                    } only %}
                </div>
            {% endblock %}
        {% endfor %}
    </div>
{% endblock %}
```

---

## Project extension of a core component (markup only)

Extend the core product tile (ShopUi `product-item`) to add a loyalty badge — Twig only, so **no `index.ts`**: the core entry
keeps loading the core styles and TS.

`src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-item/product-item.twig`
```twig
{% extends molecule('product-item', '@SprykerShop:ShopUi') %}

{% block price %}
    {% if data.product.loyaltyPoints ?? false %}
        {% include atom('status-badge') with {
            data: { status: 'loyalty', label: 'product.loyalty_points' | trans({ '%points%': data.product.loyaltyPoints }) },
            modifiers: ['active'],
        } only %}
    {% endif %}

    {{ parent() }}
{% endblock %}

{% block colors %}{% endblock %}
```

Block names (`price`, `colors`) must exist in the core template — read
`vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/components/molecules/product-item/product-item.twig`
first. If the core template has no block around the region you need, override the nearest enclosing
block and call `{{ parent() }}` where possible rather than copying the whole template.
