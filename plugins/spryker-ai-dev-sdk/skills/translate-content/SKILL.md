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

**There is no third tier — do not offer one.** "Key customer-facing areas (Recommended)", "the important bits" and similar are not a scope: no defined boundary, no record of what stayed English, no coverage check — and never the recommended option. If the developer insists on a partial pass it becomes a measured number: name the covered glossary key families literally (`checkout.*`, `cart.*`, `customer.*`, …), **count** the uncovered keys and write that count to go-live debt, and run the coverage check below (`refs … --ref-file <map>`) against the chosen families so "partial" has a denominator. Failure signature: a closing report that says "key areas translated" with no count.

## Step 0 — harvest the translations that ALREADY EXIST, before dispatching any translator

**No translator agent is launched until you have looked for the answer.** Search three places for the target locale and apply what you find **by key**, then translate only the remainder:

- **Other branches of this repository** — `git branch -a`, then `git show <branch>:<path>` / `git grep -l '<locale>' <branch> -- data/import` over the glossary and the localized CSVs. This source usually yields the most: a sibling branch often already carries real translations for most glossary keys, and finding them **after translator agents have been launched** wastes the whole dispatch and adds a reconciliation.
- **The vendor demo data** — shipped modules carry real translations for the common locales (in-container, once `vendor/` exists: `grep -rl '<locale>' vendor/*/*/data/import`).
- **The project's own other locales and files** — a key already translated for one file is the answer for the same key elsewhere.

Feed the hits into the `source,target` map unchanged (it is the same map shape §Method builds), verify it with the `refs` coverage check below, and size the translator work against **what the map still misses** — not against the whole distinct set.

## Method — distinct → translate → map → apply (NOT whole-file rewrite)

The efficiency and safety rule: translate the **distinct values**, not every row. A column has far fewer distinct strings than rows, and `apply-translations` fans one map over all rows and files safely.

For each human-text column family `X.<locale>` (see targets below):
1. **Extract the distinct source values** — `php "$CSV" distinct <file>... --column X.en_US --plain` (multi-file in one call). This is the set to translate.
2. **Translate that set** — produce a `source,target` **CSV** map (keep it CSV — no JSON intermediates). **The worker builds it with `$CSV`, never by hand** — see "What every translator prompt must carry" below; a worker told merely to "write CSV with the Write tool" hand-rolls RFC4180 quoting and then reaches for python to check its own work. If the set is large, chunk the **distinct list** (not the files) across sub-agents; keep each agent's job small and re-runnable. Apply the preservation rules below.
   - **Every concurrent sub-agent gets a private scratch space and one uniquely-named output.** Each writes exactly one output path it owns — `map.<locale>.<NN>.csv` — and keeps every intermediate in its own subdirectory, `work/<locale>/<NN>/`. Never a shared output directory with generic temp names: with many translators in one directory, two can independently pick `raw1.txt`, read back each other's content, and delete each other's files. Failure signature: two chunks whose output contains each other's language, or a chunk output that vanished mid-run.
   - **Script-family gate on the assembled map, BEFORE any apply** — a cross-contaminated map is perfectly valid input to `apply-translations`, so nothing downstream catches it. Per locale, assert the expected script is present and the wrong ones absent: Cyrillic MUST appear in `uk_UA` and MUST NOT appear in `es_ES`/`pl_PL`/`cs_CZ` — `grep -c "[а-яА-ЯёЁіїєґІЇЄҐ]" <map.csv>` (BSD `grep` has no `-P`; use the literal ranges). Zero where required, or non-zero where forbidden, means a chunk landed in the wrong file. Also assert the assembled map's row count equals the sum of the chunk row counts (`php "$CSV" count <the assembled map> <each chunk file, listed literally> --plain` — one call).
   - **Verify map coverage with the tool, not by hand** — every distinct source value must have a map entry, or that value imports untranslated. Check with `php "$VALIDATE" refs <file> --column X.en_US --ref-file <map.csv> --ref-column source` — any finding is a source value missing from the map; extend the map and re-check. (This is a set-membership check; do **not** reinvent it in `python`/`jq` over JSON — `refs` already does exactly this.)
3. **Apply the map** — `php "$CSV" apply-translations <file>... --source-column X.en_US --target-column X.<locale> --map <map.csv> --in-place`. **Only `X.<locale>`'s values change** — no other column's values are altered, so it's safe by construction (this is why the tool exists — no fragile whole-file rewrite). The file is re-encoded canonically (quoting/line-endings normalized), so verify by *values* (spot-check the column), not a raw byte-diff. Unmapped values are left as-is (re-run after extending the map — idempotent).

Repeat per column family and per file group. Because apply is one command over many files, there is **no shell loop and no chunked file reassembly**.

## Targets — shape-driven, translate human text only

**Translate** every human-readable `.<locale>` column: `name`, `description`, `meta_title`/`meta_description`/`meta_keywords`, `title`, `content`, `alt_text`, `placeholder`/`placeholder.content` (incl. the `cms-block-email--*` bodies), glossary `translation`, **and BOTH product-attribute display families in `product_management_attribute.csv`:**
- **`key_translation.<locale>` — the attribute NAME/label shown on the PDP** ("Color", "Heat Recovery Output", "Body Material"). Easy to miss: if `key_translation.pl_PL` stays the English copy `"Color"`, the storefront shows English labels next to Polish values (`Rated Output: up to 150 kW`). **It is display text, not a key — translate it.**
- **`value_translations.<locale>` — the attribute VALUES** (often a comma-list, e.g. `weiß, schwarz, grau`). Translate the human words; **preserve the list structure — same item count and order, commas intact** (it's positionally aligned with the machine `values` column).
- Per-product attribute **values** as `.<locale>` columns/JSON: `value_N.<locale>` on `product_abstract`/`product_concrete` — **but NOT** the paired `attribute_key_N.<locale>` (see the exception below).

**NEVER touch** (they are not human prose): the **unsuffixed** machine columns `key` and `values` in `product_management_attribute.csv` (those ARE the system codes — do NOT confuse them with the `.<locale>`-suffixed `key_translation`/`value_translations`, which you MUST translate); **`attribute_key_N.<locale>` on `product_abstract`/`product_concrete`** (the load-bearing exception below); `is_searchable.<locale>`, `css_class`, `*imageUrl`/`*link`/asset URLs, any bare key/SKU/FK, and **any other locale's columns** (only the one `X.<locale>` you target).

**`url.<locale>` — never *translate* it, but it is NOT untouchable: two cases require you to RE-POINT it.** A slug is never machine-translated by this skill (slug structure is `project-data` adapt's business), but two triggers require a write. The sanctioned writes, both mechanical and both mandatory when their trigger fires: (a) **after translating a category `name`** — Spryker regenerates the URL from the localized name, so every stored reference to the old slug must be rebuilt from `spy_url_redirect` (§ *Category names regenerate URLs* below); (b) **before pointing a glossary path value at a CMS page** — a page with an empty `url.<locale>` must be localized first, which means writing `url.<locale>` in `cms_page.csv` (§ *Glossary*, path precondition). Outside those two triggers, leave the family alone.

> **The rule is NOT the suffix — it is whether the value is a foreign key.** Translating every `.<locale>`-suffixed column is right *most* of the time — but there is a load-bearing exception, and the suffix cannot detect it: `attribute_key_N.<locale>` on `product_abstract`/`product_concrete` is a **machine key** (its value equals the unsuffixed `attribute_key_N`, e.g. `material`/`size`, which must exist in `product_attribute_key.csv`) — translating it to `Materiał`/`Rozmiar` breaks the localized attributes map (keys must stay `{machine_key: value}`) and **500s the Back Office product-attribute page for every product**. The display label lives only in `product_management_attribute.csv` `key_translation.<locale>`. **Restated rule:** a `.<locale>`-suffixed column is translatable **unless its value must equal an identifier declared elsewhere** (a foreign key). `attribute_key_N.<locale>` is the named exception — leave it equal to the unsuffixed key; never translate it. (`key.<locale>` in `product_search_attribute.csv` and `key_translation.<locale>` in `product_management_attribute.csv`, despite the key-ish names, ARE display labels — translate those.)

**Completion is per FILE, not per column family — name the files or the sweep stops at the glossary.** The families above say *what* to translate and never say *where*, and a finished glossary leaves **the other link-bearing files still English** unless they are named. A locale is done only when all four are consistent for it — **label AND URL columns both**:
- `glossary.<locale>.csv` — `translation`, including the path values (§ Glossary).
- `navigation_node.csv` — `title.<locale>` **and** `url.<locale>`. Typical miss: English titles and `/en/` URLs left in the target-locale columns of the footer legal and "Sell on Spryker" nodes.
- `content_banner.csv` — `title`, `subtitle`, `click_url`, `imageUrl`, `altText`, **per locale**. Typical miss: `click_url.<locale>` left empty, which falls back silently to `/en/demo-landing-page`.
- `cms_page.csv` — `name.<locale>` **and** `url.<locale>`. The shipped file is `en_US`-only, so every localized footer legal link 404s until the page is localized (§ Glossary, path precondition).

**`project-data`'s catalog/category localization does not cover any of these** — it localizes catalog and category nodes only. Don't infer coverage from "adapt already localized things."

**`content_banner`'s per-locale column set is all-or-nothing.** Touching any single `X.<locale>` flips that locale bucket from absent to present, and the importer then rejects the row for the remaining blanks — filling only `click_url.it_IT` fails with `This value should not be blank`. Fill the complete set (`title`, `subtitle`, `click_url`, `imageUrl`, `altText`) or leave the locale entirely absent. Same shape as the `product-concrete` localized-attribute rule, which is the general case (`../project-data/references/generate.md`, C9).

## What every translator prompt must carry

A sub-agent inherits the work and **none** of the discipline. It loads no skill, no project
`CLAUDE.md` and none of this session's conventions, so every rule below exists for that worker only
if you wrote it into the prompt.

> A parent that uses the named tools consistently still loses that discipline at the **dispatch** if
> the prompt omits it. For example, a `general-purpose` worker told to "hand-write RFC4180 CSV with
> the Write tool" hand-rolls quoting, reaches for python to verify it, and produces both malformed
> data and a stream of permission prompts.

During a wizard run (a `.ai-dev/project-setup.md` or `.ai-dev/demo-prep.md` exists), `guard-agent.php` denies a dispatch that misses the first two; outside a run nothing checks it. Paste this block into every one:

```
TOOLING — do not hand-write CSV, and do not use python, awk or a heredoc to build or check it:
  create    the file must EXIST first: Write it with exactly one line — source,target
            (`add-row` on a missing file returns `csv_read: cannot open '<file>'`)
  build     php <literal path>/csv.php add-row <out.csv> --set source=... --set target=...
  bulk      php <literal path>/csv.php append <out.csv> --from <rows.csv>
  verify    php <literal path>/csv.php count <out.csv> --plain
            php <literal path>/csv.php columns <out.csv> --plain
SKILL — per `translate-content` (follow its rules below): <paste the preserve-list,
  the placeholder-parity rule and the script-family rule literally — the worker cannot read them>.
OUTPUT — exactly one file you own: map.<locale>.<NN>.csv. Scratch goes in work/<locale>/<NN>/.
```

**"Stopped by the user" on a worker means a denied tool call, not a cancellation.** A DENIED tool call
inside a sub-agent surfaces to you as `Agent "…" was stopped by user` — the notification cannot tell
a cancelled task from a denied command. Re-dispatch it with tools inside the allowlist (replacing the
shell assembly usually resolves it), or record the chunk as blocked and carry on. Never tell
the developer they stopped your work: the message does not mean the developer withdrew the
instruction.

## Preservation rules (bake into the translator prompt)

Keep intact, translating only the surrounding prose: `{{tokens}}` / `%s` / `%min%`-style placeholders, HTML markup + attributes (translate text nodes only), units and numbers, and brand / proper names. A translation that drops a token or breaks markup is a defect.

**Decide the preserve-list ONCE, up front, before any chunk is dispatched — and pass the same list to every worker and every target locale.** Enumerate it literally: brands, proper names, product codes, provider names. Left to each chunk worker's judgment the classification *splits* — for example, `checkout.payment.provider.DummyMarketplacePayment` translated in ES ("Pago de marketplace de prueba") and left English in IT. The coverage check cannot catch this — it is set membership, and both answers are members. Failure signature: the same source string verbatim in one locale's map and translated in another's (checked in § Verify).

## Glossary

**Check the shape first — the SHIPPED glossary is not the shape this skill's mechanics assume.** The distinct→map→apply flow works on `X.en_US`→`X.<locale>` column pairs, but the shipped `glossary.csv` is `key,translation,locale` — ONE `translation` column with locale as a ROW value. Two shapes, two paths:
- **Per-locale files** (`glossary.<locale>.csv` — the shape `project-data` adapt produces): the target locale's file still holds the English copies, so translate it in place with a **same-column apply**. Extract the distinct source values, build the map, then apply it back onto the same column: `distinct glossary.en_US.csv --column translation` → build map → `apply-translations glossary.<locale>.csv --source-column translation --target-column translation --map …` (the map's `source` side carries the English values the copy still holds).
- **Shipped interleaved shape** (standalone invocation on an un-adapted repo): first split per locale exactly as `project-data` adapt step 1 does (`filter --where locale=en_US --out` → `set --column locale` per project locale, repoint the config, delete the interleaved original) — then proceed as above. Never map onto the interleaved file directly (every other locale's rows sit in the same column). Store/currency-templated keys (`sales-order-threshold.<type>.<store>.<cur>.message`) are generated by the currency step — translate their text too; do not invent keys.

**Path values in the glossary are locale-specific too — re-prefix them, don't translate and don't leave verbatim** (applies to both shapes above). A few glossary keys hold **navigational paths**, not prose (`checkout.success.to_orders.url`, `page.terms.url`, `page.imprint.url`, `page.privacy.url`, `main_slider_*.url`). A glossary file is per-locale, so a path value must carry **that locale's** language prefix: rewrite `/<source-lang>/…` → `/<target-lang>/…` (e.g. `/en/gtc` → `/nb/gtc` for `nb_NO`). Asset paths (`/assets/…`) are exempt — not locale-scoped. Check how the path is consumed rather than guessing the form: here every `generatePath()` caller passes an already-language-prefixed path and relies on the store-prefix plugin to prepend the store (`/nb/customer/order` → `/NO/nb/customer/order`), and some keys (`page.terms.url`, `page.privacy.url`) are used as a raw `href` with no `generatePath()` — both mean the language prefix must live in the data. **Gate:** for every per-locale `url` column and every locale-row path value, assert it starts with that locale's `/<lang>/` prefix — but exempt `/assets/` and require a `/<lang>/` word boundary, or the naive sweep false-positives on `product_image.csv` asset paths and on plain-text email bodies that open with `/////` ASCII art.

**The rewrite is CONDITIONAL — the target must exist in that locale. The Gate above checks the path's *form*, never its *referent*, so a well-formed 404 passes it.** Rewriting `page.imprint.url` / `page.terms.url` / `page.privacy.url` to `/it/…` and `/es/…` produces 404s while `cms_page.csv` carries `en_US`-only columns, because those pages then exist in no other locale. Before rewriting a path value:
- **Assert the locale is actually served by the store** (`locale_store.csv`) — and check the same for any `/en/` link you were about to treat as a working fallback. A store with **no `en_US` locale at all** (for example, an ES-only store) has footer legal links pointing at an unserved locale before anything is rewritten.
- **Assert the referent exists** — for a CMS-page path, a populated `url.<locale>` in `cms_page.csv`: filter the path keys out first (`php "$CSV" filter glossary.<locale>.csv --where key=.url --match contains --out paths.csv`), then `php "$VALIDATE" refs paths.csv --column translation --ref-file cms_page.csv --ref-column url.<locale>`. Every finding is a path with nothing behind it in that locale — either a CMS page to localize, or a non-CMS route (`checkout.success.to_orders.url`, `main_slider_*.url`) that must be confirmed as a served route by hand.
- **If the page is missing in that locale, localize the CMS page — do not rewrite into a 404.** `php "$CSV" duplicate-columns cms_page.csv --from en_US --to <locale> --in-place`, translate `name.<locale>` and content, then set `url.<locale>`. This is one of the two sanctioned writes to `url.<locale>` (see § Targets).

## Category names regenerate URLs — the post-apply URL rebuild (catalog scope, mandatory)

**Translating a category `name` makes Spryker regenerate that category's URL from the localized name.** For example, re-importing `category.csv` with translated names turns `/es/components-and-accessories` into `/es/componentes-y-accesorios` and converts the old slug into a 301 — while every stored reference (navigation nodes, CMS links, banners) still points at the old slug. Failure signature: `Overall Import status: Successful`, queues drained clean, preflight clean, and **storefront navigation 404s on every category link**. **The 301 does not act as a fallback:** its `to_url` drops the DMS store prefix, so following it lands on the 404 page. So whenever the catalog scope touches `name.<locale>` on `category.csv`, this rebuild is part of the job, not an optional follow-up:

1. **Harvest the authoritative old→new map from the redirects the import just created** — `SELECT u.url, r.to_url … FROM spy_url_redirect r … WHERE locale = <the target locale>`. That result *is* the map (old slug → new slug); nothing else knows it. Route the SQL and confirm the columns per `../boot-and-verify/references/verify-recipes.md` (`docker/sdk cli "mariadb …"`; `DESCRIBE` before writing the query — don't guess column names). Write it as a `source,target` CSV.
2. **Re-point every stored reference with a same-column apply** — exactly the glossary pattern: `php "$CSV" apply-translations <every file with a url/link column for that locale — navigation_node.csv, cms_page.csv, content_banner.csv, category.csv> --source-column url.<loc> --target-column url.<loc> --map <urlmap.csv> --in-place`. **Safe by construction:** `apply-translations` leaves unmapped values as-is, so merchant pages, CMS pages, `/new`, `/outlet` and search URLs are untouched — which is why the same-column apply is safe even on files that are mostly non-category links.
3. **Re-import, drain, then re-crawl the navigation** — assert every category link in the rendered nav returns **200, not 301**. A 301 means a reference was missed; the redirect will 404 for the user.

## Apply live (running project)

If the project is booted, make the translations visible: assemble a **temporary** import config listing only the localized entities, `docker/sdk console data:import --config=<temp>` (this emits the publish events), then **drain the queue workers** so read-model/search reflect it. **Delete the temp config immediately** — never leave stray `data/import/local/*.yml` artifacts. (Pre-boot use: just translate the CSVs; the first boot imports them — no live apply needed.)


**Rung check before that import.** `project-data` forbids rung 1 (a plain `data:import`) only
after a **value** change on `product-price`, `product-price-schedule`, `discount-amount`, and
`cms-block` when the template changes — those importers look an existing row up by its values, so a
changed value adds a row or trips a unique constraint instead of updating in place. This skill
changes `placeholder`/`placeholder.content` text (including the `cms-block-email--*` bodies) on the
same template, which `cms-block` updates in place, so a CMS translation stays on rung 2 (the same
`data:import -c <config>` applied for real). `*_store` relation rows never duplicate; removing one
needs a `reset` or a relation-row delete (`project-data` → add-only importers).

**Post-drain gate — "import succeeded + queues drained" is not evidence the read model updated. Prove it per locale, by reading KV.** When several locales are imported in one `data:import` and the queues drain to zero, one locale can still fail to publish: its `kv:translation:<loc>:*` entries keep the **boot-time** `_timestamp` and English values, so that storefront renders English UI ("Sign in") beside correctly translated category names while the DB is correct. Only a KV read detects it. A manual `publish:trigger-events` is for a read model that is out of sync; this is the check that shows it is:
- Pick one key you actually changed. Read `kv:translation:<locale_lower>:<key_lower>` for **every** locale you imported, and compare `_timestamp` and `value` **across locales** — not for one locale in isolation. Sampling a single locale proves nothing about the others: three of four locales can publish while the fourth keeps its boot-time values.
- **BOTH the locale AND the key are lowercased in the KV key.** `checkout.payment.provider.DummyPayment` for `en_GB` is stored as `kv:translation:en_gb:checkout.payment.provider.dummypayment`; reading `…:en_gb:checkout.payment.provider.DummyPayment` returns nothing and reads as MISSING. A mixed-case lookup reports present keys as missing — even one the storefront visibly renders (`DummyPaymentCreditCard`). **Before concluding a key is missing, scan for it:** `docker exec <ns>_key_value_store_1 valkey-cli -n <N> --scan --pattern 'kv:translation:<loc>:*<lowercased fragment>*'`. **A "missing" key the storefront visibly renders means the lookup is wrong, not the data** — stop and fix the lookup before touching the import. Other `kv:` families may be case-normalised the same way; scan before asserting absence on any of them.
- A locale whose sample still shows the **pre-import** timestamp (or the English value) did not publish → `docker/sdk console publish:trigger-events -r translation`, drain again, re-read the same keys.
- Same gate, same way, for `navigation`, `category_node` and `url` after the URL rebuild above (`-r <that resource>`).
- Route the KV read per `../boot-and-verify/references/verify-recipes.md`: the client lives in the **KV container** (`docker exec <ns>_key_value_store_1 valkey-cli -n <N> …`, not `docker/sdk cli`), and `-n` must be the project's **numbered** Redis DB from the deploy `key_value_store` config — `db 0` is usually empty and reads as missing data.

## Verify

- **DONE = a coverage sweep, never a hand-picked column list.** The locale is finished only when **no target-locale cell is still identical to its source cell**: `php "$VALIDATE" translation-coverage --from <source locale> --to <target locale> <manifest>` → zero findings, or every remaining finding named as a decided preserve-list entry. Naming the columns you checked is not coverage: it reports "translation complete" while product descriptions and facet values are still in the source language.


> **`translation-coverage` is blind to a per-locale glossary, and returns `ok` when it is.** It
> compares `<base>.<from>` / `<base>.<to>` **columns inside one file**. The glossary this plugin
> produces is one file per locale (`glossary.en_US.csv`, `glossary.pl_PL.csv`, shape
> `key,translation,locale`), so there is no such column pair and the check compares nothing:
>
> ```
> translation-coverage --from en_US --to pl_PL  →  "status":"ok", findingCount 0, columnsCompared 0, exit 0
> ```
>
> — even when every `translation` cell is byte-identical English. That is the defect this check exists to catch.
>
> **So: read `columnsCompared` before `findingCount`. `columnsCompared: 0` means the check did not
> run — it is not a pass.** For the glossary, the real check is a value diff between the two locale
> files: `php "$CSV" distinct glossary.<locale>.csv --column translation` against the same for
> `en_US`, and any key whose target value is byte-identical to the source is untranslated unless it
> is on the preserve-list.

- `apply-translations` changes only the target column's values (the file is re-encoded canonically) — verify by spot-checking the column's values, not a raw byte-diff.
- Sanity: the target column now contains target-language text where mapped (`distinct` spot-check), source and other locales unchanged.
- **Attribute labels specifically:** spot-check `key_translation.<locale>` in `product_management_attribute.csv` — if it still equals the English copy (e.g. `"Color"`, `"Heat Recovery Output"`), the attribute-name family was skipped. The PDP shows a translated value next to an English label until this column is translated too.
- On a running project: the storefront in `/<store>/<lang>` renders the translated strings after publish.
- **Cross-locale preserve consistency** — for each source string, compare across the per-locale maps whether it was left **verbatim** in some locales and changed in others, and report every disagreement. A source whose `target == source` in one map and `target != source` in another is an unresolved brand/proper-name classification (for example, `DummyMarketplacePayment` translated in ES and English in IT). The goal is **not** to force one answer — it is to make the split visible so it is decided once, in the shared preserve-list. The coverage check cannot see this; it is set membership and both answers are members.
- **Untranslatable-by-construction strings — sweep the SOURCE, not only the data.** This skill's completeness model is entirely data-side, so a template that never consults the glossary is invisible to it — and because the fallback is fluent English, a raw-key scan misses it too. Where a template passes the English *sentence* as the glossary key, the translator echoes the key: "Add asset", "Add comment", "Show details", "You can unsubscribe at any time." render English on a fully translated page with no raw keys anywhere (the demoshop carries dozens of these across `src/Pyz/`, about half customer-facing). Sweep: `grep -rnoE "'[A-Z][^']{4,80}'\s*\|\s*trans" src/`. Report **every** hit as **untranslatable-by-construction** — glossary changes alone cannot fix it (it needs a template change plus a glossary row), so it must be **reported, never silently left**. Refinement: where an existing glossary key already carries that same sentence as its *value*, propose that key as the swap-in. Prevention lives in `yves-atomic-frontend`.
- **Shipment-type names are one global string. They need glossary rows and a template `| trans`.** `shipment_type.csv` `name` has no `.<locale>` column (the importer reads a single `name`), so "Delivery / Store pickup" is the same text in every store. Two surfaces render it:
  - **Cart/checkout group headers** already translate `'shipment_type.name.shipment_type_' ~ <shipment-type key>` (`SelfServicePortal` `list-items-by-shipment-type.twig`, `address-form-items-by-shipment-type.twig`). The core glossary ships that key only for `delivery`, `pickup`, `in-center-service` and `on-site-service`, in en_US/de_DE. Add a row in every project locale's glossary file for **each** shipment-type key the manifest imports, new keys included.
  - **The PDP shipment-type selector** prints the raw name (`service-point-shipment-types.twig` `label: shipmentType.label`, which `ShipmentTypeOptionsProvider` fills from the storage `name`). The option carries no shipment-type key, so translating it needs a template change: `| trans` on a key derived from the name, plus a glossary row per locale. Report it as untranslatable-by-construction and hand the template to `spryker-customization` / `yves-atomic-frontend`.
  - The same shape applies to any display column without a `.<locale>` suffix: before calling a locale done, check how its template renders it.

## Not here

The English-copy default (`project-data` adapt). URL localization (structural, `project-data` adapt). **The SEO half of localization is owned by no skill in this plugin — flag it:** localized URL slugs — **note that translating a category `name` regenerates its slug** from the localized name, which is why the URL rebuild above exists. Adapt changes only the prefix; a catalog-scope translation additionally produces localized **category** slugs (and owes the rebuild), while product, CMS and merchant slugs do stay English. Still unowned: sitemap regeneration, `hreflang` signals, robots — a "localized" store ships English slugs and no locale signals until the team addresses these. Name them in this skill's closing report whenever a locale is localized. Machine-translation quality is the translator's concern; this skill guarantees the *mechanics* are safe. Note a limit: the map is one target per distinct source string (whole-cell exact match), so context-dependent wording (same English string needing different translations by grammatical context) collapses to one — that's the translator's judgment to flag, not something the mechanics resolve.
