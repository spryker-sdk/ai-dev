---
name: backoffice-frontend
description: >
  Use when building or overriding Spryker Back Office (Zed) pages in a project — new list/create/edit
  pages, overrides of core Zed Twig templates, Back Office navigation/ACL, or Back Office JS/SCSS. Triggers:
  "create a Back Office page", "add an admin screen for X", "override a Zed template", "add BO navigation",
  "add a menu entry to the Back Office", "BO JS/SCSS", "Bootstrap classes in Zed", "my Zed override is not
  picked up", "the new menu item does not show".
---

# Spryker Back Office Frontend

The always-on conventions (Bootstrap 5 only, no new jQuery, `| raw` safety, `| trans`, ACL server-side,
`data-qa`) live in `.claude/rules/zed-backoffice-frontend.md`. This skill is the procedure.

Project code goes in `src/Pyz/Zed/{Module}/`; core (`vendor/spryker*/{module}/src/*/Zed/{Module}/`) is
read-only and extended from Pyz. Find the nearest existing screen first (grep the core `Presentation/` dirs)
and copy its structure.

## Decide: override or new page?

- **Change an existing core page** → step 3b only (plus 5 if JS/CSS changes). Prefer an extension point
  (form/table expander plugin, configuration) over a template override when one exists.
- **New page** → steps 1–7.

## 1. Controller — `src/Pyz/Zed/{Module}/Communication/Controller/{Name}Controller.php`

URL is `/{module-kebab}/{controller-kebab}/{action-kebab}`, e.g. `IndexController::listAction()` in module
`FooGui` → `/foo-gui/index/list`. Thin controller per `.claude/rules/controller.md`.

- List page = `indexAction()` (`viewResponse(['fooTable' => $table->render()])`) **and** `tableAction()`
  (`jsonResponse($table->fetchData())`) — DataTables calls the second one; one controller per table
  (`.claude/rules/table.md`).
- Form page: `handleRequest()` → on valid submit call the facade, `addSuccessMessage()`, then
  `redirectResponse($url)`; otherwise `viewResponse(['form' => $form->createView()])`.
- Flash messages take placeholders as a parameter array — `addErrorMessage('Foo %name% not found.', ['%name%' => $name])` — never a `sprintf()` result (breaks translation).

## 2. Table / form / tabs — created in the `{Module}CommunicationFactory`

- Table extends `Spryker\Zed\Gui\Communication\Table\AbstractTable` (rules: `.claude/rules/table.md`).
  Row buttons: `generateEditButton()` / `generateViewButton()` / `generateRemoveButton()` /
  `generateButtonGroup()`; register those columns in `setRawColumns()`.
- Table JS features (selectable, filterable, master-detail, …) are declared in PHP with
  `$config->setTableAttributes(['data-selectable' => [...]])` — no JS needed. See `references/assets-and-build.md`.
- Forms: Symfony types in `Communication/Form/` (choice data per `.claude/rules/form-data-loading-performance.md`).
- Tabbed pages: a class extending `Spryker\Zed\Gui\Communication\Tabs\AbstractTabs`, `->createView()` passed
  to Twig, rendered with `{{ tabs(fooTabs, {...context}) }}`.

## 3a. New template — `src/Pyz/Zed/{Module}/Presentation/{Controller}/{action}.twig`

```twig
{% extends '@Gui/Layout/layout.twig' %}

{% set widget_title = 'Foo list' | trans %}
{% block head_title widget_title %}
{% block section_title widget_title %}

{% block action %}
    {{ createActionButton('/foo-gui/index/create', 'Add Foo' | trans) }}
{% endblock %}

{% block content %}
    {% embed '@Gui/Partials/widget.twig' %}
        {% block widget_content %}
            {{ fooTable | raw }}
        {% endblock %}
    {% endembed %}
{% endblock %}
```

Available blocks/helpers/partials: `references/twig-layout-and-helpers.md`; legacy→BS5 classes: `references/bootstrap-migration.md`.

## 3b. Override a core template

Mirror the core path (`vendor/spryker/{module}/src/Spryker/Zed/{Module}/Presentation/Edit/index.twig` →
`src/Pyz/Zed/{Module}/Presentation/Edit/index.twig`), extend the core file **explicitly**, override only
the blocks you change — never copy the template:

```twig
{% extends '@Spryker:ProductManagement/Edit/index.twig' %}

{% block content %}
    {{ parent() }}
    {% include '@ProductManagement/_partials/my-extra-panel.twig' %}
{% endblock %}
```

`@Spryker:` / `@SprykerFeature:` / `@SprykerEco:` = the core file; plain `@ProductManagement/...` resolves
the project file first, so extending it from the override recurses on itself.

## 4. Navigation and ACL

- Add the entry to `config/Zed/navigation.xml` (project order and top-level sections; top-level `<icon>`
  takes a Material Symbols name such as `settings`) or to `src/Pyz/Zed/{Module}/Communication/navigation.xml`
  (also scanned). Keyed by `<bundle>` / `<controller>` / `<action>` in kebab-case:

```xml
<foo-gui>
    <label>Foo</label>
    <title>Foo</title>
    <bundle>foo-gui</bundle>
    <controller>index</controller>
    <action>index</action>
</foo-gui>
```

- Navigation is cached (`ZED_NAVIGATION_CACHE_ENABLED` defaults to `true`) — run `console navigation:build-cache`.
- ACL checks the same bundle/controller/action triple. The root role has `*/*/*`; any restricted role
  needs a rule: installer rules in `src/Pyz/Zed/Acl/AclConfig.php::getInstallerRules()` (fresh installs;
  see `addDiscountManagerInstallerRules()`), or Back Office → Users → Roles (rules are attached to a role) for an existing DB.
  Always-allowed / whitelisted bundles: `AclConstants::ACL_DEFAULT_RULES` / `ACL_USER_RULE_WHITELIST` in
  `config/Shared/config_default.php` — do not add business pages there.

## 5. Assets (only if the page needs JS/SCSS)

Entry point `src/Pyz/Zed/{Module}/assets/Zed/js/{bundle-name}.entry.js`, built by `npm run zed`, included
with `{{ assetsPath('js/{bundle-name}.js') }}` in `footer_js` (after `{{ parent() }}`). Same file name as a
core entry **replaces** that core bundle. Details, table handle API and aliases: `references/assets-and-build.md`.

## 6. Translations

`src/Pyz/Zed/Translator/data/{Module}/en_US.csv` + `de_DE.csv` (one per Back Office locale), two columns
`"source","translation"`, no header, `en_US` repeats the source. Reuse core keys from
`vendor/*/{module}/data/translation/Zed/*.csv` first. Rebuild: `translator:generate-cache`.

## 7. Make it visible, then verify

| Changed | Run (`docker/sdk cli console …` unless noted) |
|---|---|
| New or moved Twig file (override not picked up) | `twig:cache:warmer` — the Zed template path cache is on by default |
| New project PHP class overriding core (controller, factory, config) | `cache:class-resolver:build` |
| New controller/action → 404 | `router:cache:warm-up:backoffice` |
| `navigation.xml` | `navigation:build-cache` |
| Translation CSV | `translator:generate-cache` |
| JS/SCSS | `docker/sdk cli npm run zed` (`zed:watch` while iterating) |

Verification — a green build is not proof:

1. Log in to the Back Office and open the page (`spryker-runtime` skill); check the browser console for
   JS errors and the table's AJAX request (`/{module}/{controller}/table`) returning JSON.
2. Exercise the happy path, a validation error, and a user role without the ACL rule (expect the `/acl/index/denied` "Access denied" page).
3. `npm run formatter` (`formatter:fix`) is the only automated check for Zed JS/SCSS; PHP via `static-validation`.
4. E2E coverage when the page matters: `cypress-tests` skill; select on `data-qa` attributes.

## Checklist

- [ ] Override extends `@Spryker:...` and overrides blocks only; no vendor edit
- [ ] `indexAction` + `tableAction`; tables/forms/tabs created in the factory
- [ ] All text `| trans` with keys in both CSVs; navigation entry + ACL rule for restricted roles
- [ ] Caches rebuilt per the table above; page verified in the running Back Office
