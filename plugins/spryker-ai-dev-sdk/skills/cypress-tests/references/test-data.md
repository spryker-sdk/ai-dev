# Test data: dynamic and static fixtures

This suite does not depend on demodata being present. Each spec declares the records it needs
and the shop creates them before the spec runs, so tests are self-sufficient and don't collide
with each other. Understanding this system is usually the difference between a spec that passes
on CI and one that only passes on your machine.

The suite's own `<e2e-dir>/README.md` (section **Test data**) is the companion to this file; it
documents the available helper operations in more depth. (`<e2e-dir>` is the suite directory
located in SKILL.md Step 0 — `tests/cypress-boilerplate/` by default. All other paths in this
file are relative to it.)

## Contents

- [How a spec finds its fixtures](#how-a-spec-finds-its-fixtures)
- [Dynamic fixtures](#dynamic-fixtures)
- [Static fixtures](#static-fixtures)
- [Placeholders](#placeholders)
- [Typing fixtures](#typing-fixtures)
- [Choosing dynamic vs. static](#choosing-dynamic-vs-static)
- [What can't be generated](#what-cant-be-generated)

## How a spec finds its fixtures

Purely by convention — nothing is registered anywhere. The global `before()` in
`cypress/support/e2e.ts` derives two paths from the spec's own path:

```
spec:    cypress/e2e/storefront/cart/storefront-cart-smoke.cy.ts
dynamic: cypress/fixtures/storefront/cart/dynamic-storefront-cart-smoke.json
static:  cypress/fixtures/storefront/cart/static-storefront-cart-smoke.json
```

Both files are optional; whichever exists is loaded into `Cypress.env('dynamicFixtures')` /
`Cypress.env('staticFixtures')`. The practical consequence: **renaming or moving a spec silently
orphans its fixtures**, because the lookup follows the new path. Move the pair with the spec.

Specs read them through the typed helper rather than touching `Cypress.env` directly:

```ts
;({ dynamicFixtures, staticFixtures } = getFixtures<
  CartSmokeDynamicFixtures,
  CartSmokeStaticFixtures
>())
```

## Dynamic fixtures

A `dynamic-*.json` file is a declarative build order sent to the shop. Each operation names a
Codeception helper or data builder, and `key` publishes the created record under that name:

```json
{
  "data": {
    "type": "dynamic-fixtures",
    "attributes": {
      "synchronize": true,
      "operations": [
        {
          "type": "helper",
          "name": "haveConfirmedCustomer",
          "key": "customer",
          "arguments": [
            { "locale_name": "{{LOCALE_NAME}}", "password": "{{DEFAULT_PASSWORD}}" }
          ]
        },
        {
          "type": "helper",
          "name": "haveCompany",
          "key": "company",
          "arguments": [{ "isActive": true, "status": "approved" }]
        },
        {
          "type": "helper",
          "name": "haveCompanyBusinessUnit",
          "key": "businessUnit",
          "arguments": [{ "fkCompany": "#company.id_company" }]
        }
      ]
    }
  }
}
```

Three mechanics carry most of the weight:

- **`key`** publishes a result. Anything the spec needs to read (an email, a SKU) must have one,
  since only keyed records come back in `dynamicFixtures`.
- **`#key.field`** back-references an earlier operation's output — `"#company.id_company"`.
  This is how records get wired together, and it's why **operation order matters**: a reference
  can only point at something created above it.
- **`"synchronize": true`** waits for publish & sync to land the data in Redis/Elasticsearch
  before the spec starts. Storefront specs that search or browse a catalog need it; without it
  you get a race where the product exists in the database but isn't yet searchable.
- **`"type": "builder"`** invokes a transfer data builder (e.g. `LocalizedAttributesBuilder`)
  to generate a value — useful for unique names you then reference as
  `"#localizedAttribute.name"`.

Because every record is created per run, there is normally **no cleanup to write**: the
starting state is empty by construction. Reach for a reset hook (e.g.
`new GlueCartsScenarios().deleteAllShoppingCarts(email, password)`) only when the spec mutates
something it did not create, or creates state mid-test that a later `it` must not see.

## Static fixtures

A `static-*.json` file holds values that come from **project configuration** rather than
generated data, so they can't be created on demand:

```json
{ "defaultPassword": "{{DEFAULT_PASSWORD}}" }
```

Payment method names are the canonical case — a payment method is bound to a plugin registered
in project code (`Pyz\Yves\DummyPayment`), so a generated one would never render in checkout.

## Placeholders

`{{NAME}}` in either fixture file is substituted from Cypress's resolved environment. There are
two kinds, and knowing which is which saves a pointless edit:

- **Configuration values** you maintain in `.envs/.env.<environment>` — `{{DEFAULT_PASSWORD}}`,
  `{{PRODUCT_PRICE_ABOVE_THRESHOLD}}`.
- **Store context resolved from the running shop**, never configured —
  `{{STORE_NAME}}`, `{{LOCALE_NAME}}`, `{{CURRENCY_CODE}}`, `{{COUNTRY_ISO2}}`. `cypress.config.ts`
  derives these from the shop's default store at startup, which is why the same fixture works
  against any store without editing. Adding them to `.envs/` has no effect — the resolved values
  are applied last and win.

An unresolved placeholder fails loudly:

> Fixture placeholder `{{FOO}}` could not be resolved: environment variable FOO is not set.
> Add it to `.envs/.env.<environment>`.

Keep store-dependent values in `.envs/` rather than inline in fixtures, so the same fixture
works across environments. `PRODUCT_PRICE_ABOVE_THRESHOLD=60000` exists precisely because the
price has to clear the store's hard minimum and stay under its soft-minimum threshold
(`spy_sales_order_threshold`) — no API exposes those, so it's an environment fact, and adding a
new environment means adding the value there too.

## Typing fixtures

Declare what your fixture pair produces in `cypress/support/types/<app>/`, then pass those types
to `getFixtures<TDynamic, TStatic>()`. This is what turns a fixture typo into a compile error
from `npm run lint:check` instead of a confusing `undefined` at runtime.

Use `getProductName(product)` from `@support/fixture-helper/fixture-helper` to read a localized
product name — it resolves `localized_attributes` against `LOCALE_NAME` and throws a clear
error when the locale is missing, rather than silently yielding `undefined`.

## What can't be generated

Two documented exceptions stay as configuration, per the boilerplate README:

- **Payment methods** — bound to a registered payment plugin (see above); the method *name*
  lives in the static fixture.
- **Glue shipment/payment identifiers** — the shipment method id and payment provider/method
  names the Glue checkout payload sends live in `cypress/fixtures/shared/checkout-data.json`.
  It sits in `shared/` because Glue, Backoffice, and Merchant Portal specs all place their
  setup order the same way.
