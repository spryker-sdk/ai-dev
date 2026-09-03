# Baseline volumes — the design floor when no project numbers exist

When `architecture/10-quality-requirements.md` is missing/empty and the user has no numbers, these
Spryker baseline volume figures are the **stated default** in the Step 0d scale envelope. **Use the
upper bound as the design floor** — a design is judged against the top of the range, not the middle.
A design that only works on demo data is rejected at the plan gate, not discovered in production.

Row names map to the arc42 §10 Volume Planning rows so the two never drift; this table also doubles
as seed content when offering to populate an empty §10.

| Entity | Baseline range | arc42 §10 row |
|---|---|---|
| Abstract products | **70,000** | Abstract Products |
| Concrete products | **70,000 – 120,000** | Active Products (SKUs) |
| Prices | **100,000 – 1,000,000** | Prices (Total) |
| Categories | **500 – 2,500** | Active Categories |
| Stores | **2 – 8** | Stores (Markets) |
| Locales | **4 – 15** | Locales |
| Currencies | **1 – 4** | Currencies |
| Orders per day | **400 – 6,000** | Orders per Day |
| Items per order | **5 – 80** | Order Lines per Day (× orders) |
| Merchants | **120** | Merchants |
| Merchant users | **25 – 300** | Concurrent Merchants in MP (related) |
| Customers | **200 – 2,500** *(see note)* | — |
| Back Office users | **20 – 150** | Concurrent Back Office Users (related) |
| Companies | **2,000 – 40,000** | Companies |
| Business units | **4,000 – 120,000** | Branches (Business Units) |
| Company users | **10,000 – 300,000** | Company Users |

**Worked example — why the envelope rejects designs by arithmetic, not opinion.** A feature that
writes per-business-unit data into every product's shared search document grows as
`abstract products × business units` = 70,000 × 120,000 ≈ **8.4 billion** index entries at the upper
bound (~280 million at the lower). No judgement call remains: the growth-characteristic check in the
plan disqualifies the shape at intake, before a single file exists.

> **Known inconsistency — do not silently reconcile.** `Customers: 200 – 2,500` sits below
> `Company users: 10,000 – 300,000`, but every `spy_company_user` requires a `spy_customer` row, so
> the customer table cannot be smaller than company users. Read the Customers row as *standalone
> B2C-style customers outside any company* until the numbers' owner confirms or revises it. For
> sizing anything keyed on `spy_customer`, use `customers + company users`.

These figures are a product-side default pending sign-off; a project's own numbers (from
`architecture/§10` or the user) always override them, per row.
