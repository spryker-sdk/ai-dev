# Finding and using installed UI components

Nearly every UI primitive already exists as a prebuilt `@spryker/*` package or a core Merchant Portal
component. Look them up before writing anything. Always check the **installed** version, never the
public `spryker/ui-components` README, which may describe another major.

## Search the three surfaces

```bash
# 1. Which @spryker packages the app depends on (and their allowed ranges)
grep '"@spryker/' vendor/spryker/zed-ui/package.json

# 2. Existing core Merchant Portal components you can reuse via @mp/{module}
find vendor/spryker/*/src/Spryker/Zed/*/Presentation/Components -name '*.component.ts'
cat vendor/spryker/{module}/src/Spryker/Zed/{Module}/Presentation/Components/public-api.ts   # what is importable

# 3. How elements are actually used today (the best usage documentation there is)
grep -rhoE '<web-(spy|mp)-[a-z-]+' --include='*.twig' vendor/spryker src/Pyz | sort | uniq -c | sort -rn
grep -rn 'spy-table\|TableModule' --include='*.ts' --include='*.html' vendor/spryker/*/src/Spryker/Zed/*/Presentation/Components
```

## Read the real API

The packages ship without source (`fesm2022/*.mjs` + typings). The typings location depends on the major:

```bash
cat node_modules/@spryker/table/package.json | grep '"version"'   # installed version wins
ls node_modules/@spryker/table/                                   # types/ (v4+) or index.d.ts (v3)
cat node_modules/@spryker/table/types/*.d.ts 2>/dev/null || cat node_modules/@spryker/table/index.d.ts
ls node_modules/@spryker/table/testing/                           # ready-made mocks, when present
```

Confirm the exact `@Input()` names, types, and the NgModule to import before writing the calling code. An
input name that is merely plausible fails silently on a custom element. The `README.md` inside the
package matches its version.

## Package naming

| Package | Purpose |
|---|---|
| `@spryker/table` | the table |
| `@spryker/table.column.*` | column renderers (`text`, `date`, `chip`, `image`, `select`, `input`, …) |
| `@spryker/table.feature.*` | behaviours (`pagination`, `filters`, `search`, `selectable`, `row-actions`, …) |
| `@spryker/table.filter.*` | filter types (`select`, `date-range`, `tree-select`) |
| `@spryker/datasource.*` | data sources (`http`, `inline`, `dependable`, `trigger.*`) |
| `@spryker/actions.*` | action handlers (`drawer`, `http`, `notification`, `redirect`, `refresh-table`, …) |

So "a table with pagination and a date column" is a composition of existing packages. Import each piece
in the module that uses it. A server-configured table often needs no new Angular code: build a
`GuiTableConfigurationTransfer` in PHP and render it with `<web-mp-table table-id="..." config='{{ guiTableConfiguration(tableConfiguration) }}'>`
(`GuiTable` module; example: `vendor/spryker/product-merchant-portal-gui/src/Spryker/Zed/ProductMerchantPortalGui/Presentation/ProductsConcrete/index.twig`).

## Using a `@spryker/*` component

- **Inside an MP component template**, use the Angular selector (`<spy-button>`), and import its module in
  the leaf module.
- **Directly from Twig**, use `<web-spy-*>`, for example `<web-spy-button-action>`. Add the component
  class to `withComponents([...])` and import its module in `components.module.ts`. A tag that another
  entry already defined still works, but register it in your own module too rather than relying on load
  order.
- Prefer the Spryker wrapper over raw `ng-zorro-antd` (`nz-*`). Use Ant Design directly only when no
  wrapper exists.
