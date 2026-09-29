---
name: merchant-portal-frontend
description: >
  Use when creating, extending, or replacing Spryker Merchant Portal (MP) frontend code in a project —
  Angular components and entry.ts registration (always src/Pyz/Zed/*/Presentation/Components), the Twig
  pages that render them (any project namespace), and their Jest specs. Invoke for "create a Merchant Portal
  component", "extend an MP page", "add a web-mp component", "replace a core MP component", "use a
  @spryker/* table/drawer/select in the Merchant Portal", "pass data from Twig to Angular", "write an MP
  Jest spec", "mp:test is failing", "mp:build fails", or "my MP component does not render".
---

# Spryker Merchant Portal Frontend

The Merchant Portal is **Angular Elements embedded in Zed Twig pages**, not a routed SPA. A component
reaches the page only through a registration chain, and every break in that chain fails silently — the
`<web-mp-*>` tag just stays empty. Always-on conventions (decorator metadata, inputs, RxJS, LESS, i18n,
a11y) live in `.claude/rules/merchant-portal-angular.md`; this skill is the procedure.

`{ProjectNamespace}` = the namespace the project writes to — its custom namespace if one is defined (listed
first in `KernelConstants::PROJECT_NAMESPACES`, `config/Shared/config_default.php`), otherwise `Pyz`.

| Where | Path | You may |
|---|---|---|
| MP page (PHP + Twig) | `src/{ProjectNamespace}/Zed/{Module}/` — controller, factory, `Presentation/{Controller}/{action}.twig` rendering `<web-mp-*>` | create, override |
| MP Angular code | `src/Pyz/Zed/{Module}/Presentation/Components/` — **always `Pyz`**: zed-ui 4.3.0 hardcodes it (`FrontendBuilder/settings.mts:79-80`), no override; see `references/component-wiring.md` | create, extend, replace |
| App shell | `src/Pyz/Zed/ZedUi/Presentation/Components/` (`main.ts`, `app/app.module.ts`, `styles.less`) | root config, global theme overrides |
| Core (read-only) | `vendor/spryker/{module}/src/Spryker/Zed/{Module}/Presentation/Components/` | read, import via `@mp/{module}` |
| UI library (read-only) | `node_modules/@spryker/*`, `node_modules/ng-zorro-antd` | read typings, import |

## 1 · Find something to reuse first

Stop at the first rung that works: configure an installed `@spryker/*` component → compose several →
reuse a core `*MerchantPortalGui` component via `@mp/{module}` → build a new one. The lookup commands and
how to read an installed component's real API are in `references/ui-components-lookup.md`. Never guess
input names: an unknown attribute on a custom element is ignored without error.

## 2 · Decide: new module, or extend a core module?

The builder names each entry chunk `spy/{dasherized-module-dir}`, and **a project `entry.ts` in a module
with the same name as a core module replaces the core entry entirely**.

| Goal | Where the entry lives | Consequence |
|---|---|---|
| New MP component for a new page | `src/Pyz/Zed/{NewModule}/Presentation/Components/entry.ts` (new name) | Added next to core; nothing replaced |
| Add or replace a component on a core module's pages | `src/Pyz/Zed/{CoreModule}/Presentation/Components/entry.ts` (same name) | Core entry no longer loads — re-register **every** element and module in the core `components.module.ts`, not just the ones its Twig uses: tags are global, and other modules' pages rely on them |
| Only change markup around existing elements | Override the Twig template, no Angular code | See step 5 |

A second registration of an already-defined tag is skipped silently (`customElements.get()` guard in
`@spryker/web-components`), and the first entry to load wins. So never "replace" a core element from a
**differently named** module — the result depends on script order. Full recipe with code:
`references/component-wiring.md`.

## 3 · Create the component

```
src/Pyz/Zed/{Module}/Presentation/Components/
├── entry.ts                       # single-entry marker + registerNgModule(ComponentsModule)
└── app/
    ├── components.module.ts       # WebComponentsModule.withComponents([...])
    └── {name}/
        ├── {name}.component.ts    # selector 'mp-{name}', metadata per the rule
        ├── {name}.component.html
        ├── {name}.component.less
        ├── {name}.component.spec.ts
        └── {name}.module.ts       # declares + exports the component
```

Copy the shape of the nearest core component, e.g.
`vendor/spryker/agent-dashboard-merchant-portal-gui/src/Spryker/Zed/AgentDashboardMerchantPortalGui/Presentation/Components/app/agent-bar/`.
Import core code through `@mp/{module}` aliases (generated from each core module's `mp.public-api.ts`)
and project code relatively; no alias exists for project modules.

## 4 · Register it

1. Leaf module declares **and** exports the component.
2. `app/components.module.ts`: add the component to `WebComponentsModule.withComponents([...])` **and**
   import its module. Do the same for any `@spryker/*` component used directly as `<web-spy-*>` in Twig.
3. `entry.ts`: first line `// spy/merchant-portal:single-entry-marker`, then `registerNgModule(ComponentsModule)`
   from `@mp/zed-ui`. Without the marker the entry becomes its own `spy/{module}.js` chunk, which the
   Merchant Portal layout never loads (it loads only `spy/merchant-portal.js`).

`app/app.module.ts` is the root shell — do not register components there.

## 5 · Use it from Twig

Page templates live in `Presentation/{Controller}/{action}.twig`, extend
`@ZedUi/Layout/merchant-layout-main.twig`, and render elements with the auto-added `web-` prefix:

```twig
<web-mp-product-list
    cloak
    table-id="{{ idTableProductList }}"
    table-config='{{ guiTableConfiguration(productAbstractTableConfiguration) }}'>
    <h1 title>{{ 'Products' | trans }}</h1>
</web-mp-product-list>
```

- `@Input() tableId` ↔ `table-id="..."`. Every attribute arrives as a **string**. For JSON (objects,
  arrays, booleans) declare `@Input({ transform: jsonAttribute })` (`jsonAttribute` from `@spryker/utils`),
  or the input stays a string. Serialise with `| json_encode`; wrap raw (`guiTableConfiguration`) JSON in
  single quotes. Translate with `| trans` in Twig and pass the result in.
- `<h1 title>` lands in `<ng-content select="[title]">`.
- To change a core page, first check `src/*/Zed/{Module}/Presentation/...` for an existing override (the
  first namespace in `PROJECT_NAMESPACES` wins), else create it under `src/{ProjectNamespace}/Zed/...` and
  `{% extends '@Spryker:{Module}/{Controller}/{action}.twig' %}`, overriding only the blocks you change.
  A plain `@{Module}/...` resolves to your own override and extends itself.
- Example page: `vendor/spryker/product-merchant-portal-gui/src/Spryker/Zed/ProductMerchantPortalGui/Presentation/Products/index.twig`.

## 6 · Write the spec

Use the TestHost + `NO_ERRORS_SCHEMA` pattern, and call `fixture.detectChanges()` after every input change
(components are `OnPush`). Template and running one spec: `references/testing.md`.

## 7 · Build, validate, verify

```bash
docker/sdk cli npm run mp:build          # compile to public/MerchantPortal/assets/js — required to see changes
docker/sdk cli npm run mp:build:watch    # restart it after adding a NEW entry.ts (entries are discovered at startup)
docker/sdk cli npm run mp:update:config  # after composer adds/removes an MP module (regenerates @mp/* aliases)
docker/sdk cli npm run formatter
docker/sdk cli npm run mp:stylelint      # -- --fix, -- -p <file>
docker/sdk cli npm run mp:lint           # no argument passthrough; check coverage (below)
docker/sdk cli npm run mp:test           # -- --test-path-patterns=<name>
docker/sdk cli console twig:cache:warmer # when a new Twig override is not picked up
```

- `mp:lint` prints nothing and exits 0 for project files when they are not covered, even with errors in
  them. Run the `--print-config` check from `references/testing.md` § Lint; if it prints `undefined`, add
  the root `eslint.config.mp.mjs` from there and re-run.
- Verify in the browser. Log in to the Merchant Portal (`mp.<region>.<project>.local` in
  `deploy.dev.yml`; see the `spryker-runtime` skill for users), open the page, confirm the element
  rendered (not an empty `<web-mp-*>`), and check the browser console.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `<web-mp-x>` in DOM but empty | Tag is not `web-` + the selector, not in `withComponents`, module not imported, entry not registered or missing the single-entry marker, or build not re-run |
| Core elements vanished from a page | A project `entry.ts` with a core module's name replaced that core entry; re-register its whole list (the page may belong to another module) |
| Project replacement of a core tag ignored | Registered from a differently named module; core defined the tag first |
| Input never arrives, or arrives as a string | Attribute not kebab-case, wrong input name (read the `.d.ts`), or a JSON input without `transform: jsonAttribute` |
| `Cannot find module '@mp/...'` | Alias missing: run `mp:update:config`; project modules have no alias, import relatively |
| New component not picked up in watch mode | Restart `mp:build:watch` after adding an `entry.ts` |
| Angular files never built, linted or tested | They are outside `src/Pyz/Zed/*/Presentation/Components/`; move them (the page may stay in `{ProjectNamespace}`) |

## Reference files

- `references/component-wiring.md` — entry discovery and naming, new-module vs same-name-override recipes with code, re-registering core elements, `@mp/*` aliases, assets
- `references/ui-components-lookup.md` — finding installed `@spryker/*` packages and core MP components, reading typings, package naming, `web-spy-*` usage
- `references/testing.md` — TestHost spec template, running one spec, `/testing` mocks, the ESLint coverage check and override config
