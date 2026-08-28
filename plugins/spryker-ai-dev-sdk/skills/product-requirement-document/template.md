# Product Requirement Document: [Feature Name]

> Grounded in research: [module(s)/feature name(s), key docs from the spryker-docs-research brief]. Endpoint hosts resolved from `deploy.dev.yml` (or user-specified target).
> **Implementation references:** see [`{feature-name}.refs.md`](./{feature-name}.refs.md) — the discovered code references (controller/action FQCNs, transfers, configuration keys, file paths). This PRD body is intentionally code-free.

<!-- AUTHORING RULE: No code references in this document. No FQCNs, no Class::method(), no controller/action/plugin/facade/transfer/repository class names, no file paths — anywhere, including Dependencies. Use feature/module names and configuration names. Endpoint lines show the URL path only. All code references live in {feature-name}.refs.md. -->


## Background

[Explain WHY this feature exists]
- Current problem or opportunity
- Business impact (revenue, costs, user satisfaction)
- Market context or competitive landscape

## Goals

[List 3-5 measurable objectives]

1. **[Goal 1]** - [Specific, quantifiable outcome]
2. **[Goal 2]** - [Specific, quantifiable outcome]
3. **[Goal 3]** - [Specific, quantifiable outcome]

## User Stories

### User Story 1: [Title]

**As a** [canonical Spryker actor: Back Office user (or a specific role like "Back Office content manager (Back Office user)") | Customer | Agent | Merchant user | Merchant Agent — see actors.md]
**I want to** [action]
**So that** [benefit]

**Affected endpoint:** `[URL path resolved from deploy.dev.yml host or user-specified target]` (existing | greenfield — closest neighbor: [path]; [Module name])  <!-- path only — no controller/action class; the FQCN goes in {feature-name}.refs.md -->`

**Acceptance Criteria:**

Scenario: [Specific behavior being tested]
  Given [initial context/precondition]
  When [user action or trigger]
  Then [expected observable, quantified outcome]
  And [additional expected outcome if applicable]

Scenario: [Another specific behavior]
  Given [different context]
  When [different action]
  Then [expected result]

### User Story 2: [Title]

**As a** [canonical Spryker actor]
**I want to** [action]
**So that** [benefit]

**Affected endpoint:** `[URL path]` (existing | greenfield; [Module name])  <!-- path only — no class name -->`

**Acceptance Criteria:**

Scenario: [Specific behavior being tested]
  Given [initial context/precondition]
  When [user action or trigger]
  Then [expected observable outcome]

[Add more user stories as needed — one canonical actor each]

## Non-functional Requirements

[Business-level quality attributes only. No tooling commands, no test suites, no "quality gates".]

### Performance
- [Latency / response-time target]
- [Throughput target]

### Reliability
- [Availability target]
- [Error handling / fallback behavior]

### Security
- [Data protection requirements]
- [Authentication / authorization]
- [Compliance requirements]

### Scalability
- [Growth / volume projections]

## Constraints

[Discovered hard constraints that change what can be promised — business-phrased, mechanism-free. These come from Phase 0 research: things that are neither implementation detail nor goals, but limit or shape the promise. Omit the section only if genuinely none were discovered — never silently drop one.]

- [e.g. "Customer-specific identifiers must physically reside in the shared search index — a confidentiality trade-off the customer must accept"]
- [e.g. "Search text analysis cannot be changed in place after go-live; changes require a re-index"]

## Decisions & Accepted Risks

[User choices with a cost they explicitly accepted. State the decision, the cost in plain language, and that it was accepted. This is the audit trail for "we knew, and we chose".]

- **[Decision]** — [its cost, plainly] — accepted by [user] on [date].

## Success Metrics

[Define HOW we measure if goals are achieved]

- [Metric 1]: [Current baseline] → [Target]  (measured via [tool])
- [Metric 2]: [Current baseline] → [Target]  (measured via [tool])

## Out of Scope

[What we are NOT building in this iteration]

- [Item 1]
- [Item 2]

## Dependencies

<!-- Names only — module/feature names and configuration names. No class/method/file references (those go in {feature-name}.refs.md). -->

**Internal:**
- [Module/feature name prerequisite — e.g. "<Feature> feature", "<Module> module"]
- [Configuration name prerequisite — e.g. "the `<config:key:path>` configuration enabled"]

**External:**
- [Third-party / service prerequisite — e.g. "a configured <provider> API token"]
