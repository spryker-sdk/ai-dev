# PRD Creation: Detailed Interactive Workflow

This document provides the detailed phase-by-phase breakdown of the interactive PRD creation process.

**See [SKILL.md](SKILL.md) for overview and when to use this skill.**

---
## Interactive Workflow

**CRITICAL:** This is an INTERACTIVE process. You MUST involve the user at each checkpoint before proceeding. Never write a complete PRD in one go.

### Phase 0: Research FIRST 🔬 REQUIRED (before any requirement)

Ground everything in real Spryker before drafting. Codebase facts (Step 0.2) are always gathered; docs research (Step 0.1) and running-app validation (Step 0.3) are **opt-in — ask the user with `AskUserQuestion` before running each**.

**Step 0.1 — Public documentation research (ASK FIRST; run as subagent[s])**
Ask the user whether docs research is needed: *"Should I research the official Spryker docs for this feature first?"* Some features are project-specific or already clear from the codebase and need none. **Only if yes**, dispatch the research to **subagent(s) via the Agent tool** — never inline in the main loop — each subagent's prompt telling it to invoke the **`spryker-docs-research`** skill (docs-only). This keeps the large doc-fetch output out of the PRD-drafting context; only the distilled brief comes back. **Fan out for multi-concept features:** launch one subagent per concept/PBC/actor-area in a single message so they run in parallel; use a single subagent for a narrow feature. Each returns the relevant documentation content + source links plus a short brief: feature/PBC name, supported actors, documented behavior/constraints, and any documented endpoints. Merge the briefs.
- If a subagent reports a missing MCP tool, tell the user and suggest enabling it before continuing.

**Step 0.2 — Codebase facts (inline, Spryker tooling MCP server)**
Confirm against the real install and pin exact names — match tools by tool name (server names vary by install):
- `getSprykerModules` — exact module name(s).
- `getSprykerModuleMap` — controller+action behind the feature, plugins/extension points, whether it already ships. (Yves routes from `RouteProviderPlugin`s; Glue resource from a `ResourceRoutePlugin`.)
- `getTransferStructureByName` — real transfer field names/types. Never invent fields.
**If the tooling MCP server is not connected / not running** (tool unavailable or `/mcp` reconnect failed): **start the underlying server first** — it backs the local app, so `script -q /dev/null docker/sdk run` (or `up`) brings it up — then **ask the user to reload the MCP connection** (run `/mcp` to reconnect) and wait for confirmation before continuing. Don't guess names; if the reconnect keeps failing, fall back to reading the codebase directly (grep/Read) and say so.

**Step 0.3 — Validate existing flows (ASK FIRST; only when the behavior already exists)**
If the feature modifies or extends an existing flow, ask the user: *"This extends an existing flow (<name> → <endpoint>). Should I run the app and validate how it behaves today?"* **Only if yes**, invoke the **`spryker-runtime`** skill to see how it behaves today — run a `docker/sdk cli console` command, call the endpoint over HTTP, or log in and drive the UI in Chrome (start the app with `script -q /dev/null docker/sdk run` if it's down). Use the observed status/response/UI to write accurate acceptance criteria and the correct endpoint path. Skip this (and the question) for greenfield behavior that cannot be run yet.

**Step 0.4 — Hold the findings**
Keep what you gathered: real module/transfer names always; documented behavior (if researched) and observed behavior (if validated) when applicable. They feed Phases 3–5 directly. Do not invent any of these later.

**CHECKPOINT:** Do not draft requirements until codebase facts are gathered, the opt-in questions (docs / running-app validation) have been asked, and any tool gaps are surfaced to the user.

### Phase 1: Initial Requirements Gathering 🔄 INTERACTIVE

**Step 1.1: Feature Description**

Use `AskUserQuestion` to gather initial context:

```
Question 1: "What problem or opportunity does this feature address?"
- Ask for current pain points
- Business impact (revenue, costs, satisfaction)
- User needs being addressed

Question 2: "Is there additional context I should know?"
- Technical constraints
- Market/competitive context
- User research or data
- Regulatory requirements

Question 3: "What are your preliminary goals for this feature?"
- What measurable outcomes define success?
- What metrics will improve?
- What user behavior should change?
```

**Example AskUserQuestion:**
```json
{
  "questions": [
    {
      "question": "What problem or opportunity does this feature address?",
      "header": "Feature Need",
      "options": [
        {
          "label": "Solving user pain point",
          "description": "Users are struggling with current functionality or lack of feature"
        },
        {
          "label": "Business opportunity",
          "description": "Revenue growth, cost reduction, or competitive advantage"
        },
        {
          "label": "Technical debt or improvement",
          "description": "Improving existing systems, performance, or maintainability"
        }
      ],
      "multiSelect": false
    }
  ]
}
```

After gathering, ask user to describe the problem in their own words (freeform text).

### Phase 2: Draft Background & Goals 🔄 INTERACTIVE

**Step 2.1: Write Draft**

Based on Phase 1 answers, draft:
- Background section (WHY this exists)
- Goals section (3-5 measurable objectives)

**Step 2.2: Present for Review**

Present the draft to user and ask:

```
"Here's the Background and Goals I've drafted based on your input:

## Background
[Your draft]

## Goals
[Your draft]

Does this accurately capture the WHY and the measurable objectives?"
```

Use `AskUserQuestion`:
```json
{
  "questions": [
    {
      "question": "Does the Background and Goals section accurately capture your intent?",
      "header": "Review",
      "options": [
        {
          "label": "Approve - looks good",
          "description": "Background and goals accurately reflect the feature need"
        },
        {
          "label": "Needs refinement",
          "description": "Some parts need adjustment or clarification"
        },
        {
          "label": "Add more context",
          "description": "Missing important background information or goals"
        }
      ],
      "multiSelect": false
    }
  ]
}
```

**Step 2.3: Iterate if Needed**

If user requests changes:
- Ask what needs adjustment
- Revise the sections
- Present again for approval
- Repeat until approved

**CHECKPOINT:** Do not proceed to Phase 3 until Background & Goals are approved.

### Phase 3: User Stories - Iterative Creation 🔄 INTERACTIVE

**CRITICAL:** Create user stories ONE AT A TIME, not all at once.

**Step 3.1: Identify Story Count**

Ask user: "Based on the goals, I estimate we need [X] user stories. Does this sound right, or do you have specific scenarios in mind?"

**Step 3.2: For Each User Story (Iterative Loop)**

Repeat this process for each story:

**a) Ask user to describe scenario:**
```
"Let's work on User Story #[N]. Please describe:
- Who is the user? (role/persona)
- What do they want to do?
- Why do they want to do it? (benefit/goal)"
```

**b) Draft the story — with actor and affected endpoint:**
Assign a canonical actor (see [actors.md](actors.md); a Back Office story may carry a specific role like "Back Office content manager (Back Office user)"), and resolve the affected endpoint now using the Phase 5 rules (controller/action from the module map + host from `deploy.dev.yml`). Record the controller+action FQCN in the `.refs.md` attachment; the PRD body shows the **URL path only**. Write all three together:
```markdown
### User Story [N]: [Title]

**As a** [canonical actor / specific role (canonical actor)]
**I want to** [action]
**So that** [benefit]

**Actor:** [the exact actor chosen]
**Affected endpoint:** `[resolved URL path]` ([existing | greenfield] — [Module name], or "No endpoint affected")
```
> Path only — no controller/action class in the body. The FQCN goes in `{feature-name}.refs.md`.

**c) Present to user — story + actor + endpoint as one unit:**
Show the drafted story together with its actor and affected endpoint, then ask the user to confirm or correct each. Do not ask only about the sentence.

```json
{
  "questions": [
    {
      "question": "Approve this story with its actor and affected endpoint?",
      "header": "Story Review",
      "options": [
        {
          "label": "Approve",
          "description": "Story, actor, and endpoint are correct — move to acceptance criteria"
        },
        {
          "label": "Fix actor",
          "description": "Wrong actor/role (e.g. should be Back Office content manager, not admin)"
        },
        {
          "label": "Fix endpoint",
          "description": "Endpoint name/path is wrong, or it should be 'no endpoint affected'"
        },
        {
          "label": "Modify story",
          "description": "Adjust the action or benefit, or reframe the scenario"
        }
      ],
      "multiSelect": false
    }
  ]
}
```

**d) If approved, move to Phase 4 for that story. If modified, revise and present again.**

**e) After completing acceptance criteria (Phase 4), ask:**

```json
{
  "questions": [
    {
      "question": "Do we need another user story?",
      "header": "More Stories",
      "options": [
        {
          "label": "Add another story",
          "description": "There's another user scenario we need to capture"
        },
        {
          "label": "Done with user stories",
          "description": "All key scenarios are covered"
        }
      ],
      "multiSelect": false
    }
  ]
}
```

**CHECKPOINT:** Do not proceed to Phase 5 until all user stories are complete with acceptance criteria.

### Phase 4: Acceptance Criteria - Per Story Iteration 🔄 INTERACTIVE

**CRITICAL:** For the approved user story from Phase 3, create acceptance criteria ONE SCENARIO AT A TIME.

**Step 4.1: Draft Initial Scenarios**

Based on the user story, draft 2-3 Gherkin scenarios that test different aspects.

**Step 4.2: Present Each Scenario Individually**

For each scenario:

**a) Show the scenario:**
```markdown
Scenario: [Scenario name]
  Given [precondition]
  When [action]
  Then [outcome]
  And [additional outcome if needed]
```

**b) Ask for approval:**

```json
{
  "questions": [
    {
      "question": "Does this acceptance criterion correctly validate the user story?",
      "header": "Criterion Review",
      "options": [
        {
          "label": "Approve this criterion",
          "description": "This scenario accurately tests the expected behavior"
        },
        {
          "label": "Modify this criterion",
          "description": "Needs adjustments to Given/When/Then statements"
        },
        {
          "label": "Delete this criterion",
          "description": "This scenario isn't relevant or is redundant"
        }
      ],
      "multiSelect": false
    }
  ]
}
```

**c) After all initial scenarios reviewed, ask:**

```json
{
  "questions": [
    {
      "question": "Are there additional acceptance criteria needed for this user story?",
      "header": "More Criteria",
      "options": [
        {
          "label": "Add another scenario",
          "description": "There's an edge case or behavior we need to test"
        },
        {
          "label": "Done with this story",
          "description": "All acceptance criteria for this story are complete"
        }
      ],
      "multiSelect": false
    }
  ]
}
```

**Step 4.3: Loop Back to Phase 3**

After completing acceptance criteria for current story, record its affected endpoint (Phase 5 below) while the context is fresh, then return to Phase 3 Step 3.2(e) to ask if more user stories are needed.

### Phase 5: Record Affected Endpoint(s) 🎯 REQUIRED per story

For each story that touches a request/response surface, add the endpoint affected — pulled from the Phase 0 research brief, never guessed.

**Step 5.1: Resolve the host**
Endpoint hosts are configurable. Read `deploy.dev.yml` (`groups.<region>.applications.<app>.endpoints`) for the real host of the relevant application (Back Office, Yves, Glue, Merchant Portal). **If the user named a specific endpoint to target — e.g. a real cloud URL `https://<env>.cloud.spryker.com` — use that host instead.**

**Step 5.2: Record path + status in the PRD body (FQCN goes to the attachment)**
```markdown
**Affected endpoint:** `http://<backoffice-host>/<module-name-dashed>/<controller>/<action>` (existing; <Module> module)
```
- The PRD body shows the **URL path only** = `<resolved-host>` + route. Status = `existing` or `greenfield` (name the closest neighbor path for greenfield). Add the module/feature name.
- Resolve the controller + action during research and write the **FQCN into `{feature-name}.refs.md`** (Endpoints table) — not the PRD body.
- If the story has no endpoint (pure config/data), write "No endpoint affected."

> **No code references in the body.** This holds for every section. FQCNs, transfer fields, configuration keys, plugin names, and file paths discovered during research are recorded in the sibling `{feature-name}.refs.md` attachment (see [refs-attachment.md](refs-attachment.md)), which the PRD header links.

### Phase 6: Non-functional Requirements 🔄 INTERACTIVE

**Step 6.1: Draft Sections**

Business-level quality attributes only — no tooling, no test-suite commands:
- Performance (latency, throughput targets)
- Reliability (availability, error handling, fallback behavior)
- Security (data protection, auth, compliance)
- Scalability (growth/volume projections)

Quantify every attribute (no "fast"/"reliable"). Do NOT add tooling commands, test suites, or "quality gates" — those belong to planning, not the PRD.

**Step 6.2: Present for Review**

Show the draft and ask:

```json
{
  "questions": [
    {
      "question": "Are there additional quality attributes or constraints we should specify?",
      "header": "NFR Review",
      "options": [
        {
          "label": "Approve as-is",
          "description": "Non-functional requirements are comprehensive"
        },
        {
          "label": "Add performance requirements",
          "description": "Need to specify additional latency or throughput targets"
        },
        {
          "label": "Add security requirements",
          "description": "Need to specify data protection, auth, or compliance needs"
        },
        {
          "label": "Add other requirements",
          "description": "Reliability or scalability needs"
        }
      ],
      "multiSelect": true
    }
  ]
}
```

**Step 6.3: Iterate if Needed**

For each selected addition: ask the user to describe it, draft the specific requirement, add to the appropriate section.

**CHECKPOINT:** Do not proceed until NFRs are approved.

### Phase 7: Success Metrics & Scope

**Step 7.1: Draft Success Metrics**

Based on Goals from Phase 2, write measurable KPIs:
```markdown
## Success Metrics

- [Metric 1]: [Current baseline] → [Target]  (measured via [tool])
- [Metric 2]: [Current baseline] → [Target]  (measured via [tool])
```

**Step 7.2: Draft Out of Scope**

List what we are explicitly NOT building this iteration:
```markdown
## Out of Scope

- [Item 1]
- [Item 2]
```

**Step 7.3: Draft Dependencies (if any)**

Names only — module/feature names and configuration names. **No class/method/file references** (those belong in `{feature-name}.refs.md`).

```markdown
## Dependencies

**Internal:**
- [Module/feature name prerequisite]
- [Configuration name prerequisite, referred to by name]

**External:**
- [Third-party/service prerequisite]
```

**Step 7.4: Quick Confirmation**

Present these sections and ask for quick approval or additions.

### Phase 8: Final Review & Save 🔄 INTERACTIVE

**Step 8.1: Red Flag Check**

Review the complete PRD and remove anything matching:
- ❌ Time estimates ("Week 1", "2 days", "Q1")
- ❌ A "Quality Gates" section or any phpstan/phpcs/codecept commands
- ❌ A "Tasks" / implementation breakdown
- ❌ Architecture/code design inside user stories
- ❌ "As a user" instead of a canonical actor (see [actors.md](actors.md))
- ❌ A guessed endpoint path not traceable to the Phase 0 research brief / `deploy.dev.yml`
- ❌ **Any code reference in the body** — FQCN, `Class::method()`, controller/action/plugin/facade/transfer/repository class name, or file path (anywhere, including Dependencies). Move it to `{feature-name}.refs.md`; in the body use module/feature names and configuration names; endpoints show the URL path only.
- ❌ Vague goals or unquantified criteria ("fast", "good", "works well")
- ❌ Acceptance criteria not in Gherkin, >4 steps, or multiple behaviors per scenario
- ❌ Missing business justification or success metrics

Quick grep before saving (the body should be clean): `grep -nE '::|\\\\[A-Z][A-Za-z]+\\\\|/src/' {feature-name}.prd.md` — hits other than the linked attachment path mean code leaked into the body.

**Step 8.2: Present Complete PRD** to the user.

**Step 8.3: Final Approval**

```json
{
  "questions": [
    {
      "question": "Is the PRD ready to save?",
      "header": "Final Review",
      "options": [
        {"label": "Approve and save", "description": "PRD is complete and ready for implementation planning"},
        {"label": "Needs revisions", "description": "Some sections need adjustment before saving"}
      ],
      "multiSelect": false
    }
  ]
}
```

**Step 8.4: Determine Storage Location**

Ask the user which location:
- Global feature: `resources/plan/PRD/Features/{FeatureName}/{feature-name}.prd.md`
- Single module: `src/{Org}/{Module}/resources/plan/PRD/{feature-name}.prd.md`

**Step 8.5: Save PRD + the code-reference attachment** with the `Write` tool. Write the code-free `{feature-name}.prd.md` **and** its sibling `{feature-name}.refs.md` (same directory) containing the code references discovered during research — see [refs-attachment.md](refs-attachment.md). Ensure the PRD research header links the attachment.

**Step 8.6: Confirm Completion** — mention both files.

```
PRD saved to: [path]/{feature-name}.prd.md (business requirements, code-free)
Code references saved to: [path]/{feature-name}.refs.md (controller/action FQCNs, transfers, configuration keys, file paths — for the planner)

Next steps:
- Use this PRD (and its .refs.md attachment) as input for implementation planning (e.g. the prd-to-planning skill)
- Share with stakeholders for alignment
- Update as requirements evolve
```

