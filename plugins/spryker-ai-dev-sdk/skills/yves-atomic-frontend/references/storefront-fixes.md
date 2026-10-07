# Storefront fixes — Twig traps, caches, form fields and verifying a fix

Read this when a Yves change "does nothing", when a template edit needs to hide or suppress something,
when a checkout address form is involved, or before reporting a UI fix as verified.

## Contents
- Twig traps
- Vendor mixins
- Suppressing a form field / hiding a block / checkout address-step forms
- Builds, stylesheets and caches
- Verifying a UI fix

## Twig traps

- **`{# #}` is a statement, not whitespace.** Legal between statements; inside an expression, a `| merge(...)` chain, an `embed:`/`with {}` hash or an argument list — the places a dense Spryker template tempts you to annotate — it raises `Twig\Error\SyntaxError - Unexpected character` (or `Unclosed "block"`, pointing at a line far from the comment). Put it on its own line above the `{% ... %}` it explains; to annotate one key of a map, comment above the whole statement (the example above does this). The error surfaces only on the next page load, and the Twig cache (see "After Creating Components") can serve the old compiled template first — so after a comment-only edit, clear the cache and load one page before moving on.
- **An `aria-hidden` element must not carry a `title`** — assistive tech ignores it, and sighted mouse users get a meaningless tooltip. The shipped `font-icon.twig` does exactly this: `{% define attributes %}` defaults `title: data.name`, so every icon on every page tooltips its ligature (`shopping_cart`, `menu`, `person`). One-line fix in a Pyz override of the atom: drop `title` from the attributes define. Check in the browser: `document.querySelectorAll('[aria-hidden="true"][title]').length` → `0`.

## Vendor mixins

**Before `@include`-ing a vendor mixin in an override, confirm it exists:**
```bash
grep -rn "@mixin shop-ui-color-selector" vendor/spryker-shop/shop-ui vendor/spryker-shop/{module}
```
A non-existent mixin (e.g. `shop-ui-color-selector`) breaks the **whole** frontend build, and the build error does not name the offending file clearly. Grep vendor first; do not rely on the build to catch it.

## Suppressing a Form Field

Hiding a Symfony form field (e.g. the "billing same as shipping" checkbox in `address-item-form`) has two traps. Both are invisible to a green render — the page loads fine and the field is still there.

- **Skipping `form_row` does NOT remove the field.** Symfony's `form_rest()` renders every un-rendered field at the end of the form, unstyled and visible. The field must stay rendered.
- **Hiding by class can be undone at runtime.** `address-item-form` passes `billingSameAsShippingClassName` to the JS as `elements-to-toggle-class`; the component strips `is-hidden` back off that class. The wrapper must also **drop** the JS-targeted class, or the hide does not survive.

**Working shape:**
1. Render the field (keep `form_row`), so `form_rest()` has nothing left to emit.
2. Render it unchecked / with the neutral value.
3. Hide the **wrapper** (`is-hidden`), not the field.
4. Omit the JS-targeted class (the one passed as `elements-to-toggle-class`) from that wrapper.

Verify in the rendered DOM, not by the absence of a Twig error.

## Hiding a Block That Carries Something Else

Before hiding a component, list every widget and input it renders — a list, badge or row often carries data the page still needs. Example: on a marketplace PDP with a single seller, hiding the seller list (`BuyBox` → `seller-offers` → `seller-list` → `seller-list-item`) also hides the stock badge, because `seller-list-item.twig` renders `ProductAvailabilityWidget` for each offer; and the checked `product_offer_reference` radio is what `seller-list.ts` copies into the add-to-cart form's offer input on mount — so the component must stay rendered.

**Working shape:** keep the list rendered inside an `is-hidden` wrapper, pick the selected offer (`data.offers | filter(o => o.productOfferReference == selectedReference) | first`, else the first offer), and render `{% widget 'ProductAvailabilityWidget' args [selectedOffer.availabilityProduct] only %}{% endwidget %}` outside the wrapper. Check: the availability text is visible on the PDP, and add-to-cart still posts the offer reference.

## Checkout Address-Step Forms: Item-level AND Quote-level

A fix to the ITEM-level sub-form must be mirrored on the QUOTE-level form in the same change:

- A cart that is **not** "deliver to multiple addresses" is validated through the **quote-level** form, not the per-item one. Example: `ClickAndCollectServiceTypeSubForm` reads `option_available_shipment_types`, which `ShipmentTypeFormOptionExpander` builds once for the whole quote — so "take the first pickable entry" is wrong in both places, and fixing only the item form leaves the single-item cart broken.
- Fix `ItemTransfer` **and** `QuoteTransfer` data together.
- Verify on a **single-item** cart as well as a multi-item one — they take different code paths, and the single-item path is the one that is easy to miss.

---


## Builds, stylesheets and caches

If `/data/node_modules` exists in the cli container (check with `docker/sdk cli "ls -d /data/node_modules"`), the console build is available for JS/SCSS changes:

```bash
docker/sdk console frontend:yves:build
```

**Verify against the stylesheet the page actually LINKS — a storefront may serve `critical.css` + `util.css` rather than `app.css`.** Read the `<link rel="stylesheet">` hrefs out of the rendered page and grep *those* files for your rule — a fix verified in `app.css` is verified in a bundle this storefront never serves.

**An asset written into the published assets directory does not survive the next build.** `public/Yves/assets/**` is generated output. The **source** of a served static file is `frontend/static/**`, and it reaches `public/` only when a build copies it there. So the order is: write the source file → run the build → fetch the served URL. Dropping a file into `public/` and reporting it done ships a change that the next build silently removes.

**A `.twig` change that "does nothing"** — the file is correct on disk and inside the container, yet the rendered page is the old one — is the Yves Twig cache, not a wrong fix. Clear it before diagnosing anything else. **The compiled Twig cache and the template path map live in `src/Generated/Yves/Twig/codeBucket`** (`Spryker\Shared\Twig\TwigConfig::getDefaultTwigOptions()` / `getDefaultPathCache()`), unless the project config overrides it — `grep -rn "YVES_TWIG_OPTIONS\|YVES_PATH_CACHE_FILE" config/Shared/` and read the file the running environment loads. Clear it inside the container, then confirm it is gone:

```bash
docker/sdk cli "rm -rf src/Generated/Yves/Twig/codeBucket"
docker/sdk cli "ls -A src/Generated/Yves/Twig"   # codeBucket absent; the next page load recreates it
```

**Never delete `data/cache/Yves/<env>` as a Twig clear.** That directory is the Symfony kernel cache (`Spryker\Shared\Application\Kernel::getCacheDir()`) — the compiled DI container (`Container<hash>/…`, `*DebugContainer.php`), not templates. Deleting it clears no template and, while the sync is still propagating the delete, requests fail with a 500 `Failed opening required '…/Container<hash>/…Service.php'`. `console cache:empty-all` also empties every `src/Generated/*/*/codeBucket` (Zed Twig and the routers too) — use it when the narrow clear is not enough.

**A NEW override template is invisible until the path map is rebuilt.** The loader resolves each template name once and stores the result in `codeBucket/.pathCache`; a name already mapped to the vendor file keeps resolving there, so creating `src/Pyz/Yves/<Module>/Theme/default/views/…/x.twig` changes nothing while edits to existing overrides show up fine. After **creating** (not editing) an override, rebuild the map — `docker/sdk console twig:cache:warmer` rewrites `.pathCache` for Yves and Zed (the Twig clear above removes it too) — then confirm the new path appears: `docker/sdk cli "grep -c 'Pyz/Yves/<Module>/Theme/default/views/…/x.twig' src/Generated/Yves/Twig/codeBucket/.pathCache"`.

`docker/sdk up --assets` clears neither. Skipping this step leads to a false "the change had no effect" conclusion and a second, unnecessary code change — and a cache clear is not verification: load the page and look.

## Verifying a UI Fix

A UI fix is verified when the **rendered DOM** shows the control:
- **not** inside a hidden container (no `is-hidden` ancestor), and
- **not** disabled.

Rules:
- A hand-built POST that the server accepts (e.g. `servicePoint[uuid]` returning `302`) tests the **endpoint**, never the fix. If the UI cannot produce that value, the shopper is still at a dead end.
- Build the verification cart the way the shopper does — through the UI / configurator — not by POST. A cart assembled by POST skips steps (e.g. product configuration) and place-order then fails with an unrelated-looking error.
- Where the fix is cart-shape-specific, verify on each shape it claims to cover (see the single-item vs multi-item note above).

**After a build, hard-reload BEFORE measuring anything.** Yves' built CSS/JS carries no content hash, so the browser keeps serving the previous build and every number, screenshot and verdict you take comes from it. Bust it first — `document.querySelectorAll('link[rel="stylesheet"]').forEach(l => l.href = l.href.split('?')[0] + '?cb=' + Date.now())`, wait for the re-fetch — or reload bypassing cache, then measure. A measurement taken off a cached stylesheet describes the previous build.

**Measure, don't estimate.** For any spacing / alignment / size complaint, open the page in Claude in Chrome and read numbers (when loaded from `spryker-customization`, hand the measurement to `spryker-verifier` — that skill's main loop does not drive the browser) — `el.getBoundingClientRect()` and `getComputedStyle(el).marginTop` give exact px; a guessed margin costs one developer round-trip per guess, while one measurement identifies the change directly. Screenshot the result and compare it against the reference (the developer's screenshot, or the reference site / `.ai-dev/composition.md`) **before** reporting, not after the developer rejects it. `grep` or `curl` on the markup proves the string is present — never that it is legible, aligned or the right size. Failure signature: the developer replies with a screenshot right after you reported a visual change complete.

**Run the recurring-defects checks on the band you changed** — cut faces, images not filling their box or misaligned, empty blocks, dead chevrons, low header contrast (guest and logged in), layout breaks at 390 / 768 / 900 / 1100 / 1440 px. Each has a measurable check and a JS snippet in `../../match-reference-design/references/visual-defects.md`; a pass there still ends with a zoomed screenshot next to the reference.

**Regression on a file you changed → read the committed version first.** `git show HEAD:<file>` and diff it against yours before changing a single property. On a visual regression this is mandatory: the diff usually *is* the defect (an override that adds a modifier can drop blocks the shipped template rendered — restoring the shipped file fixes it where guessed CSS does not). Failure signature: a second round of single-property changes on the same file without having read its committed version.

**Wording / translation ACs sweep attributes, not just text.** A "no user-visible 'cart'" check can pass every text node and still fail on `title="shopping_cart"` (the icon atom) or `aria-label="Basket, 2 %d items in basket"` (the `header.cart.items` glossary key). The surface is text nodes **plus** `aria-label`, `title`, `alt`, `placeholder` and submit-button `value`; `title` is invisible until hover, so screenshots miss it too. Check in the browser:

```js
[...document.querySelectorAll('[aria-label],[title],[alt],[placeholder],[type=submit]')]
  .flatMap(e => ['aria-label','title','alt','placeholder','value'].map(a => e.getAttribute(a)))
  .filter(v => v && /cart/i.test(v))   // must be []
```

A glossary value carrying `%d` / `%s` that its consuming template never interpolates (the `header.cart.items` example above — the template prepends the count itself) is a silent defect: it renders, nothing errors, no gate flags it. Grep the project glossary for `%[sd]` and confirm each consumer passes the parameter.

