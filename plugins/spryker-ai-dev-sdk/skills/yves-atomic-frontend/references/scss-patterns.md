# SCSS Patterns & Conventions

## The mixin pattern

A component's `{component-name}.scss` defines **one mixin**; the CSS is emitted where that mixin is
included. This lets other components and project overrides reuse the styles with a different selector
or extra rules.

```
@mixin {module-name-kebab}-{component-name-kebab}($name: '.{component-name}')
```

Examples from core: `shop-ui-toggler-checkbox` (ShopUi/molecules/toggler-checkbox),
`catalog-page-filter-category` (CatalogPage/molecules/filter-category). The first parameter is the
**dot-prefixed** default selector; use `#{$name}` for the root and for self-references that `&` cannot
express.

Where the mixin is included differs:

| Style | Files | Used by |
|---|---|---|
| Project convention | `{name}.scss` defines the mixin and ends with `@include {mixin};` — `index.ts` imports `./{name}.scss` | nearly all components in `src/Pyz/Yves` |
| Core convention | `{name}.scss` only defines; `style.scss` does `@include helper-import({tier}, {name}) { @include {mixin}; }` — `index.ts` imports `./style.scss` | `vendor/spryker-shop/**` |

Follow the neighbouring components of the module you are working in. `helper-import` skips its block
when a keyword is listed in `$setting-import-blacklist`, which lets a project switch off a core
component's CSS without touching the mixin.

## `@content` — always last

Place `@content;` as the last statement inside the root `#{$name}` block so callers can inject rules
scoped to the component:

```scss
@mixin my-module-my-component($name: '.my-component') {
    #{$name} {
        display: flex;

        @content;
    }
}
```

## BEM

```scss
@mixin my-module-my-component($name: '.my-component') {
    #{$name} {
        &__element {
            &:hover {
                // element state
            }
        }

        &__element--modifier {
            // element modifier
        }

        &--modifier {
            #{$name}__element {
                // root modifier affecting an element
            }
        }

        @content;
    }
}
```

- Keep nesting shallow — the Stylelint selector budget (below) rejects deep chains.
- Never style `js-` classes — they are TypeScript hooks.
- Never reference a parent component's classes — components are self-contained. Parents pass
  `modifiers` or `class` instead.

## Tokens and helpers — no import needed

The builder injects ShopUi's `styles/shared.scss` (the project copy in
`src/Pyz/Yves/ShopUi/Theme/default/styles/` when present, which forwards the core one) into every
component file, so `$setting-*`, `helper-*` and `map.get` work
without `@use`/`@import`.

Look up what exists before using it — tokens are project-specific:
- Core defaults: `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/styles/{settings,helpers}/`
- Project overrides and additions: `src/Pyz/Yves/ShopUi/Theme/default/styles/{settings,helpers}/` and `shared.scss`

Core tokens (always present unless the project removed them):

```text
$setting-color-main, $setting-color-alt, $setting-color-white, $setting-color-black
$setting-color-light, $setting-color-lighter, $setting-color-lightest
$setting-color-dark, $setting-color-darker, $setting-color-darkest
$setting-color-text, $setting-color-bg, $setting-color-shadow, $setting-color-overlay
$setting-color-actions          // map

map.get($setting-spacing, 'big' | 'default' | 'small' | 'reset')   // projects often add keys
$setting-font-size, $setting-font-weight, $setting-font-line-height // maps
$setting-breakpoints, $setting-zi-*                                  // breakpoints map, z-index scale
// projects often add more, e.g. breakpoint variables ($lg) or grey scales — check the project settings
```

Core helpers:

```scss
@include helper-font-size(big);                // keys of $setting-font-size
@include helper-font-weight(bold);              // keys of $setting-font-weight
@include helper-effect-transition(color border-color);
@include helper-ui-clearfix;
@include helper-breakpoint(md) { ... }                  // key of $setting-breakpoints; (md, lg) = range
@include helper-breakpoint-media-min(768px) { ... }     // also -media-max, -media-between
color: helper-color-dark($setting-color-main);          // function, also helper-color-light
```

If the project ships a design-token source (`frontend/assets/global/{theme}/design-tokens/design-tokens.json`),
the builder generates `src/Pyz/Yves/ShopUi/Theme/{theme}/styles/design-tokens.css` from it. Edit the
JSON, never the generated CSS; consume tokens as the project already does (CSS custom properties,
project helpers such as a `typography()` mixin).

**Never hardcode a colour** that a token covers. Named colours are forbidden by Stylelint.

## Overriding core SCSS in the project

Call the core mixin and put your rules in its `@content` slot (pattern taken from a real project override):

```scss
// src/Pyz/Yves/ShopUi/Theme/default/components/molecules/toggler-checkbox/toggler-checkbox.scss
@mixin shop-ui-toggler-checkbox($name: '.toggler-checkbox') {
    @include shop-ui-checkbox($name) {
        &__input:checked ~ &__label {
            @include helper-font-weight(regular);
        }

        @content;
    }
}

@include shop-ui-toggler-checkbox;
```

Core mixins are callable without imports. Mixin names resolve **project-last**: a project file that
defines a mixin with a core mixin's name replaces it everywhere it is included. Pair the file with an
`index.ts` that imports it and re-registers the core TS class (see SKILL.md, "What to ship next to the
Twig").

## Enforced Stylelint rules

`npm run yves:stylelint` extends `stylelint-config-standard-scss` with
`vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/libs/lint/spryker-base-stylelint.mjs`
(a project-root `.stylelintrc.js` replaces it). Read that file for authoritative values.

| Rule | Constraint |
|---|---|
| `color-named` | never — no `red`, `white`; use tokens |
| `color-hex-length` | off — match the surrounding file |
| `number-max-precision` | 4 |
| `unit-disallowed-list` | `pt` forbidden |
| `selector-pseudo-element-colon-notation` | double — `::before` |
| `*-no-vendor-prefix` | no vendor prefixes — autoprefixer adds them |
| `selector-max-class` / `-compound-selectors` / `-id` / `-attribute` / `-universal` | 2 / 3 / 1 / 1 / 1 |
| `length-zero-no-unit` | `0`, not `0px` |
| `declaration-empty-line-before` | never |

`-- --fix` handles whitespace, casing and units. Selector-budget and `color-named` violations are not
autofixable — restructure the selector or use a token.
