---
name: demo-run-sheet
description: >
  Use to produce the sheet a salesperson presents from on a deployed demo environment — which URL,
  which login, which product, which filter, in which order. Triggers: "demo cheat sheet", "run sheet",
  "what do I show", "give me the links and logins for the demo", "prepare for the customer call".
  Every host, URL, login, price, stock figure and facet is read from the running system and tested
  before it ships; the output format is asked once and defaults to paste-ready, Google-Docs-safe HTML,
  never a markdown file. The last phase of `demo-prep-wizard`.
---

# demo-run-sheet

The last step of a demo build. The deliverable is the document a **salesperson** opens on a second
screen while presenting: hosts, logins, product URLs, category URLs, the filters that actually work,
the beats in the order the brief asks for them, and the traps to stay away from on stage. It is not a
handover doc, a status report, or a list of what was built.

Two rules govern everything below:

> **Rule 1 — the deliverable is judged by whether it pastes** into the target document, not by
> whether it reads correctly in the terminal. An accurate sheet delivered as markdown does not meet
> this rule. (see `## Pitfalls`)
>
> **Rule 2 — nothing on the sheet is composed, inferred or remembered. Every line is read from the
> running system, and every line is tested before it ships.** A URL assembled from a category name
> is usually wrong, so no URL is composed.

In a demo run, "the person" below is usually a demo preparer, not an engineer (`demo-prep-wizard` → "Who runs this"): plain words, your recommended option first, and every technical judgement yours.

## 0. Ask the output format — once, first, before writing a single line

**If `.ai-dev/demo-prep.md` records `up_front.run_sheet_format`, the question was already asked in the wizard's one sitting — use that answer and do not ask again.** Otherwise: one question, four options, asked **before** the research and never repeated:

1. **An existing document** (Google Doc / Confluence page) that the person will paste into —
   token `html`. Its structure is never asked: the default HTML keeps headings, tables and links
   through the paste.
2. **A fresh document** they want created — token `doc`.
3. **A chat message** (they will read it here and copy fragments) — token `chat`.
4. **A wiki page typed as plain text with formatting marks** (a GitHub/GitLab-style wiki, not a
   rich-text editor) — token `markdown`; the only case where markdown is correct.

**Whatever the format, a local copy is always written:** `html` → `.ai-dev/demo-run-sheet.html` +
`.txt`; `doc` → `.html` plus the connector document (extra, never instead); `chat` → `.txt`;
`markdown` → `.md`. The wizard marks the step `done` only once one of these exists.

**Default when the question is not answered, or is answered vaguely ("just give it to me"):**

- Write **Google-Docs-safe HTML** to a file (`.ai-dev/demo-run-sheet.html`) and **open it in the
  browser** so the person does <kbd>select-all</kbd> → copy → paste. Headings stay headings,
  tables stay tables, links stay clickable. The HTML rules (which tags survive a Docs paste, which
  silently do not) are in `references/output-format.md` — follow it rather than hand-rolling markup.
- **Plus a plain-text fallback** (`.ai-dev/demo-run-sheet.txt`) in the same run, for chat/Slack.

**Hard rules on the artefact:**

- **Never a markdown file and never a markdown table** unless the format is `markdown` (option 4). A
  markdown table pasted into a document arrives as rows of pipe characters; `## Products` pasted into
  a document is the literal text `## Products`.
- **Never answer a format complaint with the same content in a new markdown file.** If the person
  says the formatting is wrong, the *next* artefact changes format, not wording.
- **Never print the sheet into chat as the deliverable** when the target is a document — chat is a
  preview at most, and a long sheet in chat is not copyable in one click.
- State in one line where the file is and what to do with it ("open this, select all, copy, paste
  into your doc"). One line.

## 1. Where each fact is read from — the source table

| Sheet section | Read it from | Never |
|---|---|---|
| Hosts / URLs base | the project's **deploy file** — `groups.<region>.applications.<app>.endpoints` in `deploy.dev.yml` (or the deployed env's own deploy file / the URL the person gave) | a remembered `*.spryker.local`, a host pattern, or "probably yves.eu.…" |
| Store + locale path prefix | the store rows + the project's locales; the URL contract is `/<STORE>/<lang>/…` | a bare `/<lang>/…` — it silently falls back to the default store |
| Product URLs | the **url table** (`spy_url`) for the product's abstract, or the storefront itself (navigate and copy the address) | composing `/en/` + the product name |
| Category URLs | the **url table** (`spy_url`) for the category node, or clicking the category in the rendered navigation | composing a slug from `category_key` or from the category name — slugs are generated from the *localized name* (`/category-a/sub-category`, not `/shorts-3-4-shorts`) |
| Prices | the **price rows for the store and currency actually being demoed** (`spy_price_product_store` joined to store+currency), and the rendered PDP | a price from another store, another currency, or the CSV before the import changed it |
| Contract / customer-specific price | the merchant-relationship or customer price rows for the **persona's** company, asserted while logged in as that persona | assuming the guest price is what the persona sees (the B2B clone hides guest prices unless the customer-access import opens them) |
| Stock / in-stock variants | the **stock rows** (`product_stock.csv` pre-boot; `spy_stock_product` / the availability rows post-boot — `DESCRIBE` before writing the query, don't guess columns) **and** the rendered variant selector | "it's a demo, everything is in stock" |
| Personas | the **customer** and **company-user** data with their **roles and role permissions** (`company_user.csv`, `company_role.csv`, `company_role_permission.csv`; `spy_company_role*` at runtime) | the shipped demo logins from memory, or a role's name as evidence of what it can do |
| Facet values | the **search result on that category page** — the facets the page actually renders, with their counts | the attribute names, or the attribute's full value list (a value declared on the attribute may appear on no product) |
| Product counts per category | **counting the rendered product tiles** on the fetched page | the `N Items` string on the category page — it prints `0 Items` regardless of content |
| Script beats | the **brief** — its wording, its order | a narrative you find more logical |

**The host is required and is not asked for.** A demo environment host lives in the deploy file
for that environment; read it. If the demo runs on a cloud/staging env the person named, that
URL wins over anything local — but it is still read from what they gave, not guessed.

## 2. The sections the sheet must carry

Write them in this order; a salesperson reads top-to-bottom during a call.

**2.1 Environment and hosts.** The storefront host per store, the Back Office host, and any other app
the demo touches (Merchant Portal, API) — each as a clickable link, each fetched. State which store
and locale the demo runs in, and note the `/<STORE>/<lang>/` prefix explicitly so nobody hand-edits
a URL on stage.

**2.2 Personas.** On a consumer demo (`audience: consumer` in `.ai-dev/demo-prep.md`), the personas are a
guest (no login) and a shopper account — no company, role or business unit, and the company-role checks
below do not apply. On `audience: both`, those two plus the business persona below. On a business demo: one row per account actually used, with: **email · password · company / business
unit · role · what this persona is for · what this persona must not be used for.** The "must not"
column prevents a wrong turn during the demo — *"this account cannot place an order; switch to X
before the checkout beat"* belongs on the sheet, not only in the rehearsal notes. Every persona listed must have been
logged in as, and must have been walked through the path the script asks of it.

**2.3 The product walk.** Per product: **URL · why this product (the point it makes) · its in-stock
variants, named · list price vs the persona's contract price, in the demoed currency.** Out-of-stock
variants are named too — *"sizes L and XL are out of stock, pick M"* — because a hero product whose
size is unavailable breaks the beat during the demo. Never write "in stock" about an abstract
product; stock is per concrete.

**2.4 Categories.** Per category: **URL · how many products the customer will actually see (counted
from rendered tiles) · which facets on that page actually narrow the result** — each one named, with
the values that produce a non-empty result. A facet with one distinct value inside that category is
shown but narrows nothing; name it as such rather than listing it as a feature.

**2.5 The script beats.** Mapped **one-to-one against the brief's wording, in the brief's order.** Each beat: what the presenter does (URL + click), what the customer sees, and
the one sentence that makes the point. If the brief has a beat the environment cannot support, the
beat stays on the sheet and is marked — it is not silently dropped or reworded into something the
demo happens to do well.

**2.6 Known traps / what to avoid on stage.** Everything discovered while testing that would derail
a live run: the account that cannot check out, the filter that empties the page, the category that is
hidden for this persona, the second store that has no prices, the slow first page load. Each one with
the workaround the presenter should take.

## 3. Every claim is tested before it ships

The salesperson does not re-check anything on the sheet, so:

- **Every URL is fetched** and expected to return a page **with at least one product tile** (for
  product and category URLs). A 200 alone does not pass: a category page renders 200 with zero products.
  Count anchored product-tile elements, not the `N Items` string.
- **Every login is exercised.** **Delegate the login runs to the `spryker-verifier` agent** rather
  than typing credentials in the main session — hand it the ACs ("persona P can log in, reach
  `<url>`, add `<sku>` to cart, and place the order"), let it return PASS / FAIL / BLOCKED with
  evidence, and copy its verdicts onto the sheet. Treat a verifier FAIL as a hypothesis: cross-check
  it against the persisted state (`spy_sales_order`, `spy_quote`) before writing it down as a trap.
- **Every facet is clicked** and confirmed to **change the result count**. A facet that leaves the
  count unchanged, or drops it to zero, is not listed as a working filter.
- **Every demoed persona completes the path the script asks of it, end to end, including placing an
  order** where the script places one. On a `business` or `both` demo, holding a role does not prove
  the permission — a role can lack the place-order permission. Read the permission rows for the role
  (`company_role_permission`), then *prove it by placing an order*. On a `consumer` or `both` demo,
  prove the guest path the same way — prices visible and an order placed without logging in — and
  the shopper's order from their own account.
- **A line that could not be tested is marked `[UNTESTED — <why>]`.** It is neither dropped (the
  salesperson would improvise it) nor asserted (the salesperson would rely on it). An untested line is
  allowed on the sheet; a guessed line is not.

## 4. Count gate — never write a count without naming the members

The plugin's convention, and it applies to every number on this sheet: **no bare "N items" in prose
unless the members are enumerated right there.** "Three categories are hidden for this persona" gives
the presenter nothing to act on without the names. Write "three categories are hidden for
Buyer: Category A, Category B, Seasonal" — or drop the sentence. Same for "several products", "a few sizes",
"most filters".

## 5. Scope — the sheet describes what exists

- The run sheet is **descriptive**. It says what the demo *can* show, not what the product *should*
  do.
- **A gap found while writing the sheet is reported separately**, as a short defect list handed over
  with the sheet (in a demo run, to the wizard's final review) — never mixed into the beats, which describe
  only what the demo shows.
- **Design opinions are not gaps.** "The tile spacing is tight", "the hero image is weak", "the
  facet labels could be clearer" — these do not belong on the defect list at all. A gap is something
  that does not work: a 500, a dead link, an empty category, an account that cannot transact.
  Everything else is a preference and stays off the defect list.

## 6. Worked skeleton

The shape the HTML (and the plain-text fallback) renders. Full markup + the Docs-paste rules:
`references/output-format.md`.

```
DEMO RUN SHEET — <project> — <store> / <locale> / <currency>        (built <date>)

ENVIRONMENT
  Storefront   https://yves.eu.<domain>/DE/en/          [fetched 200]
  Back Office  https://backoffice.eu.<domain>/          [fetched 200]
  URL pattern  /<STORE>/<lang>/…  — always keep the /DE/en prefix

PERSONAS   (business / both: the company users · consumer / both: Guest + Shopper)
  Guest        no login                     USE FOR: prices, add to cart, guest checkout [verified: order 1043]
  Shopper      anna@<domain> / change123    no company · USE FOR: account, order history
  Buyer        sonia@<domain> / change123   Acme GmbH · Unit North · role "Buyer"
               USE FOR: catalogue, contract price, add to cart, PLACE ORDER  [verified: order 1042]
               DO NOT:  company-admin screens (no permission — 403 on stage)
  Approver     peter@<domain> / change123   Acme GmbH · Unit North · role "Approver"
               USE FOR: the approval beat only
               DO NOT:  place an order — this role has NO place-order permission [verified: FAIL]

PRODUCT WALK
  1. Product Alpha   https://…/DE/en/product-alpha-1001
     Why: the contract-price beat.
     In stock: S, M, XL.   OUT OF STOCK: L, XXL — do not pick them.
     List 249.00 EUR · Buyer's contract price 199.20 EUR (shown after login only).
  2. …

CATEGORIES
  Category A   https://…/DE/en/category-a      24 products visible
     Facets that narrow here: Size (S/M/L/XL) · Colour (black, navy) · Brand (2 values)
     Colour=green returns 0 products — avoid.
  …

SCRIPT BEATS  (brief order, brief wording)
  1. "Show the branded storefront"          → storefront home, point at header + hero
  2. "Find a product as a logged-in buyer"  → log in as Buyer → Category A → Product Alpha
  3. "Show the contract price"              → PDP, compare 249.00 / 199.20
  4. "Place the order"                      → cart → checkout → confirmation (Buyer only)
  5. "Show the approval flow"               → [UNTESTED — approval workflow not configured in this env]

TRAPS
  · Approver cannot place an order — switch back to Buyer before beat 4.
  · Sizes L/XXL out of stock on the hero product.
  · The second store (AT) has no prices imported — do not switch stores on stage.
```

## Verify & close

Before handing it over, confirm each of these and say so in one line:

- [ ] The format question was asked at most once (never when `up_front.run_sheet_format` is set) and
      the artefact matches the answer. A local `.html`, `.md` or `.txt` exists on disk; it was
      opened/the path was given.
- [ ] Every URL on the sheet was fetched; every product/category URL rendered ≥ 1 product tile.
- [ ] Every login was exercised (verifier report referenced), including the order-placing path for
      every persona the script asks to transact.
- [ ] Every facet listed was clicked and changed the count; dead facets are named as dead.
- [ ] Every count on the sheet enumerates its members.
- [ ] Stock is per concrete, and out-of-stock variants of every demoed product are named.
- [ ] Untestable lines carry `[UNTESTED — why]`.
- [ ] Gaps are on a separate defect list; no design opinions on it.

## Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A request for a different output format is answered with the same markdown in a new file | The content was treated as the deliverable and the format as packaging | Ask the format **once, first** (§0). Default to Google-Docs-safe HTML + a plain-text fallback. On any format complaint, the next artefact changes **format**, not wording |
| `## Products` / `\| sku \| price \|` appears literally in the customer's document | A markdown file or markdown table was pasted into a rich-text document | Never ship markdown unless the target is a wiki. Headings as `<h2>`, tables as `<table>` — `references/output-format.md` |
| Several product/category links on the sheet 404 or land on the wrong page | URLs were composed from category/product names; Spryker slugs are generated from the *localized* name and are not derivable from keys or titles | Read every URL from the url table (`spy_url`) or copy it from the rendered storefront, then fetch each one and require ≥ 1 product tile |
| A count appears in prose without naming its members | The reader cannot act on a number alone | Count gate (§4): never a count in prose without the members enumerated in the same sentence |
| On stage the hero product's size is unavailable and the sheet never flagged the stock state | Stock was assumed rather than read; a sizeable share of concretes can carry zero stock | Read the stock rows per concrete and name the in-stock variants **and** the out-of-stock ones for every demoed product; confirm on the rendered variant selector |
| The demo account cannot place an order — found in rehearsal, not on the sheet | The persona's role was read as evidence of capability; no role carried the place-order permission | Exercise the whole path per persona (delegate to `spryker-verifier`), read `company_role_permission` for the role, and record a "DO NOT" line for every persona that cannot transact |
| The defect list carries design preferences | Design preferences (spacing, imagery, wording) were filed as functional gaps | A gap is something that does not work. Preferences stay off the defect list, and gaps go on a separate defect list — never into the beats |
| The sheet has no environment host, or a remembered one | The deploy file was never read | Resolve hosts from the deploy file of the environment being demoed (`groups.<region>.applications.<app>.endpoints`); a cloud URL the person named wins over the local default |
| The beats read well but the customer asked for something else | The brief's order/wording was rewritten into a more logical narrative | Map beats one-to-one to the brief's wording, in the brief's order; an unsupportable beat is marked, not replaced |
| A price on the sheet is not the price on screen | Price read for the wrong store/currency, or the guest price recorded for a persona who sees a contract price | Read price rows for the store **and** currency being demoed, and assert the persona's price while logged in as that persona |
