# Cleanup strategy (project-data)

> The cleanup-specific method — selectively remove whole demo domains. Shared tooling, command discipline, current-state reading, the apply/reset ladder, and the cross-cutting invariants live in the parent `../SKILL.md` — not repeated here.

The developer wants to **remove** chosen demo data and keep a working shop. This is the intent-based entry for cleanup — **ask what to remove in plain terms, never make them pick a skill name.** It routes each choice to the right engine and runs that domain's orphan cascade. Distinct from `adapt` (reshape demo data) and from project-start.

> **Boundary vs `curate-golive-data`:** cleanup **removes whole demo domains** (drop the customers, drop the merchants) — run it any time during setup/dev. `curate-golive-data` is the **final pre-launch pass** that makes the data you're **keeping** production-safe (real tax rates, licensed imagery, rotated demo passwords). Rule of thumb: *"drop the demo X"* → here; *"make X real for go-live"* → `curate-golive-data`.

## 1. Ask WHAT to remove — a plain domain menu (multi-select), not skill names

Present the domains actually present in `data/import/**` (scan; don't assume), in the developer's terms. Each is independent; some cascade into others (noted). Offer **"strip everything to bootable-minimum"** as one option too.

| Remove… | Drops (+cascade) | Engine |
|---|---|---|
| **Product catalog** — *all* or *keep a subset* | products/attributes/prices/stock/images/options/sets/relations/labels + reviews, offers on those SKUs, wishlist items, content lists | *subset* → **reduce** (`reduce.md`); *all-but-keep-other-domains* → this cascade |
| **CMS & navigation** | `cms_page`/`cms_block`(**keep `cms-block-email--*`**)/`content_*`/`navigation(_node)` | this skill |
| **Customers & B2B org** | `customer`/`company*`/roles/`budget`/`cost_center` → their carts, quotes, comments, wishlists, reviews, orders | this skill |
| **Merchants & offers** | `merchant*`/`product_offer`/`price_product_offer`/commissions/relationships/`merchant_stock` — dropping these removes the extra marketplace sellers; products keep their own price+stock and stay buyable. If a product was sold *only* through an offer (no product-level price/stock), it loses its price when its offers go — flag that in one line before removing. | this skill |
| **Discounts / vouchers** | `discount*` | this skill |
| **Reviews** | `product_review` | this skill |
| **Wishlists** | `shopping_list*` | this skill |
| **Transactional activity** | orders/carts/quotes/comments **and quote requests** (`quote_request*` — store-bound like orders, but the binding hides in the serialized `quote` JSON, not a store column) | this skill |
| **Services / SSP** | `service*`/`service_point*`/`ssp_*`/`product_offer_service` | this skill |
| **Configurable bundles** | `configurable_bundle_template*` (+ their image sets in `product_image.csv`) | this skill |
| **Product classes / configurations** | `product_class`/`product_to_product_class`; `product_configuration*` | this skill |
| **WEEE fees** | `weee_*` — only if present (not in the shipped tree; a project addition) | this skill |
| **Everything → bootable-minimum** | all of the above; keep only the structural set a shop needs to boot | **clean** (`clean.md`) |

## 2. Remove each chosen domain — with its cascade

For a domain with a dedicated strategy, follow it: **subset of the catalog → reduce (`reduce.md`)**; **strip-everything → clean (`clean.md`)**. Otherwise apply the cascade here:
1. **Identify the domain's files** by scanning headers/paths (don't trust a fixed list — the demoshop evolves).
2. **Remove the rows/files** (`csv filter`/`delete`, or drop the file + its `source:` from the import config; `rm` prompts — surface it).
3. **Reconcile dependents — nothing may reference a removed key.** For a catalog removal, `php "$VALIDATE" product-refs data/import/common --keep-from <product_abstract>:abstract_sku --keep-from <product_concrete>:concrete_sku` to zero orphans. For other domains, scan for files referencing the removed entity's key (e.g. remove customers → their carts/wishlists/orders/reviews; remove merchants → their offers/commissions/relationships/stock). Every removal is a cascade; a dangling reference aborts the import.

## 3. NEVER remove operational config (the shop must still boot)
Payment/shipment methods, tax, warehouse, currency/country/locale/store definitions, order thresholds (they carry runtime glossary keys), glossary, the `cms-block-email--*` blocks + `cms_*_template`, the root category. These are not "demo data" — they're what makes the shop operable.

## 4. Apply — pre-boot OR post-boot (this is not a pre-boot-only operation)
- **Pre-boot** (un-booted clone): edit the files; the first boot imports the cleaned set. Done.
- **Post-boot** (already booted — the common case): edit the files, then reflect it via the **reset ladder** (see `boot-and-verify` §3b for the full detail): first `docker/sdk console data:import -c <config>` to confirm the edits leave no orphan (fast, no teardown), then a DB drop via **`docker/sdk reset`** to actually remove the deleted rows (a plain `data:import` upserts and won't delete). **`reset` runs the install/rebuild → it needs a TTY: run `script -q .ai-dev/reset.log docker/sdk reset` in background, never a plain background shell; and check what `reset` does in this SDK first — in some it's a full destructive teardown (wipes volumes) as heavy as `clean-data`+`up`.** Then **drain the queues** + re-verify (the reset's install re-emits the events — no manual `publish:trigger-events`; that's only for an out-of-sync read model).

## Verify
The removed domains are gone; what remains is still consistent (per-store×locale content intact for kept data; `product-refs` clean; `validate paths` resolves); boot/reset green. Report what was removed and what stayed.

> Reshaping demo data is the **adapt** strategy (`adapt.md`); generating fresh themed data is **generate** (`generate.md`); go-live data curation (real tax/imagery/passwords) is `../curate-golive-data`. See the parent router.
