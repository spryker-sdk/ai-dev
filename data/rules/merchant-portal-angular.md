---
name: merchant-portal-angular
description: Use when writing, reviewing, or modifying Merchant Portal Angular code (components, modules, services, specs, LESS) under Presentation/Components. Enforces reuse of installed @spryker/* UI components, the NgModule + Angular Elements architecture embedded in Zed Twig, project-side extension of core, and honest validation with the mp:* scripts.
paths: "src/*/Zed/*/Presentation/Components/**/*.{ts,html,less}"
---

**Frontend rule**
Merchant Portal Angular code MUST reuse the installed `@spryker/*` UI components before building custom UI. It MUST follow the NgModule + Angular Elements architecture the app actually uses. Core is extended from `src/Pyz/Zed/{Module}/Presentation/Components/`, never by editing `vendor/` or `node_modules/`. Every change MUST pass the `mp:*` scripts.

## Architecture (non-negotiable)

- `{ProjectNamespace}` = the namespace the project writes to — its custom namespace if one is defined (listed first in `KernelConstants::PROJECT_NAMESPACES`, `config/Shared/config_default.php`), otherwise `Pyz`.
- The MP **page** (Zed controller, factory, Twig rendering `<web-mp-*>`) may live in `src/{ProjectNamespace}/Zed/{Module}/`; Twig and PHP resolve across all project namespaces.
- MP **Angular code** (`Presentation/Components/**`, `entry.ts`) MUST live in `src/Pyz/Zed/{Module}/Presentation/Components/`. This is a builder limitation, not a convention: zed-ui 4.3.0 hardcodes `projectModulesDirectory: './src/Pyz/Zed'` (`FrontendBuilder/settings.mts:79-80`) with no project override, so files elsewhere are silently not built, linted or tested. If this rule loaded for a file outside `src/Pyz`, move the file. Re-check after upgrading `spryker/zed-ui`.
- Components are custom elements rendered from Twig as `<web-mp-*>` (`@spryker/*`: `<web-spy-*>`). A component renders only after it is registered through `entry.ts` (first line `// spy/merchant-portal:single-entry-marker`) → `components.module.ts` (`WebComponentsModule.withComponents`) → leaf module. `app/app.module.ts` is the root shell, not a registry.
- A project `entry.ts` in a module named like a core module **replaces** the core entry. That override MUST re-register every element and module of the core `components.module.ts`: tags are global, and other modules' pages rely on them.
- Never hand-roll a table, modal, drawer, notification, spinner, pagination, select, date picker, tabs, chips or form control. Read the installed package's typings for its real API; never guess input names or trust the public README.

## Component conventions

- `standalone: false` + NgModules. No standalone components, `provideRouter`, `bootstrapApplication` or other SPA bootstrapping (core: 84 `standalone: false`, 0 standalone).
- `@Input()` / `@Output()` decorators. No signal inputs, `signal()` or `computed()` (core: 0 usages). The codebase is RxJS-based.
- New components set `changeDetection: ChangeDetectionStrategy.OnPush`, `encapsulation: ViewEncapsulation.None` (deliberate, never "fix" it to `Emulated`), and `host: { class: 'mp-<name>' }`.
- Selector `mp-<kebab-name>` (element). Directives use `mp<CamelName>` (attribute).
- Inputs cross the Twig boundary as kebab-case HTML attributes, always as strings. Parse Twig-serialised JSON with `@Input({ transform: jsonAttribute })` (`@spryker/utils`), type inputs honestly, and give safe defaults.
- **Translate in Twig (`| trans`), never in Angular.** No hardcoded user-facing strings. Accept them as inputs; the house pattern is a `translations` object.
- Async state as observables rendered with the `async` pipe. No nested subscriptions; unsubscribe (`takeUntilDestroyed`/`takeUntil`) when you must subscribe, because a leaked subscription outlives the Twig page.
- No `any` (use `unknown` + narrowing), no non-null assertions, no `rxjs/Rx` imports. Member order: instance fields → instance methods → static fields → static methods.
- Never `bypassSecurityTrust*` or `[innerHTML]` with server or user data without a documented justification. Twig attributes are data, not trusted markup.
- Accessibility is not linted. Use semantic elements before ARIA, give every control an accessible name and label, keep it keyboard operable with visible focus, and announce loading/empty/error states as text.

## Styling

- `<name>.component.less`, BEM scoped under the host class. Read colours through a CSS custom property with a theme fallback (`@x: var(--spy-x, @gray-lighter);` after importing `~@spryker/styles/src/lib/themes/default/variables/index.less`).
- No global styles from a component, no `!important`, no selectors reaching into another component's internals, no hardcoded colour that exists as a theme variable. Project-wide overrides go in `src/Pyz/Zed/ZedUi/Presentation/Components/styles.less`.

## Validation honesty

- Gate: `npm run formatter`, `mp:stylelint`, `mp:lint`, `mp:test` (via `docker/sdk cli`), then `mp:build` and render the page. A green build is not verification.
- A green `mp:lint` is NOT evidence for project files. The packaged config targets the monorepo layout, and ESLint skips uncovered files silently (exit 0, no output). Prove coverage with `--print-config` on a file, or use a root `eslint.config.mp.mjs`.
- `mp:test` passes with no tests; confirm your spec ran. Never `xit`/`skip` or weaken an assertion to reach green.
- `max-lines` is off for Merchant Portal. `eslint-disable`, `stylelint-disable`, `@ts-ignore` and `@ts-expect-error` each need a comment explaining why the rule cannot apply.
- Merchant Portal has no Nx, no Storybook (`.stories.ts`) and no Atomic Design level tagging. Do not invent workflows around them.

For the create/extend/register/Twig/spec/build procedure, the core-override recipe, UI component lookup and the ESLint override config, use the `merchant-portal-frontend` skill.
