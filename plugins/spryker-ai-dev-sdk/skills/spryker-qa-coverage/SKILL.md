---
name: spryker-qa-coverage
description: >
  Use to expand a Spryker AC list into a structured test-coverage plan before
  verification runs. Groups cases into four buckets - Happy, Negative,
  Authorization, Corner - and picks the lightest verification mode (DB/Redis
  /console/API/Chrome) that proves each case. Output is consumed by
  spryker-verifier for per-case execution. Trigger when the user wants
  thorough verification beyond the literal AC list, typically as part of an
  MVP-quality customization workflow. Does NOT execute tests, does NOT fix
  red cases, does NOT write automated test code.
---

# Spryker QA Coverage

Expand a Spryker AC list into a structured test-coverage plan. Each AC produces test cases in four buckets, each case carries a lightest-mode tag so `spryker-verifier` knows whether to use DB / console / API / browser.

## When to invoke

- During the customization workflow at Step 6, before invoking `spryker-verifier`, when the QA-thorough phase is on (default for MVP, off for PoC).
- When the user asks for *"a thorough test plan"*, *"corner-case coverage"*, *"what could go wrong with these ACs"*.

Skip when ACs are trivial or PoC-quality — the four-bucket expansion is overkill there.

## The four buckets

For each applicable AC, produce cases in each bucket. Empty buckets are fine — not every AC has Authorization cases, for example.

### 1. Happy

The AC as written — buyer fills the form correctly, expected values, expected role. The literal AC.

**For every UI-touching Happy case, include a visual-fit sub-assertion**: the new UI element (badge / label / button / form field / banner / widget / table column) must visually integrate with the surrounding shop design — same typography, spacing, color, button style, badge shape as its siblings on the same page. Plain unstyled text on a polished page = red, even when the underlying logic works. The case description should mention this explicitly: *"... AND the new element matches the surrounding visual idiom."*

### 2. Negative

The AC attempted with invalid input:
- Missing required field
- Wrong type (text where number expected, etc.)
- Out-of-range values
- Boundary violations

Assert the error UX surfaces AND that no state change occurred (no order created, no row inserted).

### 3. Authorization

The AC attempted by the **wrong actor**:
- Anonymous user on an authenticated endpoint → 401 / redirect to login
- Wrong role (Buyer attempting Admin action) → **403, NOT 500**
- Wrong company / business unit (cross-tenant attempt) → 403
- Expired session → 302 to login

Assert proper HTTP code AND no state change (no data leaked across tenant boundary, no privilege escalation).

### 4. Corner

The AC under edge conditions. Pick the 2-3 most likely to surface defects for the specific AC — not all of these for every AC:
- Empty state (no data yet)
- Bulk: 1 item vs N items vs 0 items
- Long strings / XSS attempts in text fields
- CSRF token expired
- Multi-store / multi-locale / multi-currency divergence
- Pagination boundary (last page, single item, no items)
- Published vs stored (P&S timing: Redis says X, DB says Y)
- Session expiry mid-flow
- Partial failure rollback (insert succeeds, publish fails)
- Concurrent write from two actors

## Lightest-mode decision table

For each case, pick the lightest verification mode that proves it. Don't drive Chrome just because the AC sounds UI-shaped — if a DB query proves the persistence side, that's enough.

| Case shape | Lightest mode | Why |
|---|---|---|
| Persistence-only (data lands in DB / Redis correctly) | `executeDatabaseQuery` MCP + Redis Commander | Fastest, deterministic |
| Console-driven (a `docker/sdk console` command produces expected output) | `docker/sdk console <cmd>` + parse exit code/output | Fastest, deterministic |
| API (Glue / SAPI / BAPI endpoint returns expected shape) | `curl` (after Chrome-seeded auth if needed — see verifier's browser-seeded-curl section) | Faster than driving Chrome through CSRF forms |
| UI-state (the page shows X) | Chrome via Claude-in-Chrome | Required — no other way |
| JS interaction (clicking X triggers Y in the DOM) | Chrome | Required |
| Email sent | Mailpit UI via Chrome | Required |
| Queue / event landed | RabbitMQ Management UI via Chrome | Required |

## Output format — what to hand to spryker-verifier

The skill emits a structured plan that `spryker-verifier` executes case by case:

```
## Test Plan for AC <N>: <AC one-line summary>

### Happy
- H1: <description>
  - Mode: <DB | Console | API | Chrome | Mailpit | …>
  - Preconditions: <test data + actor + system state>
  - Action: <step(s) to perform>
  - Assertion: <observable outcome>

### Negative
- N1: <description with invalid input>
  - Mode: …
  - Preconditions: …
  - Action: …
  - Assertion: <expected error UX + no state change>

### Authorization
- A1: <wrong actor description>
  - Mode: …
  - Preconditions: …
  - Action: …
  - Assertion: <expected HTTP code + no state change>

### Corner
- C1: <edge condition>
  - Mode: …
  - Preconditions: …
  - Action: …
  - Assertion: …
```

Each case becomes one `spryker-verifier` invocation. Cases that are independent of each other (no ordering dependency) can be invoked in parallel.

For the per-case structure see `test-case-template.md` in this skill folder.

## What this skill does NOT do

- Does NOT execute tests — that's `spryker-verifier`.
- Does NOT fix red cases — that's `spryker-issue-diagnoser`.
- Does NOT write automated test code (codecept-functional / unit tests) — those are written alongside the implementation in Step 4, not here.
- Does NOT capture screenshots — that's `spryker-screenshot-collector`.
