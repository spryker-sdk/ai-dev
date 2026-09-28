# TypeScript Component Patterns

Yves storefront JS is **native Web Components** — every interactive component is a custom element
extending the ShopUi `Component` base class. No framework (Angular is the Merchant Portal, not Yves).
Base class: `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/models/component.ts`.

## Lifecycle

```
index.ts calls register('{name}', lazy import)
    ↓
the tag appears in the DOM → webpack lazy-loads the class → custom element defined
    ↓
app mounts every component → mountCallback() → init()
```

- **Override `init()`** for setup — it runs once the DOM is loaded and every other component is
  defined, so querying other components is safe. Never do setup in the constructor.
- Use the native `connectedCallback()` only when you do not depend on other components (faster).
- Older ShopUi versions declare an abstract, deprecated `readyCallback()`. If the installed
  `models/component.ts` still has it, every subclass must declare `protected readyCallback(): void {}`
  — the build does not type-check, so nothing will tell you.
- House pattern inside `init()`: resolve element references first, then call `mapEvents()`.

## Base class API

```typescript
import Component from 'ShopUi/models/component';

export default class MyComponent extends Component {
    // this.name   → tag name lowercased ('my-component') == config.name in Twig
    // this.jsName → 'js-my-component' == config.jsName in Twig
    // this.dispatchCustomEvent(name, detail?, options?)
    // this.isMounted
    protected init(): void {}
}
```

- `export default` the class — the registry uses the default export.
- Class name is PascalCase of the tag (`toggler-checkbox` → `TogglerCheckbox`).
- Prefer `protected` over `private` so projects can extend the class.

## Querying child elements

Query by the `js-` BEM class the Twig emits via `config.jsName`, built from `this.jsName`:

```typescript
protected init(): void {
    this.trigger = this.querySelector<HTMLElement>(`.${this.jsName}__trigger`);
    this.items = Array.from(this.querySelectorAll<HTMLElement>(`.${this.jsName}__item`));
    // Wrong: this.querySelector('.my-component__trigger') — styling class, breaks on restyle
}
```

Prefer `querySelector`/`querySelectorAll`; use `getElementsByClassName` only when you need a live
collection. Never hardcode the class string.

## Reading attributes from Twig

Values declared in `{% define attributes %}` render on the root element. Read them inline where used:

```typescript
element.classList.add(this.getAttribute('class-to-toggle'));
```

Add a getter only when the value is read in several places or needs parsing/a default:

```typescript
protected get animationSpeed(): number {
    return Number(this.getAttribute('animation-speed'));
}
```

Structured data: serialize to JSON in an attribute and parse it into a typed interface:

```twig
{% define attributes = {
    'json': data.config | json_encode,
} %}
```

```typescript
interface MyComponentConfig {
    maxItems: number;
    labels: string[];
}

const config = <MyComponentConfig>JSON.parse(this.getAttribute('json'));
```

## Events

Keep event names in constants and use the base helper. It does **not** bubble unless you ask:

```typescript
const EVENT_SELECTED = 'my-component:selected';

this.dispatchCustomEvent(EVENT_SELECTED, { value }, { bubbles: true });
```

Other components listen on the element (or an ancestor when bubbling). Prefer events over calling
methods on another component's element directly.

```typescript
export default class MyComponent extends Component {
    protected readonly activeClassName = 'is-active';
    protected triggers: HTMLElement[] = [];

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
        this.activate(<HTMLElement>event.currentTarget);
    }

    protected activate(element: HTMLElement): void {
        this.triggers.forEach((trigger: HTMLElement) => trigger.classList.remove(this.activeClassName));
        element.classList.add(this.activeClassName);
    }
}
```

## Extending a core TS component in the project

Real project pattern — extend the core class through its module alias and call `super`:

```typescript
// src/Pyz/Yves/CatalogPage/Theme/default/components/molecules/window-location-applicator/window-location-applicator.ts
import WindowLocationApplicatorCore from 'CatalogPage/components/molecules/window-location-applicator/window-location-applicator';

export default class WindowLocationApplicator extends WindowLocationApplicatorCore {
    protected sortTriggers: HTMLSelectElement[];

    protected init(): void {
        this.sortTriggers = <HTMLSelectElement[]>Array.from(document.getElementsByClassName(this.sortTriggerClassName));

        super.init();
    }

    protected get sortTriggerClassName(): string {
        return this.getAttribute('sort-trigger-class-name');
    }
}
```

```typescript
// index.ts next to it — registers the project class under the same tag
import register from 'ShopUi/app/registry';

export default register(
    'window-location-applicator',
    () =>
        import(
            /* webpackMode: "lazy" */
            /* webpackChunkName: "window-location-applicator" */
            './window-location-applicator'
        ),
);
```

- The alias (`CatalogPage/*`) must exist in `tsconfig.yves.json` `paths`; add it if missing.
- Your `index.ts` replaces the core entry point — mirror everything the core `index.ts` imports
  (styles included), or those styles stop loading.
- Override only the methods you change; never copy the core class body.

## Enforced ESLint rules

From `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/libs/lint/spryker-base-eslint.mjs`
plus the TS block in `eslint.config.mjs` next to it (a project-root `eslint.config.yves.mjs` replaces
the packaged config). Confirm the config actually covers your file first — see `validation.md`.

| Rule | Constraint | Fix |
|---|---|---|
| `max-lines` | 200 per file (blank lines and comments skipped) | Split into child components or helpers. Disabling is a last resort for a component that truly cannot be decomposed. |
| `@typescript-eslint/no-magic-numbers` | only `-1, 0, 1` inline | Hoist to `protected readonly` class fields — readonly initial values, enums, default values and array indexes are exempt. |
| `no-console` | error | Remove debug output. |
| `camelcase` | error, properties included | camelCase everywhere; snake_case JSON payload keys may need a justified disable. |
| `eqeqeq` | `===` (null ignored) | Never `==`. |
| `@typescript-eslint/no-unused-vars` | error, arguments ignored | Remove dead locals. |
| `no-eval` / `no-new-func` / `no-implied-eval` | error | No dynamic code execution. |

None of these are autofixable — they need a structural change. No rule enforces return types, but
the codebase annotates them (`: void`, `: string`); match the surrounding files.

## TypeScript config facts

- `tsconfig.base.json`: `target es2020`, `module esnext`, `moduleResolution: "bundler"`,
  **`strict: false`**, `noImplicitAny: false` — little is caught, so write explicit types.
- `tsconfig.yves.json` `paths` define both the TS and the webpack aliases (`ShopUi/*`, `{Module}/*`,
  `src/ShopUi/*` for the project ShopUi).
- Webpack (Babel) transpiles **without type-checking**; there is no `tsc` script or CI job.
  `tsc` is advisory — see `validation.md`.
- Import styles with the explicit extension: `import './my-component.scss';`.
