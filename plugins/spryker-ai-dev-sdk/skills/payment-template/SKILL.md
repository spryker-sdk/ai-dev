---
name: payment-template
description: >
  Use when a developer wants to build, implement, scaffold, or onboard a new PSP (Payment Service
  Provider) or payment gateway integration in Spryker from scratch — including named providers like
  Adyen, Mollie, Stripe, Braintree, Klarna, Worldpay, Buckaroo, Payone, Nuvei, or any custom PSP
  partner. Trigger whenever someone says "add X as payment provider", "implement X payment
  integration", "build a payment module for X", "set up a new payment gateway", "new PSP
  integration", or "create a payment provider module". Uses spryker-community/payment-template as
  scaffold, collects requirements interactively (payment methods, auth mechanism, webhooks),
  confirms a plan, then executes full implementation. Do NOT trigger for: debugging existing
  integrations, adding refunds to existing modules, back office payment configuration, writing
  payment tests, or API Platform migrations.
user-invocable: true
---

# Payment Template Implementation Skill

Produces a complete, working PSP payment integration through four phases:
**Collect → Plan → Confirm → Execute.**

No code is written until the developer has confirmed the implementation plan.

**Refund support is out of scope** — it will be added when the template introduces it.

---

## Hard Rules — Never Override

### Card data
Spryker must never receive raw card data server-side. Any credit/debit card payment method that
is neither a redirect flow nor backed by a PSP JS SDK is a hard stop. Do not generate card input
forms under any circumstances.

### Security patterns
These patterns are non-negotiable and override anything in IMPLEMENTATION.md:

- **Webhook controller**: always read `$request->getContent()` before any other body access;
  always return HTTP 200 regardless of signature outcome or processing result
- **Signature validation**: always use `hash_equals()`, never `===` or `==`
- **Idempotency**: check current status before updating — duplicate webhook delivery must not re-process
- **Log masking**: mask any field whose key contains (case-insensitive):
  `key`, `secret`, `token`, `password`, `authorization`, `card`, `cvv`, `pan`, `iban`
- **Secret separation**: publishable/frontend credentials and server-side secrets use separate
  constants and separate Config classes (Yves vs Zed)
- **SSRF protection**: any redirect URL received from the PSP (not constructed internally) must
  be validated against `KernelConstants::DOMAIN_WHITELIST` before use
- **No hardcoded credentials**: all credentials use `$this->get(Constants::KEY)` — no literals
- **HMAC auth**: when auth mechanism is `hmac`, the signature is computed per-request over the
  serialised request body and injected as a header — it is not a static `getDefaultHeaders()` value

---

## Standing Instruction — Clarify Before Continuing

At any point during requirements collection or implementation: if an answer is ambiguous,
contradictory, or conflicts with the hard rules — **stop and use `AskUserQuestion` to resolve
it before proceeding**.

Specific triggers:
- Method name contains card keywords but flow is `direct`
- Async operation declared but webhooks are disabled
- Redirect flow declared but no redirect domains provided
- Two answers contradict each other

---

## Pre-flight: Branch Check

```bash
git rev-parse --abbrev-ref HEAD
```

If current branch is `master`, `main`, `develop`, `staging`, `production`, `release`, or matches
`release/*` — note it. A feature branch will be created after Stage 1 collects the PSP name.
If already on a custom branch, continue without creating one.

---

## Case Conversion Reference

Derive all naming variants from the kebab-case PSP name collected in Stage 1.
Establish once, reuse everywhere:

| Variant | Rule | Example (`pay-pal`) |
|---|---|---|
| PascalCase | capitalise each segment | `PayPal` |
| camelCase | lowercase first segment | `payPal` |
| snake_case | join with underscore | `pay_pal` |
| SCREAMING_SNAKE | uppercase snake | `PAY_PAL` |
| kebab-case | as provided | `pay-pal` |

---

## Phase 1: Requirements Collection

### Stage 1 — PSP Identity

Use `AskUserQuestion` to collect:

1. **PSP name** in kebab-case (e.g. `adyen`, `mollie`, `pay-pal`)
2. **Namespace** — leave blank for `Pyz` (default), or specify a custom value
3. **Payment methods** — all methods this PSP supports, in snake_case
   (e.g. `credit_card`, `invoice`, `sepa_direct_debit`, `ideal`)

After receiving answers: establish the case conversion table.

Use `AskUserQuestion` to confirm Stage 1 answers before continuing. After confirmation, if still
on a protected branch, create the feature branch:

```bash
git checkout -b feature/payment-integration/{psp-name}
```

---

### Stage 2 — Shared PSP Configuration

Use `AskUserQuestion` to collect all of the following in one question block:

**Credentials**
- Name each credential in SCREAMING_SNAKE (e.g. `API_KEY`, `MERCHANT_ID`, `WEBHOOK_SECRET`)
- Which credentials need Yves frontend access (e.g. a publishable key for a JS SDK)?

**API endpoints**
- Sandbox base URL
- Production base URL

**Authentication mechanism** — one of:
- `api-key-header` — custom header (provide header name)
- `bearer` — `Authorization: Bearer {key}`
- `basic` — HTTP Basic Auth (API key as username, secret as password)
- `hmac` — per-request HMAC signature (provide header name and algorithm, e.g. `X-Signature`, `sha256`)
- `other` — describe it

**Operations** — for Authorize, Capture, and Cancel provide:
- Sync or async? (sync = PSP responds with final status; async = final status arrives via webhook)
- Request body fields shared across all methods
- Provider reference field name in success response (e.g. `transactionId`, `paymentId`)
- Error response shape (e.g. `{ "error": { "code": "...", "message": "..." } }`)

**Webhooks**
- Does the PSP send webhooks? (`yes` / `no`)
- If yes: signature header name, algorithm, and event-to-status mapping
  (e.g. `payment.authorized` → authorized, `payment.captured` → captured)

**Status constant names**
- Leave blank to use defaults: `authorized`, `authorization_failed`, `authorization_pending`,
  `captured`, `capture_failed`, `capture_pending`, `cancelled`, `cancel_failed`

Use `AskUserQuestion` to confirm Stage 2 before continuing.

**Webhook secret validation**: if webhooks are enabled and no credential with `SECRET` or
`WEBHOOK` in its name was listed, use `AskUserQuestion`:
> "Webhook signature validation requires a shared secret. What should this credential be named
> (e.g. `WEBHOOK_SECRET`)?"
Add the answer to the credentials list before continuing.

---

### Stage 3 — Per-Method Details

Use `AskUserQuestion` to present this table template and wait for it to be filled in:

> For each payment method from Stage 1, fill in one row.
> Use `redirect`, `direct`, or `js-sdk` for the Flow column.
> Leave Redirect domains blank if Flow is not `redirect`.
>
> | Method | Flow | Form fields (name · type · required?) | Line items? | Method-specific request fields | Redirect domains |
> |---|---|---|---|---|---|
> | credit_card | js-sdk | token · hidden · yes | no | paymentMethod.type=scheme | |
> | ideal | redirect | issuer · select · yes | no | paymentMethod.type=ideal | checkout.mollie.com, sandbox.mollie.com |
>
> If any method uses a JS SDK, also provide once:
> - SDK script URL or npm package name
> - Configuration values needed at initialisation (any publishable key must be listed in Stage 2 frontend credentials)

#### Card method detection

After receiving the table, inspect every row **before asking the developer to confirm**.

A method is a card method if the method name contains any of:
`card`, `cc`, `credit`, `debit`, `visa`, `mastercard`, `amex`, `maestro`, `discover`,
`unionpay`, `cartes_bancaires`, `jcb`

OR the form fields include any of:
`card_number`, `pan`, `cvv`, `cvc`, `cvc2`, `expiry`, `expiration`, `card_holder`, `cardholder`

For every detected card method where Flow is not `redirect` and not `js-sdk` — **HARD STOP**:

> "The method `{method_name}` is a card method and its flow is `direct`. This is not permitted —
> Spryker cannot accept raw card data server-side. Change the flow to `redirect` or `js-sdk`
> before continuing."

Do not proceed until resolved.

Use `AskUserQuestion` to present a full collected-requirements summary and get final confirmation
before moving to Phase 2.

---

## Phase 2: Implementation Planning

Fetch the authoritative implementation checklist from GitHub:

```
WebFetch: https://raw.githubusercontent.com/spryker-community/payment-template/main/IMPLEMENTATION.md
```

Read the full document. For each checklist section, generate a concrete plan entry with PSP-specific
values substituted from the collected requirements. Present as a numbered plan:

```
Implementation Plan: {PspName} Payment Integration
===================================================

Namespace:        {Namespace}
Payment methods:  {list}
Auth:             {mechanism}
Operations:       Authorize ({sync/async}), Capture ({sync/async}), Cancel ({sync/async})
Webhooks:         {yes/no}

1. Configuration & Setup
   - Constants: {API_KEY}, ... in {PspName}Constants
   - PAYMENT_PROVIDER_NAME = '{psp-name}'
   - Payment methods: {psp_name}_credit_card, ...

2. Transfer Object Schema
   - {PspName}Transfer fields: status, providerReference, amount, currencyIsoCode, ...
   - Method transfers: {PspName}CreditCard ({form fields}), ...
   - Operation transfers: Authorize/Capture/Cancel request + response
   [if webhooks] - Webhook transfer: rawPayload, eventType, signature, providerReference

3. Database Schema
   - spy_{psp_name}: status, provider_reference, order_reference, amount, currency_iso_code
   [if line items in any method] - spy_{psp_name}_order_item: sku, name, unit_price, quantity

[continue for all IMPLEMENTATION.md sections]

Commit checkpoints:
  After Phase 3 (bootstrap + rename):  "Add {PspName} payment integration scaffolding"
  After persistence complete:          "Add {PspName} persistence layer"
  After Yves layer complete:           "Add {PspName} Yves layer"
  After DependencyProvider wiring:     "Wire {PspName} plugins into project"

Security overrides active:
  - Webhook controller: raw body first, always HTTP 200
  - Signature comparison: hash_equals() only
  - Idempotency: status check before every update
  - Log masking: key/secret/token/password/authorization/card/cvv/pan/iban
  [if redirect methods] - SSRF: redirect URL host validated against KernelConstants::DOMAIN_WHITELIST
  - Secret separation: publishable keys in Yves Config only
```

Use `AskUserQuestion` to present the plan and ask for confirmation. Do not write any code or modify
any file before confirmation is received.

---

## Phase 3: Bootstrap

### Resolve project root

```bash
git rev-parse --show-toplevel
```

Store this value as `{project-root}`. Use it in all subsequent commands that need a project path.

### Clone template

Remove any leftover clone from a previous run, then clone fresh:

```bash
rm -rf /tmp/payment-template-{psp-name}
git clone https://github.com/spryker-community/payment-template.git /tmp/payment-template-{psp-name}
```

Stop and report if the clone fails.

### Run rename script

Use Workflow 3 — Direct Project Integration, following IMPLEMENTATION.md:

```bash
cd /tmp/payment-template-{psp-name} && php rename.php {psp-name} --project-path={project-root} --namespace={Namespace}
```

Verify renamed directories exist in `src/{Namespace}/`. Read
`src/{Namespace}/Zed/{PspName}/{PspName}Config.php` to confirm namespace and class name are
correct. Stop and report if wrong.

### Commit scaffolding

```bash
git add src/{Namespace}/ config/Zed/oms/
git commit -m "Add {PspName} payment integration scaffolding"
```

---

## Phase 4: Implementation

Read `/tmp/payment-template-{psp-name}/IMPLEMENTATION.md` (available from the Phase 3 clone).
Execute checklist sections **1–9 only**. Skip sections 10 (Testing), 13 (Documentation), and
14 (Deployment Checklist) — these are human tasks, not automated steps.

For every file:
1. Read the file before modifying it
2. Implement TODO stubs using the collected requirements
3. Apply security overrides from the Hard Rules section where applicable
4. Do not use `->toArray()` on transfers — map each field individually

### Conditional sections

Skip the following implementation items when the condition is not met:

| Item | Skip when |
|---|---|
| `NotificationController`, `NotificationProcessor`, webhook signature, idempotency | Webhooks = no |
| `ReturnController`, redirect OMS states (`authorization pending` timeout pattern) | No method has Flow = `redirect` |
| JS SDK data provider, SDK script tag in Twig template | No method has Flow = `js-sdk` |
| `spy_{psp_name}_order_item` schema + `saveOrderItems()` | No method has Line items = yes |
| `getWebhookEventToStatusMap()` in Config | Webhooks = no |
| `completeRedirect()` on Client facade | No method has Flow = `redirect` |

### CI gate

After each IMPLEMENTATION.md section run:

```bash
vendor/bin/spryker-ci spryker-ci --current
```

Fix all errors before continuing to the next section. CI is a gate, not a checkpoint.

After any schema file change run:

```bash
docker/sdk cli console transfer:generate
docker/sdk cli console propel:install
```

### Async operations

For any operation marked async in Stage 2: write the pending status on command execution;
final status is set by the webhook processor. OMS conditions check against the **final** status,
not the pending one. Use timeout-driven re-evaluation, not on-enter immediate check:

```xml
<state name="authorization pending">
    <flag>exclude timeout</flag>
</state>

<event name="check authorization" onEnter="false" manual="false" timeout="5 minutes"/>
```

### Commit: after persistence section complete

```bash
git add src/{Namespace}/ config/
git commit -m "Add {PspName} persistence layer: schema, entity manager, repository"
```

### Commit: after Yves layer complete

```bash
git add src/{Namespace}/
git commit -m "Add {PspName} Yves layer: forms, templates, controllers, route provider"
```

### Pyz DependencyProvider wiring

IMPLEMENTATION.md covers the module's own `DependencyProvider`. Pyz-level project wiring is
in INTEGRATION.md. Read `{project-root}/INTEGRATION.md` (copied there by the rename script) and
execute all wiring steps it describes: registering OMS processes, command/condition plugins,
checkout save/post-hooks, payment method filter plugin, router plugin, SubForm and StepHandler
plugins.

### Data import files

Add one row per payment method to `data/import/payment_method.csv`:
```csv
{psp_name}_{method},{PspName} {Method Display Name},{PspName},1
```

Add translation entries to `data/import/glossary.csv`:
```csv
payment.type.{psp_name}_{method},{Method Display Name},{Method Display Name}
```

### Commit: after DependencyProvider wiring and data import complete

```bash
git add src/Pyz/ data/import/
git commit -m "Wire {PspName} plugins into project and add payment method import data"
```

---

## Phase 5: Security Review

Verify each applicable item in the code just written. Report any failing item as a blocking issue.

**Always check:**
- [ ] Publishable/frontend keys and server-side secrets in separate constants and Config classes
- [ ] `ApiLogger` masks all fields with sensitive key names
- [ ] All credentials use `$this->get(Constants::KEY)` — no literals
- [ ] No SubForm contains visible card input fields (PAN, CVV, expiry)

**Only when webhooks are enabled (Stage 2):**
- [ ] `NotificationController` reads `$request->getContent()` before any other body access
- [ ] `NotificationController` always returns HTTP 200 regardless of signature or processing outcome
- [ ] `NotificationProcessor` uses `hash_equals()` for signature comparison
- [ ] `NotificationProcessor` checks current status before updating (idempotency)
- [ ] `saveNotification()` persists raw payload to DB only — not to application logs

**Only when redirect methods exist (Stage 3):**
- [ ] Any redirect URL received from the PSP validated against `KernelConstants::DOMAIN_WHITELIST`

---

## Phase 6: Config Snippet

Output this block for the developer to add to `config/Shared/config_default.php`.
One `$config` line per credential. Never a non-empty default for secrets:

```php
use {Namespace}\Shared\{PspName}\{PspName}Constants;

// {PspName} payment integration
// Set these environment variables in your deployment configuration.
$config[{PspName}Constants::BASE_URL]   = getenv('SPRYKER_{PSP_SCREAMING}_BASE_URL') ?: '{sandbox-base-url}';
$config[{PspName}Constants::API_KEY]    = getenv('SPRYKER_{PSP_SCREAMING}_API_KEY') ?: '';
// ... one line per credential from Stage 2
```

If any method uses redirect flow, also output a `KernelConstants::DOMAIN_WHITELIST` merge block
using the domains from Stage 3:

```php
use Spryker\Shared\Kernel\KernelConstants;

$config[KernelConstants::DOMAIN_WHITELIST] = array_merge(
    $config[KernelConstants::DOMAIN_WHITELIST] ?? [],
    [
        '{psp-hosted-page-domain}',
        '{psp-hosted-page-sandbox-domain}',
    ],
);
```

---

## Phase 7: Integration Checklist

Output with all placeholders substituted:

```
{PspName} — Remaining Manual Steps
====================================

1. Add config/Shared/config_default.php entries (see Phase 6 output)

2. Set environment variables in deployment configuration:
   SPRYKER_{PSP_SCREAMING}_BASE_URL
   SPRYKER_{PSP_SCREAMING}_API_KEY
   (one per credential from Stage 2)

3. Import payment methods:
   docker/sdk cli console data:import --config=data/import/{psp_name}.yml

4. Register webhook endpoint in PSP dashboard:
   https://{yourdomain}/{psp-name}/notification

[if redirect methods exist]
5. Register return URL in PSP dashboard:
   https://{yourdomain}/{psp-name}/return

For full project-side integration steps see INTEGRATION.md in your project root.
```

---

## Phase 8: Template Cleanup

The template clone was only needed as a scaffold. Remove the temporary directory:

```bash
rm -rf /tmp/payment-template-{psp-name}
```

Implementation is complete.
