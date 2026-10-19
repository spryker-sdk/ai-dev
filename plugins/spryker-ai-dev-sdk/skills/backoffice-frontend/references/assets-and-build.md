# Back Office JS/SCSS assets and build

## How the build finds entry points

`npm run zed` runs `node ./frontend/zed/build` (`@spryker/oryx-for-zed`):

- It scans these entry dirs in order: `vendor/spryker`, `vendor/spryker-eco`, `vendor/spryker-sdk`,
  `vendor/spryker-feature`, and then the project dirs listed in `entry.dirs` of `frontend/zed/build.js`
  (default: only `./src/Pyz/Zed/`).
- The pattern is `**/Zed/**/*.entry.js`, so a project entry needs a `Zed` directory below the module:
  `src/{ProjectNamespace}/Zed/{Module}/assets/Zed/js/{bundle-name}.entry.js` (`{ProjectNamespace}`: see `SKILL.md`).
- The bundle name is the file name minus `.entry.js`. Output goes to
  `public/Backoffice/assets/js/{bundle-name}.js`, and to `css/{bundle-name}.css` when the entry imports
  SCSS.
- **Same name wins last.** A project entry with the file name of a core entry (for example
  `spryker-zed-acl-role.entry.js`) replaces the core bundle. Every page that includes it gets your code,
  so re-import whatever the core entry did.
- Any namespace other than `Pyz` is **not** scanned until you append it (project-owned file; one-time
  setup via the `configure-codebase` skill):
  `entry: { dirs: [path.resolve('./src/Pyz/Zed/'), path.resolve('./src/{ProjectNamespace}/Zed/')] }`.
  Verify: after `npm run zed`, `public/Backoffice/assets/js/{bundle-name}.js` exists.
- Twig, PHP, navigation and translations need no such registration — they resolve across all
  `KernelConstants::PROJECT_NAMESPACES`.

Commands (on the host or with `docker/sdk cli`): `npm run zed`, `npm run zed:watch`, `npm run zed:production`.

## Entry file

Follow the core pattern (for example `vendor/spryker/category-image-gui/assets/Zed/js/spryker-zed-category-image-main.entry.js`):

```js
'use strict';

require('../sass/main.scss');      // optional → css/{bundle-name}.css
require('./modules/my-feature');
```

Include it in the page:

```twig
{% block head_css %}
    {{ parent() }}
    <link rel="stylesheet" href="{{ assetsPath('css/spryker-zed-foo-gui-main.css') }}">
{% endblock %}

{% block footer_js %}
    {{ parent() }}
    <script src="{{ assetsPath('js/spryker-zed-foo-gui-main.js') }}"></script>
{% endblock %}
```

- Use one module system per file. Mixing `import` with `module.exports` bundles without errors but
  throws in the browser.
- Webpack aliases for core code: `ZedGui` (Gui commons), `ZedGuiModules` (`vendor/spryker/gui/assets/Zed/js/modules`)
  and `ZedGuiEditorConfiguration`. Import through them instead of copying vendor files.
- `$`/`jQuery` are provided globally. Do not import a second copy.

## Gui tables from JS: go through the orchestrator

Every table rendered by `AbstractTable` (`.gui-table-data[id]`, `.gui-table-data-no-search[id]`) is
created and configured by `vendor/spryker/gui/assets/Zed/js/modules/libs/table/table.js`. **What exists
depends on the installed `spryker/gui`** — read `Table.FEATURES` in that file first. Older releases (e.g.
gui 5.5) ship only `data-selectable`, `data-filterable`, `data-uploader` and have **no** `table-access.js`,
`README.md` or `TABLE_INIT_EVENT`; requiring them fails the build with "Module not found".

1. **Prefer PHP.** Declare the feature on the table config and ship no JS. Features (newer gui):
   `data-selectable`, `data-assignable`, `data-master-detail`, `data-filterable`, `data-uploader`. Address
   columns by header id (the key in `setHeader()`), not by index.

   ```php
   $config->setTableAttributes(['data-selectable' => ['moveToSelector' => '#toBeAssigned', 'colId' => 'spy_product.id_product']]);
   ```

2. **Otherwise request a handle** (only if `libs/table/table-access.js` exists; without it, subscribe to the
   instance's jQuery events, e.g. `$('#foo-table').on('draw.dt', cb)`, the one allowed jQuery use). Never call `$(el).DataTable()` (not even with `retrieve: true`, which
   creates a bare table), and never `import { Table }`, which inlines a second orchestrator:

   ```js
   const { requestTable } = require('ZedGuiModules/libs/table/table-access');

   requestTable(document.querySelector('#foo-table'), (handle) => {
       handle.on('draw', ({ initial }) => { /* ... */ });
   });
   ```

   Handle API: `on('draw'|'loading', cb)`, `reload(url)`, `refresh()` and `refreshLayout()` (all return
   Promises), `rowsData()`, and `raw()` as a last resort. `rowsData()`/`raw()` throw before the table exists,
   so call them inside a subscription or an event handler. `requestTable` throws on a missing element or
   on a non-Gui table.

3. **Tables injected after load** (dialogs, AJAX fragments; newer gui only) must announce themselves:
   `container.dispatchEvent(new CustomEvent(require('ZedGuiModules/libs/table/table').TABLE_INIT_EVENT, { bubbles: true }))`.

## Checks

- `npm run formatter` / `formatter:fix` (Prettier) covers all project `*.js`/`*.scss` (glob `**/*`, minus `.prettierignore`). The project has **no**
  ESLint or Stylelint config for Zed assets and no JS unit tests.
- A successful `npm run zed` proves only that the bundle compiled. Load the page, check the browser
  console, and confirm the table request returns JSON.
