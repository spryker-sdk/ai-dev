---
name: spryker-qa-coverage
description: QA and functional-test a Spryker feature by exercising the RUNNING application — turn a PRD or feature description into a test checklist and concrete test cases (happy path, negative, authorization, and corner/edge cases), then execute them against the live app and report pass/fail with real evidence. Use this at ANY point — when a feature is done, MID-development to check work-in-progress as you go, or as a quick SMOKE TEST after a refactor to confirm nothing broke. Trigger whenever the user wants to QA, test, or verify that a feature actually works end-to-end, manually test a flow, build or run a test checklist or test cases, smoke-test after refactoring or a change, sanity-check work in progress, find edge/corner cases that could break a feature, check that data lands in the database/Redis/queue after an action, or hands over a PRD and asks whether it is correctly implemented — even phrased loosely as "test this", "does this still work", "did my refactor break anything", or "make sure it works in the back office". It drives the app through the `spryker-runtime` skill (Back Office and storefront in Chrome, console/CLI commands, HTTP/API calls, storage checks), picking the lightest mode per case. This is hands-on QA against a real environment: it does NOT write automated test code (use codecept-functional or cypress-e2e-test), does NOT run static analysis or CI (use spryker-ci, static-validation, or phpstan), and does NOT fix code — it observes and reports.
---

# Spryker QA & Testing (from PRD → checklist → execute → report)

## What this does and why

A PRD says what a feature should do; this skill **proves whether the running application actually does it**. It turns requirements into a concrete test checklist and test cases (happy paths *and* the corner cases that break features), then **executes them against the real app** and reports what truly happened — with evidence, not assumptions.

### Prefer true end-to-end; use a workaround only when E2E is impossible

The bar is **the real user flow**: act as the actor through the rendered UI (click the button, submit the form), let the application's own code build the request and apply the response, then confirm the result the user would see **and** the persisted/published state behind it. That is the only evidence that proves the *feature*. Default to it.

A **workaround** (hitting the endpoint directly via Mode 4/5, invoking a facade/console path, seeding state in the DB) tests a *layer below the UI*. It is legitimate and valuable — but it is **not** E2E, and a green workaround must never be reported as if the user flow works. Use a workaround only when true E2E is genuinely blocked, e.g.:
- a client control is broken (dead button) but you still want to prove the server independently,
- the harness can't supply what the UI path needs (e.g. it blocks reading the CSRF token — see `spryker-runtime` Mode 5),
- the case is inherently below the UI (a console command, a queue worker, a Glue contract with no UI).

When you fall back, **state which layer you actually exercised** and keep the UI-flow case itself honest (FAIL/BLOCKED if the UI couldn't be driven). Tag every result with its execution layer (see Step 4) so "server works" can never masquerade as "feature works". When a workaround passes but the real flow is broken or unverified, the case is **not** a clean PASS — record it as `PASS (server only)` / FAIL / BLOCKED as appropriate.

### Investigate the code when the PRD or a criterion is unclear

This skill is behavior-first, but reading the implementation to **understand what to test and how to drive it** is encouraged — it is not the same as judging code quality (which stays out of scope). When a PRD is vague, a Gherkin step is ambiguous, the real module/config slug differs from the PRD's wording, or you need the exact endpoint path, selector, config object name, toggle key, or DB table to write or execute a case — **read the code** (or delegate a scoped `Explore` pass). Ground the test cases in what's actually built: the controller/action and route, the feature-toggle key, the JS entry/config object the UI reads, the transfers, and the storage tables a result lands in. Note in the report when a criterion had to be interpreted from code rather than the PRD.

It deliberately stays in the QA lane:
- **No static analysis.** Never run phpstan/phpcs/`spryker-ci`/`validation.sh`. QA here is about runtime behavior, not code style or types. If asked to "check the code", clarify that this skill tests behavior; static checks live in other skills.
- **No automated test code.** This is manual/exploratory execution against a live environment. Writing Codeception/Cypress code is `codecept-functional` / `cypress-e2e-test`.
- **No fixing.** QA observes and reports; it does not change product code (see "On failure").

## Two modes — pick by the moment

QA isn't only a final gate. This skill runs in two modes; choose by what the user is asking for and scale the effort accordingly.

### Full QA (default)
A feature is complete (or the user explicitly wants thorough QA). Run the **full workflow below**: the test-case buckets that apply to each story (scaled to its risk, not a fixed matrix), every actor the feature touches, **save the checklist/test-case artifact**, and produce the **written report**. This is the durable QA record.

### Smoke test (light, no artifact, no report)
For **mid-development sanity checks** and **post-refactor regression checks** — "does this still work", "did my refactor break anything", "quick check the slice I just built". **No PRD needed** — derive the key paths straight from what the user changed or described (the file/area touched, the flow they name). The point is fast feedback, not a paper trail, so:
- **Do NOT save a checklist artifact and do NOT write the formal report.** Just run the checks and tell the user inline: a short pass/fail line per check with the evidence, and a one-line verdict ("looks good" / "this broke: …").
- Run only the **key paths**: the happy path for the touched area, plus the one or two checks most likely to regress (e.g. the authorization boundary, or whether published data still lands in storage). Skip the full corner-case sweep.
- Lean on the **lightest modes** (CLI / API / storage); reserve Chrome for flows whose UI actually changed.
- The bar is **"still behaves the same"** — favour catching regressions over hunting new edge cases.
- Open the reply by stating it's a smoke test (not full coverage), so the user knows what was and wasn't checked.

If a smoke test surfaces something serious, say so and offer to do a full QA pass on that area. When unsure which mode the user wants, default to smoke for "still works / refactor" phrasing and full for "QA this feature / test this PRD".

## How this runs — inline vs. subagent

QA execution is noisy (screenshots, page dumps, curl bodies, console logs), which tempts delegating it to a fresh subagent. But QA is also **only as good as the context it carries** — what was just changed, gotchas already discovered (a worker that must be running, an account whose password changed, a queue that needs draining), and findings from earlier in the session. A blind subagent re-discovers or silently misses all of that. So choose deliberately:

- **Smoke test → always run inline.** Its entire job is "did the thing we were just working on break?" — that lives in the main conversation's context. Running it in an isolated subagent would strip exactly the information it depends on. Never delegate a smoke test.
- **Full QA → inline by default; a subagent is OPTIONAL when the run will be long/noisy.** A PRD-driven full pass is well-bounded (PRD in → report out), so it's safe to delegate to keep the main conversation clean — but only when you have a reason to (many cases, heavy Chrome use).

**If you do delegate Full QA to a subagent, you MUST pass the session's collected context into its prompt** — otherwise you reintroduce the exact blindness above. Hand it forward explicitly:
- what changed / what's under test (files, refactor, feature slice),
- environment gotchas already learned (worker running? account + working password? queue state? feature installed?),
- relevant findings so far (a bug seen earlier, a flow that already passed/failed),
- the PRD path (don't assume the subagent can see the PRD you just created in-session — give it the path or the content),
- and ask it to **return the full report** (and save the artifact) so nothing useful is trapped in the subagent.

When in doubt, run inline — losing context is a worse failure than a slightly noisier conversation.

## Inputs

**Smoke test:** no PRD required. Derive the key paths from what the user changed or described — the area/file touched, the flow they name — and go.

**Full QA:** work from a **PRD** when available — it carries exactly what good test cases need: user stories, **Gherkin acceptance criteria** (each scenario is already a test case), the **actor** per story, and the **affected endpoint**.

1. If given a PRD path, read it. If the user just created a PRD in this session, use that.
2. If no PRD, ask for one — or accept a free-text feature description and proceed (note in the report that cases were derived from a description, not a PRD, so coverage may be looser).
3. Pull from each story: **actor**, **affected endpoint (name + path)**, and the **Gherkin scenarios**. These seed the test cases directly.

## Workflow

### Step 1 — Build the test checklist and test cases

For each user story / acceptance criterion, derive test cases from these four buckets — but scale the count to the story's risk rather than forcing one of each. Always cover the happy path; add negative, authorization, and corner cases where *this* story actually carries that risk, so corner cases aren't an afterthought without padding every story into a fixed matrix (more cases = a slower pass):

- **Happy path** — the documented behavior works for the intended actor.
- **Negative / validation** — missing or malformed input, wrong values, empty state, "no results".
- **Authorization** — a *different* actor (or one lacking the ACL role) is correctly denied. Spryker is actor-heavy; test the boundary, e.g. a Customer hitting a Back Office endpoint, or a Merchant user reaching another merchant's data.
- **Corner / edge** — boundaries and the things that actually break Spryker features: empty collection, single vs. many (bulk of 1 vs 100), very long strings, special characters/HTML in text fields, concurrent/duplicate submit, CSRF token missing/expired, multi-store / multi-locale, multi-currency, pagination limits, stale cache vs. published storage (Redis/Elasticsearch), and idempotency on retry.

Think explicitly about what could break this *specific* feature — don't just template. If the feature publishes data, a corner case is "does the storefront storage actually reflect it after publish?". If it's a bulk action, "what about a partial failure mid-batch?".

Write each case using the template in [test-case-template.md](test-case-template.md). Each case records: id, title, **actor**, **execution mode** (chosen in Step 2), preconditions, steps, expected result, bucket.

Save the artifact (checklist + cases) to:
```
resources/qa/{FeatureName}/{feature-name}.qa.md
```
Present the checklist to the user before executing, so they can add/trim cases.

### Step 2 — Choose the execution mode per case (lightest that proves it)

Each case names how it will be exercised. **The lightest mode that genuinely proves the case** — but for any case whose AC describes a user-facing flow, "genuinely proves" means **the real UI path (Chrome)**; don't downgrade a user-flow case to an endpoint call just because it's cheaper (that's a workaround, not a proof — see "Prefer true end-to-end" above). Escalate to the browser whenever rendered UI or client-side JS is the thing under test. All execution goes through the **`spryker-runtime`** skill (its modes are referenced below).

| What the case verifies | Mode (via `spryker-runtime`) |
|------------------------|------------------------------|
| Rendered UI, client JS, a real user flow, "does the button work" | **Chrome** (Mode 3) — log in as the actor, drive the UI |
| Endpoint contract: status, response shape, fields (Glue, or a Zed/Yves URL) | **HTTP/API** (Mode 2) — curl with a real session/token |
| Multi-step or CSRF-protected flow, repeated runs, fast iteration | **Browser-seeded curl** (Mode 4) — log in once, harvest cookie/CSRF, replay with curl |
| CSRF/session endpoint where the harness blocks reading the token, or proving the server when a UI control is broken | **Page-context fetch** (Mode 5) — run the page's own `fetch`; proves the server, **not** the user flow |
| Data created/changed, config applied, command behavior | **Console/CLI** (Mode 1) — `docker/sdk cli console …` |
| Data actually persisted / published | **Storage checks** — DB (`mariadb`), Redis (`key_value_store`), Elasticsearch, queues — via Mode 1 |

Many cases combine modes: e.g. *act in Chrome, then verify the row in DB and the key in Redis*. That's expected — a publish feature isn't proven until the storefront storage reflects it.

**Tag each case with the execution LAYER it proves**, not just the mode. This keeps the report honest about whether the *feature* was proven or only a layer beneath it. Record one of:
- **E2E** — driven through the real UI as the actor, result observed in the UI **and** (where relevant) confirmed in storage. This is the gold standard.
- **endpoint(button-driven)** — the request was confirmed to originate from the real UI action (e.g. captured at the browser network layer after a click), even if you then assert on the response/DB.
- **endpoint(synthetic)** — the request was hand-built / replayed (Mode 4 or 5). Proves the server, **not** the user flow. A user-flow AC verified only this way is **not** a clean PASS.
- **storage** — asserted directly against DB/Redis/Elasticsearch/queue.
- **console** — exercised via a CLI command.

A 200 from a synthetic request and a 200 from a button click are **not** the same evidence — the layer tag makes the difference visible.

### Step 3 — Execute via `spryker-runtime`

Invoke the **`spryker-runtime`** skill to run each case. It handles login per actor (all five roles + the DB-fallback/ask-user rule), the four modes, host resolution from `deploy.dev.yml` (or a user-specified target), and the Chrome safety rules. Follow its "Manual testing / QA" guidance.

Execution order: run the cheap CLI/API/storage cases first (fast, no browser), then the Chrome flows. Reuse one login session across cases where possible (hand the harvested cookie from Chrome to curl — Mode 4) instead of logging in repeatedly.

Capture **concrete evidence** for every case as you go: HTTP status + key response fields, the DB row / Redis key / queue message you checked, console output, console errors (`read_console_messages`), the rendered result, and a `gif_creator` recording for notable UI flows. Evidence is what makes a QA report trustworthy — record what you actually observed, never paraphrase or assume.

### Step 4 — Report

Append results to the QA artifact and give the user a summary using the **Report format** below. Mark each case **PASS / PASS (server only) / FAIL / BLOCKED** and record the **execution layer** (from Step 2):
- **PASS** — observed result matches expected, **at the layer the AC describes**, with evidence. For a user-facing AC this means it was proven E2E (or button-driven) through the real UI.
- **PASS (server only)** — the server/endpoint behaves correctly (verified synthetically, Mode 4/5), but the real UI flow was **not** exercised (e.g. a broken control forced a workaround). Use this instead of a clean PASS so the report doesn't overstate coverage; pair it with the FAIL/BLOCKED for the UI-flow case.
- **FAIL** — observed differs; record expected vs. actual + evidence + a severity (blocker / major / minor) and which actor/endpoint/layer.
- **BLOCKED** — couldn't run it (env not booted, missing credentials, feature not installed, or the UI path couldn't be driven and no valid workaround applies). Never mark a blocked case as passed; say what's needed (and ask the user for credentials per the `spryker-runtime` rule).

> **Reproduce before you fail it — especially "the button/UI does nothing".** A single bad observation is a hypothesis, not a verdict. Before recording any UI-flow FAIL (and *always* before calling something a **blocker**), reproduce it on **≥3 fresh page loads** (hard reload + at least one navigate-away-and-back), driving the **real control** each time. Front-end bootstrap bugs in Spryker Zed are frequently **intermittent races** (the `DOMContentLoaded` controller-init race — see `spryker-runtime` "Inert Zed JS controller"): the same page is dead on one load and works on the next. If it works on *any* load, it is **not a blocker** — downgrade to a *minor/flaky* finding and say so. Also rule out **your own driver**: `form_input` sets `.value` without firing `input`/`change`; an MCP click can land before init finishes — re-drive with a genuine click and a freshly-primed network monitor before concluding the code is at fault.
>
> **A Mode 5 (synthetic) green does not close a UI-flow case — and must not end the investigation.** If you reached for the page-context fetch *because* a control looked dead, that 200 proves the server, not the button. It is a companion `PASS (server only)`, never a substitute: you still owe a real button-driven attempt across reloads. The tidy "server works / UI dead" split is a smell — it is the exact shape of a *prematurely-closed* investigation. Keep pushing the real UI to completion before you commit the verdict.

## On failure — observe, don't fix

QA stays independent: report the failure with full evidence and a clear reproduction, then **stop short of changing product code**. Offer to hand off — e.g. "want me to investigate/fix this with the relevant skill?" — but don't silently start editing. This keeps the QA verdict trustworthy and separates "does it work" from "make it work".

## Report format

ALWAYS structure the final report like this:

```markdown
# QA Report: [Feature Name]

**Source:** [PRD path | feature description]
**Environment:** [hosts from deploy.dev.yml | user-specified target]
**Actors tested:** [list]

## Summary
- Total: N  | ✅ Pass: N  | 🟡 Pass (server only): N  | ❌ Fail: N  | ⛔ Blocked: N

## Results
| # | Test case | Actor | Mode | Layer | Result | Evidence | Severity |
|---|-----------|-------|------|-------|--------|----------|----------|
| 1 | … | Customer | Chrome | E2E | ✅ PASS | clicked Add to cart; cart shows 3 items; `spy_quote` row updated | — |
| 2 | … (no permission) | Customer | API | endpoint(synthetic) | ✅ PASS | 403 as expected | — |
| 3 | … generate via button | BO content editor | Chrome | E2E | ❌ FAIL | button inert; no request fired (browser net + server log) | blocker |
| 3s | … generate endpoint (server) | BO content editor | Mode 5 | endpoint(synthetic) | 🟡 PASS (server only) | 200; valid JSON; logged in `spy_ai_interaction_log` | — |

## Failures (detail)
### [#3 title] — blocker
- **Actor / endpoint / layer:** …
- **Steps:** …
- **Expected:** …
- **Actual:** … (+ evidence: status / DB row / Redis key / log / screenshot)
- **Reproduction:** …
- **Note:** if a `PASS (server only)` companion case exists, state that the server works but the user flow does not.

## Blocked
- [case] — needs [what]

## Not covered
- [anything skipped and why — keeps the report honest]
```

## Red flags — stop and reconsider

- "I'll just run phpstan/spryker-ci to check it" → NO. This skill never runs static analysis.
- "I'll write a Codeception test for this" → NO. That's `codecept-functional`. Here you execute against the running app.
- "Only the happy path matters" → NO. Corner and authorization cases are where features break; cover all four buckets.
- "It probably works" / "the code looks right" → NO. QA reports *observed* behavior with evidence, not inferences from code.
- "The endpoint returns 200, so the feature works" → NO. A synthetic/endpoint hit is a layer below the UI. If the AC is a user flow, prove it through the real UI (E2E); otherwise mark it `PASS (server only)` and keep the UI-flow case FAIL/BLOCKED. Reading code is fine to *understand what to test* — it is never a substitute for *observing* the running flow.
- "The button did nothing once, so it's a blocker" → NO. Reproduce on ≥3 fresh loads (hard reload + navigate-back) and rule out your own driver first. Spryker Zed front-end bootstrap bugs are often intermittent races — works-on-some-loads is a *minor/flaky* finding, not a blocker. One dead load is a hypothesis, not a verdict.
- "Server works (Mode 5 = 200) and the UI is dead — clean story, done" → NO. That split is the signature of a *prematurely-closed* investigation. Mode 5 proves the server only; keep driving the real button to completion across reloads before you fail the UI case.
- "I'll fix the bug I found" → NO. Report it; offer handoff; don't change product code.
- "Mark it passed even though I couldn't run it" → NO. That's BLOCKED, not PASS.

## Supporting files
- **[test-case-template.md](test-case-template.md)** — the per-case structure and a worked example.
