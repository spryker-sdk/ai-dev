# Test Case Template & Checklist Structure

Use this structure for the QA artifact saved at `resources/qa/{FeatureName}/{feature-name}.qa.md`. The checklist is the quick scan; each checklist item links to a fuller test case.

## Checklist (quick scan)

Group by user story; tag each item with its bucket (H = happy, N = negative, A = authorization, C = corner) and execution mode.

**Scale the number of cases to the story's risk — don't template a fixed matrix.** Always cover the happy path; add a negative, authorization, or corner case only when that story actually carries that risk (e.g. an authorization case only where there's a real actor boundary to cross, a corner case only where a realistic edge — bulk, partial failure, stale storage — could break *this* feature). A trivial read-only or pure-config story may be a single happy-path line; a destructive bulk action on a privileged endpoint may warrant all four. More cases mean a slower QA pass, so spend them where the feature is likely to fail, not everywhere by default.

```markdown
## Checklist — [Feature Name]

### Story 1: [title] — actor: [actor] — endpoint: [Class::action → URL]
- [ ] TC-1.1 (H, Chrome) — [happy path — always]
- [ ] TC-1.2 (A, API) — [different actor is denied — only if there's a real authorization boundary]
- [ ] TC-1.3 (C, Console+DB) — [corner case — only if a realistic edge could break this story]
```

## Test case (full)

Each case is self-contained so it can be executed and graded independently.

```markdown
### TC-1.4 — Bulk publish persists selected pages to storefront storage
- **Story / criterion:** Story 1 — "publish selected pages"
- **Actor:** Back Office content manager (Back Office user)
- **Bucket:** Corner
- **Execution mode:** Console + DB + Redis (via spryker-runtime)
- **Affected endpoint:** `Cms\...\EditPageController::bulkActivateAction` → `http://<backoffice-host>/cms-gui/edit-page/bulk-activate` (`<backoffice-host>` resolved from the `backoffice` application in deploy.dev.yml)
- **Preconditions:** a few unpublished CMS pages exist (the demo data already ships some — no special seeding); env booted; logged in as the actor.
- **Steps:**
  1. Select the unpublished pages and trigger bulk publish.
  2. Query `spy_cms_page` for the `is_active = 1` count.
  3. Check the storefront Redis keys for the published pages.
- **Expected result:** the selected pages are `is_active = 1`; corresponding `cms_page` keys present in `key_value_store`; storefront serves them.
- **Notes:** watch for partial-failure mid-batch and stale cache vs. published storage.
```

## Field guide

- **Actor** — a canonical Spryker actor or a specific role (e.g. "Back Office content manager (Back Office user)"). Drives which login `spryker-runtime` uses.
- **Bucket** — Happy / Negative / Authorization / Corner. Authorization and Corner are where Spryker features fail, so reach for them when the story has a real actor boundary or a realistic edge — but don't force one of each onto every endpoint; a low-risk story may need only the happy path.
- **Execution mode** — the lightest mode that proves the case (Chrome / API / Browser-seeded curl / Console / Storage), per the SKILL.md mode table. A case may use several (act, then verify persistence).
- **Expected result** — observable and specific (status code, exact count, a field value, a visible message) — never "works".
- **Evidence (filled at execution)** — what you actually observed: status + key fields, DB row, Redis key, queue message, console error, screenshot/GIF.

## Corner-case prompts (idea bank)

When brainstorming corner cases for a Spryker feature, run through these — keep the ones that could realistically break *this* feature:

- Empty state / zero results; collection of exactly one vs. many (bulk-of-1, bulk-of-N).
- Partial failure mid-operation (does it roll back or leave half-done state?).
- Long strings, special characters, HTML/script in text inputs (stored-XSS surface).
- Missing or expired CSRF token on a Back Office form; double-submit / rapid retry; idempotency.
- Wrong actor / missing ACL role → expect 403, not a silent success or a 500.
- Multi-store, multi-locale, multi-currency variation.
- Pagination / limits (first page, last page, beyond last).
- Published data vs. storage: after a publish/activate, does Redis/Elasticsearch actually reflect it, or is it stale until a cache/publish step?
- Session expiry mid-flow (esp. Yves ~30 min).
