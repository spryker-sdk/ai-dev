# Example handover report — shape only

The real report is one HTML file pasted into Confluence ([references/handover-report.md](references/handover-report.md)
§ Format). This shows its parts and the level of detail, for an invented project, ACME B2B.

---

# ACME B2B: Spryker upgrade 202512 → 202606

**Contents** — 1. Executive summary · 2. Technical details · 3. Action items for the ACME team ·
4. Existing issues observed · 5. Performance guideline check · 6. New Spryker features

## 1. Executive summary

**Goal:** move the ACME shop to Spryker release 202606 — the release Spryker supports and patches, and
the one its newest features build on.

**What was done:**

- **Tests.** Search, ordering, approvals and merchant orders are now checked automatically. The checks
  passed after the upgrade, and from now on they flag a broken flow before it reaches production.
- **Upgrade.** The shop now runs on release 202606. The Back Office looks different, but every menu
  entry and page is where it was.
- **Performance.** Product pages and the cart load faster. Further improvements were found and not
  applied; Part 5 lists them.

**Changes in behaviour:**

- Comments on a shared cart can now be written by everyone it is shared with, not just by the person
  who created it.

**Existing issues:** while writing the tests, 3 issues were found that already existed — for example,
volume prices are not applied when the quantity is changed in the cart. They are unchanged by the
upgrade; Part 4 has the full list.

**What happens next:** the ACME team reviews both pull requests and releases them, staging first.
Part 3 lists what to do.

## 2. Technical details

### 2.1 Pull requests

| Pull request | Branch | Content |
|---|---|---|
| #41 | upgrade-202606-tests | Tests: functional and end-to-end tests, and their CI |
| #42 | upgrade-202606 | Upgrade: Spryker 202606, the changes it needed, performance improvements |

### 2.2 Tests pull request details

- Codeception: 12 files, 86 tests, through the Facades of the customised modules.
- Cypress: 9 specs, 31 cases — storefront, Back Office, Merchant Portal.
- Code fix the tests needed: the order details page no longer fails for an order whose product was
  deleted.

### 2.3 Upgrade pull request details

| Area | Change |
|---|---|
| Spryker modules | All feature packages set to 202606; 38 modules to a new major |
| PHP and frontend | PHP 8.4, Node 22 |
| Deprecated plugins | Swapped for their successors; one of the swaps changes behaviour (§2.4) |
| Performance | The category page loads product prices in one batch |

### 2.4 Behaviour changes from replaced deprecated plugins

| Replacement | Effect |
|---|---|
| SharedCartAccessCommentValidatorPlugin | Everyone a cart is shared with may comment on it |

### 2.5 Database migrations

One migration, in `src/Orm/Propel/Migration_mysql/`. It adds a table and touches no existing data.
Nobody measured how large the production tables are.

| Migration | Change | Data-loss risk |
|---|---|---|
| PropelMigration_1780000000 | New table `spy_product_attachment` | None — empty; the feature is not enabled |

Rolling back removes the new table again. Back up the production database before deploying.

### 2.6 Known existing defects

3 defects turned up on release 202512, before anything was upgraded, so the upgrade did not cause
them. The tests pull request fixes one. Part 4 describes all three.

### 2.7 Open performance gaps

Part 5 compares the code with Spryker's performance guidelines.

## 3. Action items for the ACME team

### 3.1 Known points in the pull requests

- Merge the tests pull request first — the upgrade pull request starts from it.

### 3.2 Local environment

- Rebuild with the new Docker image (PHP 8.4, Node 22) and apply the migration from §2.5.
- To run the new tests:

`docker/sdk testing codecept run -c codeception.functional.yml`

### 3.3 Release steps

Staging first; once it looks right there, the same steps on production.

1. Deploy the release.
2. Run once, for the new storage table only:

   `vendor/bin/console publish:trigger-events -r product_attachment`

3. Point the tests at the environment. Then click through: a buyer logs in, searches, fills a cart,
   checks out and gets the order approved; an employee opens it in the Back Office; a merchant sees
   it in the Merchant Portal.

### 3.4 Not done in this upgrade

- Two deprecated Back Office navigation plugins are still in use; replacing them changes the menu
  order.

## 4. Existing issues observed

Each of these was already present on release 202512.

**Prices and carts**

| # | Finding | Where | Status |
|---|---|---|---|
| 1 | Volume prices are not applied when the quantity changes in the cart (10 × 4,00 € instead of 10 × 3,60 €) | Cart | Current behaviour covered by a test |

**Orders**

| # | Finding | Where | Status |
|---|---|---|---|
| 2 | The order details page returned HTTP 500 for an order whose product was deleted | Storefront order details | Fixed in the tests pull request |

**Back Office**

| # | Finding | Where | Status |
|---|---|---|---|
| 3 | The customer search ignores the company name | Back Office customers list | Current behaviour not covered by a test |

## 5. Performance guideline check

Compared with Spryker's performance guidelines as of 1 June 2026, by reading the code only — no
measurements.

### 5.1 Applied in the upgrade pull request

| Area | Change | Guideline |
|---|---|---|
| Category page | Product prices loaded in one batch instead of one request per product | Key-value storage |

### 5.2 Not applied: storefront pages

Left out of the upgrade: each one changes the project's own code or needs work beyond it.

| Where | Problem | Fix | Guideline |
|---|---|---|---|
| `src/Acme/Yves/ProductDetailPage/Controller/ProductController.php:58` | Reviews loaded with one Zed call per review | Load them in one call | Architecture |

### 5.3 Not applied: recommended, but changes how the shop behaves

| Recommendation | Performance gain | Side effect | Guideline |
|---|---|---|---|
| Show stock as "in stock / low stock" instead of the exact quantity | Fewer stock reads on product lists | Buyers no longer see the exact quantity | Key-value storage |

## 6. New Spryker features

From releases after 202512, up to 202606. Spryker marks features that are not final yet as "early
access".

**Install and integrate**

| Feature | What it does | Requires | Third-party costs |
|---|---|---|---|
| Product attachments | Buyers download files such as manuals from the product page; employees upload them in the Back Office. Its modules came with the upgrade | — | None |

**Install, integrate and set up an external service**

| Feature | What it does | Requires | Third-party costs |
|---|---|---|---|
| Single sign-on | Login with an existing company account instead of a shop password | — | An identity provider |

**Integrate and change existing code**

| Feature | What it does | Requires | Third-party costs |
|---|---|---|---|
| Recurring orders | Repeat purchases on a schedule; it adds its own ordering process next to the current one | — | None |
