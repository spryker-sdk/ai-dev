# Gherkin Format Guide for Acceptance Criteria

This document defines the strict Gherkin format requirements for all acceptance criteria in PRDs.

**See [SKILL.md](SKILL.md) for the main PRD creation skill.**

---

## Critical Requirement

**ALL acceptance criteria MUST use Gherkin syntax. No bullet-point lists allowed.**

## Gherkin Structure

Every acceptance criterion must follow this structure:

- **Scenario**: Name describing the specific behavior being tested
- **Given**: Initial context or precondition (system state before action)
- **When**: User action or trigger that initiates the behavior
- **Then**: Expected observable outcome that should occur
- **And**: Additional conditions or outcomes (use to chain multiple Given/When/Then)

## Gherkin Rules

1. **One behavior per scenario** - Each scenario tests a single behavior
2. **3-4 steps maximum** - Keep scenarios focused (Given, When, Then, optional And)
3. **Quantify every outcome** - NO vague language:
   - ❌ "Page loads fast" → ✅ "Page loads within 2 seconds"
   - ❌ "Good user experience" → ✅ "90% user satisfaction score"
   - ❌ "Works well" → ✅ "Processes 1000 requests per second"
4. **Quantify every input** — the mirror rule, and the one most often violated: **every value a `Then` compares against must be stated literally in the `Given` or `When`.** `2026-10-15`, not "a requested delivery date"; `12.50 EUR`, not "a target price". **Bare "the same", "identical", "unchanged", "as before" are banned unless a literal value precedes them** — "shows the same date" with no date ever named reads fine and tests nothing (the `Then` has no reference point, so the assertion cannot be executed). Correspondingly, **ban quantities in the `Given` that no step depends on** ("an order placed 7 days ago" when elapsed time plays no role) — an arbitrary number implies a meaning the reader will hunt for and not find.
5. **Active voice** - User-centric, executable statements
6. **Testable** - Each scenario must be verifiable with pass/fail — which requires an assertion that **can fail**: never "empty **or** omitted" (either outcome passes), never "renders as before" (no reference point), never "with no code change required" (not observable from outside)
7. **Specific** - Include exact values, formats, states

## Complete Example

```markdown
### User Story 1: Currency Selection

**As a** EU customer
**I want to** select EUR as my checkout currency
**So that** I see familiar pricing and avoid conversion fees

**Acceptance Criteria:**

Scenario: Select currency from cart page
  Given I am on the cart page with items in USD
  When I select EUR from the currency dropdown
  Then all prices update to EUR within 500 milliseconds
  And the page does not reload

Scenario: Currency selection persists through checkout
  Given I have selected EUR as my currency on the cart page
  When I navigate to the checkout page
  Then the checkout page displays all prices in EUR

Scenario: Default currency matches geolocation
  Given I am accessing the site from France
  When I view the cart page for the first time
  Then the currency selector shows EUR as the selected value
```

## Common Mistakes

### ❌ WRONG - Bullet point list:
```markdown
**Acceptance Criteria:**
- User can select currency
- Prices update when currency changes
- Selection persists through checkout
```

### ❌ WRONG - Vague language:
```markdown
Scenario: Fast currency conversion
  Given I select a currency
  When the page updates
  Then the conversion happens quickly
  And users are happy
```

### ✅ CORRECT - Quantified Gherkin:
```markdown
Scenario: Currency conversion performance
  Given I am on the cart page with prices shown in USD
  When I select EUR from the currency dropdown
  Then all prices update to EUR within 500 milliseconds
  And no page reload occurs
```

### ❌ WRONG - Unquantified input (the `Then` has no reference point):
```markdown
Scenario: Date persists on the order detail page
  Given an order placed 7 days ago with a requested delivery date on 1 item
  When the Company user opens that order's detail page
  Then that item shows the same date
```
*The same date as what?* No value was ever named, so the assertion cannot be executed — and "7 days ago" implies elapsed time matters when nothing depends on it.

### ✅ CORRECT - Literal input, executable assertion:
```markdown
Scenario: Date persists on the order detail page
  Given an order with a requested delivery date of 2026-10-15 on 1 item
  When the Company user opens that order's detail page
  Then that item shows 2026-10-15 as its requested delivery date
```

## Scenario lint pass (run before Phase 8 save)

Audit every scenario mechanically — these defects all *read fine* and are found only by checking structure, not by re-reading for sense:

- [ ] **Exactly one `Given` → `When` → `Then` spine.** A scenario with no `When` hides its action inside the `Given`. A `When` or `Then` chaining two actions or two assertions with "and"/"then" ("filters by waiting, then searches…" / "the form renders **and** the Agent saves") is two scenarios — split it.
- [ ] **Every comparison value in a `Then` was stated literally upstream** — no bare "the same / identical / unchanged / as before" without a preceding literal.
- [ ] **No quantity in a `Given` that no step depends on.**
- [ ] **No `or` in a `Then`** — an assertion that passes either way cannot fail.
- [ ] **The title names exactly what the body asserts** — "Reachable from navigation, denied without the role" over a body testing only the denial is a promise the scenario doesn't keep.

Splitting compounds may grow the scenario count — that's correct (a real audit fixed 39 instances in one pass, 33 → 40 scenarios, zero new behaviour). Catching these at authoring time costs nothing; a reader catching them costs their trust in every scenario they haven't read yet.

## Validation Checklist

Before approving any acceptance criterion, verify:
- [ ] Starts with "Scenario:" keyword
- [ ] Uses Given/When/Then/And keywords correctly — with a real `When` (the action is never hidden in the `Given`)
- [ ] Maximum 4 steps per scenario
- [ ] No vague language ("fast", "good", "well")
- [ ] All outcomes are quantified
- [ ] All inputs the `Then` compares against are stated literally in the `Given`/`When` (no bare "the same/unchanged/as before")
- [ ] The assertion can fail (no `or` in the `Then`, no "as before" without a reference, no externally unobservable claims)
- [ ] Tests exactly ONE behavior — and the title names exactly what the body asserts
- [ ] After each line we have two whitespaces to have a line break of the lines in the Markdown file

## Why Gherkin?

1. **AI-parseable**: Structured format enables automatic task extraction
2. **Testable**: Direct mapping to automated tests
3. **Unambiguous**: Clear preconditions, actions, and outcomes
4. **Executable**: Can be run as BDD tests
5. **Universal**: Standard format understood by all tools
