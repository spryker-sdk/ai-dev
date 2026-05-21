# Full Worked Examples

## Atom Example: Status Badge

A simple display atom with no JS — shows a colored badge.

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
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: bold;
        text-transform: uppercase;

        &--active {
            background-color: $setting-color-success;
            color: $setting-color-white;
        }

        &--inactive {
            background-color: $setting-color-lighter;
            color: $setting-color-dark;
        }

        @content;
    }
}
```

**`style.scss`**
```scss
@include helper-import(atom, status-badge) {
    @include shop-ui-status-badge;
}
```

**`index.ts`**
```typescript
import './style.scss';
```

**Usage:**
```twig
{% include atom('status-badge') with {
    data: { status: 'active', label: 'In Stock' },
    modifiers: ['active'],
} only %}
```

---

## Molecule Example: Notification Banner (with TS)

An interactive molecule with a dismiss button.

**`notification-banner.twig`**
```twig
{% extends model('component') %}
{% import model('component') as component %}

{% define config = {
    name: 'notification-banner',
    tag: 'notification-banner',
} %}

{% define data = {
    message: required,
    type: 'info',
} %}

{% define attributes = {
    'close-class-name': config.name ~ '--hidden',
} %}

{% block body %}
    <p class="{{ config.name }}__message">{{ data.message }}</p>

    <button
        type="button"
        class="{{ config.name }}__close {{ config.jsName }}__close"
        aria-label="{{ 'general.close' | trans }}">
        {% include atom('icon') with {
            data: { name: 'cross' },
        } only %}
    </button>
{% endblock %}
```

**`notification-banner.ts`**
```typescript
import Component from 'ShopUi/models/component';

export default class NotificationBanner extends Component {
    protected closeButton: HTMLButtonElement;

    protected init(): void {
        this.closeButton = this.querySelector(`.${this.jsName}__close`) as HTMLButtonElement;
        this.mapEvents();
    }

    protected mapEvents(): void {
        this.closeButton.addEventListener('click', () => this.onClose());
    }

    protected onClose(): void {
        this.classList.add(this.closeClassName);
    }

    protected get closeClassName(): string {
        return this.getAttribute('close-class-name');
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
        padding: map-get($setting-spacing, 'default');
        border-radius: 0.25rem;

        &__message {
            flex: 1;
            margin: 0;
        }

        &__close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
        }

        &--hidden {
            display: none;
        }

        &--info {
            background-color: $setting-color-lightest;
            border-left: 4px solid $setting-color-main;
        }

        &--error {
            background-color: #fff0f0;
            border-left: 4px solid $setting-color-error;
        }

        @content;
    }
}
```

**`style.scss`**
```scss
@include helper-import(molecule, notification-banner) {
    @include catalog-page-notification-banner;
}
```

**`index.ts`**
```typescript
import './style.scss';
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

---

## Organism Example: Product Showcase (layout composer)

A display organism that composes multiple molecules — no custom TS needed.

**`product-showcase.twig`**
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
    <header class="{{ config.name }}__header">
        <h2 class="{{ config.name }}__title">{{ data.title }}</h2>
    </header>

    <div class="{{ config.name }}__grid">
        {% for product in data.products | slice(0, data.maxItems) %}
            {% block product %}
                <div class="{{ config.name }}__item">
                    {% include molecule('product-card', 'ProductWidget') with {
                        data: { product: product },
                    } only %}
                </div>
            {% endblock %}
        {% endfor %}
    </div>
{% endblock %}
```

---

## Pyz Extension Example: Custom Product Card

Extend the core product card to add a loyalty badge block.

**`src/Pyz/ProductWidget/src/Pyz/Yves/ProductWidget/Theme/default/components/molecules/product-card/product-card.twig`**
```twig
{% extends molecule('product-card', '@SprykerShop:ProductWidget') %}

{# Add a loyalty points badge before the price #}
{% block price %}
    {% if data.product.loyaltyPoints is defined and data.product.loyaltyPoints %}
        {% include atom('status-badge') with {
            data: { status: 'loyalty', label: data.product.loyaltyPoints ~ ' pts' },
            modifiers: ['loyalty'],
        } only %}
    {% endif %}

    {{ parent() }}
{% endblock %}

{# Remove the color selector if not needed #}
{% block colors %}{% endblock %}
```

**`index.ts`** (re-use core TS logic)
```typescript
export { default } from 'SprykerShop/ProductWidget/components/molecules/product-card/product-card';
```