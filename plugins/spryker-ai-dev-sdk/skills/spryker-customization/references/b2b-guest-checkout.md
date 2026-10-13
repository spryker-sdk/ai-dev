# Guest checkout on the B2B demoshop — the known recipe

The procedure for letting a shopper who is not logged in see prices, fill a cart and **place an order**
on a `b2b-demo-marketplace` clone. The clone ships as a business shop, and guest checkout fails at
several points in turn, each hidden until the one before it is fixed. Apply **every** change below in
one pass, then verify both cart shapes. Fixing them one at a time costs a rebuild per blocker.

## When it applies

- The audience is `consumer` or `both` and the project is a `b2b-demo-marketplace` clone. A `consumer`
  demo always needs it, because its brief has shoppers buying without an account.
- **Not** for a `business`-only demo, and not for a B2C clone (where guest checkout already works).
- In `demo-prep-wizard` it is the "shoppers see prices and can buy without an account" row: one
  customization row, parts 1 to 3, all this skill's work under the **demo preset** (Step 0). The recipe
  is the plan: no solution design, no discovery of the blockers. On an unmodified clone the wizard runs
  the row after `project-starter-wizard`'s pre-boot steps (1–7) and before its step 8, in the preset's
  **pre-boot mode**: parts 1 to 3 as file edits only, and the checks under "Verify" run in
  `boot-and-verify` after the first boot. On a clone that is already booted, the row runs in full and
  its report says "needs a reset" (part 1).

**The project namespace** is the first entry of `PROJECT_NAMESPACES` in `config/Shared/config_default.php`
(`Pyz` on a stock clone); paths below write it as `<ProjectNamespace>`. Read it before the first edit: a
demo branch can ship another namespace ahead of `Pyz`, and where that namespace carries its own copy of a
class or template, it shadows the `Pyz` one, so an edit under `src/Pyz` changes nothing. Edit each existing
file in the first listed namespace that carries it; create new files (3a, 3f) in the first one.

Most files below are existing demoshop files or Twig overrides. Part 1 is a PHP config class and **one new
PHP class is needed** (3f), which is why the whole row is customization rather than setup or look and content.

## 1 · Customer access (before the first boot)

`src/<ProjectNamespace>/Zed/CustomerAccess/CustomerAccessConfig.php`, `getContentAccessByType()`:

```php
SprykerSharedCustomerAccessConfig::CONTENT_TYPE_PRICE => true,
SprykerSharedCustomerAccessConfig::CONTENT_TYPE_ORDER_PLACE_SUBMIT => true,
SprykerSharedCustomerAccessConfig::CONTENT_TYPE_ADD_TO_CART => true,
```

- **`true` means open to guests.** The installer stores `is_restricted = !value`
  (`CustomerAccessInstaller::install()`). The demoshop ships `false`, which means restricted.
- **The installer only inserts missing rows.** If the row already exists, it prints *"You need to clean
  up table spy_unauthenticated_customer_access…"* and skips it. A change after the first install
  therefore needs a fresh database (`reset`), or that table emptied before the installer runs again.
  This skill runs neither (What you do NOT do): on a clone that is already booted, finish the other
  parts and put "needs a reset" in the report. Under the demo preset, `demo-prep-wizard` runs it through
  `boot-and-verify` §3b.
  Only `price` can be toggled in the Back Office. `add-to-cart` and `order-place-submit` are not in
  `getManageableContentTypes()`.

## 2 · Security pattern

`config/Shared/config_default.php`, `CustomerConstants::CUSTOMER_SECURED_PATTERN`:

- **Cart and checkout.** Part 1 grants `add-to-cart` and `order-place-submit`. After that, the
  `CustomerAccessSecuredPatternRulePlugin` removes the literal substrings `|^(/en|/de)?/cart(?!/add)`
  and `|^(/en|/de)?/checkout` from the pattern for guests (a `str_replace` in
  `CustomerAccessPermissionConfig::CONTENT_TYPE_PERMISSION_ACCESS`). It only matches the exact text. If
  the project changed the locale group in the pattern (for example, added a language), nothing matches
  and cart and checkout stay behind the login. In that case, **delete the cart and checkout alternatives
  from the pattern by hand**.
- **Service-point widget.** The pickup selector loads `customer/ssp-service-point-widget-content` and
  `customer/ssp-service-point-widget/search`, and both match the `customer($|/)` alternative. Add a
  lookahead to that alternative only:
  `…[/]*customer(?!/ssp-service-point-widget)($|/)|…`. The rest of `/customer` stays secured.

## 3 · The checkout changes

**a. Login step: the "continue as guest" toggle is never rendered.** The core view renders both
togglers in `{% block title %}`. The project's `page-layout-checkout.twig` empties that block, and its
`pageInfo` and `contentWrap` overrides never output it, so the guest form stays hidden. Add a new
override, `src/<ProjectNamespace>/Yves/CheckoutPage/Theme/default/views/login/login.twig`:

```twig
{% extends view('login', '@SprykerShop:CheckoutPage') %}

{% block content %}
    <div class="spacing-bottom spacing-bottom--big">{{ block('title') }}</div>
    {{ parent() }}
{% endblock %}
```

**b. Summary step: "place order" stays disabled.** In
`src/<ProjectNamespace>/Yves/CheckoutPage/Theme/default/views/summary/summary.twig`, the submit is gated on
`can('WriteSharedCartPermissionPlugin', data.cart.idQuote)`. A guest quote has no id and no
permission, so the check is false. Change the gate so logged-in users keep it:

```twig
enable: data.isPlaceableOrder and (not is_granted('ROLE_USER') or can('WriteSharedCartPermissionPlugin', data.cart.idQuote)),
```

**c. Pickup: the service-point selector is missing for guests.** The core
`service-point-shipment-types.twig` wraps `{% block servicePointSelector %}` in
`{% if is_granted('ROLE_USER') %}`. In the existing project override
`src/<ProjectNamespace>/Yves/SelfServicePortal/Theme/default/components/molecules/service-point-shipment-types/service-point-shipment-types.twig`,
redefine the block without the condition:

```twig
{% block servicePointSelector %}
    {{ block('ajax') }}
    {{ block('render') }}
{% endblock %}
```

This change only works together with the pattern lookahead in part 2. Without it, the widget's AJAX
call is redirected to the login page.

**d. Address step: the Next button stays disabled.** `validate-next-checkout-step` counts the required
fields of every address form unless the form, or its closest `…__address-form-container`, has
`is-hidden`. The quote-level shipping form sits in a static `<div class="is-hidden …">` wrapper that is
not a container. For a guest it is always empty, so its required fields keep the button disabled. In
`src/<ProjectNamespace>/Yves/CheckoutPage/Theme/default/views/address/address.twig`, give that wrapper the container
class for guests:

```twig
<div class="is-hidden col col--sm-12{{ not is_granted('ROLE_USER') ? ' ' ~ addressFormContainerClassName }}">
```

**e. Address step: the server rejects the step.** `CheckoutAddressCollectionForm` validates the
quote-level `shippingAddress` unless `id_customer_address` (or `id_company_unit_address`) is non-empty.
The layout shows only the item-level (per shipment group) forms. In the same `address.twig`, add a
guest branch next to the company-address branch. It posts `-1`
(`CheckoutAddressForm::VALUE_DELIVER_TO_MULTIPLE_ADDRESSES`), so the item-level addresses are the ones
that count:

```twig
{% elseif not is_granted('ROLE_USER') %}
    <input type="hidden" name="{{ embed.forms.shipping.id_customer_address.vars.full_name }}" value="-1">
    {% do embed.forms.shipping.id_customer_address.setRendered %}
{% else %}
    {# the existing form_row(embed.forms.shipping.id_customer_address …) #}
```

**f. Place order: redirected back with "permission failed" (new PHP class).**
`CheckoutController::placeOrderAction()` checks
`can('PlaceOrderWithAmountUpToPermissionPlugin', $grandTotal)`. The plugin is registered in the Client
`PermissionDependencyProvider`, and a guest's permission collection is empty, so
`PermissionExecutor::can()` returns false. Parts a to e do not fix this. Add a Client permission storage
plugin that grants the cart and place-order permissions **to guests only**, for example
`src/<ProjectNamespace>/Client/CustomerAccessPermission/Plugin/GuestCartPermissionStoragePlugin.php`:

```php
class GuestCartPermissionStoragePlugin extends AbstractPlugin implements PermissionStoragePluginInterface
{
    protected const array GUEST_PERMISSIONS = [
        'AddCartItemPermissionPlugin' => [],
        'ChangeCartItemPermissionPlugin' => [],
        'RemoveCartItemPermissionPlugin' => [],
        'PlaceOrderPermissionPlugin' => [],
        'PlaceOrderWithAmountUpToPermissionPlugin' => ['cent_amount' => 100000000],
    ];

    public function getPermissionCollection(): PermissionCollectionTransfer
    {
        $collection = new PermissionCollectionTransfer();
        if ($this->getFactory()->getCustomerClient()->isLoggedIn()) {
            return $collection;
        }
        foreach (static::GUEST_PERMISSIONS as $key => $configuration) {
            $collection->addPermission((new PermissionTransfer())->setKey($key)->setConfiguration($configuration));
        }

        return $collection;
    }
}
```

(`@method \Spryker\Client\CustomerAccessPermission\CustomerAccessPermissionFactory getFactory()`.)
Register it in `src/<ProjectNamespace>/Client/Permission/PermissionDependencyProvider::getPermissionStoragePlugins()`
after `CustomerAccessPermissionStoragePlugin`. The cart-item grants also bring back the quantity and
remove controls for guests, because the project's cart templates gate them on `can(...)`. They also allow the
configurable-bundle add-to-cart. Do not unregister `PlaceOrderWithAmountUpToPermissionPlugin` instead:
company users' order limits depend on it.

**g. Header: the cart icon is missing for guests.** The project's header override
(`src/<ProjectNamespace>/Yves/ShopUi/Theme/default/components/organisms/header/header.twig`, and the
same file under `src/Pyz` when the project namespace is `Pyz`) renders the cart pill — the
`MiniCartWidget` in `__cart-pill-wrap` and the `cart-panel` drawer trigger — inside
`{% if is_granted('ROLE_USER') %}`. A guest can add to the cart but has no cart icon to reach it. Move
the `__cart-pill-wrap` block out of that condition so it renders for every shopper, and keep the
account pill, agent bar and sign-in button where they are. Do the same for the `MiniCartWidget` near the
top of `organisms/side-drawer/side-drawer.twig`, which is the cart in the phone drawer; the drawer's
`cart-panel` itself is not gated. A new override file needs the Twig cache warmed
(`twig:cache:warmer`) before it renders.

**Optional:** in `page-layout-order-submitted.twig`, wrap the "to my orders" button in
`{% if is_granted('ROLE_USER') %}`. It links into the secured customer area.

## Verify: both cart shapes, every store

As a guest (a fresh session, never logged in), in **each** store:

1. The PDP shows the price and add-to-cart. `/cart` and `/checkout` do not redirect to the login page.
   After an add-to-cart, the header shows the cart icon with the item count, and it opens the cart, at
   desktop and phone width.
2. **Single-item delivery cart.** Login step → "continue as guest" → address step (fill the item-level
   form; the Next button enables) → shipment → payment → summary (place order is enabled) → place order
   → success page. The order is in the Back Office.
3. **Single-item pickup cart.** The service-point selector renders on the PDP and its AJAX call returns
   200, not a login redirect. Checkout completes the same way.
4. A logged-in business persona still sees the secured pages (`/customer/…`, shopping lists, quotes).
   Its summary gate still follows the shared-cart permission.

A pickup-only check can pass while delivery still fails at d and e. Both break the delivery address
form. **The delivery cart is the case that proves the recipe.**
