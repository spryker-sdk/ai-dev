# PRD Examples and Common Mistakes

This document provides a complete PRD example and anti-patterns to avoid.

**See [SKILL.md](SKILL.md) for the main PRD creation skill.**

---

## Common Mistakes

| Mistake | Why It's Wrong | Fix |
|---------|----------------|-----|
| Writing the whole PRD in one pass without the user choosing that | Skips the validation the user expected | Ask the pacing once at Phase 3 (per-criterion / per-story / draft-then-review) and honour it — draft-then-review still gets a full revision pass |
| Skipping research (Phase 0) | Invents module/transfer/endpoint names | Gather codebase facts inline **first** (module map/transfers, project-overlap sweep), then targeted `spryker-docs-research` per the user's research-depth choice, and validate existing flows with `spryker-runtime` |
| "As a user" / "As an admin" | Actor is ambiguous | Use a canonical actor from [actors.md](actors.md) |
| Guessed endpoint path | Doesn't match the real app | Resolve host from `deploy.dev.yml`; path from the research brief |
| Code references in the body (FQCN, `Class::method`, file path) | PRD is business-facing, not code | Use module/feature + configuration names; endpoint = URL path only; put code refs in `{feature-name}.refs.md` |
| Discarding the FQCNs found in research | Planner loses the crosswalk | Save them to `{feature-name}.refs.md` next to the PRD |
| Implementation details in stories | Stories describe WHAT, not HOW | Remove technical/architecture detail |
| Vague acceptance criteria | Not testable/measurable | Gherkin with specific values |
| Adding a "Quality Gates" section | That's planning, not requirements | Remove it entirely |
| Adding a "Tasks" breakdown | That's the planner's job | Remove it entirely |
| Goals without metrics | Can't measure success | Every goal gets a measurable KPI |
| No out-of-scope section | Scope creep | State explicitly what won't be built |
| Multiple behaviors per scenario | Unfocused | One behavior per scenario |
| No storage location specified | PRD gets lost | Confirm where to save at Phase 8 |

## Red Flags — STOP and Revise

- "I'll write the whole PRD and show the user" → NO — unless the user chose draft-then-review pacing at Phase 3 (which still includes a revision pass).
- "I'll skip the research" → NO. Phase 0 first, codebase facts before docs; no invented names.
- "I'll run docs research inline without the user choosing that" → NO. The research-depth question offers subagents / inline / skip — the user picks; inline is legitimate only as their explicit choice.
- "As a user…" → NO. Canonical actor from [actors.md](actors.md).
- "I'll guess the endpoint path" → NO. From the research brief + `deploy.dev.yml` host.
- "I'll put the class/method/file in the body" → NO. Code-free body; refs go in `{feature-name}.refs.md`.
- "Add the phpstan/codecept commands / Quality Gates" → NO. Excluded by design.
- "Let me include implementation details" → NO. WHAT and WHY only.
- "This criterion is obvious" → NO. Gherkin + quantified.
- "Goals are clear enough" → NO. Every goal needs a measurable KPI.

## Complete Example PRD

> The example below is **illustrative and feature-agnostic** — the feature ("Agent-Assisted Cart Recovery"), actors, modules, paths, and numbers are placeholders to show *shape and rules*, not a real spec. When you author a real PRD, replace every placeholder with values traced to your own Phase 0 research. Note how the body stays code-free and the code references live in a separate `.refs.md` attachment (shown after the PRD).

```markdown
# Product Requirement Document: Agent-Assisted Cart Recovery

> Grounded in research: <feature/module names> (from the spryker-docs-research subagent brief); hosts from deploy.dev.yml (EU group); current flow validated with spryker-runtime.
> **Implementation references:** see `agent-assisted-cart-recovery.refs.md` — controller/action FQCNs, transfers, configuration keys. This PRD body is intentionally code-free.

## Background

**Problem:** When a customer abandons a cart, support Agents cannot resume the customer's exact cart state during a call, so they recreate items manually — slow and error-prone.

**Business Impact:**
- Average agent-assisted call takes 8 minutes rebuilding carts
- 12% of recovered carts have wrong items, causing returns
- Lost recovery revenue estimated at €400K/year

**User Impact:**
- Customers repeat their whole order to the agent
- Agents juggle two screens and mistype SKUs

## Goals

1. **Cut agent cart-rebuild time** from 8 min to ≤2 min (measured via support tooling timestamps)
2. **Reduce wrong-item recovered carts** from 12% to ≤2% (measured via return reason codes)
3. **Increase abandoned-cart recovery rate** by 20% (baseline 15% → target 18%)

## User Stories

### User Story 1: Resume a customer's abandoned cart

**As an** Agent assisting a customer
**I want to** load the customer's most recent abandoned cart into the agent session
**So that** I can complete the order without re-entering items

**Affected endpoint:** `http://<backoffice-host>/agent/dashboard` (existing; Agent module — `<backoffice-host>` resolved from the `backoffice` application in deploy.dev.yml)

**Acceptance Criteria:**

Scenario: Load the latest abandoned cart for an impersonated customer
  Given I am impersonating customer "sonia@acme.com", whose abandoned cart holds 3 items (SKUs 001, 002, 003)
  When I open the customer's abandoned-cart panel
  Then the panel lists exactly those 3 items (SKUs 001, 002, 003) within 2 seconds

Scenario: No abandoned cart exists
  Given I am impersonating a customer with no abandoned cart
  When I open the abandoned-cart panel
  Then the panel shows "No abandoned cart found"
  And no empty cart is created

### User Story 2: See cart changes reflected on the storefront

**As a** Customer
**I want** the cart the agent restored to appear in my own storefront session
**So that** I can review and pay for it myself if I prefer

**Affected endpoint:** `http://<yves-host>/DE/en/cart` (existing; CartPage module — `<yves-host>` resolved from the `yves` application in deploy.dev.yml)

**Acceptance Criteria:**

Scenario: Restored cart syncs to the customer's storefront
  Given an Agent has restored my abandoned cart of 3 items (SKUs 001, 002, 003) totalling 149.90 EUR
  When I refresh my storefront cart page while logged in
  Then my cart lists those 3 items (SKUs 001, 002, 003) with a total of 149.90 EUR within 1 second of page load

## Non-functional Requirements

### Performance
- Abandoned-cart lookup returns within 2 seconds for carts up to 100 line items
- Storefront cart sync reflects changes within 1 second of page load

### Reliability
- If the persistent-cart store is unavailable, the agent panel shows a clear error and does not partially load a cart

### Security
- An Agent can only load carts for the customer they are currently authorized to impersonate
- Cart access is denied (HTTP 403) for any customer outside the agent's permission scope

### Scalability
- Supports 500 concurrent agent sessions performing cart lookups

## Success Metrics

- Agent cart-rebuild time: 8 min → ≤2 min  (measured via support tooling timestamps)
- Wrong-item recovered carts: 12% → ≤2%  (measured via return reason codes)
- Abandoned-cart recovery rate: 15% → 18%  (measured via analytics funnel)

## Out of Scope

- Automated outbound recovery emails
- Cart recovery for guest (non-registered) customers
- Merchant-portal agent flows (marketplace sellers)

## Dependencies

**Internal:**
- Agent module impersonation must be enabled
- Persistent Cart feature must store abandoned carts with a retrievable timestamp

**External:**
- None
```

### Companion attachment — `agent-assisted-cart-recovery.refs.md`

Saved next to the PRD; holds the code references discovered in research so the body stays code-free (see [refs-attachment.md](refs-attachment.md) for the full structure). Illustrative:

```markdown
# Implementation References: Agent-Assisted Cart Recovery

> Attachment to `agent-assisted-cart-recovery.prd.md`. Not part of the requirements — discovered code references for the planner.

## Endpoints (per story)
| PRD story | URL path (in PRD) | Controller::action (FQCN) | Status | Route source |
|-----------|-------------------|---------------------------|--------|--------------|
| Story 1 — Resume abandoned cart | `/agent/dashboard` | `Spryker\Zed\Agent\Communication\Controller\DashboardController::indexAction` | existing | Zed controller |
| Story 2 — Sync to storefront | `/DE/en/cart` | `SprykerShop\Yves\CartPage\Controller\CartController::indexAction` | existing | Yves route provider |

## Facade / API methods
- `Spryker\Client\PersistentCart\PersistentCartClientInterface::...` — [retrieve abandoned quote]

## Configuration
- `agent:...:impersonation_enabled` — [feature flag], resolver `AgentConfig::isImpersonationEnabled()`
```

This example demonstrates:
- ✅ Research note at the top, hosts traced to `deploy.dev.yml`, **attachment linked**
- ✅ Canonical actors (Agent, Customer) — never "as a user"
- ✅ Affected endpoint as a **URL path + module name** (no controller::action in the body)
- ✅ **Code-free body** — no FQCNs/`::method`/file paths, including Dependencies (module/feature + configuration names only)
- ✅ Code references captured in the sibling `.refs.md` attachment
- ✅ Gherkin acceptance criteria, quantified — outcomes AND inputs (every `Then` comparison value stated literally in the `Given`)
- ✅ Business-level NFRs (no tooling/test commands)
- ✅ Success metrics with measurement tools; Out of Scope and Dependencies
- ✅ NO Quality Gates, NO Tasks, NO priority tiers
```
