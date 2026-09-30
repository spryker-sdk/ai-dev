# Delegation cheatsheets — subagents and skills

## Subagent delegation cheatsheet

All of these ship as agent definitions (`.claude/agents/` for a setup install, or the plugin's `agents/` directory). Invoke them with the harness's subagent-spawning tool (commonly `Agent`), passing `subagent_type="<name>"` — never via the `Skill` tool.

**Agent type names are prefixed on a plugin install.** When these agents ship via the
`spryker-ai-dev-sdk` plugin, the registered type carries the plugin prefix —
`subagent_type="spryker-ai-dev-sdk:spryker-verifier"`, not the bare `spryker-verifier` (the
`Agent` tool rejects an unregistered bare name with an "Agent type not found" error that lists
the valid names). The bare names in this document are shorthand: try the bare name only on a
setup install (`.claude/agents/`); if it fails to resolve, retry with the
`spryker-ai-dev-sdk:` prefix before reporting a step blocked.

| Subagent | When to invoke |
|---|---|
| `spryker-feature-expert` | Before planning — *"how does this feature work?"*, *"how is X configured in this project?"*, *"what's the extension point for Y?"*. Parallel-invoke for multiple Spryker domains. |
| *(architect pass)* | Step 3a when the Solution-design phase is on — a **fresh `general-purpose` subagent** briefed per [solution-design.md](solution-design.md). Never a fork and never the implementing context. |
| `spryker-verifier` | After refresh — per-AC verification; browser-driving cases one at a time, browser-free cases (API, DB, console) may run together. |
| `spryker-issue-diagnoser` | When verifier returns red or refresher fails — diagnose root cause before retrying. |
| `spryker-data-seeder` | When a verification needs test data that doesn't yet exist. |
| `spryker-code-reviewer` | Step 7b and the final diff review before the commit gate, when the code-review phase is on. |
| `spryker-screenshot-collector` | Step 7c (before commit) when the screenshots phase is on — capture demo artifacts so they're part of the final report the user reviews before deciding to commit. |

**Weigh a verifier FAIL / BLOCKED before acting on it.** Browser-driven agents fail for browser reasons — inherited tabs, cert interstitials, unbound JS — so a red verdict is a hypothesis, not a diagnosis. Before dispatching the diagnoser: (1) **act on the agent's own caveats first** — the verifier writes them at the top of `.ai-dev/verifier-report.md`; a flagged stale tab or skipped login means re-run it clean, not investigate; (2) **spend one first-party query on the same claim** — the table the action would have written (`spy_sales_order` for "place order", `spy_quote` for "add to cart"), or the log line the exception would have hit — via the read-only `executeDatabaseQuery` route, per the DB rule below. A verdict that contradicts persisted state is an agent problem, not a product defect. For example, "Add to basket produces zero effect — BLOCKED" next to a completed order in `spy_sales_order`, with an inherited-tab caveat in the report, is a browser problem.

## Skill delegation cheatsheet

These are **skills** (loaded into the main session), not subagents. Invoke via the `Skill` tool — never via `Agent`.

| Skill | When to invoke |
|---|---|
| `product-requirement-document` | Step 0c — when the user has no PRD (or wants to create/refresh one) before intake. Creates the business-facing PRD that Step 1 reads. |
| `spryker-refresher` | Step 5 — post-change commands (composer dumpautoload, codegen, schema, cache clears, frontend builds, cache warmups). Mandatory; the orchestrator must not run `docker/sdk console` / `docker/sdk cli composer` inline during Step 5. |
| `spryker-qa-coverage` | Step 6 (when the QA-thorough phase is on) — expand the AC list + scale envelope into a 5-bucket test plan before invoking the verifier. |
| `ai-runtime-debugging` | When you need to see runtime values that aren't surfacing in logs / DB / browser state — during Step 4 build or Step 7 self-correct. Teaches the `[AI-DEBUG]` tagged-log pattern and optional XDebug step-debug. Always paired with a cleanup pass in Step 7b. |
| `cypress-tests` | Step 7a (when the Cypress E2E phase is on) — once all ACs are green, fix / improve / add a Cypress E2E spec locking in the user-visible behavior, run it targeted + the suite's quality gate. |
| `yves-atomic-frontend` | Step 4 — before the first write to any `.scss` / `.ts` / atomic-component file under `Theme/default/components/`. |
| `static-validation` | Step 7b — lint / phpcs / phpstan over the final diff before commit. |
