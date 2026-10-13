---
name: match-reference-design
description: >
  Use when a Spryker storefront has to look like a reference site — the customer's own site or one the
  person names. Triggers: "make it look like <url>", "match the reference", "fix the navigation to
  be like theirs", and look-and-feel feedback on a page being matched to a reference or a demo look
  ("this block looks ugly", "swap this block with the second one").
  Owns the design loop for homepage composition, navigation and CMS blocks: reference capture, a
  numbered page map, a printed cost ladder, one change per cycle, and a screenshotted before/after.
  Not component mechanics (Twig/SCSS/TS, overrides, build, Twig cache) — that is `yves-atomic-
  frontend`; not catalogue, CMS or navigation rows — `project-data`; not behaviour changes — `spryker-
  customization`.
---

# match-reference-design

One job: **the shop reads as the reference site**, and every step of getting there is cheap, visible
and reversible. This skill is the *workflow* layer — it decides **what** to change, in **what order**,
at **what cost**, and how the result is **proved**. The mechanics of the change belong elsewhere:

| need | owner |
|---|---|
| Twig component, SCSS mixin, Pyz/`<Ns>` override, the frontend build, the Yves Twig cache | `yves-atomic-frontend` |
| brand values only — palette, logo, theme tokens, the asset-scope rule (layout and composition capture, `spec.md` and `.ai-dev/composition.md`, are this skill's, §1) | `brand-project` |
| any change to catalog/CMS **rows** (`data/import/**`) | `project-data` |
| the reset ladder, rebuild counter, asset-build rung | `boot-and-verify` §3b |
| a logged-in flow (account, checkout, B2B pricing) | `spryker-verifier` agent — this skill never logs in |
| short-request intake discipline | `spryker-customization` Step 1 demo-intake micro-step |

Work products live in `$MRD = ${CLAUDE_PROJECT_DIR:-$(pwd)}/.ai-dev/match-reference/<slug>/`.

---

## 0. Intake — one line, before any tool call

Reuse the demo-intake micro-step (`spryker-customization` Step 1): restate in **one line** —
**who** (store / locale / actor), **where** (the exact page and viewport), **what must be true after**.
All three known → proceed; otherwise ask for the single missing one — except in an autonomous demo
run, where you take it from the needs list and the beat, log it, and go on. Then decide one thing
yourself: **is the stated fix the goal, or a symptom of one?** "Make the top image bigger" is a
measurement change; "the landing page should look like theirs" is a composition change. Ask only when
it is truly ambiguous, and in plain words — "just bigger, or should the whole top area look like their
site?" Reading the first as the second turns a stylesheet edit into a rebuild (see Pitfalls).

Plus the reference itself: read `up_front.reference_sites` in `.ai-dev/demo-prep.md` first, then
`project.reference_sites` in `.ai-dev/project-setup.md`. Only outside a demo run, with neither set, ask
**which site(s)?** Blank is a legitimate answer, and never a site you pick yourself.

**Who you are talking to.** In a demo run the person is a preparer, not a developer (`demo-prep-wizard`
→ "Who runs this"): they judge **what they see** — layout, size, colour, order, whether it looks like
the reference. Everything about **how** — which rung, which file, which importer — is yours to decide.

**Open the local shop in Claude in Chrome (`mcp__claude-in-chrome__*`), not the built-in browser** — the built-in browser asks the person on every action for a local host. The reference site itself may use either browser.

---

## 1. Reference capture — first, and every time

**Verify in the browser.** The browser that opens the reference also checks the result;
`curl | grep` does not count as evidence of how the shop looks.

1. Screenshot the **reference** at the widths listed in `references/visual-defects.md` (`resize_window`),
   for each page in scope — home, and the menu open if navigation is in scope.
2. Screenshot the **current shop**, same pages, same widths. This pair is the baseline for every
   later before/after. On a clone not booted yet (demo-prep phase 3), capture the reference and the
   spec now; the shop half follows once phase 5 has booted it.
3. Extract a **written composition spec** — structure, never content. Six to ten lines, each carrying
   a **measured** value (`getBoundingClientRect()`, `getComputedStyle()`), never an eyeballed one:

```
# spec — <reference url> vs <shop url>              (.ai-dev/match-reference/<slug>/spec.md)
1 section order, top→bottom  : hero(full-bleed) · 3-up category grid(contained) · editorial band(full-bleed) · product strip(contained) · newsletter(full-bleed)
2 container / gutter         : max-width 1200px, side gutter 16px  (shop: 1200px / 16px — same)
3 full-bleed technique       : wrapper outside the container (not 100vw + negative margin)
4 aspect ratios              : hero 16:5 · category tile 1:1 · editorial 3:2 · product tile 3:4
5 type scale                 : h1 48/56 700 · h2 32/40 700 · body 16/24 400 · eyebrow 12 upper +0.08em
6 spacing scale              : section gap 80 · tile gap 24 · headline→grid 32
7 menu                       : two levels visible at once · trigger hover · no counts · images on level 2
8 tile anatomy               : image → label → price; no border, radius 0, hover = image zoom 1.04
9 deltas vs shop             : shop shows one level on click; shop hero 16:9; shop section gap 40
```

4. **Show the spec and get one "ok" before any edit** — except in an autonomous demo run (`.ai-dev/demo-prep.md` says `run_mode: autonomous`): there, proceed on the spec, log it as one decision, and hand the reference-next-to-result pair to the wizard's final review instead of waiting. It is the acceptance criterion for every
   cycle that follows, and the thing a later "looks ugly" is measured against. To a preparer, show it
   as the reference screenshot next to the current shop, plus a plain list of what will change ("a
   wide photo across the top, three category tiles under it, the footer in their blue") — the table
   stays in `spec.md`.

**Asset scope rule** (`brand-project`): **structure and layout may be taken from any reference;
imagery, photography, logos and copy only from the customer's own brand.** Record the source of every
file placed under `frontend/static/images/brand/` in `composition.md`.

If `.ai-dev/composition.md` already exists, **extend it, do not re-derive it** — and keep the two
distinct: `composition.md` is the project's durable visual grammar, `spec.md` is this task's checklist.

---

## 2. The page map — numbered, printed, before touching anything

**Numbers come from the rendered page, top to bottom — never from a private subset.** Numbering only
"the image blocks" while the page renders a larger set points at a different "second one" than the
person means, and a wrong move re-indexes everything after it.

### Homepage — the slot → block → template join

Read the rendering chain and join it to the rows that produce it:

- `data/import/**/cms_slot.csv` + `cms_slot_template.csv` — the slots a template exposes.
- `cms_slot_block.csv` — `slot_key`, `block_key`, `position` (the render order within a slot).
- `cms_block.csv` — `block_key`, `template_name` / `template_path`, `active`, and the
  `placeholder.*.<locale>` columns that hold the content.
- `cms_block_store.csv` — per-store visibility. A block with no row for the store is invisible with
  every other signal green.
- The home slot keys on this demoshop: `slt-2` carousel, `slt-3` / `slt-5` full-width, `slt-4` grid,
  `slt-home-bottom`; the header menu is `slt-desktop-header` (a slot, not a widget).

Print it as a numbered map in **render order**, and check it against what the browser actually shows:

```
#  band (as rendered)        slot            block_key              template                       active
1  hero carousel             slt-2           blck-home-hero         @CmsBlock/template/banner        1
2  3-up category grid        slt-4           blck-home-cat-grid     @CmsBlock/template/banner_grid_column_block  1
...
```

A band that renders but has no row, or a row that has no band, is itself the finding — resolve it
before changing anything.

### Navigation — the node tree

The storefront menu is a four-layer chain: `navigation.csv` (menu keys) → `navigation_node.csv`
(the items: `node_type`, parent, `position`, `title.<locale>`, `url.<locale>`, `css_class`) →
`content_navigation.csv` → the `blck-nav-*` blocks and their `cms_slot_block.csv` rows. Print the
**tree with numbers**, one line per node, showing `node_type` (`category` / `cms_page` /
`external_url` / `link`), depth and position — next to the reference's menu tree and the PLP filter
tree, before the first design edit (`references/visual-defects.md` (f)).

### The echo rule

**Every positional request is echoed against the printed map and confirmed before acting.**

> "the second one" → `#2 = slt-4 / blck-home-cat-grid (3-up category grid)`. Swap that with
> `#5 = slt-5 / blck-home-editorial`? (positions are from the map above, not from the previous move)

To a preparer, echo in what they see — "the second band, the three category tiles — swap it with the
fifth, the wide photo?" — never slot or block keys; those stay in `map-<n>.md`.

### Snapshots

Write the map to `$MRD/map-<n>.md` **per change**, numbered in order. After a revert or a reset, a
person referring to where a block "was originally" still resolves — against the map they were looking at,
not against the state the last edit produced. `$MRD/log.md` records one line per cycle: map number,
rung, file touched, the spec line it was for, screenshot pair.

---

## 3. The cost ladder — printed before any change

**The rung is your call — the person judges the look, not the mechanism.** Choose the cheapest rung
that achieves the visual result, and write the ladder with that rung marked into `$MRD/log.md` before
the change. In the chat, say only what will change on screen and roughly how long it takes. When the
cheapest rung cannot deliver, take the next rung that can, yourself — say its cost in their terms ("a
new section, about half an hour including a data reload") and go on; in an autonomous run, log it.

| rung | the change | how it applies | cost | teardown |
|---|---|---|---|---|
| **1** | a stylesheet or template edit (SCSS, Twig, a modifier, a new component) | frontend build — `docker/sdk console frontend:yves:build` where `/data/node_modules` is present in the cli container, else `docker/sdk up --assets` | seconds | **none** |
| **2** | a content value on an existing row: placeholder text, `active`, `position` within a slot, an image path / URL | scoped `docker/sdk console data:import -c <config>` (rung 1 of the reset ladder) | under a minute | **none** |
| **3** | a **template change on an existing `block_key`**, or **moving a block across slots** | the importers are not upserts — `cms-block` is insert-only and throws `Unable to execute INSERT … spy_cms_block_glossary_key_mapping`; `cms-slot-block` is add-only and does **not** error, it adds a second `(slot, block)` relation so the block renders twice | rung 2 **if rewritten as an insert**, rung 4 otherwise | **avoidable** |
| **4** | anything genuinely needing `docker/sdk reset` (value changes on imported rows, deletions) | `script -q .ai-dev/reset.log docker/sdk reset` in background, per `boot-and-verify` §3b | **ten-plus minutes**, plus the rebuild counter | **yes** |

**Rung 3 has a cheap route.** Prefer a **new block key** over a reset: add the new `cms_block.csv`
row with the new template, its `cms_block_store` rows per store and its `cms_slot_block` wiring at the
wanted slot/position, and set the old row's `active` to `0`. That is an insert and a value change on a
column the importer does accept — rung 2 cost for a rung 4 intent. Same for a cross-slot move: a new
relation plus a deactivated old block avoids a teardown.

**Rung 4 is announced and priced:** say what it buys and how long it takes. Before it, run
`php <VALIDATE> gate` (`boot-and-verify`) and stop on a gating finding. Trusted rebuilds (a demo clone,
first setup, or a standing "rebuilds" approval in the person's own words) run without a prompt; on a
project with real data, ask the person once before the rebuild. Count the rebuilds yourself (per
project, not per step) and quote the count in the report. Before the third rebuild on a project, write
`rebuild #N: <why rung 1 cannot show it>` to the decision log and continue; it never interrupts the
preparer.

**Sizing and spacing complaints are rung 1 until proven otherwise.** Find the element in the browser,
read its box, change the rule that sets it, build, look.

---

## 4. One change per cycle

```
change (one thing) → build → look (screenshot, every sweep width) → compare to the spec line it was for → report with the before/after pair
```

- **Never batch visual changes.** Two changes in one build means neither is attributable, and a
  rejection undoes both.
- **Never report a visual result that was not looked at.** A markup `grep`, a `curl`, an identical
  DOM diff — none of them are evidence about appearance. They prove a string is present, never that
  it is legible, aligned, or the right size (`../yves-atomic-frontend/references/storefront-fixes.md` → Verifying a UI Fix).
- **After a build, hard-reload before measuring**; after a `.twig` edit clear the compiled Twig cache
  (`src/Generated/Yves/Twig/codeBucket`, never `data/cache/Yves/<env>` — that is the DI container), and
  after creating a new override rebuild the path map — `yves-atomic-frontend` owns both recipes.
- **End the cycle with the `references/visual-defects.md` checks** for the band that changed.
- Append the cycle to `$MRD/log.md` and bump the map snapshot when the change moved anything
  positional.

**Signature of a skipped look step:** the person's next message is a screenshot, or "do you think it
looks good?" — they are checking the result themselves.

---

## 5. Never compensate in data for a layout problem

A navigation or layout task edits **templates, styles**, and at most **navigation node order, labels
and visibility** (`position`, `title.<locale>`, whether a node is present in the menu). That is the
whole permitted surface.

**Categories, products and category keys are the catalog.** Removing them to make a menu fit is a
data change, not a design fix: it changes what the shop sells in order to change how a bar looks, and
a green boot does not show it because the surviving rows import fine. If the catalog
genuinely has to change, that is a **separate, explicitly requested** change, routed through
`project-data` with its accounting rule (in-count = kept + retargeted + dropped, in writing, with the
literal revert command).

The test question, asked before every edit in this skill:

> **Does this edit change what the shop sells, or what the page looks like?**

If the answer is "what it sells", the change is out of scope for this task — say so and offer it as
its own step.

---

## 6. Never restyle a shared component for one page

Before editing any component's `.scss` or `.twig`, **enumerate its consumers and print the list**:

1. Templates that include or extend it:
   `grep -rn "molecule('product-card'\|organism('product-card'" src/ vendor/spryker-shop/ --include=*.twig`
   (and `{% extends %}` of the same name in the project layer).
2. **Content-widget view names** that reference it — the `content_product_abstract_list` /
   `content_banner` view identifiers used in CMS block placeholders and registered in the
   content-widget plugin stack.
3. Which **slots** those templates land in — read them off the page map from §2.

Then decide:

- Wanted **everywhere** the component renders → edit the component. Say in the report which pages
  this repaints, and screenshot at least the product detail page and the homepage.
- Wanted on **one page / one strip** → **register a new view or variant**: a BEM modifier passed at
  the include site, a new content-widget view name, or a separate component. The shared mixin stays
  untouched.

**Identical markup in two places means a change to one repaints both.** The same tile renders on the
PDP and in the homepage strip; the only difference is the wrapper. Equal DOM fragments show that a
component change is not scoped to one page.

---

## 7. Measure, don't guess

- **Utility classes:** read the actual value before choosing one. The names do not carry the numbers,
  and neighbouring steps of a spacing scale can be a handful of px apart — swapping between two of
  them by name produces rounds of invisible change. Read the compiled rule
  (`grep -n '\.spacing-top--big' <the served stylesheet>`) or the `$setting-spacing` map in the theme
  SCSS.
- **Container width:** never invent it. Read it from the page —
  `getComputedStyle(document.querySelector('.container')).maxWidth` — and inherit it rather than
  restating a number. A panel built to an imagined 1440px against a 1200px container misaligns
  against everything around it, visibly, on the first screenshot.
- **Gaps:** read them from the rendered page (`getBoundingClientRect()` on both neighbours, subtract),
  then set the number you measured. Do not nudge a margin and re-look.
- **Know which stylesheet the page serves.** Read the rendered page's own `<link>` tags before
  grepping anything: this clone serves `critical.css` + `util.css` — **not** `app.css`. A correct edit
  verified against the wrong compiled file reads as "no effect" and leads to a second, unnecessary change.
- **Hard-reload after every build** before measuring, or the numbers come from the previous bundle.

---

## 8. Never edit build output

Generated, gitignored, and overwritten by the next build:

| do not write here | the real source |
|---|---|
| `public/Yves/assets/**` (compiled css/js, fonts, copied static) | `src/<Ns>/…/Theme/default/components/**` and the theme SCSS |
| `public/Backoffice/assets`, `public/MerchantPortal/assets` | `data/configuration/gui.configuration.yml` and `zed_ui.configuration.yml` (`brand-project`) |
| `data/cache/**` | nothing — a cache, never edited; `data/cache/Yves/<env>` is the DI container, not the Twig cache |

Served static assets (Yves only) belong in `frontend/static/**`, which the build copies into
`public/`. **Check before writing:** `git check-ignore -v <path>` — a hit means the edit is invisible
to git and gone at the next build, even though the write reports success. Treat a hit as the finding
and write the source under `frontend/static/**` instead.

---

## 9. Vocabulary — the one question to ask

Ambiguous feedback gets **one** question that names the two candidate dimensions, then an edit. Two
guesses cost more than one question, and the second guess also moves what the first guess
moved, so the person's original reference point is lost.

| what they said | the two candidates | ask exactly this |
|---|---|---|
| "make it bigger" | the block's box vs the type inside it | "Bigger block (height/width), or bigger text inside it?" |
| "looks ugly" | composition (spacing, alignment, proportion) vs content (image, copy, colour) | "Is it the spacing/alignment, or what's in it? Which band of <reference> should it read like?" |
| "not good" / "doesn't look right" | deviation from the reference vs something they are picturing that is not in the spec | "Which part is off against <reference> — or is there something you're picturing that isn't in the spec?" |
| "make it tighter" / "too much space" | the gap between blocks vs the padding inside one | "The gap between the blocks, or the padding inside this one?" |
| "change the place with the second one" | which map entry | echo against the printed map (§2) — never act on an unechoed position |
| "it's broken on mobile" | layout at the breakpoint vs an element that never had a mobile rule | "Which width, and is it the whole band or one element?" — then reproduce at that width first |

Never ask two of these at once, and never ask one you can answer by measuring.

---

## 10. CMS content hygiene

**Read [references/cms-content-hygiene.md](references/cms-content-hygiene.md) before writing a CMS block row or placeholder.** Each broken rule forces a higher rung for a template-sized problem.
In short: layout lives in the template or a section modifier, never inline in a placeholder; a block uses its template's shipped placeholders, and more content means a second block; `template_path` starts with `@CmsBlock/`; every new block key needs a `cms_block_store` row per store and a `cms_slot_block` row with a `position`.

---

## 11. Verify and report

A visual task is done when **all** of these exist, and not before:

1. **Side-by-side screenshots, reference vs shop, at every sweep width**, for every band that changed.
2. **The spec checklist from §1**, line by line: `ok` or `deviates — <measured value> vs <spec value>`.
   A deviation left in place is stated, not omitted.
3. The rung used per change, the file touched, and the build command's own success line.
4. The rebuild count on the project so far, with the reason for each rebuild this step consumed.
5. What was **not** touched: the components you decided against restyling, the data you did not
   change, the deviations you chose to leave.
6. The `references/visual-defects.md` checklist, per surface and width, each result quoted.

**Anything behind a login** — account pages, checkout, B2B pricing, a company-user menu — is delegated
to the `spryker-verifier` agent with the AC written out. This skill does not log in.

### 11a. The closing sweep — `.ai-dev/design-acceptance.md`

**The step is done when every surface has been looked at since the last change** — not when the last
reported defect is gone. A pass that re-checks only the surface just reported leaves every other surface
unchecked. The dropdowns (each opened), the footer (scrolled to) and the phone width (tried) each need
an explicit look.

Write `.ai-dev/design-acceptance.md` — one row per surface **per store** — and fill it from scratch
after the last change, not incrementally as you fix things; each row runs the checks in
`references/visual-defects.md` at every width it covers:

| surface | store × locale | evidence |
|---|---|---|
| header | EN/en | logo measured 40×40, brand asset |
| dropdown | EN/en | **every** dropdown opened one by one; `mega-menu.twig` width max-content |
| hero | EN/en | 16/9, max 760px, copy inside `<div class="container">` |
| homepage | EN/en | 8 bands, each named, 0 widget "not found" |
| plp | EN/en | `/EN/en/category` 200, tiles + facets + swatches render |
| pdp | EN/en | `/EN/en/<sku>` 200, gallery + variant picker |
| cart | EN/en | `/EN/en/cart` 200, 2 lines, totals styled |
| checkout | EN/en | `/EN/en/checkout` reached, each step styled |
| search | EN/en | `/EN/en/search?q=example` 200, results + facets |
| footer | EN/en | **scrolled to**; brand logo, `contact@acme.example` |
| mobile | EN/en | 390px: `scrollWidth === innerWidth`, 0 cut cards |
| tablet | EN/en | 768 / 900 / 1100px: `scrollWidth === innerWidth` each, 0 cut cards |
| header (logged in) | EN/en | via `spryker-verifier`, company user: every header text/icon ≥ 4.5:1 |

`cart`, `checkout` and `search` are on the list because the demo is walked through them — a demo
ends in a checkout, so an unstyled cart shows in the demo's final beat.

Four rules about the evidence column:

- **A measurement, a rendered value, a URL fetched or a file path — never the word "checked".**
  The step is not `done` while any surface row carries no evidence token.
- **"Every dropdown" means every dropdown opened, "footer" means scrolled to, "mobile" means at
  phone width.** A general look at the page does not include any of those three.
- **Each row carries its own evidence** — a file whose surfaces all carry identical evidence, such
  as `` `ok` `` on every row, does not count as a sweep.
- **Write it after the last change.** A sweep older than the newest template/style edit, or older
  than the last rebuild, does not cover them: write it again before marking the step `done`.

### 11b. Three layout rules

Apply each from the first edit. **A panel is sized by
its content** (`width: max-content; height: auto`), never a fixed box. **An affordance that does not act is
removed, not styled** — a chevron without a submenu. **A media box declares both `aspect-ratio` and
`object-fit`, and its copy sits in the `<div class="container">` wrapper** (`containerMode: true` on the
molecule), or the headline lands flush at x=0. Checks: `references/visual-defects.md` (b), (d), (e).

---

## 12. Consumer and `both` demos — hiding the business-only screens

When `audience` in `.ai-dev/demo-prep.md` is `consumer` or `both`, put each business surface behind **a logged-in company user**: nobody walked on a consumer demo is one, and on `both` only the business persona is.
`is_granted('ROLE_USER')` alone is not that test — a logged-in shopper passes it too:

```twig
{% set isCompanyUser = is_granted('ROLE_USER') and (app.user.customerTransfer.companyUserTransfer | default(null)) is not null %}
```

It relies on `CustomerTransferCompanyUserExpanderPlugin` in the project's `Zed/Customer/CustomerDependencyProvider.php`,
which fills `companyUserTransfer` at login — confirm it is registered. The demoshop's templates, under `src/<Ns>/Yves/`:

| surface | where it renders |
|---|---|
| company name, business-unit switch | `MenuItemCompanyWidget` in `ShopUi` `organisms/header`, `organisms/side-drawer`; `BusinessOnBehalfStatusWidget` in `CustomerPage` `molecules/navigation-sidebar` |
| quick order | `url('quick-order')` in `ShopUi` `organisms/header`, `organisms/side-drawer` |
| quotes (RFQ) | `url('quote-request')` in `ShopUi` `molecules/header-dropdown`, `organisms/account-navigation`; `QuoteRequestCreateWidget`, `QuoteRequestCartWidget` in `CartPage` `molecules/cart-summary` |
| approvals | `QuoteApprovalWidget` in `CartPage` `molecules/cart-summary`, `CheckoutPage` `views/summary` |
| shopping lists | `ShoppingListNavigationMenuWidget` in `ShopUi` `organisms/header`, `organisms/side-drawer`, `molecules/header-shopping-list-pill`; `AddToShoppingListWidget` in `ProductDetailPage` `molecules/product-configurator`; `ShoppingListMenuItemWidget` in `CustomerPage` `molecules/navigation-sidebar` |
| company-account menu | already behind `can('SeeCompanyMenuPermissionPlugin')` in `ShopUi` `molecules/header-dropdown`, `organisms/header`, `organisms/account-navigation` — keep that gate, verify it |

Grep the clone for each name first (`grep -rn "<name>" src/<Ns>/Yves --include='*.twig'`) — a project may have moved one — and wrap the include in the project override, or create one (`yves-atomic-frontend`).
**Never delete companies, lists or quotes**: the business persona and the imports still need them.
`boot-and-verify` asserts it per persona: nothing for a guest or shopper; on `both`, all of it for the company user.

## Pitfalls

Format: **symptom → cause → what to do.**

- **"Make the top image bigger" becomes a new component, project overrides, an importer change and
  rebuilds, then a rollback.** → A measurement change was read as a request to rebuild the
  landing page, and with no cost ladder logged the most expensive rung was taken. Such a fix is
  usually a few lines in one stylesheet. → Size and
  spacing complaints are rung 1 until proven otherwise: locate the element in the browser, read its
  box, change the rule that sets it, build, look. Log the ladder (§3), take the cheapest rung that
  achieves the look, and take the next rung yourself when it cannot.

- **A swap is applied to the wrong pair: the person meant the block's original position, not where
  an earlier move put it.** → a positional swap request was acted on
  against a private numbering of a subset (only the image blocks) while the page rendered a much
  larger set — so the two parties meant different blocks — and the wrong move then re-indexed
  everything behind it. → Print the numbered map from the rendered page (§2), echo every positional
  request against it and wait for the confirmation, and snapshot the map per change so "originally"
  still resolves after a revert.

- **A request to fix the navigation is answered by deleting categories from the import data.** → A
  layout problem was compensated in data; the layout itself was never changed.
  → §5: nav and layout work edits templates, styles and at most node order/labels/visibility. A
  too-long menu is fixed with depth, visibility or a template, never by removing what the shop sells.
  Catalog changes are a separate, explicitly requested step through `project-data`.

- **A restyle intended for one homepage strip repaints the product detail page as well; the markup of
  the two is compared, found identical, and the change is reported as scoped.** → The shared product tile was styled for one page, and the
  identical markup was read as proof of no impact, when it means the change reaches both — the
  difference between the two contexts was the wrapper, not the component. → §6: enumerate the consumers
  (including templates, content-widget view names, the slots they land in) before the edit, and
  register a new view/variant for a page-scoped intent.

- **Styling cycles verified with `curl | grep` while the browser is used only to read the
  reference.** → Markup presence was accepted as visual evidence, and the browser was treated as a
  reading tool rather than a verification tool. → §4: the browser that looked at the reference looks
  at the result. A cycle ends with a
  screenshot pair at every sweep width, never a grep.

- **Two utility classes swapped back and forth, neither producing the wanted result.** → The class was
  chosen by its name; the two candidates were a few px apart, which no name reveals. → §7: read the
  compiled value of the class before using it, read the current gap from the rendered page, and set
  the number you measured.

- **A panel that misaligns with everything around it on the first screenshot.** → Its width was
  invented (1440px) while the site container is 1200px. → §7: read the container's computed max-width
  from the page and inherit it; never restate a width from memory of some other site.

- **Font and asset edits reported done, page unchanged.** → The edits landed in
  gitignored build output, and in `app.css` — a stylesheet this page does not serve; it serves
  `critical.css` + `util.css`. → §8: `git check-ignore -v` the path before writing, read the page's
  own `<link>` tags to learn which stylesheet is served, edit the source component or theme file, and
  rebuild.

- **A change lands on the wrong dimension: "bigger" meant the block, not the text; "looks ugly" got
  another guess instead of a question.** → An ambiguous word was resolved by guessing, and the second
  guess also displaced what the first one moved. → §9: one question naming the two candidates, then
  one edit.

- **Reference sites were supplied — the customer's own and a second one — and never opened for
  structure.** → The references were mined for content (copy, imagery, category names) or not opened
  at all, so the composition was re-derived by trial and error, with the person checking each attempt. → §1:
  capture comes first, always, and produces a written spec the person approves (autonomous: logged,
  shown at the final review); that spec is the acceptance criterion for every cycle afterwards.

- **A block whose full-bleed and spacing live in inline CSS inside a CMS placeholder cell, and a
  second content area faked with a separator inside one cell.** → Layout was put into content because
  content looked like the cheaper rung, and the block template's shipped placeholders were treated as
  optional. → §10: layout in the template or a section modifier, content in the placeholder; more
  content areas means a second block with its own key, slot wiring and per-store rows.

- **A reset taken to undo a visual experiment, after which the person's positional references no
  longer resolve.** → A reset restores the stack but not the map the person was looking at. → §2: map snapshots are per change and survive the reset; quote the snapshot
  number in every positional echo.
