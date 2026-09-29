# Component wiring

How the ZedUi FrontendBuilder turns project files into custom elements, and the two recipes a project
uses. Source of truth: `vendor/spryker/zed-ui/src/Spryker/Zed/ZedUi/FrontendBuilder/settings.mts` and
`libs/entry-points.mts`.

## Namespace limitation

`{ProjectNamespace}` = the namespace the project writes to — its custom namespace if one is defined (listed
first in `KernelConstants::PROJECT_NAMESPACES`, `config/Shared/config_default.php`), otherwise `Pyz`.

**A custom namespace cannot be used fully for the Merchant Portal.** The split:

- **MP page** (controller, factory, config, ACL, `Presentation/{Controller}/{action}.twig`): any project
  namespace. Twig and PHP resolve across all `PROJECT_NAMESPACES` (first listed wins), so
  `src/{ProjectNamespace}/Zed/{Module}/` works with no extra wiring.
- **MP Angular code** (`Presentation/Components/**`, `entry.ts`, specs, LESS, the ZedUi app shell):
  `src/Pyz/Zed` only. A page in `src/{ProjectNamespace}/Zed/{Module}/` renders `<web-mp-*>` elements whose
  Angular code is in `src/Pyz/Zed/{Module}/Presentation/Components/`.

### Why: `src/Pyz` is hardcoded in the builder, with no project setting

All in `vendor/spryker/zed-ui/src/Spryker/Zed/ZedUi/FrontendBuilder/` (zed-ui 4.3.0):

| File | Hardcodes |
|---|---|
| `settings.mts:79-80` | `projectModulesDirectory: './src/Pyz/Zed'`, `projectApplicationDirectory: './src/Pyz/Zed/ZedUi/Presentation/Components'` |
| `settings.mts:130` `resolveBuilderSettings()` | takes only the project root — reads no project settings file and no env var; webpack, Jest, ESLint, Stylelint and `mp:update:config` all call it |
| `tsconfig.mp.json:63-67`, `tsconfig.mp.spec.json:17`, `tsconfig.mp.lint.json:5` | `src/Pyz/Zed/*/Presentation/Components/...` includes |
| `jest.config.mjs:15` | Jest `roots: [.../src/Pyz, ...]` |

There is no project file to configure: unlike Yves (`frontend/yves.settings.mts` → `paths.sources`) and Back
Office JS (`frontend/zed/build.js` → `entry.dirs`), the Merchant Portal has no project-level builder settings.

### Rejected workarounds

- **Symlink** `src/Pyz/Zed/{Module}/Presentation/Components` → the custom namespace: breaks with git on some
  platforms, Docker mounts and watch mode; two paths for one file confuse lint, tests and reviews.
- **Project webpack wrapper** in `angular.json` `customWebpackConfig.path`: `mp:update:config` rewrites it back
  to the vendor file (`libs/angular-configuration.mts:211`), and `postinstall` runs that on every `npm install`.
  Hand-added `tsconfig.mp.json` includes survive but create no webpack entries.
- **Patching `vendor/`** (composer-patches, patch-package): the project then owns the patch across every
  zed-ui update — against the upgradability rule.

### When this changes

Only an upstream `spryker/zed-ui` change can lift it — e.g. an optional `frontend/mp.settings.mts` read through
`defineConfig({ paths: { sources: { ... } } })`, as ShopUi does for Yves (that file does not exist today).
After every `spryker/zed-ui` upgrade, re-check `settings.mts` before assuming the limitation still holds.

## How entries are discovered

- Roots: core `vendor/spryker/*/src/Spryker/Zed/*/Presentation/Components/entry.ts` and project
  `src/Pyz/Zed/*/Presentation/Components/entry.ts`. Nothing else is scanned.
- Chunk name: `spy/` + the dasherized module directory. `vendor/spryker/product-merchant-portal-gui` and
  `src/Pyz/Zed/ProductMerchantPortalGui` both become `spy/product-merchant-portal-gui`.
- Project entries are merged after core entries, so a same-named project entry **replaces** the core one.
- An entry that contains the comment `// spy/merchant-portal:single-entry-marker` is bundled into the
  shared `spy/merchant-portal` chunk. All 17 core entries carry it, and yours **must**: the Merchant Portal
  layout (`@ZedUi/Layout/layout.twig`, block `layoutJs`) loads only `js/spy/merchant-portal.js`. An entry
  without the marker is emitted as its own `spy/{module}.js`, which no page loads, so its tags stay empty.
- Entries are discovered when webpack starts, so restart `mp:build:watch` after adding an `entry.ts`.
- `@spryker/web-components` defines a tag only `if (!customElements.get(name))`. The first definition
  wins, and duplicates are dropped without a warning.

## Recipe A: a new component in a new project module

`src/Pyz/Zed/MerchantNotesMerchantPortalGui/Presentation/Components/`:

```ts
// entry.ts
// spy/merchant-portal:single-entry-marker
import { registerNgModule } from '@mp/zed-ui';
import { ComponentsModule } from './app/components.module';

registerNgModule(ComponentsModule);
```

```ts
// app/components.module.ts
import { NgModule } from '@angular/core';
import { WebComponentsModule } from '@spryker/web-components';
import { ButtonLinkComponent, ButtonLinkModule } from '@spryker/button';

import { MerchantNoteComponent } from './merchant-note/merchant-note.component';
import { MerchantNoteModule } from './merchant-note/merchant-note.module';

@NgModule({
    imports: [
        // Every element Twig renders directly, including @spryker/* ones such as <web-spy-button-link>.
        WebComponentsModule.withComponents([MerchantNoteComponent, ButtonLinkComponent]),
        MerchantNoteModule,
        ButtonLinkModule,
    ],
})
export class ComponentsModule {}
```

```ts
// app/merchant-note/merchant-note.module.ts
@NgModule({
    imports: [CommonModule],
    declarations: [MerchantNoteComponent],
    exports: [MerchantNoteComponent],
})
export class MerchantNoteModule {}
```

Twig: `<web-mp-merchant-note ...></web-mp-merchant-note>`. The page itself (controller, template extending
`@ZedUi/Layout/merchant-layout-main.twig`, navigation) is ordinary Zed work and may live in
`src/{ProjectNamespace}/Zed/`. A new MP module also needs an
ACL rule for its dasherized bundle name: extend `getMerchantAclRoleRules()` in
`src/{ProjectNamespace}/Zed/AclMerchantPortal/AclMerchantPortalConfig.php` (e.g. in b2b-demo-marketplace:
`src/Pyz/Zed/AclMerchantPortal/`; check `src/*/Zed/AclMerchantPortal/` for an existing override first;
core list: `vendor/spryker/acl-merchant-portal`),
or merchant users are denied the page.

## Recipe B: add or replace a component on a core module's pages

Create `src/Pyz/Zed/{CoreModule}/Presentation/Components/entry.ts` with the **same module name**. Because
it replaces the core entry, your `ComponentsModule` must define **every** element and import **every**
module the core `app/components.module.ts` does, including icon modules. Tags are global, so another
module's page may depend on them: `<web-spy-icon>` on the DataImportMerchantPortalGui page is defined only by
ProductMerchantPortalGui and MerchantAppMerchantPortalGui. Carry the whole list over. Import core classes
from `@mp/{module}` where its `public-api.ts` exports them; import the rest (for example
ProductMerchantPortalGui's `../icons` and `create-abstract-product-concretes-list`, or three
SalesMerchantPortalGui `manage-order-*` components) by a relative path into
`vendor/spryker/{module}/src/Spryker/Zed/{Module}/Presentation/Components/`. Never drop one because it is not
exported:

```ts
// src/Pyz/Zed/ProductMerchantPortalGui/Presentation/Components/app/components.module.ts
import { NgModule } from '@angular/core';
import { WebComponentsModule } from '@spryker/web-components';
import {
    EditAbstractProductModule,
    EditAbstractProductComponent,
    // ...every other element from the core components.module.ts
} from '@mp/product-merchant-portal-gui';
import {
    IconDeleteModule,
    IconGermanyModule,
    IconNoDataModule,
    IconUnitedStatesModule,
} from '../../../../../../../vendor/spryker/product-merchant-portal-gui/src/Spryker/Zed/ProductMerchantPortalGui/Presentation/Components/icons'; // not in public-api.ts

import { ProductListComponent } from './product-list/product-list.component';
import { ProductListModule } from './product-list/product-list.module';

@NgModule({
    imports: [
        WebComponentsModule.withComponents([
            ProductListComponent,          // project class, same selector 'mp-product-list'
            EditAbstractProductComponent,  // core classes, re-registered
            // ...
        ]),
        ProductListModule,
        EditAbstractProductModule,
        // ...
    ],
})
export class ComponentsModule {}
```

- To change behaviour, extend the core class (`export class ProductListComponent extends CoreProductListComponent`,
  imported from `@mp/product-merchant-portal-gui`). Declare it with the same `selector` so the existing
  `<web-mp-product-list>` in Twig picks it up. Carry over the `templateUrl` and `styleUrls` as relative
  paths into `vendor/`, or point them at your own copies. Declare it in your own leaf module that repeats the
  core leaf module's `imports` (core-internal ones such as `ProductListTableModule` come from `@mp/{module}`).
- Do **not** import the core `ComponentsModule`. It would define the core `mp-product-list` element, and
  whichever definition runs first would win.
- Diff your list against the core `components.module.ts` after every `composer update` of that module.
  A newly added core element will otherwise be missing from your override.
- To add a new element to a core page without replacing anything, prefer Recipe A in a new module plus a
  Twig override of the page.

## Path aliases

- `@mp/{module}` → `vendor/spryker/{module}/mp.public-api.ts`, for **core modules only**. Examples:
  `@mp/zed-ui` (`registerNgModule`, `ContentToggleModule` and others), `@mp/gui-table`.
- The aliases are written into the builder's `tsconfig.mp*.json` by `npm run mp:update:config`. Run it
  after composer adds or removes a Merchant Portal module. Project modules get no alias, so import them
  relatively.

## Assets and global styles

- `src/Pyz/Zed/*/Presentation/Components/assets/**` is copied to `/assets/`, and `src/Pyz/Zed/*/data/files/**`
  to `/static/` (the project `angular.json` assets, e.g. in b2b-demo-marketplace; `Pyz` only, like the builder).
- `src/Pyz/Zed/ZedUi/Presentation/Components/styles.less` is loaded after the core `styles.less`. Put
  project-wide theme overrides, such as CSS custom properties, there.
