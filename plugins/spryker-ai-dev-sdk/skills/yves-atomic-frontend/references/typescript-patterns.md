# TypeScript Component Patterns

## Component Lifecycle

```
register() called in index.ts
    ↓
Webpack lazy-loads the module when component tag appears in DOM
    ↓
Component class is registered as a Custom Element
    ↓
readyCallback() — called when ALL components on page are initialized
    ↓
init() — called right after readyCallback (preferred entry point)
```

Use `init()` for standard setup. Use `readyCallback()` only when you need other components to already be available.

## Base Class: Component

```typescript
import Component from 'ShopUi/models/component';

export default class MyComponent extends Component {
    // Component is a Custom Element — `this` is the DOM element
    // Available properties:
    //   this.jsName  → 'js-{config.name}' from Twig config
    //   this.name    → '{config.name}' from Twig config (the CSS class)

    protected init(): void {
        // Setup code here — called once, after DOM is ready
    }

    protected readyCallback(): void {
        // Called after ALL components on page are ready
        // Usually not needed — prefer init()
    }
}
```

## Querying Child Elements

Always use `jsName` classes for JS queries — never use the visual CSS class:

```typescript
protected init(): void {
    // Correct: query by jsName-based class
    const trigger = this.querySelector(`.${this.jsName}__trigger`) as HTMLElement;
    const items = Array.from(this.getElementsByClassName(`${this.jsName}__item`)) as HTMLElement[];

    // Wrong: never query by visual class
    // const trigger = this.querySelector('.my-component__trigger');  // BAD
}
```

## Reading HTML Attributes from Twig

Attributes declared in `{% define attributes %}` are rendered on the component's root element. Read them in TS:

```twig
{# In Twig #}
{% define attributes = {
    'target-selector': required,
    'animation-speed': '300',
} %}
```

```typescript
// In TypeScript
protected get targetSelector(): string {
    return this.getAttribute('target-selector');
}

protected get animationSpeed(): number {
    return parseInt(this.getAttribute('animation-speed'), 10);
}
```

## Communicating with Other Components

Query sibling components that share a container:

```typescript
protected init(): void {
    // Get a reference to another component on the page by its tag name
    const slider = document.querySelector('product-slider') as HTMLElement & { goToSlide(n: number): void };

    // Or find a child component
    const dropdown = this.querySelector('custom-select') as HTMLElement;

    // Dispatch custom events for loose coupling
    this.dispatchCustomEvent('my-component:selected', { value: 'something' });
}

protected dispatchCustomEvent(name: string, detail: object): void {
    this.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
}
```

## Event Patterns

```typescript
export default class MyComponent extends Component {
    protected triggers: HTMLElement[] = [];

    protected init(): void {
        this.triggers = Array.from(
            this.getElementsByClassName(`${this.jsName}__trigger`)
        ) as HTMLElement[];

        this.mapEvents();
    }

    protected mapEvents(): void {
        this.triggers.forEach((el: HTMLElement) => {
            el.addEventListener('click', (event: Event) => this.onTriggerClick(event));
        });

        // For one-time setup
        this.addEventListener('change', (event: Event) => this.onChange(event as InputEvent));
    }

    protected onTriggerClick(event: Event): void {
        event.preventDefault();
        const target = event.currentTarget as HTMLElement;
        this.activate(target);
    }

    protected onChange(event: InputEvent): void {
        const input = event.target as HTMLInputElement;
        // handle change
    }

    protected activate(element: HTMLElement): void {
        this.triggers.forEach((el) => el.classList.remove(this.activeClass));
        element.classList.add(this.activeClass);
    }

    protected get activeClass(): string {
        return `${this.jsName}__trigger--active`;
    }
}
```

## Extending a TypeScript Component in Pyz

```typescript
// src/Pyz/{Module}/src/Pyz/Yves/{Module}/Theme/default/components/molecules/{component}/index.ts
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

```typescript
// src/Pyz/{Module}/src/Pyz/Yves/{Module}/Theme/default/components/molecules/{component}/{component}.ts
import MyComponent from 'SprykerShop/ModuleName/components/molecules/my-component/my-component';

export default class MyComponentExtended extends MyComponent {
    // Override a method
    protected onTriggerClick(event: Event): void {
        super.onTriggerClick(event); // call parent
        // Add extra behavior
        this.trackAnalyticsEvent();
    }

    protected trackAnalyticsEvent(): void {
        // custom code
    }
}
```

## Component Lazy-Loading Pattern (Standard)

```typescript
// index.ts
import './style.scss';
import register from 'ShopUi/app/registry';

export default register(
    'component-name',   // Must match config.name in Twig AND the HTML tag
    () =>
        import(
            /* webpackMode: "lazy" */
            /* webpackChunkName: "component-name" */
            './component-name'
        ),
);
```

The `webpackChunkName` comment tells webpack what to name the chunk file. Keep it the same as the component name.

## Accessing Transferred Data via Data Attributes

For passing complex data from Twig to TS, use `data-json` attributes:

```twig
{% block body %}
    <div
        class="{{ config.name }}__data {{ config.jsName }}__data"
        data-json="{{ data.config | json_encode | e('html_attr') }}">
    </div>
{% endblock %}
```

```typescript
protected init(): void {
    const dataElement = this.querySelector(`.${this.jsName}__data`) as HTMLElement;
    const config = JSON.parse(dataElement.dataset.json ?? '{}');
}
```
