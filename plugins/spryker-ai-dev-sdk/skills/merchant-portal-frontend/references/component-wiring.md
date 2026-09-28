# Component wiring

How the ZedUi FrontendBuilder turns project files into custom elements, and the two recipes a project
uses. Source of truth: `vendor/spryker/zed-ui/src/Spryker/Zed/ZedUi/FrontendBuilder/settings.mts` and
`libs/entry-points.mts`.

## How entries are discovered

- Roots: core `vendor/spryker/*/src/Spryker/Zed/*/Presentation/Components/entry.ts` and project
  `src/Pyz/Zed/*/Presentation/Components/entry.ts`. Nothing else is scanned.
- Chunk name: `spy/` + the dasherized module directory. `vendor/spryker/product-merchant-portal-gui` and
  `src/Pyz/Zed/ProductMerchantPortalGui` both become `spy/product-merchant-portal-gui`.
- Project entries are merged after core entries, so a same-named project entry **replaces** the core one.
- An entry that contains the comment `// spy/merchant-portal:single-entry-marker` is bundled into the
  shared `spy/merchant-portal` chunk. All 17 core entries carry it, and so should yours.
- `libs/index-transform.mts` injects one `<script>` per chunk into the built `index.html`, and the Merchant
  Portal layout loads that. Entries are discovered when webpack starts, so restart `mp:build:watch` after
  adding an `entry.ts`.
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
`@ZedUi/Layout/merchant-layout-main.twig`, navigation) is ordinary Zed work.

## Recipe B: add or replace a component on a core module's pages

Create `src/Pyz/Zed/{CoreModule}/Presentation/Components/entry.ts` with the **same module name**. Because
it replaces the core entry, your `ComponentsModule` must define every element the module's Twig still
renders. Open the core `app/components.module.ts` and carry its `withComponents([...])` list and module
imports over, importing core classes from `@mp/{module}`:

```ts
// src/Pyz/Zed/ProductMerchantPortalGui/Presentation/Components/app/components.module.ts
import { NgModule } from '@angular/core';
import { WebComponentsModule } from '@spryker/web-components';
import {
    EditAbstractProductModule,
    EditAbstractProductComponent,
    // ...every other element from the core components.module.ts
} from '@mp/product-merchant-portal-gui';

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
  `<web-mp-product-list>` in Twig picks it up. Carry over the `templateUrl` and `styleUrls`, or point them
  at your own copies.
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
  to `/static/` (see `angular.json`).
- `src/Pyz/Zed/ZedUi/Presentation/Components/styles.less` is loaded after the core `styles.less`. Put
  project-wide theme overrides, such as CSS custom properties, there.
