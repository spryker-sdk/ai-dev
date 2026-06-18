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
3. **Quantify everything** - NO vague language:
   - ❌ "Page loads fast" → ✅ "Page loads within 2 seconds"
   - ❌ "Good user experience" → ✅ "90% user satisfaction score"
   - ❌ "Works well" → ✅ "Processes 1000 requests per second"
4. **Active voice** - User-centric, executable statements
5. **Testable** - Each scenario must be verifiable with pass/fail
6. **Specific** - Include exact values, formats, states

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
  And my currency selection is preserved

Scenario: Default currency matches geolocation
  Given I am accessing the site from France
  When I view the cart page for the first time
  Then the default currency is automatically set to EUR
  And I can manually change it if desired
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
  Given I select EUR from the currency dropdown
  When the currency conversion completes
  Then all prices update within 500 milliseconds
  And no page reload occurs
```

## Validation Checklist

Before approving any acceptance criterion, verify:
- [ ] Starts with "Scenario:" keyword
- [ ] Uses Given/When/Then/And keywords correctly
- [ ] Maximum 4 steps per scenario
- [ ] No vague language ("fast", "good", "well")
- [ ] All outcomes are quantified
- [ ] Tests exactly ONE behavior
- [ ] After each line we have two whitespaces to have a line break of the lines in the Markdown file

## Why Gherkin?

1. **AI-parseable**: Structured format enables automatic task extraction
2. **Testable**: Direct mapping to automated tests
3. **Unambiguous**: Clear preconditions, actions, and outcomes
4. **Executable**: Can be run as BDD tests
5. **Universal**: Standard format understood by all tools
