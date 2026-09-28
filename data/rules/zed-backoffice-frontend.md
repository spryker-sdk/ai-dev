---
name: zed-backoffice-frontend
description: Use when writing, reviewing, or overriding Back Office (Zed) Twig templates, Back Office JavaScript/SCSS, or Back Office navigation. Enforces project-level overrides that extend core blocks, Bootstrap 5 markup on the Gui/Inspinia2 layers, no new jQuery, translated text, safe escaping, server-side ACL, and verifying changes in the running Back Office.
paths: "src/*/Zed/*/Presentation/**/*.twig,src/*/Zed/*/assets/Zed/**/*.{js,scss},config/Zed/navigation.xml"
---

**Frontend rule**

Back Office templates, JavaScript and SCSS:
- MUST be customized on project level by extending core, never by editing `vendor/`.
- MUST use **Bootstrap 5** markup on the existing Gui/Inspinia2 layers before adding styles.
- MUST NOT introduce new jQuery.

For procedures, use the `backoffice-frontend` skill. It covers building or overriding a page, Gui
helpers, navigation/ACL setup, asset entry points and which cache to rebuild.

Scope: Back Office pages only. These are out of scope:
- `Presentation/Mail/**` templates, which are e-mails.
- `*MerchantPortalGui` modules and `ZedUi`, which belong to the Angular Merchant Portal.

## Overrides

- Mirror the core path under `src/Pyz/Zed/{Module}/Presentation/`.
- In an override, extend the core file explicitly: `{% extends '@Spryker:{Module}/...' %}` (or
  `@SprykerFeature:` / `@SprykerEco:`). Plain `@{Module}/...` resolves to the override itself.
- Override only the blocks you change, and call `{{ parent() }}` unless you are replacing the block on purpose.
- Never copy a whole template.
- Prefer an extension point (plugin, form/table expander, configuration) or a small `_partials/` include
  over overriding a large template.
- The same idea applies to Zed JS: a project `*.entry.js` with a core entry's file name replaces that core bundle.

## Twig

- New pages extend `@Gui/Layout/layout.twig` and reuse Gui helpers and partials instead of hand-written
  markup. Use only Twig functions registered in `src/Pyz/Zed/Twig/TwigDependencyProvider.php`.
- No business logic in templates. Prepare data in the controller/facade (see [performance.md](performance.md)).
- Use `| raw` only on application-generated markup (a rendered table or form, sanitized HTML). **Never**
  use it on customer-, merchant-, import- or request-supplied values.

## Bootstrap 5 and styling

- Do not write Bootstrap 3/4 classes (`panel`, `ibox`, `form-group`, `btn-default`, `col-xs-*`, `pull-*`,
  `data-toggle`, `ml-*`/`mr-*`, …). They render only through legacy shims, and `col-xs-*` does nothing.
  The mapping is in the skill's `references/bootstrap-migration.md`.
- Convert only the region you are editing. Do not mass-migrate untouched templates.
- Work through the layers in order: Bootstrap 5 → Gui → Inspinia2 → project SCSS (last resort, scoped to
  one root class). Do not extend the legacy `Inspinia/` theme.
- Colours come from the theme CSS custom properties (for example `--bo-main-color`). Never hardcode brand
  hex values, and never use `!important` or deep selector chains to beat the theme.

## JavaScript

- jQuery is global and load-bearing. Do not rewrite working jQuery, but write new code with native DOM
  APIs, `fetch()` with explicit `response.ok` handling, and ES modules/classes.
- Never call `.DataTable()` on a Gui table. Declare the feature with `setTableAttributes()` in PHP, or
  take a handle via `requestTable()` (`ZedGuiModules/libs/table/table-access`).
- Use `js-` prefixed hook classes. Pass UI text and state classes from Twig via `data-*` or `<template>`.
- Never build `innerHTML` from user input.
- No React/Vue/Angular on Back Office pages.

## Translations

- All static user-facing text goes through `| trans`: titles, labels, buttons, placeholders,
  `title`/`alt`, table headers, modal content.
- Translate complete phrases with punctuation inside the key: `{{ 'Scope:' | trans }}`, not
  `{{ 'Save' | trans }} Configuration`.
- Every new key needs an entry in each Back Office locale's CSV.

## Security

- ACL is keyed on bundle/controller/action. Hiding a menu entry or button protects nothing, so enforce
  access server-side.
- Client-side validation is UX only.

## Accessibility and test hooks

- Use `<button>` for actions, `<a href>` for navigation, `<label for>` on every control and `<th scope>`
  in tables. Keep controls keyboard-operable.
- Keep existing `data-qa` attributes stable, and add one to new controls worth testing.

## Verification

A green `npm run zed` build is not verification. Rebuild the affected cache, then load the page in the
Back Office (`spryker-runtime` skill) or cover it with an E2E spec (`cypress-tests` skill).
