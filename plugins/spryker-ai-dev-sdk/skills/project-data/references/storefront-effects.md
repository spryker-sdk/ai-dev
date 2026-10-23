# Rows that import clean and do nothing on screen (project-data)

> Shared by every strategy. Each trap below imports without an error, passes a green boot, and shows up only when someone looks at the storefront. The parent `../SKILL.md` links here from its cross-cutting invariants. Where a `gate` group catches the trap, it is named; the rest need the on-screen check given.

## Discount rules on shipment compare ids, not names

`shipment-method` and `shipment-carrier` (decision rule and collector alike, `ShipmentDiscountConnector` `MethodDiscountDecisionRule` / `CarrierDiscountDecisionRule`) compare the clause value with the numeric **id** (`spy_shipment_method.id_shipment_method`, `spy_shipment_carrier.id_shipment_carrier`). So `shipment-method = 'DHL Standard'` imports, the Back Office shows the rule, and it never applies.

- Write the id: `shipment-method = '4'`, or `shipment-method is in '1;4'` (`;` separates list values). The Back Office query builder shows the method names but saves the ids.
- Ids follow import order: on a fresh import the first `shipment.csv` row gets `1`, and so on. Read them from the running database (`select id_shipment_method, shipment_method_key from spy_shipment_method`) and check them again whenever `shipment.csv` rows are added, removed or reordered before a reset. Otherwise the rule silently moves to another method.
- `shipment-price` compares the shipment price in **major** units (`'4.95'`), unlike the minor-unit CSV money columns.
- Checked: `preflight` group `discountShipmentByName`, which flags any non-numeric `shipment-method` / `shipment-carrier` value. On screen: put a basket over the threshold, choose the method, and confirm the shipment line is discounted.

## Every category needs its own `category_store` row once the shop exists

`category-store` (`CategoryStoreWriteStep` → `MainChildrenPropagationCategoryStoreAssignerPlugin`) spreads a category's stores to its children **only when that category gains a store it did not have**. The shipped root-only `category_store.csv` works on a fresh import because every category already exists when the root relation is created. A category added on a **later** import (no reset) finds the root unchanged, so nothing propagates. It gets no `spy_category_store` row: its URL resolves, but the page renders without products (or falls back to the homepage) in every store.

- Once categories are added to an existing shop, give **every** category one row per store: `included_store_names` for the stores, `excluded_store_names` to keep it out, or both empty to take the parent's stores. Explicit rows are harmless on a fresh import too.
- Checked: `preflight` group `categoryStoreIncomplete`. As soon as a store's rows name any child category, every child category must be stated for that store. On screen: open each new category URL per store and count the product tiles.

## `is_in_menu = 0` hides a category from the menu tree and the filter sidebar, not from its page

The category tree storage (`CategoryTreeStorageWriter`) is built from categories with `is_active = 1` **and** `is_in_menu = 1`. That tree is the Twig global `categories`, which feeds the catalogue's filter-category sidebar (and any menu drawn from the category tree). Setting `is_in_menu = 0` removes the category from both, while its own URL, page, products and search facets keep working. So it is the tool to hide an unwanted top-level entry in the filter sidebar without deleting the category.

- Never on the root: a root with `is_in_menu = 0` publishes an empty tree (`preflight` group `rootCategoryNotInMenu`).
- The header menu of the shipped storefront comes from `navigation_node.csv`, not the category tree. Hiding a category there is a navigation change (next section).

## A navigation node cannot be switched off through `is_active`

`NavigationNodeWriterStep::isActive()` uses `!empty($dataSet['is_active'])`, so `is_active = 0` (and an empty cell) keeps the node's current value. The schema default is `true`, so a new node imported with `0` is active too. `valid_from` / `valid_to` are written only when non-empty, so the importer can set a date but never clear one.

- **Retire a node:** set `valid_to` to a past date. The core `node` atom (`ShopUi` `atoms/node/node.twig`) hides nodes that are inactive or outside their validity window. The shipped Pyz `navigation-multilevel.twig` renders its side-drawer panels (`navPanel` macro) from raw `node.children` **without** that check, though. A retired child still shows in the mobile drawer until the template filters on `isActive` / `validFrom` / `validTo` the same way. Changing that template belongs to `match-reference-design` / `yves-atomic-frontend`, not here. Name it in the step report.
- **Delete a node:** remove the row, then `reset`. The importer never deletes, so a removed row stays in the database until the tables are rebuilt.
- On screen: check the header menu **and** the mobile drawer.

## B2B features stay wired when their import data is dropped

On a B2B clone the feature code stays registered after demo data is replaced (in `DataImportDependencyProvider`, the checkout plugins, the permission plugins), whether or not rows exist. Dropping the shipped `purchasing-control-cost-center`, `purchasing-control-budget` and `purchasing-control-cost-center-to-company-business-unit` sources leaves company users with empty Cost Centers / Budgets screens and a cost-center selector with nothing to pick.

When `project.audience` is `business` or `both` and demo data is replaced (generate, reduce, cleanup of customers/B2B org):

1. List the B2B entities whose import plugin is registered: grep `src/Pyz/Zed/DataImport/DataImportDependencyProvider.php` for the feature's `*DataImportPlugin`, and diff against the shipped manifest (`manifest-diff`). The purchasing-control set is `cost_center.csv` (`key,name,description,is_active`), `budget.csv` (`cost_center_key,name,amount,currency_iso_code,starts_at,ends_at,enforcement_rule,is_active`) and `cost_center_company_business_unit.csv` (`cost_center_key,business_unit_key`). The company admin role also needs `ManageCostCentersPermissionPlugin` in `company_role_permission.csv`.
2. Either author them for the project's companies and business units, or record the drop in the step report as a decision ("cost centers/budgets not demoed — screens will be empty").
3. Budgets are **integer minor units** (`250000` = 2,500.00) in a currency the store sells. Compare them with the catalogue's price range, as the parent's money invariant requires: a budget far below one order blocks every checkout with "exceeds the allocated budget".
