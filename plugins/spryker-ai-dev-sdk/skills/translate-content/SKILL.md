---
name: translate-content
description: "Use when actually translating a Spryker project's storefront content into a project locale — glossary and/or catalog/CMS/navigation/labels — the opt-in localization pass that project setup defers: 'localize uk_UA', 'translate the shop content into Polish', 'the storefront is still English in my locale'. Standalone on a project, or offered by the wizard after boot; strictly per-locale and opt-in."
---

# translate-content

Adapt-mode leaves every project locale as an **English copy** (fast boot, translation debt flagged). This skill is the opt-in that actually localizes a chosen locale. It is **never forced** — the default is English copies; the developer asks for it ("localize `uk_UA`").

You drive `spryker-import-tools`; you (and translator sub-agents) supply the translations. Invocation + command discipline: follow **`spryker-import-tools` → "Invocation & command discipline"** (the authoritative copy) — literal-path invocation from the project cwd (`$CSV`/`$VALIDATE` below = that path, substituted inline; never a shell variable, never `cd`), one op over many files in one command, no shell operators. Work from real files.

## Scope (developer chooses, per locale)

- **glossary only** — UI strings (fastest, covers most visible text), or
- **glossary + catalog content** — categories, products, CMS, navigation, labels, merchant profiles.

One target locale at a time (e.g. `uk_UA`). Keep `en_US` as the untouched source/fallback.

## Method — distinct → translate → map → apply (NOT whole-file rewrite)

The load-bearing efficiency + safety win: translate the **distinct values**, not every row. A column has far fewer distinct strings than rows, and `apply-translations` fans one map over all rows and files safely.

For each human-text column family `X.<locale>` (see targets below):
1. **Extract the distinct source values** — `php "$CSV" distinct <file>... --column X.en_US --plain` (multi-file in one call). This is the set to translate.
2. **Translate that set** — produce a `source,target` **CSV** map (a translator sub-agent writes it with the built-in Write tool; keep it CSV — no JSON intermediates). If the set is large, chunk the **distinct list** (not the files) across sub-agents; keep each agent's job small and re-runnable. Apply the preservation rules below.
   - **Verify map coverage with the tool, not by hand** — every distinct source value must have a map entry, or that value imports untranslated. Check with `php "$VALIDATE" refs <file> --column X.en_US --ref-file <map.csv> --ref-column source` — any finding is a source value missing from the map; extend the map and re-check. (This is a set-membership check; do **not** reinvent it in `python`/`jq` over JSON — `refs` already does exactly this.)
3. **Apply the map** — `php "$CSV" apply-translations <file>... --source-column X.en_US --target-column X.<locale> --map <map.csv> --in-place`. **Only `X.<locale>`'s values change** — no other column's values are altered, so it's safe by construction (this is why the tool exists — no fragile whole-file rewrite). The file is re-encoded canonically (quoting/line-endings normalized), so verify by *values* (spot-check the column), not a raw byte-diff. Unmapped values are left as-is (re-run after extending the map — idempotent).

Repeat per column family and per file group. Because apply is one command over many files, there is **no shell loop and no chunked file reassembly**.

## Targets — shape-driven, translate human text only

**Translate** every human-readable `.<locale>` column: `name`, `description`, `meta_title`/`meta_description`/`meta_keywords`, `title`, `content`, `alt_text`, `placeholder`/`placeholder.content` (incl. the `cms-block-email--*` bodies), glossary `translation`, **and BOTH product-attribute display families in `product_management_attribute.csv`:**
- **`key_translation.<locale>` — the attribute NAME/label shown on the PDP** ("Color", "Heat Recovery Output", "Body Material"). Easy to miss: if `key_translation.pl_PL` stays the English copy `"Color"`, the storefront shows English labels next to Polish values (`Heat Recovery Output: do 150 kW`). **It is display text, not a key — translate it.**
- **`value_translations.<locale>` — the attribute VALUES** (often a comma-list, e.g. `weiß, schwarz, grau`). Translate the human words; **preserve the list structure — same item count and order, commas intact** (it's positionally aligned with the machine `values` column).
- Per-product attribute **values** as `.<locale>` columns/JSON: `value_N.<locale>` on `product_abstract`/`product_concrete` — **but NOT** the paired `attribute_key_N.<locale>` (see the exception below).

**NEVER touch** (they are not human prose): the **unsuffixed** machine columns `key` and `values` in `product_management_attribute.csv` (those ARE the system codes — do NOT confuse them with the `.<locale>`-suffixed `key_translation`/`value_translations`, which you MUST translate); **`attribute_key_N.<locale>` on `product_abstract`/`product_concrete`** (the load-bearing exception below); `url.<locale>` (structural — owned by project-data adapt), `is_searchable.<locale>`, `css_class`, `*imageUrl`/`*link`/asset URLs, any bare key/SKU/FK, and **any other locale's columns** (only the one `X.<locale>` you target).

> **The rule is NOT the suffix — it is whether the value is a foreign key.** The earlier miss taught "translate every `.<locale>`-suffixed column," and that is right *most* of the time — but there is a load-bearing exception, and the suffix cannot detect it: `attribute_key_N.<locale>` on `product_abstract`/`product_concrete` is a **machine key** (its value equals the unsuffixed `attribute_key_N`, e.g. `material`/`size`, which must exist in `product_attribute_key.csv`) — translating it to `Materiał`/`Rozmiar` breaks the localized attributes map (keys must stay `{machine_key: value}`) and **500s the Back Office product-attribute page for every product**. The display label lives only in `product_management_attribute.csv` `key_translation.<locale>`. **Restated rule:** a `.<locale>`-suffixed column is translatable **unless its value must equal an identifier declared elsewhere** (a foreign key). `attribute_key_N.<locale>` is the named exception — leave it equal to the unsuffixed key; never translate it. (`key.<locale>` in `product_search_attribute.csv` and `key_translation.<locale>` in `product_management_attribute.csv`, despite the key-ish names, ARE display labels — translate those.)

## Preservation rules (bake into the translator prompt)

Keep intact, translating only the surrounding prose: `{{tokens}}` / `%s` / `%min%`-style placeholders, HTML markup + attributes (translate text nodes only), units and numbers, and brand / proper names. A translation that drops a token or breaks markup is a defect.

## Glossary

**Check the shape first — the SHIPPED glossary is not the shape this skill's mechanics assume.** The distinct→map→apply flow works on `X.en_US`→`X.<locale>` column pairs, but the shipped `glossary.csv` is `key,translation,locale` — ONE `translation` column with locale as a ROW value. Two shapes, two paths:
- **Per-locale files** (`glossary.<locale>.csv` — the shape `project-data` adapt produces): the target locale's file still holds the English copies, so translate it in place with a **same-column apply**. Extract the distinct source values, build the map, then apply it back onto the same column: `distinct glossary.en_US.csv --column translation` → build map → `apply-translations glossary.<locale>.csv --source-column translation --target-column translation --map …` (the map's `source` side carries the English values the copy still holds).
- **Shipped interleaved shape** (standalone invocation on an un-adapted repo): first split per locale exactly as `project-data` adapt step 1 does (`filter --where locale=en_US --out` → `set --column locale` per project locale, repoint the config, delete the interleaved original) — then proceed as above. Never map onto the interleaved file directly (every other locale's rows sit in the same column). Store/currency-templated keys (`sales-order-threshold.<type>.<store>.<cur>.message`) are generated by the currency step — translate their text too; do not invent keys.

**Path values in the glossary are locale-specific too — re-prefix them, don't translate and don't leave verbatim** (applies to both shapes above). A few glossary keys hold **navigational paths**, not prose (`checkout.success.to_orders.url`, `page.terms.url`, `page.imprint.url`, `page.privacy.url`, `main_slider_*.url`). A glossary file is per-locale, so a path value must carry **that locale's** language prefix: rewrite `/<source-lang>/…` → `/<target-lang>/…` (e.g. `/en/gtc` → `/nb/gtc` for `nb_NO`). Asset paths (`/assets/…`) are exempt — not locale-scoped. Check how the path is consumed rather than guessing the form: here every `generatePath()` caller passes an already-language-prefixed path and relies on the store-prefix plugin to prepend the store (`/nb/customer/order` → `/NO/nb/customer/order`), and some keys (`page.terms.url`, `page.privacy.url`) are used as a raw `href` with no `generatePath()` — both mean the language prefix must live in the data. **Gate:** for every per-locale `url` column and every locale-row path value, assert it starts with that locale's `/<lang>/` prefix — but exempt `/assets/` and require a `/<lang>/` word boundary, or the naive sweep false-positives on `product_image.csv` asset paths and on plain-text email bodies that open with `/////` ASCII art.

## Apply live (running project)

If the project is booted, make the translations visible: assemble a **temporary** import config listing only the localized entities, `docker/sdk console data:import --config=<temp>` (this emits the publish events), then **drain the queue workers** so read-model/search reflect it (no manual `publish:trigger-events` unless the read model is out of sync). **Delete the temp config immediately** — never leave stray `data/import/local/*.yml` artifacts. (Pre-boot use: just translate the CSVs; the first boot imports them — no live apply needed.)

## Verify

- `apply-translations` changes only the target column's values (the file is re-encoded canonically) — verify by spot-checking the column's values, not a raw byte-diff.
- Sanity: the target column now contains target-language text where mapped (`distinct` spot-check), source and other locales unchanged.
- **Attribute labels specifically:** spot-check `key_translation.<locale>` in `product_management_attribute.csv` — if it still equals the English copy (e.g. `"Color"`, `"Heat Recovery Output"`), the attribute-name family was skipped (the first-run bug). The PDP shows a translated value next to an English label until this column is translated too.
- On a running project: the storefront in `/<store>/<lang>` renders the translated strings after publish.

## Not here

The English-copy default (`project-data` adapt). URL localization (structural, `project-data` adapt). **The SEO half of localization is owned by NO skill in this plugin — flag it, don't let it silently not exist:** localized URL slugs (adapt only changes the language *prefix*; the slug stays English), sitemap regeneration, `hreflang` signals, robots — a "localized" store ships English slugs and no locale signals until the team addresses these. Name them in this skill's closing report whenever a locale is localized. Machine-translation quality is the translator's concern; this skill guarantees the *mechanics* are safe. Note a limit: the map is one target per distinct source string (whole-cell exact match), so context-dependent wording (same English string needing different translations by grammatical context) collapses to one — that's the translator's judgment to flag, not something the mechanics resolve.
