# Back Office Twig: layout, helpers, partials

Only what is Spryker-specific and verified against `vendor/spryker/gui` and the project's
`src/Pyz/Zed/Twig/TwigDependencyProvider.php`. Generic Twig is assumed.

## Layouts

| Layout | Use |
|---|---|
| `@Gui/Layout/layout.twig` | Every new Back Office page. |
| `@Gui/Layout/iframe-layout.twig` | Content rendered inside an iframe/modal. |
| `@Application/Layout/layout.twig` | Older core pages extend it; it builds on the Gui layout. Match the core template when overriding. |

Blocks of `@Gui/Layout/layout.twig`:

| Block | Holds |
|---|---|
| `head_title` | `<title>` text. Defaults to `title \| trans` when a `title` variable is passed. |
| `head_css` | Stylesheets. Add `<link href="{{ assetsPath('css/{bundle}.css') }}">` after `{{ parent() }}`. |
| `section_title` | Page heading. |
| `action` | Page-level buttons, top right. |
| `content` | Page body. |
| `footer_js` → `common_js`, `init_js` | Scripts. Add yours after `{{ parent() }}` in `footer_js`. Never replace `common_js`/`init_js`: they load jQuery, Bootstrap and the Gui runtime. |

## Twig functions registered in the project

These come from `src/Pyz/Zed/Twig/TwigDependencyProvider.php`. A Gui function that is not listed is not
available. Examples are `modal()`, `panel()` and `listGroup()`: their plugins exist in vendor but are not
registered. Use the partial instead, or register the plugin there first.

| Function | Signature / note |
|---|---|
| `createActionButton`, `editActionButton`, `viewActionButton`, `backActionButton`, `removeActionButton` | `(url, title, options = {})`, used in `{% block action %}` |
| `groupActionButtons` | `(buttons[], title, options = {})`, a dropdown of actions |
| `createTableButton`, `editTableButton`, `viewTableButton`, `backTableButton`, `removeTableButton` | `(url, title, options = {})`, button markup inside Twig-rendered rows |
| `submit_button` | `(value, attr = {})`, a form submit button |
| `tabs` | `(tabsViewTransfer, context = {})`. The transfer comes from `AbstractTabs::createView()`. |
| `assetsPath` | `('js/{bundle}.js')` / `('css/{bundle}.css')` → `public/Backoffice/assets/...` |
| `url` | `(path, query = {})`, a URL with an encoded query |

In PHP tables, use `AbstractTable::generate{Edit,View,Remove,Create}Button()` and `generateButtonGroup()`
instead of the Twig table buttons. `generateRemoveButton()` renders a CSRF-protected `DeleteForm`.

## Partials and templates

| Template | How |
|---|---|
| `@Gui/Partials/widget.twig` | `{% embed %}` with `widget_title`, then fill `widget_content` (and optionally `widget_header_content`). This is the standard content box. |
| `@Gui/Modal/modal.twig` | `{% include ... with { id, title, content, footer } %}`. Its markup is already BS5 (`btn-close`, `data-bs-dismiss`). |
| `@Gui/Modal/confirmation-modal-window.twig` | A ready-made confirm dialog. |
| `@Gui/Partials/breadcrumb.twig`, `pagination.twig`, `wave-loader.twig` | Breadcrumb, pagination and loader. |
| `@Gui/Form/Type/*`, `@Gui/Form/Theme/*` | The form theme that the Gui form types use. Render forms with `form_start`/`form_row`/`form_end`, not hand-written inputs. |

Avoid these legacy partials in new markup: `@Gui/Panel/panel.twig` (`panel panel-*`) and
`@Gui/Partials/ibox.twig` / `localized-ibox.twig` (`ibox`). They are Bootstrap 3-era. Use a `card` or
`widget.twig` instead.

## Template namespaces

| Reference | Resolves to |
|---|---|
| `@ProductManagement/Edit/index.twig` | The project file first (`src/Pyz/Zed/ProductManagement/Presentation/...`), then core. |
| `@Spryker:ProductManagement/Edit/index.twig` | Always `vendor/spryker/product-management/...`. Use it in `extends` inside an override. |
| `@SprykerFeature:AiCommerce/...`, `@SprykerEco:{Module}/...` | Feature and eco packages. |

`include ... ignore missing` keeps an override working when an optional feature package is not installed.
`src/Pyz/Zed/ProductManagement/Presentation/Edit/index.twig` does exactly that.

The Zed template path cache is on by default (`TwigConfig::isPathCacheEnabled()`). A newly added override
keeps rendering the core file until you run `console twig:cache:warmer`.

## Out of scope

- `Presentation/Mail/**` holds e-mail templates. They use inline e-mail markup and no Bootstrap.
- `*MerchantPortalGui` modules and `ZedUi` belong to the Angular Merchant Portal, not the Back Office.
