# SCSS Patterns & Conventions

## Two-File Pattern (Required)

Every component uses exactly two SCSS files:

| File | Purpose |
|---|---|
| `{component-name}.scss` | Defines a mixin — no actual CSS output |
| `style.scss` | Calls `helper-import` + invokes the mixin — outputs CSS |

This separation allows downstream customizations to call the same mixin with different selectors.

## Mixin Naming Convention

```
@mixin {module-name-kebab}-{component-name-kebab}($name: '.{component-name}')
```

Examples:
- `@mixin shop-ui-box(...)` — for ShopUi/atoms/box
- `@mixin product-group-widget-color-selector(...)` — for ProductGroupWidget/molecules/color-selector
- `@mixin catalog-page-filter-section(...)` — for CatalogPage/organisms/filter-section

## The `@content` Directive

Always include `@content` inside the root element block. This lets customizers inject extra rules:

```scss
@mixin my-module-my-component($name: '.my-component') {
    #{$name} {
        display: flex;

        // ... other rules ...

        @content;  // ALWAYS include this
    }
}
```

## style.scss Template

```scss
@include helper-import({type}, {component-name}) {
    @include {module-name}-{component-name};
}
```

Where `{type}` is `atom`, `molecule`, or `organism`.

## BEM Methodology

```scss
@mixin my-module-my-component($name: '.my-component') {
    #{$name} {
        // Block styles

        &__element {
            // Element styles

            &:hover {
                // Pseudo-class on element
            }
        }

        &__element--modifier {
            // Element modifier
        }

        &--modifier {
            // Block modifier
        }

        @content;
    }
}
```

**Rules:**
- Never nest BEM deeper than 2 levels (block → element is fine; block → element → sub-element is not)
- Modifiers go on the block or element, never both
- Never reference a parent component's class — components are self-contained

## Global Variables (from ShopUi)

Common variables available across all components:

```scss
// Colors
$setting-color-main           // Primary brand color
$setting-color-white
$setting-color-dark
$setting-color-darker
$setting-color-lighter
$setting-color-lightest
$setting-color-transparent
$setting-color-success
$setting-color-error
$setting-color-actions        // Map of action colors

// Spacing
$setting-spacing              // Map: 'default', 'big', 'small', etc.
map-get($setting-spacing, 'default')
map-get($setting-spacing, 'big')
map-get($setting-spacing, 'small')

// Typography
$setting-font-size-default
$setting-font-weight-bold
```

## Useful Mixins

```scss
// Smooth transitions
@include helper-effect-transition(border-color opacity);
@include helper-effect-transition(all);

// Clearfix
@include helper-ui-clearfix;

// Visually hidden (accessible)
@include helper-ui-visually-hidden;
```

## Responsive Breakpoints

```scss
@include helper-breakpoint('sm') {
    // Styles for small and up
}

@include helper-breakpoint('md') {
    // Styles for medium and up
}

@include helper-breakpoint('lg') {
    // Styles for large and up
}
```

## Overriding Component SCSS in Pyz

To override styles, create `style.scss` in the Pyz component folder and customize via `@content`:

```scss
// src/Pyz/{Module}/src/Pyz/Yves/{Module}/Theme/default/components/molecules/{component-name}/style.scss

@include helper-import(molecule, {component-name}) {
    // Call original mixin with custom overrides via @content
    @include {original-module}-{component-name} {
        // These rules inject into the @content slot
        background: $setting-color-lightest;

        &__title {
            font-size: 1.25rem;
        }
    }
}
```
