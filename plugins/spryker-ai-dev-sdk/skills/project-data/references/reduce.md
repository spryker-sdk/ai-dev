# Reduce strategy (project-data)

> The reduce-specific method — keep only part of the demo catalog, remove the rest without leaving an orphan. Shared tooling, command discipline, current-state reading, the apply/reset ladder, and the cross-cutting invariants live in the parent `../SKILL.md` — not repeated here.

The developer keeps only part of the demo catalog (e.g. "drop the office-supplies and transport categories — we sell heat-recovery systems"). Removal is **not** just deleting product rows: a Spryker catalog is a web of files that reference a product by SKU (prices, stock, images, options, relations, labels, offers, category assignments, approval status, measurement/packaging units, shipment types, …). **Every referencing row left pointing at a removed product aborts the import** — and a fresh-clone import runs 30–60 min, so a missed file is expensive. This skill makes removal a disciplined cascade, verified by a tool before boot.

**Do it pre-boot when you can.** In the wizard, this runs after the **adapt** strategy and **before** `boot-and-verify`, in files-mode: you edit the import CSVs, and the first boot imports the already-reduced catalog — **no reset, no teardown**. Only a change on an already-booted stack needs the reset ladder (see below). Removal *after* boot costs repeated resets — do it pre-boot.

You supply the judgment (which categories/products, which columns are real product refs).

## The method — keep-set → report the set → scan → prune → verify → (only then) boot

### 1. Fix the KEEP set (the source of truth)
Decide keep-vs-remove at the level the developer stated (usually **category**), then resolve it to the concrete product SKUs that survive:
- Filter the product spine to the kept products first: `product_abstract.csv` and `product_concrete.csv`. If removal is by category, first find the kept abstract SKUs from the category→product assignment (`product_category.csv` / category keys), then `php "$CSV" filter <product_abstract.csv> --in abstract_sku=<kept…>` (or `--in-file` for a large set) `--in-place`, and the same for concretes (keep concretes whose `abstract_sku` is kept).
- After this, **the two product files ARE the kept-set**: the distinct `abstract_sku` in `product_abstract.csv` ∪ the distinct `concrete_sku` in `product_concrete.csv` = every valid product token. The scanner reads them directly — you don't maintain a separate list.

### 1b. Report the resolved SET — before a single row is pruned
**The scope decision was already made and confirmed at interview time** (wizard §1 / questionnaire `C1`, where the branches were shown by name with product counts). This step **reports** the resolution; it does **not** re-ask. Do not stop mid-run over the size of the drop — if the developer asked for a handful of products, a handful of products is correct.

- **Render the resolved keep/drop tree by name with per-branch product counts** (`Office (n) → DROP`, `Heating & Energy (n) → KEEP`, plus the before → after total) as a one-line report, and **record it in `.ai-dev/decision-log.md`** with the revert path. Never act on a themed label ("only heating & energy") — act on the branches it resolved to, and log those.
- **Feature-coupling check — a category can own a shipped FEATURE, not just products.** Before removing a category, grep the deploy file and the install recipes for entry-points and build steps naming it (`*-configurator` applications, `frontend:*-configurator:build` steps) and check whether `product_configuration.csv` / `configurable_bundle_template*` reference its products. **Every hit goes into the report and the decision log** — the standard resolution is to drop the orphaned feature's endpoint and build steps along with the branch. Signature it prevents: a green boot still publishing the host of a configurator whose category is gone.

### 2. Broad orphan scan — discover EVERY referencing file (don't curate a list)
A hand-picked file list misses sku-bearing importers (`product_shipment_type`, `product_stock`, `product_measurement_base_unit`, `product_packaging_unit`, `product_abstract_approval_status`, and more) → a fresh boot-abort for each miss. **Use the scanner instead of guessing:**

```
php "$VALIDATE" product-refs data/import/common \
  --keep-from data/import/common/common/product_abstract.csv:abstract_sku \
  --keep-from data/import/common/common/product_concrete.csv:concrete_sku
```

It walks the whole tree, auto-discovers every product-ref column (the exact names `sku`/`abstract_sku`/`concrete_sku`/`product_sku`/`product`, **any header carrying `sku` as an underscore token** — the demoshop ships inverted/compound ones like `product_discontinued.csv` `sku_concrete`, `product_review.csv` `abstract_product_sku`, `product_alternative.csv` `alternative_product_{concrete,abstract}_sku`, all actively imported — and any `*_skus` list column; tune with `--pattern`/`--list-suffix`), and reports (a) a `columns` coverage summary — every discovered (file,column) with total vs orphan token counts — and (b) the orphan tokens themselves. **Classify the discovered columns with judgment:**
- A column that is **100 % orphan** is usually **not a product reference** — e.g. `product_option.sku` holds option codes, `product_relation.*` may key on a relation name. Confirm by eyeballing its values (`distinct --plain`) and **exclude it** (`--exclude-column <name>`) so it stops being flagged. Do not delete rows on a mis-classified column.
- **Exclude files not in the active manifest** (`--exclude <substr>`): dormant regions (`/US/`), `combined_product`, `*_internal`, and the CI trees (`robot`, `b2b_robot`, `b2b_common`) are not imported by the active `full_<REGION>.yml` — verify against that manifest (grep it) and exclude them, rather than pruning data no boot reads.

### 3. Prune each real referencing file — two rules that bite
For every column the scan confirms IS a product reference:
- **Row keyed DIRECTLY by a removed product → delete the row.** `php "$CSV" delete <files…> --in <col>=<removed…>` or, cleaner, keep-set style: `filter --in <col>=<kept…>` / `--in-file`. This covers prices, stock, images, category assignments, approval status, measurement/packaging units, shipment types, offers, etc.
- **List-valued assignment column (`abstract_product_skus`, `concrete_skus`, …) → prune the list, KEEP the row.** An assignment list going empty means "assigned to nothing", which is harmless — it must **NOT** drop the entity DEFINITION. The trap: emptying `product_option.csv` (its `abstract_product_skus` losing every kept product) while `product_option_price.csv` still references those options → `Product option SKU not found` → Aborted. The options (generic warranties/insurance, some belonging to a KEPT category) are rarely category-specific. **Keep every definition row; only prune its assignment list.**

### 4. Reconcile dependents (child cannot outlive parent)
For **every entity you reduce or empty**, find the files that reference *its* key — especially per-store children (`*_price`, `*_store`) — and reconcile them, so no child points at a removed parent:
- product → its prices/stock/images/etc. (step 3).
- product **option** → `product_option_price` (per store). If you (correctly) keep the option definitions, this resolves; if a project genuinely drops an option, drop its price rows too.
- Same shape for any parent-with-per-store-child you find. Derive it from the data — don't trust a fixed list.
Re-run the scanner after pruning; extend the `product-refs` check to any other key family the removal touched.

### 5. Verify clean BEFORE boot
`product-refs` returns **zero orphans** across the active-manifest files (excluded files/columns aside). Also `validate.php paths` still resolves (you didn't strip a file the manifest names). Only then hand off to `boot-and-verify` — a clean scan means the first boot imports the reduced catalog in one pass.

## Applying to a RUNNING project (standalone, not the wizard's pre-boot path)
See `boot-and-verify` for the full **data-iteration ladder**. In short, never full-teardown to test a removal:
1. Edit the CSVs, run the `product-refs` scan to zero orphans (above).
2. **Validate the manifest cheaply first:** `docker/sdk console data:import -c <config>` — it collects *all* importer failures and runs to the end, so one pass surfaces every remaining orphan (`Product with SKU … not found`, `Overall Import status: Failed`) **without** a reset. Fix, repeat — iterate to a clean import before spending a reset.
3. Once the import is clean, **reflect the deletions with `docker/sdk reset`** — it drops the DB and re-imports on the running stack (a plain `data:import` only upserts, so it will **not** delete already-imported removed products). Then **drain the queues** (see boot-and-verify's queue gate) and re-verify search per store — the reset re-emits events on import, so no manual `publish:trigger-events` unless the read model comes out of sync.
4. **Never `clean-data` + full `up`** for a data-only change — that tears down containers/volumes and rebuilds images/composer/frontend (~30–60 min). Reserve it for code/deploy/service changes.

## Verify
Catalog count dropped to the intended size (`columns --plain` rowCount on `product_abstract`/`product_concrete`); `product-refs` clean; each kept store's search still returns the surviving products per locale (per `boot-and-verify` step 4). Report what was removed and the residual count.

> Reshaping demo data to stores/locales/currencies is the **adapt** strategy (`adapt.md`); generating a themed catalog is **generate** (`generate.md`). This strategy only *reduces*.
