# Demo-shop facts — the identifiers, and how to re-derive each

Every concrete identifier a skill relies on lives here **with the command that re-derives it**, so a
skill links to the value instead of copy-pasting a literal that rots silently. Values measured
against this clone (`b2b-demo-marketplace`, master @ `528e7c7e85`, 2026-08-07).

**Rules for this file (enforced by `php "$VALIDATE" known-set`):**
- It records **names you can look up** (a SKU, a key, a column, a label) — never a **record count**.
  A count is the one literal with no maintainable home; state the command that measures it instead.
- Every identifier is paired with its re-derive command. If a value here no longer re-derives, the
  demo data drifted — fix the value, don't delete the command.

Commands assume `CSV="skills/spryker-import-tools/scripts/csv.php"` and that you `cd` to the
directory holding the CSV (the demo ships each file under several `data/import/**` buckets).

---

## skus

A shipped abstract → concrete pair (the concrete is what `/cart/add/{sku}` needs — the abstract 404s):

- abstract `C2235` → concrete `1001454`

Re-derive: `php "$CSV" distinct product_concrete.csv --column concrete_sku --plain | head`
(the file's first two columns are `abstract_sku,concrete_sku` — read a row to get a real pair).

---

## cms-blocks

The transactional-email blocks are matched by a **prefix on the `block_key` column** — the file has
**no `name` column** (its columns are `block_key,block_name,template_name,template_path,active,placeholder.*`):

- prefix: `cms-block-email--`
- column: `block_key` (matching on `name` wipes every block — a real data-loss bug)

Re-derive: `php "$CSV" distinct cms_block.csv --column block_key --plain`

---

## product-labels

`product_label.csv` ships these labels in its **`name`** column (first column):

- `TOP`, `NEW`, `SALE`, `Discontinued`, `Alternatives available`, `Service`, `Scheduled`, `Spare parts`

Only **four** are wired to updater plugins in `src/Pyz/Zed/ProductLabel/ProductLabelDependencyProvider.php`
(`getProductLabelRelationUpdaterPlugins()`):

| Label | Updater plugin |
|---|---|
| `NEW` | `ProductNewLabelUpdaterPlugin` |
| `SALE` | `ExampleProductSalePageLabelUpdaterPlugin` |
| `Alternatives available` | `ProductAlternativeLabelUpdaterPlugin` |
| `Discontinued` | `ProductDiscontinuedLabelUpdaterPlugin` |

`TOP`, `Service`, `Scheduled`, `Spare parts` have **no updater plugin** — unwired demo labels, safe to
drop. Which of the wired four must be present in the CSV vs. self-install is a separate concern owned
by `define-stores/references/minimal-baseline.md` (there: `NEW` + `SALE` have no self-installer, so a
missing row aborts the install).

Re-derive: `php "$CSV" distinct product_label.csv --column name --plain`, then cross-read the
DependencyProvider for the wiring.

---

## navigation

`navigation.csv` container keys live in the **`key`** column (`key` is a reserved word — backtick it
in SQL). The nodes themselves are `navigation_node.csv` (content). Shipped containers:

- `MAIN_NAVIGATION`, `FOOTER_NAVIGATION`, `FOOTER_NAVIGATION_TOP_CATEGORIES`,
  `FOOTER_NAVIGATION_POPULAR_BRANDS`, `PAYMENT_PROVIDERS`, `SHIPMENT_PROVIDERS`, `SOCIAL_LINKS`,
  `PARTNERS`

Re-derive: `php "$CSV" distinct navigation.csv --column key --plain`

---

## manifests

The shipped import manifests, and the key to diff them on:

- families: `full_<REGION>.yml`, `b2b_full_<REGION>.yml`, `store_<REGION>.yml` under `data/import/local/`
- regions present: `EU`, `US`, `ROBOT` (not every family exists for every region — list the dir)
- the key to diff on is **`data_entity`** (an entity's source *filename* often differs from its name —
  that mapping is `skills/spryker-import-tools/data/entity-map.yml`)

Re-derive the entity list: `grep -hoE "data_entity: *[a-z-]+" data/import/local/full_<REGION>.yml | sort -u`

---

## colours

The `shop_ui` brand-colour setting keys (Back-office → Theme → Storefront), from
`data/configuration/shop_ui.configuration.yml` under the `colors` group:

- `background_brand_primary`, `background_brand_subtle`, `background_brand_hover`,
  `background_brand_pressed`, `background_highlight`, `border_brand`, `icon_brand`, `text_brand`,
  `focus_ring`, `shadows_focus_color`

Re-derive: read `data/configuration/shop_ui.configuration.yml` (the setting `key:` entries under the
`colors` group).

---

## kv-keys

Key-value read-model keys are templates, not fixed values — the id/store segments vary per row:

- `kv:price_product_abstract:<store>:<id>`

This is the model already written in `skills/boot-and-verify/references/verify-recipes.md`; it is a
**template**, not a looked-up value. Derive a concrete key at runtime from the KV container (see the
verify recipes) — do not hard-code a specific id here.
