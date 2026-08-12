# Minimal bootable baseline

The truly-minimal set that boots green and degrades gracefully (every page 200, missing CMS page → soft 404, empty menus/slots render). No demo category tree, no demo CMS copy, no demo nav. Edit in place within the existing buckets (e.g. `data/import/common/common/`) — no `data/import/<project>/` tree.

## Keep, populated (structural / code-registration)
- `cms_template.csv`, `cms_slot_template.csv`, `cms_slot.csv` — register Twig templates/slots (code, not content).
- `category_template.csv`; `category.csv` → **root row only** (the `is_root=1` row).
- `navigation.csv` — menu keys (the nodes are dropped, see below).
- `currency.csv` (all 285 ISO currencies ship — no per-currency work), `configuration_value.csv`, `mime_type.csv`.
- `payment_method.csv`; `shipment.csv`, `shipment_type.csv`, `shipment_method_shipment_type.csv`; `warehouse.csv` (entity `stock`), `warehouse_address.csv` (entity `stock-address`).
- `glossary` — one file per locale (`glossary.<locale>.csv`), en_US extracted then copy+relabeled per project locale (project-data adapt, step 1). Not one shared multi-locale file.
- **`product_label.csv`** → `ProductLabelDependencyProvider` wires **four** updater plugins — `ProductNew` (`NEW`), `ExampleProductSalePage` (`SALE`), `ProductAlternative` (`Alternatives available`), `ProductDiscontinued` (`Discontinued`). Of those, **`NEW` and `SALE` have no self-installer, so they MUST be present in the CSV** (missing → install abort); `Alternatives available`/`Discontinued` self-install. Every other shipped label is an **unwired demo label — safe to drop.** So the minimal set is `NEW` + `SALE` (both dynamic; no product SKUs needed). The full shipped label list + which plugins wire them + the derivation command live in `../../spryker-import-tools/references/demo-facts.md#product-labels` — read there rather than trusting a count here (the shipped set grows on upstream bumps).

## Keep with transform
- `tax.csv` → **regenerate** for the project's countries across the demoshop's tax-set names (Standard/Shipment/Tax Exempt/MOV pattern). Placeholder rates OK (flag as follow-up). Shipped one covers only DE/AT → would break checkout tax.
- `cms_block.csv` → **keep ONLY the `cms-block-email--*` rows** (~54; the prefix is in the **`block_key`** column — the file has no `name` column). These are transactional email bodies rendered by the Mail module via `renderCmsBlockAsTwig` — dropping them throws on every registration/order/password mail. Drop all other (home/nav/catalog demo) blocks.
  - **Caveat: keeping only the email rows ALSO deletes all ~21 home/nav/catalog blocks AND every content-BLOCK template definition** — block templates live in the `template_name`/`template_path` columns of `cms_block.csv` rows (`@CmsBlock/template/*`), NOT in `cms_template.csv` (which is for CMS *pages*, `@Cms/templates/*`). So the homepage renders empty (the home template emits `{% cms_slot 'slt-3' %}`/`'slt-5'` etc., and `cms_slot` produces no wrapper markup when a slot resolves to nothing — an empty page with no clue). **Whoever runs after this MUST author replacement blocks + their block templates** (`title_and_content_block.twig`, `banner_block.twig`, `banner_grid_column_block.twig`, `section_block.twig`, `jumbotron_block.twig`, `navigation_block.twig`, `product-cms-block.twig`) plus `cms_slot_block` wiring and per-store `cms_block_store`.
  - **Locales: the kept email blocks ship `en_US`/`de_DE` only.** With project locales other than those, run `duplicate-columns --from en_US --to <project locales>` on `cms_block.csv` — a REQUIRED step whenever the email blocks are kept, or every transactional email body silently falls back to English.
  - **Inherited: the shipped data has 55 email blocks but only 50 `cms_block_store.csv` rows** — 5 email blocks are unassigned to any store. This is harmless shipped data; do NOT spend time reconciling 55 vs 50 as if it were your own error.
- stripe → its `payment_method_store.csv` hardcodes `stripe,DE`/`stripe,AT` → install abort. **`vendor/` is absent pre-boot** (installed in-container during boot), so do NOT copy vendor files or hunt for them. Author only a project-local `data/import/common/stripe/payment_method_store.csv` (project stores) + a `stripe.yml` that references the vendor paths for the store-agnostic entities and the local file for `payment_method_store`, then repoint the recipe step. Full detail + the one-time key/column capture in the literal-sweep ref.

## Header-only (drop rows, keep header — importer treats 0 rows as success)
- Content emptied: `cms_page.csv`, `cms_slot_block.csv` (also drop any product-SKU-conditioned rows), `content_banner.csv`, `content_navigation.csv`, `navigation_node.csv`.
- Store-scoped catalog scaffolds (per store dir, header-only, exact demoshop headers; price files carry the store's currency columns): `product_abstract_store`, `product_price`, `product_price_schedule`, `product_label_store`, `product_relation_store`, `product_measurement_sales_unit_store`, `product_option_price`, `discount_store`, `discount_amount`, `shipment_price`, `sales_order_threshold`.

## Store relations to populate (per store, from a template store dir)
`category_store` (root only; children inherit), `cms_block_store` (the kept email blocks × store), `cms_page_store` (header-only, pages dropped), `payment_method_store`, `shipment_method_store`, `shipment_type_store`, `warehouse_store.csv` (entity `stock-store`). Detect the store column from each header (`store`, `store_name`, or `included_store_names`).

## Validate
The **authoritative structural keep-set is `../../spryker-import-tools/data/entity-map.yml` (rows with `class: structural`)** — the lists above are the high-frequency subset. Before boot, diff your fresh manifest against the shipped one so nothing structural is silently lost:
```bash
php "$VALIDATE" manifest-diff <shipped full_<SRC-REGION>.yml> data/import/local/full_<REGION>.yml --base .
```
Classify every `missing` entity as **intentional content drop** vs **must-keep structural** against the map's `class`, in writing; rank unknowns by row count (rows ≈ population → structural; rows ≪ population → garnish). **Do not boot on an unclassified diff.** The six structural entities NOT in the hand-lists above — `product-search-attribute`, `product-search-attribute-map`, `shipment-type-service-type`, `return-reason`, `merchant-oms-process`, `merchant-product-approval-status-default` — are `structural` in the map, so the diff keeps them. Then `php "$VALIDATE" known-set --base <repo-root>` confirms the map is still honest (no unclassified rows, no record counts).

## Optional
`gtc` T&C CMS page stub — recommended (the checkout T&C link soft-404s without it; not a boot blocker).

## Deliberately excluded
All product catalog data, merchants/offers, customers/companies, demo CMS/category/nav content. That's `project-data`'s territory (adapt strategy) if the project chose `adapt`.
