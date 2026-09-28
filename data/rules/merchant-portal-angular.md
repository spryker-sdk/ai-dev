---
name: merchant-portal-angular
description: Use when writing, reviewing, or modifying Merchant Portal Angular code (components, modules, services, specs, LESS) under Presentation/Components. Enforces reuse of installed @spryker/* UI components, the NgModule + Angular Elements (WebComponentsModule) architecture embedded in Zed Twig, project-side extension of core components, Jest spec conventions, and the mp:* validation gate.
paths: "src/*/Zed/*/Presentation/Components/**/*.{ts,html,less}"
---

**Frontend rule**
Merchant Portal Angular code MUST reuse the installed `@spryker/*` UI components before building custom UI, MUST follow the NgModule + Angular Elements architecture the app actually uses, MUST extend core from `src/Pyz/Zed/...` (never `vendor/` or `node_modules/`), and MUST pass the `mp:*` gate.

## Where project code lives

The ZedUi FrontendBuilder (`vendor/spryker/zed-ui/src/Spryker/Zed/ZedUi/FrontendBuilder/`) scans exactly two roots: core `vendor/spryker/*/src/Spryker/Zed/*/Presentation/Components/` and project `src/Pyz/Zed/*/Presentation/Components/`. Code under any other namespace (e.g. `src/<CustomNamespace>/Zed/...`) is NOT built, linted, or tested — put Merchant Portal components in `src/Pyz/Zed/<Module>/Presentation/Components/`.

- `src/Pyz/Zed/ZedUi/Presentation/Components/` is the application shell (`main.ts`, `app/app.module.ts` extending `RootMerchantPortalModule`, `styles.less`, `environments/`). Use `app.module.ts` only for root-level config modules; components are NOT registered there.
- Components reach the page through a module `entry.ts`, auto-discovered by the builder:
  1. leaf `app/<name>/<name>.module.ts` declares + exports the component,
  2. `app/components.module.ts` lists it in `WebComponentsModule.withComponents([...])` AND imports its module (same for any `@spryker/*` component used directly from Twig),
  3. `entry.ts` calls `registerNgModule(ComponentsModule)` (import from `@mp/zed-ui`).
  Missing from `withComponents` fails silently — the element just does not render.
- A project `entry.ts` in a module with the **same name** as a core module (e.g. `src/Pyz/Zed/ProductMerchantPortalGui/`) **replaces** the core entry entirely — its `ComponentsModule` must re-register every core element that module's Twig still uses (import them from `@mp/<core-module>`). A new module name adds a new entry.
- `@mp/<module>` path aliases exist only for core modules (from each module's `mp.public-api.ts`); import project code relatively. Run `npm run mp:update:config` after composer adds/removes a Merchant Portal module — it regenerates the aliases in `tsconfig.mp*.json` and `angular.json`.
- Change markup by overriding the core Twig template in `src/Pyz/Zed/<Module>/Presentation/<Controller>/<action>.twig`; change look via CSS custom properties / theme variables in the component LESS or `src/Pyz/Zed/ZedUi/Presentation/Components/styles.less`.

## Reuse before building

Work down this ladder and stop at the first that works:
1. An installed `@spryker/*` component configured via its inputs.
2. Several `@spryker/*` components composed (`@spryker/table` + `table.column.*` + `table.feature.*` + `datasource.*` + `actions.*`).
3. An existing core `*MerchantPortalGui` component, reused via `@mp/<module>`.
4. A new project component — only when 1–3 genuinely cannot express it.

Never hand-roll a table, modal, drawer, notification, spinner, pagination, select, date picker, tabs, chips or form control. Prefer the `@spryker/*` wrapper over raw `ng-zorro-antd`. Search `vendor/spryker/zed-ui/package.json` dependencies, `vendor/spryker/*/src/Spryker/Zed/*/Presentation/Components/`, and existing Twig usage (`grep -rhoE '<web-(spy|mp)-[a-z-]+' --include='*.twig' vendor/spryker src/Pyz`).

**Packages ship prebuilt — never guess an API.** Read the installed typings (`node_modules/@spryker/<pkg>/types/*.d.ts` or `index.d.ts`, depending on the installed major) and its `package.json` version, not the public GitHub README. An unknown attribute on a custom element is silently ignored.

## Component conventions (Angular Elements inside Twig, not an SPA)

- `standalone: false` + NgModules. No standalone components, `provideRouter`, `bootstrapApplication` or other SPA bootstrapping (core: 84 `standalone: false`, 0 standalone).
- `@Input()` / `@Output()` decorators; no signal inputs, `signal()`, or `computed()` (core: 0 usages). The codebase is RxJS-based.
- Every component sets `changeDetection: ChangeDetectionStrategy.OnPush`, `encapsulation: ViewEncapsulation.None` (deliberate — do not "fix" to `Emulated`), and `host: { class: 'mp-<name>' }`. Selector `mp-<kebab-name>` (element); directives `mp<CamelName>` (attribute).
- Five files per component: `<name>.component.{ts,html,less,spec.ts}` + `<name>.module.ts`.
- Twig consumes elements with a `web-` prefix: `<web-mp-*>` (Merchant Portal), `<web-spy-*>` (`@spryker/*`). Inputs arrive as kebab-case HTML attributes — strings or Twig-serialised JSON — so type them honestly, parse explicitly, and give safe defaults. Content projection uses attribute slots (`<h1 title>` ↔ `<ng-content select="[title]">`).
- **Translate in Twig (`| trans`), never in Angular.** No hardcoded user-facing strings; accept them as inputs (house pattern: a `translations` object input).
- Async state as observables (`items$`) rendered with the `async` pipe. No nested subscriptions; if you must subscribe, unsubscribe (`takeUntilDestroyed`/`takeUntil`). Pick flattening by semantics: `switchMap` cancel-previous (typeahead, filter reload), `concatMap` ordered, `exhaustMap` ignore-while-busy (submit), `mergeMap` true concurrency; `shareReplay` streams bound more than once. `catchError` returns a fallback or rethrows.
- No `any` (use `unknown` + narrowing), no non-null assertions, no `rxjs/Rx` imports. Member order: instance fields → instance methods → static fields → static methods.
- Never `bypassSecurityTrust*` or `[innerHTML]` with server/user data without a documented justification — Twig attributes are data, not trusted markup.
- Accessibility is not linted: semantic elements before ARIA, accessible names and labels on every control, keyboard operable with visible focus, loading/empty/error states announced as text.

## Styling

`<name>.component.less`, scoped under the host class with BEM, reading a CSS custom property with a theme fallback:

```less
@import '~@spryker/styles/src/lib/themes/default/variables/index.less';

@my-widget-background: var(--spy-my-widget-background, @gray-lighter);

.mp-my-widget {
    &__content { background: @my-widget-background; }
}
```

No global styles from a component, no `!important`, no selectors reaching into another component's internals, no hardcoded colour that exists as a theme variable. Stylelint caps `selector-max-class` / `selector-max-compound-selectors` at 4.

## Unit tests (Jest)

- Spec next to the component (`<name>.component.spec.ts`), using the house TestHost pattern: a `standalone: false` host component with a template like Twig's, both in `declarations`, `schemas: [NO_ERRORS_SCHEMA]` (core: 74 of 76 specs). Because unknown elements are tolerated, assert on rendered output.
- Components are `OnPush`: call `fixture.detectChanges()` after every input change. Query with `By.css('.mp-<name>__<part>')` BEM classes, not structural selectors.
- Cover inputs (incl. absent/default), outputs, each content slot, and loading/empty/error states. Services via `TestBed.inject` + `HttpTestingController`; check `node_modules/@spryker/<pkg>/testing/` for ready mocks. `fakeAsync`/`tick` or `waitForAsync` — never real timers.
- Never `xit`/`skip` or weaken an assertion to reach green. Browser journeys belong in Cypress (`tests/**/cypress/e2e/merchant-portal/`), not Jest.

## Validation gate

```bash
docker/sdk cli npm run formatter
docker/sdk cli npm run mp:stylelint          # accepts -- --fix, -- -p <file>
docker/sdk cli npm run mp:lint               # ignores extra args (no --fix passthrough)
docker/sdk cli npm run mp:test               # -- --test-path-patterns=<name> for one spec
docker/sdk cli npm run mp:build              # rebuild public/MerchantPortal/assets/js to see changes
```

- `mp:test` sets `passWithNoTests` — confirm your spec actually ran in the summary.
- A green `mp:lint` proves nothing unless your files were linted: if ESLint prints `File ignored because no matching configuration was supplied`, the packaged `eslint.config.mjs` does not match `src/Pyz/Zed/*` (it targets the monorepo layout) — the project needs a root `eslint.config.mp.mjs`, which `mp:lint` picks up automatically.
- `max-lines` is off for Merchant Portal; decompose only when it genuinely helps.
- `eslint-disable`, `stylelint-disable`, `@ts-ignore`, `@ts-expect-error` each need a comment explaining why the rule cannot apply.
- There is no Nx, Storybook (`.stories.ts`), or Atomic Design level tagging in Merchant Portal — do not invent workflows around them.
