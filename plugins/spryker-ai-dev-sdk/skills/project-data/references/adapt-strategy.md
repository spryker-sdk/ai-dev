# Adapt-mode strategy map

How to re-shape each kind of column/row when adapting the demo catalog to new stores/locales/currencies. The scripts do the mechanics; this table is the judgment.

> **This map is a known set, not exhaustive.** It lists the file/column shapes seen in the demoshop at scan time. The demoshop evolves and may carry more. Match by **shape, not by name**: any `.<locale>` column is locale content; any store column is store-scoped; any currency+value column is a price surface. Scan `data/import/**` (`csv.php columns`) and apply the matching strategy row to whatever you find — the named files below must exist, but are not the complete list.

## Per column/row family

| Family | Strategy |
|---|---|
| `name` / `description` / `meta_*` / `placeholder` / `title` / `content` / `alt_text` `.<locale>` | **copy** en_US → each project locale (English content; translation debt, flagged) |
| **`is_searchable.<locale>`** | **copy the value, never blank** — blank imports as silently unsearchable. The worst silent failure. |
| **`url.<locale>`** | **transform, never copy** — duplicate then `replace` the language prefix per the 2-char scheme, then `validate unique`. **Apply to EVERY file with a `url.<locale>` column, found by scan — never a fixed list.** `spy_url.url` is globally unique, so any un-rewritten verbatim copy aborts the import. Files include `product_abstract`, `category`, `cms_page`, `navigation_node`, `product_set`, **`merchant.csv`** (a boot-blocker when missed — duplicate `/en/merchant/…` URLs collide), and possibly more — discover with `columns --plain` across `data/import/**`. |
| attribute pairs (`attribute_key_N` / `value_N` + locale variants) | copy; structural edits need the pairing + `product_attribute_key.csv` siblings |
| keys / SKUs / FKs (`abstract_sku`, `category_key`, `tax_set_name`, block/page keys) | **never touch** *as a locale/store/currency transform* — **but `tax_set_name`'s rate coverage per project country is a required adapt output**: every referenced set needs a `tax.csv` rate row for every project country, or the importer auto-creates it rateless and checkout falls back to the core 19% default (`adapt.md` step 3) |
| `locale` / `locale_name` row values | per file: `product_image` → real project-locale rows (NOT `locale=default`); `customer` / `product_review` → `locale_name` must ∈ project locales (both are carried as-is, just relocalized) |
| store columns (`store`, `store_name`, `included_store_names`) | generate per declared store (`csv set`); validate refs ⊆ declared stores |
| **country columns** (`country`, `iso2_code`, `country_name` — address files + `tax.csv`) | **generate per project country** (`csv set --column <country-col> --value <ISO2>`); validate refs ⊆ the project's `country_store.csv` set. A leftover demo country on an address file = **19% German tax at checkout** (`adapt.md` step 2) |
| currency columns + `price_data` volume JSON | generate for **assigned pairs only**; convert via rate_table incl. the JSON tiers; **convert (never blind-drop) an off-currency row** — see Prices |

## Two make-or-break chains

**1. Search visibility.** A product shows in a locale's search only if ALL hold: `name.<locale>` non-empty, **`is_searchable.<locale>` = yes** (not blank), a `product_abstract_store` row for the store, a renderable price for the store, and it's published. Plus `product_search_attribute` / `product_management_attribute` locale values, else facets/filters show English. Rule: copy-en is a **complete family copy**, never names only.

**2. Emails.** Transactional email bodies ARE locale columns — `cms_block.csv` `placeholder.content.<locale>` (+title/imageUrl/link) on the ~54 `cms-block-email--*` blocks. Missing locale family = broken mail (registration, order confirmation, password reset) in that locale. Copy-en → functional English mail, translatable later.

## Prices (currency dimension)

`product_price.csv` = row per (sku × price_type × store × currency), plus volume tiers embedded as JSON in `price_data.volume_prices`. Real scope = the **assigned** store↔currency pairs only (`currency_store.csv` is the prerequisite). Convert values via `rate_table` (verbatim copy makes prices absurd across magnitudes — €1,000 → 1,000 UAH is wrong); optional rounding to price points. Same dimension for offers, merchant-relationship prices, option prices, shipment prices, discounts, thresholds, schedules, commissions.

**Single-source seeding removes most currency mess — but the canonical dir is NOT single-currency, so verify, don't assume:** every store is seeded from the one canonical `common/DE` dir (EUR-dominant), and the differently-priced demo dirs (`US` net-only/USD/mixed, `AT`) are **deleted by `define-stores`** — so they never enter the pipeline. But the canonical dir itself carries off-currency rows (`common/DE/product_price_schedule.csv` opens with a **CHF** row), so a **required first step of the currency pass** is `distinct --column currency` on EVERY price surface of the canonical dir — enumerate the actual source currencies and give each its own `--rates` entry. **Any off-currency row: convert it — never blind-delete** (a "stray EUR/CHF row" can be a SKU's ONLY price; check the SKU keeps a kept-currency price first, else convert). See `adapt.md` step 3 for the authoritative convert-first + per-store price-completeness rule.

**Store/currency-templated glossary (runtime-blocker if missed).** See `adapt.md` step 3 — the authoritative rule (seed `sales-order-threshold.*.<store_lc>.<cur_lc>.message` per project store×currency, remove the demo `de/at/us × eur/chf/usd` keys; `merchant-relationship-threshold.*` is auto-generated, never seed it). Not repeated here.

## Constraints the interview must enforce

- **One locale per language per store:** the storefront URL segment is the 2-char language code, so two same-language locales (fr_CA + fr_FR) both map to `/store/fr` and the second is silently unreachable. Reject/warn unless the URL scheme is changed (out of scope).
- **Image locale rows** use real locale names, never `default`.

## Strip demo leftovers

The authoritative version is `adapt.md` step 5 (drop non-project `.<locale>` columns via `drop-columns`; remove demo threshold glossary keys; `absent`-sweep — **scoped to the active manifest bucket**, not blindly `data/import/**`, since CI/robot fixtures were never adapted). Not repeated here — the rationale is just that a finished project should contain only its own stores/locales/currencies.
