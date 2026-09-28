---
name: zed-backoffice-frontend
description: Use when writing, reviewing, or overriding Back Office (Zed) Twig templates, Back Office JavaScript/SCSS, or Back Office navigation. Enforces project-level template overrides that extend core blocks, Bootstrap 5 markup on the Gui/Inspinia2 layers, no new jQuery, translated text, safe escaping, and verifying changes in the running Back Office.
paths: "src/*/Zed/*/Presentation/**/*.twig,src/*/Zed/*/assets/Zed/**/*.{js,scss},config/Zed/navigation.xml"
---

**Frontend rule**
Back Office templates, JavaScript and SCSS MUST be customized on project level by extending core (never editing `vendor/`), MUST use **Bootstrap 5** markup on the existing Gui/Inspinia2 layers before adding styles, and MUST NOT introduce new jQuery.

Scope: Back Office pages only. `Presentation/Mail/**` templates are e-mails (inline e-mail markup, no Bootstrap), and `*MerchantPortalGui` modules / `ZedUi` belong to the Angular Merchant Portal — neither follows this rule.

## Overriding core templates

- Override a core template by creating the same relative path on project level: `vendor/spryker/{module}/src/Spryker/Zed/{Module}/Presentation/{Controller}/{action}.twig` → `src/Pyz/Zed/{Module}/Presentation/{Controller}/{action}.twig`. The project file wins for `@{Module}/...` everywhere.
- Extend the core original and override only the blocks you change — never copy the whole template:

```twig
{% extends '@Spryker:ProductManagement/Edit/index.twig' %}

{% block content %}
    {{ parent() }}
    {% include '@ProductManagement/_partials/my-panel.twig' %}
{% endblock %}
```

  `@Spryker:{Module}/...` (also `@SprykerFeature:`, `@SprykerShop:`) targets the core file explicitly; plain `@{Module}/...` resolves to the project override and would extend itself.
- Call `{{ parent() }}` unless you deliberately replace the block content.
- Prefer a small `_partials/` include or an extension point (plugin, form/table expander, configuration) over overriding a large template. Every override is code you re-check on each core update.

## Twig

- New pages extend `@Gui/Layout/layout.twig` and fill `head_title`, `section_title`, `action` (page buttons), `content`; add page scripts in `footer_js` after `{{ parent() }}`.
- Page buttons use the Gui helpers: `createActionButton`, `editActionButton`, `viewActionButton`, `backActionButton`, `removeActionButton`, `groupActionButton`.
- Reuse Gui templates instead of hand-written markup: `@Gui/Partials/widget.twig` (via `{% embed %}` + `widget_content`), `@Gui/Tabs/tabs.twig`, `@Gui/Modal/modal.twig`, `@Gui/Panel/panel.twig`, `@Gui/Table/*`. Tables come from `AbstractTable` and are rendered as `{{ table | raw }}` — see [table.md](table.md).
- Forms render via `form_start` / `form_row` / `form_widget` / `form_end`; do not hand-write inputs for fields the form type owns. CSRF stays enabled.
- No business logic in templates — prepare data in the controller/facade (see [performance.md](performance.md) → Twig).
- Give lists an explicit empty state.

### `| raw` is the XSS surface

Only apply `| raw` to markup the application generated (rendered table/form, sanitized HTML). **Never** to customer-, merchant-, import- or request-supplied values. When unsure, leave it escaped.

## Bootstrap 5 — no legacy markup in new or edited code

The Back Office ships Bootstrap 5 (`vendor/spryker/gui/assets/Zed/package.json`). Legacy Bootstrap 3/4 classes still render only because Inspinia/Gui SCSS re-implement them — do not copy them from neighbouring templates:

| Legacy (do not write) | Bootstrap 5 |
|---|---|
| `panel panel-default` / `panel-heading` / `panel-body`, `ibox` / `ibox-title` / `ibox-content` | `card` / `card-header` / `card-body` |
| `form-group` | `mb-3` |
| `btn-default` | `btn-secondary` |
| `col-xs-*` (dead — nothing defines it) | `col-*` |
| `pull-left` / `pull-right`, `text-left` / `text-right` | `float-start` / `float-end`, `text-start` / `text-end` |
| `input-group-addon` | `input-group-text` |
| `data-toggle` / `data-target` / `data-dismiss` | `data-bs-toggle` / `data-bs-target` / `data-bs-dismiss` |
| `ml-*` / `mr-*` | `ms-*` / `me-*` |

Convert only the region you are already editing — do not mass-migrate untouched templates. Do not copy markup from the public Inspinia demo; it is Bootstrap 3-era.

## Styling — use the layers in this order

1. Bootstrap 5 utility/component — no CSS needed.
2. Spryker Gui template/helper/form type/`AbstractTable`.
3. Inspinia2 (`vendor/spryker/gui/assets/Inspinia2`) — it themes plain Bootstrap 5 classes; legacy `assets/Inspinia` is frozen.
4. Project SCSS — last resort, scoped to one root class.

- Colours and the Back Office logo come from Back Office Configuration (Theme → Back Office), emitted as CSS custom properties (e.g. `--bo-main-color`) by `@Gui/Partials/theme-styles.twig`. Use the variables; never hardcode hex values.
- No `!important`, `#id` selectors or deep chains to beat Bootstrap/Inspinia — needing them means the wrong component is used.

## JavaScript and SCSS assets

- Project entry points: `src/Pyz/Zed/{Module}/assets/Zed/js/{bundle-name}.entry.js` (the build scans `src/Pyz/Zed/` for `**/Zed/**/*.entry.js`; a custom namespace must be added to `entry.dirs` in `frontend/zed/build.js`). An entry with the **same file name as a core entry replaces it**. Output is `public/Backoffice/assets/js/{bundle-name}.js`, included via `<script src="{{ assetsPath('js/{bundle-name}.js') }}"></script>` in `footer_js`.
- Import SCSS from the entry; reuse core JS via the `ZedGui` / `ZedGuiModules` aliases instead of copying vendor files.
- jQuery is global and load-bearing (DataTables, select2, summernote) — do not rewrite working jQuery, but write new code with native APIs: `querySelector`, `addEventListener`, `closest`, `classList`, `dataset`, `fetch()` with explicit `response.ok` handling, `async/await`, ES modules and `class`.
- Never call `.DataTable()` on a Gui table — get a handle from the orchestrator (`requestTable()` in `ZedGuiModules/libs/table/table-access`) or declare the feature via `setTableAttributes()` in PHP.
- Use `js-` prefixed classes as JS hooks; pass translated text and state classes from Twig via `data-*` attributes or `<template>` elements — no hardcoded UI strings in JS, no `innerHTML` built from user input.
- Bootstrap components are available as `window.bootstrap`; prefer declarative `data-bs-*`. Do not add React/Vue/Angular to Back Office pages.

## Translations

- All static user-facing text (titles, labels, buttons, placeholders, `title`/`alt`, table headers, modal content) goes through `| trans`, as complete phrases with punctuation inside the key: `{{ 'Scope:' | trans }}`, not `{{ 'Save' | trans }} Configuration`.
- Project keys live in `src/Pyz/Zed/Translator/data/{Module}/{locale}.csv` — two columns `"source","translation"`, one file per Back Office locale (e.g. `en_US.csv`, `de_DE.csv`). Rebuild with `console translator:generate-cache`.
- Controller flash messages are translated too — pass placeholders as parameters (`addSuccessMessage('Saved %count% items', ['%count%' => $count])`), never a `sprintf()`-built string.

## Navigation and ACL

- Menu entries go in `config/Zed/navigation.xml` (project order and custom entries; top-level `<icon>` uses Material Symbols names) or the module's `Communication/navigation.xml`, keyed by `<bundle>`/`<controller>`/`<action>`. Rebuild with `console navigation:build-cache`.
- ACL is keyed on the same bundle/controller/action. Hiding a menu entry or button protects nothing — enforce access server-side; client-side validation is UX only.

## Accessibility and test hooks

- `<button>` for actions, `<a href>` for navigation, `<label for>` on every control, `<th scope>` in tables, one `<h1>`, keyboard-operable controls with visible focus.
- Keep existing `data-qa` attributes stable (E2E tests select on them); add one to new controls worth testing.

## See the change

- Twig: `console twig:cache:warmer` (or `console cache:empty-all`) when a new override is not picked up; `console cache:class-resolver:build` if you added a project class override.
- JS/SCSS: `npm run zed` (`zed:watch` while iterating, `zed:production` for release builds); `npm run formatter` checks formatting.
- A green build is not verification — load the page in the Back Office (`spryker-runtime` skill) or cover it with an E2E spec (`cypress-tests` skill).
