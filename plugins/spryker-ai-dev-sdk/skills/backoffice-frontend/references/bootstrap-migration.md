# Back Office Bootstrap 5 and styling layers

The Back Office ships Bootstrap 5 (`vendor/spryker/gui/assets/Zed/package.json`). Much of the core markup
still uses Bootstrap 3/4 classes. They render only because the legacy Inspinia CSS and the Gui SCSS
(`vendor/spryker/gui/assets/Zed/sass/_form.scss`, `_custom.scss`, `_buttons.scss`) re-implement them, so
neighbouring templates are a bad source to copy.

## Legacy → Bootstrap 5

Use this when writing new markup or when converting the region you are editing:

| Legacy (do not write) | Bootstrap 5 |
|---|---|
| `panel panel-default` / `panel-heading` / `panel-body` | `card` / `card-header` / `card-body` |
| `ibox` / `ibox-title` / `ibox-content` (Inspinia 1 only, no Inspinia2 equivalent) | `card` / `card-header` / `card-body` |
| `form-group` | `mb-3` |
| `btn-default` | `btn-secondary` |
| `col-xs-*` | `col-*` |
| `pull-left` / `pull-right` | `float-start` / `float-end` |
| `text-left` / `text-right` | `text-start` / `text-end` |
| `ml-*` / `mr-*`, `pl-*` / `pr-*` | `ms-*` / `me-*`, `ps-*` / `pe-*` |
| `input-group-addon` | `input-group-text` |
| `data-toggle` / `data-target` / `data-dismiss` | `data-bs-toggle` / `data-bs-target` / `data-bs-dismiss` |

`col-xs-*` is **dead**: nothing defines it any more. In `col-xs-8 col-8`, only `col-8` does anything.

Do not mass-migrate untouched templates, and do not copy from the public Inspinia demo site, which is
Bootstrap 3-era. Before converting a class in *existing* markup, check vendor JS does not select it:
`hidden`, `form-group`, `has-error` and `control-label` are toggled or queried by core
Zed JS, so renaming them breaks behaviour. The `spryker-upgrade` skill's `check-legacy-css-classes.php`
reports these as `KEEP (JS)`. Bootstrap JS is available as `window.bootstrap` (set in `ZedGui` commons), so prefer
`data-bs-*` attributes.

## Styling layers: stop at the first one that fits

1. **Bootstrap 5** utility or component. No CSS needed.
2. **Spryker Gui**: `@Gui/*` templates, the Twig helpers, Gui form types, `AbstractTable`.
3. **Inspinia2** (`vendor/spryker/gui/assets/Inspinia2/scss/`). It styles the plain BS5 classes
   (`components/_card.scss`, `_modal.scss`, `_forms.scss`, `_tables.scss`, …), so BS5 markup is themed
   automatically. Legacy `vendor/spryker/gui/assets/Inspinia/` is frozen. `main.scss` imports both.
4. **Project SCSS** imported from your entry point (see `assets-and-build.md`). Scope it under one root
   class and use no `!important`.

## Theme colours and logo

Colours are Back Office Configuration settings (Theme → Back Office → colors), defined in
`vendor/spryker/gui/resources/configuration/gui.configuration.yml`. `@Gui/Partials/theme-styles.twig`
emits them as CSS custom properties on `:root`, turning underscores into hyphens:
`bo_main_color` → `--bo-main-color`. The logo becomes `--zed-spryker-logo-url`.

- Consume the variables in SCSS (`color: var(--bo-main-color)`). Never hardcode the brand hex.
- To change a colour, change the configuration value. Project configuration YAML lives in
  `data/configuration/`.
