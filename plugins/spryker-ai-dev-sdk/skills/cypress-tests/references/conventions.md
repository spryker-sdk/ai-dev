# Conventions

The shapes every new spec, page object, and fixture is expected to match. All examples are
real code from the suite — grep the neighbours before inventing anything. `<e2e-dir>` is the
suite directory located in SKILL.md Step 0 (`tests/cypress-boilerplate/` by default; the
`cypress-migration` skill may have vendored it under another name).

## Contents

- [Directory layout](#directory-layout)
- [Naming](#naming)
- [Path aliases](#path-aliases)
- [Page objects](#page-objects)
- [Locators](#locators)
- [Specs](#specs)
- [Scenarios vs. page objects vs. cy-commands](#scenarios-vs-page-objects-vs-cy-commands)
- [Waiting](#waiting)

## Directory layout

```
<e2e-dir>/                                 # tests/cypress-boilerplate/ by default
├── cypress/
│   ├── e2e/<app>/<feature>/<app>-<feature>.cy.ts
│   ├── fixtures/<app>/<feature>/{static,dynamic}-<spec-name>.json
│   │   └── shared/                     # cross-app config values (checkout-data.json)
│   └── support/
│       ├── page-objects/<app>/<section>/<app>-<section>-<page>.ts
│       ├── page-objects/abstract-page.ts
│       ├── scenarios/<app>/<app>-<domain>-scenarios.ts
│       ├── cy-commands/<app>/<domain>-commands.ts
│       ├── glue-endpoints/            # Glue URL builders
│       ├── api-helper/                # request/auth plumbing
│       ├── fixture-helper/            # getFixtures(), getProductName()
│       ├── types/<app>/               # fixture type declarations + index.ts barrel
│       └── e2e.ts                     # global before() that loads fixtures
├── .envs/.env.<environment>           # local, ci
└── package.json
```

`<app>` is exactly one of `storefront`, `backoffice`, `merchant-portal`, `glue`. Nothing else —
these four are what the fixture loader, the `.envs` URLs, and the CI job all assume.

## Naming

Kebab-case everywhere, with the app as the leading token so files sort by application:

| Kind | Pattern | Real example |
|---|---|---|
| Spec | `<app>-<feature>.cy.ts` | `storefront-cart-smoke.cy.ts` |
| Page object | `<app>-<section>-<page>.ts` | `storefront-checkout-address-page.ts` |
| Scenario | `<app>-<domain>-scenarios.ts` | `glue-carts-scenarios.ts` |
| Fixture pair | `{static,dynamic}-<spec-name>.json` | `dynamic-storefront-cart-smoke.json` |
| Class | PascalCase of the filename | `StorefrontCheckoutAddressPage` |

A flyout or partial that isn't a whole page drops the `-page` suffix
(`storefront-cart-flyout.ts`, `storefront-search-suggestions-flyout.ts`) — the suffix
communicates whether the thing has its own URL.

## Path aliases

Only two are defined (`tsconfig.json`), and both are mandatory — relative `../../..` imports
across directories will not survive review:

```ts
import { StorefrontCartPage } from '@support/page-objects/storefront/cart/storefront-cart-page'
import checkoutData from '@fixtures/shared/checkout-data.json'
```

Inside `support/page-objects/`, sibling imports stay relative (`../../abstract-page`) — that's
what the existing page objects do, since the alias would be noise for a one-hop import.

## Page objects

Every page object extends `AbstractPage`, which supplies `visit()` and demands a `PAGE_URL`:

```ts
export abstract class AbstractPage {
  protected abstract PAGE_URL: string
  visit = (options?: Partial<Cypress.VisitOptions>): void => {
    cy.visit(this.PAGE_URL, options)
  }
}
```

Build `PAGE_URL` from env values, never a hardcoded host, so the same spec runs against local
and CI:

```ts
export class StorefrontCartPage extends AbstractPage {
  protected PAGE_URL =
    Cypress.env('STOREFRONT_URL') + '/' + Cypress.env('LOCALE_PREFIX') + '/cart'

  getCartItem = (concreteSku: string): Cypress.Chainable => {
    return this.getCartItemsList()
      .contains('span[itemprop="sku"]', concreteSku)
      .parents('[data-qa="component product-cart-item"]')
  }
}
```

Shape rules, and the reasoning behind them:

- **Arrow-function properties**, returning `Cypress.Chainable` for getters and `void` for
  actions. Consistency here matters more than the choice itself — mixed styles make the
  classes harder to skim.
- **Getters return elements; they don't assert.** The spec owns assertions, because that's
  where the reader looks to learn what the test actually claims. A page object that asserts
  hides the claim from the spec.
- **Compose getters** (`getCartItemPrice` builds on `getCartItem` builds on
  `getCartItemsList`) so a markup change is a one-line fix in one place.
- **Take domain arguments, not selectors** — `getCartItem(sku)`, so the spec never learns
  what the DOM looks like.

## Locators

`data-qa` is this project's convention, present in both the Twig templates and these specs.
Don't introduce `data-testid` or `data-cy`; a second convention means every future author has
to check which one applies.

Preference order when you need a hook:

1. An existing `data-qa` attribute — `[data-qa="cart-go-to-checkout"]`.
2. A stable semantic selector already used in the suite — `[itemprop="price"]`,
   `span[itemprop="sku"]`, or a custom element tag like `cart-items-list`. Microdata and
   custom element names are part of the rendered contract, so they're safe to lean on.
3. A new `data-qa` attribute added to the Twig template, named consistently with its
   siblings. Do this last, and only when nothing stable exists.

**Read the rendered DOM, not the Twig, when picking a selector.** Twig is the *pre-hydration*
source: ShopUi custom elements re-render the control, so the vendor template can be honestly
wrong — a plain quantity form field in the Twig renders at runtime as a `<quantity-counter>`
custom element with its own buttons. Confirm against the live markup
(`cy.get('<parent>').then(el => cy.log(el.html()))`, the failure screenshot, or a `--headed`
run) before writing the selector; treat Twig as a hint. Failure signature: a selector that
demonstrably exists in the template and never matches at runtime. Known wrappers:
`<quantity-counter>`, `<cart-items-list>`, `<flash-message>`.

Never use nth-child/positional CSS, generated class hashes, or XPath — they encode incidental
layout, so they break on unrelated redesigns and produce failures that look like real bugs.

## Specs

Instantiate page objects at module scope, declare typed fixture variables, load fixtures in
`before`, then write flat readable steps:

```ts
const cartPage = new StorefrontCartPage()

let dynamicFixtures: CartSmokeDynamicFixtures
let staticFixtures: CartSmokeStaticFixtures

context('Storefront smoke: homepage to cart', () => {
  before(() => {
    ;({ dynamicFixtures, staticFixtures } = getFixtures<
      CartSmokeDynamicFixtures,
      CartSmokeStaticFixtures
    >())
  })

  it('can find a product from the homepage and add it to the cart', () => {
    storefrontLoginPage.login(
      dynamicFixtures.customer.email,
      staticFixtures.defaultPassword
    )
    homePage.visit()
    search.findProduct(dynamicFixtures.product.abstract_sku)
    cartIcon.getCartBadge().should('contain', '1')
  })
})
```

- **Use `context()` for the outer block**, not `describe()` — every spec in the suite does, so
  matching keeps the reports consistent.
- **Assert on values, not presence.** `.should('contain', expectedPrice)` tells you what broke;
  `.should('exist')` only tells you something is gone. Use fixture data as the expected value
  so the assertion can't drift from the seeded state.
- **No `cy.get()` / `cy.visit()` in a spec.** Selectors belong in page objects — this is the
  one rule that keeps a markup change from touching ten specs.
- `cy.formatDisplayPrice()` converts minor-unit fixture amounts to the rendered string; use it
  rather than hand-formatting currency. But it is **project-local suite code, not a Cypress
  builtin** — grep `support/cy-commands/` to confirm this project has it, that it derives
  currency and locale from the resolved store context (`CURRENCY_CODE`/`LOCALE_NAME`) rather
  than literals, and that it passes `currencyDisplay: 'narrowSymbol'` to `Intl.NumberFormat`.
  Without that option ICU picks a per-runtime default: UAH renders `грн` under Electron's
  bundled ICU and `₴` under Chrome and the app's own PHP formatter. **A currency-string
  mismatch that differs only in symbol form is a helper defect, not an app defect** — fix the
  command, don't file a bug.
- Titles read as capabilities (`'can find a product from the homepage and add it to the cart'`),
  so a CI failure line states which user-facing capability regressed.

## Scenarios vs. page objects vs. cy-commands

Three homes for reusable logic; picking the wrong one is the most common structural mistake:

| Use | For | Example |
|---|---|---|
| **Page object** | Interacting with one page's DOM | `StorefrontCartPage.getCartItemPrice()` |
| **Scenario** | A multi-step flow, often via API, spanning pages | `new GlueCartsScenarios().deleteAllShoppingCarts()` |
| **cy-command** | A globally useful primitive registered on `cy` | `cy.formatDisplayPrice()` |

Reuse before adding: check `support/page-objects/` and `support/scenarios/` first. Duplicated
selector logic is what this structure exists to prevent.

## Waiting

Rely on Cypress retry-ability and raise the timeout on the specific getter that's genuinely
slow, with a comment saying why:

```ts
// wait longer for the cart items list to appear after add-to-cart navigation
return cy.get('cart-items-list', { timeout: 20000 })
```

Avoid fixed `cy.wait(<number>)` sleeps — they're simultaneously too slow on a fast machine and
too short on a loaded CI runner, which is exactly how flake gets in. Wait on an intercepted
alias (`cy.wait('@alias')`) when you need to synchronize on a request.

### Optimistic UI vs. persisted state

An intercepted alias is not only a flake tool — for ajax mutations it's a correctness
requirement. ShopUi controls update the DOM *before* the request that persists the change
resolves, so **the rendered value can disagree with what the server actually stored**. After any
action that mutates server state through ajax (cart quantity, wishlist, shopping list), the
page-object action must intercept the mutation route and wait for a 2xx:

```ts
cy.intercept('POST', '**/cart/async/change-quantity/**').as('changeQuantity')
this.getQuantityIncreaseButton(sku).click()
cy.wait('@changeQuantity').its('response.statusCode').should('eq', 200)
```

And the spec must re-`visit()` the page before asserting a value it will later rely on for money
or checkout, so the assertion reads persisted state rather than the optimistic DOM. Failure
signature: **the assertion passes and the resulting order is wrong.** A real spec asserted cart
quantity 2 against the optimistic `<quantity-counter>` input, checked out, and placed the order
with quantity 1 — green, and wrong. "Assert on values, not presence" does not protect you here;
the optimistic DOM satisfies it.
