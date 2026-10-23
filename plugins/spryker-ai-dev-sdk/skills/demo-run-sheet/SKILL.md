---
name: demo-run-sheet
description: >
  Use to produce the demo handover report for a deployed demo environment — the document the
  presenter works from and the team takes over: environment and links, demo accounts, products,
  categories, one demo point per requirement of the brief with its status, what to watch out for, and
  how each part is built. Triggers: "handover report", "demo cheat sheet", "run sheet", "what do I
  show", "give me the links and logins for the demo", "prepare for the customer call". Every host,
  URL, login, price and stock figure is read from the running system and tested before it ships. The
  format is asked once: Markdown or HTML for a paste into a Google Doc or Confluence. The last phase
  of `demo-prep-wizard`.
---

# demo-run-sheet

The last step of a demo build. The deliverable is the **demo handover report**: the document the
presenter opens on a second screen during the call, and the document the team takes over with the
demo. It names the hosts, the logins, the products and categories, every requirement of the brief
with its status and how to show it, the traps to stay away from on stage, and how each part is built.

Two rules govern everything below:

> **Rule 1 — the demo points come from the brief.** One row per requirement, in the brief's order and
> wording. A requirement the demo does not support stays in the report with its status and the
> reason. Nothing is dropped, and nothing is reworded into what the demo happens to do well.
>
> **Rule 2 — nothing in the report is composed, inferred or remembered. Every line is read from the
> running system, and every line is tested before it ships.** A URL assembled from a category name
> is usually wrong, so no URL is composed.

In a demo run, "the person" below is usually a demo preparer, not an engineer (`demo-prep-wizard` →
"Who runs this"): plain words, your recommended option first, and every technical judgement yours.

## 0. Ask the format — once, first, before writing a single line

**If `.ai-dev/demo-prep.md` records `up_front.handover_format`, the question was already asked in the
wizard's one sitting — use that answer and do not ask again.** Otherwise ask one question, before the
research, and never again:

1. **HTML for a paste into a Google Doc or Confluence (Recommended)** — token `html`. Write
   `.ai-dev/demo-handover-report.html` by [references/output-format.md](references/output-format.md)
   and open it in the browser: the person selects all, copies and pastes. Headings, tables, links and
   the status labels survive the paste.
2. **Markdown** — token `markdown`. Write `.ai-dev/demo-handover-report.md`: the template in § 6, plain
   CommonMark with pipe tables.

An unanswered or vague answer means `html`. A Markdown table pasted into a rich-text document arrives
as rows of pipe characters, so a person who pastes into a document gets HTML. When the person says
the formatting is wrong, the next file changes the format, not the wording.

State in one line where the file is and what to do with it ("open this, select all, copy, paste into
your doc"). Never print the report into chat as the deliverable.

## 1. Where each fact is read from — the source table

| Report part | Read it from | Never |
|---|---|---|
| Hosts / URLs base | the project's **deploy file** — `groups.<region>.applications.<app>.endpoints` in `deploy.dev.yml` (or the deployed env's own deploy file / the URL the person gave) | a remembered `*.spryker.local`, a host pattern, or "probably yves.eu.…" |
| Store + locale path prefix | the store rows + the project's locales; the URL contract is `/<STORE>/<lang>/…` | a bare `/<lang>/…` — it silently falls back to the default store |
| Product URLs | the **url table** (`spy_url`) for the product's abstract, or the storefront itself (navigate and copy the address) | composing `/en/` + the product name |
| Category URLs | the **url table** (`spy_url`) for the category node, or clicking the category in the rendered navigation | composing a slug from `category_key` or from the category name — slugs are generated from the *localized name* (`/category-a/sub-category`, not `/shorts-3-4-shorts`) |
| Prices | the **price rows for the store and currency actually being demoed** (`spy_price_product_store` joined to store+currency), and the rendered PDP | a price from another store, another currency, or the CSV before the import changed it |
| Contract / customer-specific price | the merchant-relationship or customer price rows for the **persona's** company, asserted while logged in as that persona | assuming the guest price is what the persona sees (the B2B clone hides guest prices unless the customer-access import opens them) |
| Stock / in-stock variants | the **stock rows** (`product_stock.csv` pre-boot; `spy_stock_product` / the availability rows post-boot — `DESCRIBE` before writing the query, don't guess columns) **and** the rendered variant selector | "it's a demo, everything is in stock" |
| Demo accounts | the **customer** and **company-user** data with their **roles and role permissions** (`company_user.csv`, `company_role.csv`, `company_role_permission.csv`; `spy_company_role*` at runtime) | the shipped demo logins from memory, or a role's name as evidence of what it can do |
| Facet values | the **search result on that category page** — the facets the page actually renders, with their counts | the attribute names, or the attribute's full value list (a value declared on the attribute may appear on no product) |
| Product counts per category | **counting the rendered product tiles** on the fetched page | the `N Items` string on the category page — it prints `0 Items` regardless of content |
| Demo points | the **brief's requirements**: the confirmed routing table from `demo-intake` (in a demo run, `.ai-dev/demo-prep.md`), or the requirement list the person gave — their order, their wording | a narrative you find more logical, or only the requirements the demo does well |
| Status of a demo point | the rehearsal (`.ai-dev/rehearsal.md`) and the tests in § 3 | a status from memory, or "built" taken as "tested" |
| How each part is built | the project's changes against the demoshop it started from (`git diff`), the decision log in `.ai-dev/demo-prep.md`, and the records of the build steps | a guess from the feature name |

**The host is required and is not asked for.** A demo environment host lives in the deploy file for
that environment; read it. If the demo runs on a cloud/staging env the person named, that URL wins
over anything local — but it is still read from what they gave, not guessed.

## 2. The sections of the report

Title: `<Customer> × Spryker demo`. Then the seven sections in this order: the presenter reads
top-to-bottom during the call.

**2.1 Environment.** A table: what, link, note. The storefront per store, the Back Office, and any
other app the demo touches (agent login, Merchant Portal, API), each a clickable, fetched link. State
the `/<STORE>/<lang>/` prefix so nobody hand-edits a URL on stage. Below the table: what the presenter
must do before the call, for example a site password or a mail-redirect setting, and how to undo it
after.

**2.2 Demo accounts.** A table: person, login, company / role, use for, do not. One row per account
the demo uses. Write no password: say in one line how the password is handed over. On a consumer demo
(`audience: consumer` in `.ai-dev/demo-prep.md`), the accounts are a guest (no login) and a shopper
account, with no company or role. On `audience: both`, those two plus the business accounts. The "do
not" column prevents a wrong turn on stage: *"cannot place an order; switch to X before checkout"*.
Every account listed was logged in as and walked through the path the demo points ask of it.

**2.3 Products.** A table: product (name and SKU), link, why (the point it makes), stock per
warehouse, price. Name out-of-stock variants too — *"sizes L and XL are out of stock, pick M"*.
Never write "in stock" about an abstract product: stock is per concrete. Prices in the demoed
currency; say which price a persona sees after login.

**2.4 Categories.** A table: category, link, note. In the note: the products the customer sees
(counted from rendered tiles), and the facets that narrow the result, with the values that give a
non-empty result. A facet with one value inside the category narrows nothing: say so.

**2.5 Demo points.** First the status legend. Then a table: point, how to show, status. One group row
per section of the brief, in the brief's order; under it, one row per requirement, in the brief's
wording. **Every requirement of the brief appears exactly once.**

- **How to show** — the clicks, in order: the account (name and login), the product or page as a
  link, the values to enter, what the customer sees. Name the buttons as the shop labels them.
- **Status** — one of:

  | Status | Means |
  |---|---|
  | `Ready` | tested in the shop, end to end |
  | `Ready, with a note` | tested; the row names the one point to know |
  | `Not yet tested` | built, not tested; the row says why |
  | `Not built yet` | planned for this demo |
  | `Not built` | not part of this demo build; the row says why |

- The last group is **Environment**: how to reset the demo data before the rehearsal and before the
  meeting, and what the reset restores.

**2.6 Watch out during the demo.** A list: everything found while testing that would derail a live
run — the account that cannot check out, the filter that empties the page, the slow cart, the
message that looks like an error but is standard behavior. Each item with what the presenter does
instead.

**2.7 How each part is built.** A table: feature, how it is done. One group row per area (stores and
content, companies and users, catalogue and prices, stock and delivery, cart and ordering, approval,
mails, service — only the areas the demo has). Start each "how" with one of:

| Label | Means |
|---|---|
| `Standard` | Spryker as shipped |
| `Configured` | Spryker as shipped, set up with data or configuration; name what |
| `Custom` | new code; name what it does |
| `Custom (small)` | a small code change; name what it does |
| `Custom (mocked)` | new code that simulates an outside system, for example an ERP; name what it simulates |
| `Content page only (mocked)` | a page that describes the feature; nothing works behind it |

Name what the code does, not the class or file. A team that takes over the demo reads this section to
know what is Spryker and what it must maintain.

## 3. Every claim is tested before it ships

The presenter does not re-check anything in the report, so:

- **Every URL is fetched** and expected to return a page **with at least one product tile** (for
  product and category URLs). A 200 alone does not pass: a category page renders 200 with zero
  products. Count anchored product-tile elements, not the `N Items` string.
- **Every login is exercised.** **Delegate the login runs to the `spryker-verifier` agent** rather
  than typing credentials in the main session — hand it the ACs ("persona P can log in, reach
  `<url>`, add `<sku>` to cart, and place the order"), let it return PASS / FAIL / BLOCKED with
  evidence, and use its verdicts for the status. Treat a verifier FAIL as a hypothesis: cross-check
  it against the persisted state (`spy_sales_order`, `spy_quote`) before writing it down.
- **Every facet is clicked** and confirmed to **change the result count**. A facet that leaves the
  count unchanged, or drops it to zero, is not listed as a working filter.
- **Every demoed account completes the path its demo points ask of it, end to end, including placing
  an order** where a point places one. On a `business` or `both` demo, holding a role does not prove
  the permission — a role can lack the place-order permission. Read the permission rows for the role
  (`company_role_permission`), then *prove it by placing an order*. On a `consumer` or `both` demo,
  prove the guest path the same way — prices visible and an order placed without logging in — and
  the shopper's order from their own account.
- **`Ready` means tested.** A point that could not be tested is `Not yet tested`, with the reason. It
  is neither dropped (the presenter would improvise it) nor marked `Ready` (the presenter would rely
  on it).

## 4. Count gate — never write a count without naming the members

The plugin's convention, and it applies to every number in this report: **no bare "N items" in
prose unless the members are enumerated right there.** "Three categories are hidden for this
persona" gives the presenter nothing to act on without the names. Write "three categories are hidden
for Buyer: Category A, Category B, Seasonal" — or drop the sentence. Same for "several products", "a
few sizes", "most filters".

## 5. Scope — the report describes what exists

- The report is **descriptive**. It says what the demo *can* show, not what the product *should* do.
- **A requirement that does not work is a status, not a silence.** It keeps its row in § 2.5 with
  `Not built`, `Not built yet` or `Ready, with a note`, and the reason.
- **Design opinions are not gaps.** "The tile spacing is tight", "the hero image is weak", "the facet
  labels could be clearer" — these do not go into the report. A gap is something that does not work:
  a 500, a dead link, an empty category, an account that cannot transact.

## 6. Template

The Markdown form. The HTML form has the same content: [references/output-format.md](references/output-format.md).
All values below are invented; substitute the real ones and keep the structure.

```markdown
# ACME × Spryker demo

## 1. Environment

| What | Link | Note |
|---|---|---|
| Shop Germany | https://www.demo.acme.example/DE/de | Always keep /DE/de or /DE/en at the start of a link |
| Back Office | https://backoffice.demo.acme.example | Mail redirect setting |

The environment asks for a site password first (browser pop-up). Have it ready.

Mails: before the meeting, open Back Office → Configuration → E-Mail → Delivery and enter your
address. Every shop mail then reaches that inbox. Empty the field after the demo.

## 2. Demo accounts

All demo accounts share one password, handed over separately.

| Person | Login | Company / role | Use for | Do not |
|---|---|---|---|---|
| Anna Buyer | anna.buyer@acme-retail.example | ACME Retail · buyer, €500 limit | Use case 1 buyer: direct order up to €500, approval above | — |
| Paul Approver | paul.approver@acme-retail.example | ACME Retail · approver | Use case 1 approver | Do not place orders: the role cannot |

## 3. Products

| Product | Link | Why | Stock Berlin / Munich | Price |
|---|---|---|---|---|
| Drill X200 (ACME-DR-200) | [open](https://www.demo.acme.example/DE/de/drill-x200-acme-dr-200) | List price and customer price on one product | 24 / 6 | List €249.00; Anna sees €199.20 after login |
| Battery pack B5 (ACME-BP-005) | [open](https://www.demo.acme.example/DE/de/battery-pack-b5-acme-bp-005) | Red stock example — do not add to a cart | 0 / 0 | €59.00 |

## 4. Categories

| Category | Link | Note |
|---|---|---|
| Power tools | [open](https://www.demo.acme.example/DE/de/power-tools) | 24 products. Brand narrows (ACME, Volt); Color does not (one value) |

## 5. Demo points

Ready = tested in the shop · Ready, with a note = tested; one point is named in the row · Not yet
tested · Not built yet = planned · Not built = not part of this demo build; the row says why

| Point | How to show | Status |
|---|---|---|
| **Use case 1 — ACME Retail** | | |
| Anna orders up to €500: placed directly | Log in as Anna Buyer (anna.buyer@acme-retail.example) → [Drill X200](https://www.demo.acme.example/DE/de/drill-x200-acme-dr-200), add 2 → checkout → *Buy now*. The order is placed without approval. | Ready |
| Anna orders above €500: approval by Paul | Anna: 3 × Drill X200 → last checkout page: *Buy now* is disabled → select Paul Approver → *Send request*. Paul: cart list → the cart → checkout → *Approve*. | Ready, with a note: Paul gets no mail; he sees the request in his cart list |
| **Delivery** | | |
| Delivery cost per item | The brief asks for it "where possible". Spryker calculates delivery cost per delivery, not per item; per item needs surcharge amounts per product that the brief does not give. | Not built |
| **Environment** | | |
| Reset before the rehearsal and before the meeting | Run the full install of the demo environment: the accounts, prices and prepared carts come back, and everything created while testing is gone. | Ready |

## 6. Watch out during the demo

- Paul cannot place orders. Switch back to Anna before the checkout point.
- Do not add Battery pack B5 to a cart: it is the out-of-stock example.

## 7. How each part is built

| Feature | How it is done |
|---|---|
| **Companies and users** | |
| Buyers and approvers with a €500 limit | Configured: standard roles and approval permissions |
| **Stock and delivery** | |
| Stock per warehouse on the product page | Custom: extension of the standard availability widget |
| Estimated delivery date per item | Custom (mocked): a date rule by product type and stock, in place of the ERP |
```

## Verify & close

Before handing it over, confirm each of these and say so in one line:

- [ ] The format question was asked at most once (never when `up_front.handover_format` is set) and
      `.ai-dev/demo-handover-report.html` or `.md` exists; it was opened, or its path was given.
- [ ] Every requirement of the brief has exactly one row in § 2.5, in the brief's order and wording,
      with a status; every status other than `Ready` carries its reason.
- [ ] Every URL in the report was fetched; every product/category URL rendered ≥ 1 product tile.
- [ ] Every login was exercised (verifier report referenced), including the order-placing path for
      every account a demo point asks to transact.
- [ ] Every facet listed was clicked and changed the count; dead facets are named as dead.
- [ ] Every count in the report enumerates its members.
- [ ] Stock is per concrete, and out-of-stock variants of every demoed product are named.
- [ ] Every feature in § 2.7 carries one of the labels, and no design opinion is in the report.

## Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `## Products` / `\| sku \| price \|` appears literally in the customer's document | A Markdown file was pasted into a rich-text document | Ask the format once, first (§ 0). For a paste into a document, write HTML by `references/output-format.md` |
| A format complaint is answered with the same file in new wording | The content was treated as the problem and the format as packaging | The next file changes **format**, not wording |
| A requirement of the brief is missing from the demo points | Only what the demo does well was written down | One row per requirement, in the brief's order; what does not work gets `Not built` and the reason |
| A demo point is `Ready` and fails on stage | "Built" was taken as "tested" | `Ready` only after the test in § 3; otherwise `Not yet tested` with the reason |
| Several product/category links in the report 404 or land on the wrong page | URLs were composed from category/product names; Spryker slugs are generated from the *localized* name and are not derivable from keys or titles | Read every URL from the url table (`spy_url`) or copy it from the rendered storefront, then fetch each one and require ≥ 1 product tile |
| A count appears in prose without naming its members | The reader cannot act on a number alone | Count gate (§ 4): never a count in prose without the members enumerated in the same sentence |
| On stage the hero product's size is unavailable and the report never flagged the stock state | Stock was assumed rather than read; a sizeable share of concretes can carry zero stock | Read the stock rows per concrete and name the in-stock variants **and** the out-of-stock ones for every demoed product; confirm on the rendered variant selector |
| The demo account cannot place an order — found in rehearsal, not in the report | The account's role was read as evidence of capability; no role carried the place-order permission | Exercise the whole path per account (delegate to `spryker-verifier`), read `company_role_permission` for the role, and record a "do not" for every account that cannot transact |
| § 2.7 says "Custom" for a feature Spryker ships | The feature name was read, not the project's changes | Read the diff against the demoshop it started from; `Configured` when only data or configuration changed |
| The report has no environment host, or a remembered one | The deploy file was never read | Resolve hosts from the deploy file of the environment being demoed (`groups.<region>.applications.<app>.endpoints`); a cloud URL the person named wins over the local default |
| A price in the report is not the price on screen | Price read for the wrong store/currency, or the guest price recorded for a persona who sees a contract price | Read price rows for the store **and** currency being demoed, and assert the persona's price while logged in as that persona |
