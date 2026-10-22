# Example handover report — shape only

The real report is Markdown or HTML for a paste into Confluence
([references/handover-report.md](references/handover-report.md) § Format). This example shows the
parts and the level of detail for an invented project, ACME B2B. All data, including the security
issues, is invented.

---

# ACME B2B: Spryker upgrade 202512 → 202606

**Contents** — 1. Executive summary · 2. Technical details · 3. Action items for the development
team · 4. Existing issues observed · 5. Performance guideline check · 6. New Spryker features

## 1. Executive summary

**Goal:** move the shop to Spryker release 202606. Spryker supports and patches this release, and its
newest features need it.

**What was done:**

- **Tests.** Automated tests now check the search, ordering, approval and merchant order workflows.
  The tests passed after the upgrade. They show a broken workflow before it gets to production.
- **Upgrade.** The shop now runs on release 202606. The Back Office has a new design. The menu
  entries and the pages did not move.
- **Performance.** The category page loads product prices in one batch. Part 5 lists more
  improvements that were not applied.

**Changes in behavior:**

- Every user with access to a shared cart can now add comments to it. Before, only the owner could.

**Security fixes:** the upgrade fixes 2 of the 3 security issues that Spryker published after release
202512. 1 issue stays open, because the shop has its own version of the affected page. Part 2 gives
a recommended fix.

**Existing issues:** the work found 3 issues that existed before the upgrade. For example, the cart
does not apply volume prices when the quantity changes. The upgrade does not change these issues.
Part 4 lists them.

**What happens next:** the development team reviews both pull requests. Then the team releases them,
staging first. Part 3 gives the steps.

## 2. Technical details

### 2.1 Pull requests

The work starts from `master`.

| Pull request | Branch | Content |
|---|---|---|
| #41 | upgrade-202606-tests | Tests: functional and end-to-end tests, and their CI |
| #42 | upgrade-202606 | Upgrade: Spryker 202606, the changes that it needs, performance improvements |

### 2.2 Tests pull request details

- Codeception: 12 files, 86 tests, through the facades of the customized modules.
- Cypress: 9 specs, 31 cases for the storefront, the Back Office and the Merchant Portal.
- Code fix that the tests needed: the order details page no longer fails for an order with a deleted
  product.

### 2.3 Upgrade pull request details

| Area | Change |
|---|---|
| Spryker modules | All feature packages set to 202606. 38 modules moved to a new major version |
| PHP and frontend | PHP 8.4, Node 22 |
| Deprecated plugins | Replaced with their successors. One replacement changes behavior (§2.4) |
| Performance | The category page loads product prices in one batch |

### 2.4 Behavior changes from replaced deprecated plugins

| Replacement | Effect |
|---|---|
| SharedCartAccessCommentValidatorPlugin | Every user with access to a shared cart can add comments |

### 2.5 Database migrations

The migration is in `src/Orm/Propel/Migration_mysql/`. It adds a table and changes no existing data.
The production table sizes were not measured.

| Migration | Change | Data-loss risk |
|---|---|---|
| PropelMigration_1780000000 | New table `spy_product_attachment` | None. The table is empty, because the feature is not enabled |

### 2.6 Security fixes

The security release notes 202603.0 and 202606.0 were read.

| Issue | Affected modules | Extra steps | Status |
|---|---|---|---|
| A guest could open the return slip of another customer's order | `spryker-shop/sales-return-page` < 2.4.0 | None | Fixed in the upgrade pull request |
| The password reset link stayed valid after the password change | `spryker/customer` < 8.2.0 | Set `CustomerConstants::PASSWORD_RESET_LINK_SINGLE_USE` to `true`. Done in `config/Shared/config_default.php` | Fixed in the upgrade pull request |
| The quote request page showed the prices of another company's quote | `spryker-shop/quote-request-page` < 3.1.0 | None | Not fixed: the project overrides `src/Pyz/Yves/QuoteRequestPage/Controller/QuoteRequestViewController.php`. Recommended fix: add the company check from the vendor `QuoteRequestViewController::viewAction()` to the project controller |

### 2.7 Known existing issues

The work found 3 issues on release 202512, before the upgrade. The upgrade did not cause them. The
tests pull request fixes one of them. Part 4 describes all three.

### 2.8 Open performance gaps

Part 5 compares the code with the Spryker performance guidelines.

## 3. Action items for the development team

### 3.1 Review

Review the tests pull request first, then the upgrade pull request. Review all code and all test
cases before the merge. If a test does not match the intended shop behavior, change or remove the
test before the merge.

### 3.2 Local environment

Rebuild with the new Docker image (PHP 8.4, Node 22). Apply the migration from §2.5. To run the new
tests:

```bash
docker/sdk testing codecept run -c codeception.functional.yml
```

### 3.3 Release steps

Do these steps on staging. When the check on staging passes, do them on production.

1. Make sure that the variables `ACME_FILE_STORAGE_BUCKET` and `ACME_FILE_STORAGE_KEY` are set. If you
   use other names, change `config/Shared/config_default.php` to match. The product attachments need
   these variables.
2. Deploy with the normal pipeline.
3. Run this command once, as a pipeline job:

   ```bash
   vendor/bin/console publish:trigger-events -r product_attachment
   ```

   The upgrade adds a storage table for product attachments. The normal deployment leaves it empty.
4. Run the automated tests against the environment. Then check these critical workflows by hand:
   buyer login, search, cart, checkout, order approval, Back Office orders, Merchant Portal offers.

### 3.4 Not done in this upgrade

- Two deprecated Back Office navigation plugins are still in use. Their replacements change the menu
  order, so the development team must decide first.
- The security fix for the quote request page (§2.6). The project controller replaces the vendor
  controller, so the module update does not reach it. The fix can change what buyers see, so the
  development team must apply the recommended fix and test it.

## 4. Existing issues observed

All these issues existed on release 202512, before the upgrade.

**Prices and carts**

| # | Finding | Where | Status |
|---|---|---|---|
| 1 | The cart does not apply volume prices when the quantity changes (10 × 4,00 € instead of 10 × 3,60 €) | Cart | Current behavior covered by a test |

**Orders**

| # | Finding | Where | Status |
|---|---|---|---|
| 2 | The order details page returned HTTP 500 for an order with a deleted product | Storefront order details | Fixed in the tests pull request |

**Back Office**

| # | Finding | Where | Status |
|---|---|---|---|
| 3 | The customer search ignores the company name | Back Office customers list | Current behavior not covered by a test |

## 5. Performance guideline check

The code was compared with the Spryker performance guidelines as of 1 June 2026. The check read the
code only. It measured nothing.

### 5.1 Applied in the upgrade pull request

| Area | Change | Guideline |
|---|---|---|
| Category page | Product prices load in one batch, not in one request per product | Key-value storage |

### 5.2 Not applied: storefront pages

These fixes change the project code or need work outside the upgrade.

| Where | Problem | Fix | Guideline |
|---|---|---|---|
| `src/Acme/Yves/ProductDetailPage/Controller/ProductController.php:58` | One Zed call per review | Load all reviews in one call | Architecture |

### 5.3 Not applied: recommended, but changes the shop behavior

| Recommendation | Performance gain | Side effect | Guideline |
|---|---|---|---|
| Show the stock as "in stock" or "low stock", not as the exact quantity | Fewer stock reads on product lists | Buyers do not see the exact quantity | Key-value storage |

## 6. New Spryker features

These features come from the releases after 202512, up to 202606. Spryker labels a feature that is
not final as "early access".

**Install and integrate**

| Feature | What it does | Requires | Third-party costs |
|---|---|---|---|
| Product attachments | Buyers download files, for example manuals, on the product page. Employees upload the files in the Back Office. The upgrade installed its modules | — | None |

**Install, integrate and set up an external service**

| Feature | What it does | Requires | Third-party costs |
|---|---|---|---|
| Single sign-on | Users log in with a company account instead of a shop password | — | An identity provider |

**Integrate and change existing code**

| Feature | What it does | Requires | Third-party costs |
|---|---|---|---|
| Recurring orders | Buyers repeat purchases on a schedule. The feature adds a second ordering process | — | None |
