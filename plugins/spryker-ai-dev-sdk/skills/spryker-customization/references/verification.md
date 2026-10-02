# Step 6b — per-case verification in full

**If the QA-thorough phase is on** (default for MVP):

1. Invoke the `spryker-qa-coverage` Skill (via the `Skill` tool) and pass it the AC list from Step 1 **and the Step 0d scale envelope** — without the envelope its Scale/NFR bucket reports "no envelope provided" and the whole NFR chain silently degrades.
2. The skill returns a structured test plan with cases bucketed as Happy / Negative / Authorization / Corner / Scale-NFR — each case tagged with its lightest verification mode (DB / Console / API / Chrome / Mailpit / Queue-UI / Redis-UI).
3. **Order matters**: run **UI / Chrome cases first** for the Happy bucket (they cheap-fail if something visual is broken), then API/DB/Console cases, then Negative/Authorization/Corner, then the Scale/NFR measurements last (they need a working feature to measure).
4. **Login session reuse.** Group the Chrome cases by required user (Admin / Buyer / Buyer_With_Limit / Approver / Merchant user / etc.) and run each group back to back. The first verifier in a group logs in itself; each later one gets the hint *"the browser is already logged in as <user>"* in its prompt, checks the session, and skips its own login (saves ~5s per case). The main loop never logs in.
5. Invoke `spryker-verifier` per test case from the plan. Each case becomes its own green/red verdict with evidence.
6. **Functional tests** (facade-level codecept tests, when the tests phase is on) run **after** the UI verification cases — never before. If UI Happy cases are red, don't run functional tests; the feature isn't ready.

**If the QA-thorough phase is off** (default for PoC):

Invoke `spryker-verifier` per literal AC from Step 1 — no expansion. Order: UI ACs first, then API/DB/console ACs. Functional tests (if the tests phase is on) run last, after all UI ACs are green.

**In both modes, browser-driving cases run one at a time** — parallel verifiers share the browser's tab group and evict each other (the `spryker-verifier` agent definition). Only cases that drive no browser (API, DB, console) may be dispatched together in one message.

**In both modes**, if a verification needs test data that doesn't exist (a specific entity with specific attributes referenced by the case), invoke **`spryker-data-seeder`** first to seed the minimum entities, then run verifier.

**Write-path evidence rule (both modes, unconditional).** A write path is verified by reading the written state through a **different mechanism than the one that wrote it**. Concretely:

- Any import-touching AC asserts the **row delta** — expected count stated up front, `SELECT COUNT(*)` on the target table before/after. The importer's own console report is explicitly **not** acceptable evidence: a "Successful, 3 imported DataSets" line can be printed with zero rows written.
- Any search/KV-touching feature gets at least one case exercising a **non-import write path** (Back Office edit or direct facade call) asserting the index/KV updated — "import propagates" is not evidence any other write does, and an index that drifts on non-import writes drifts silently.

**Scale/NFR bucket (MVP, when the QA-thorough phase is on).** `spryker-qa-coverage` returns a fifth bucket that verifies the Step 0d scale envelope: the **query-count delta** on the primary flow (ES/DB round trips before vs after the feature), the **per-document index-size delta** multiplied out against the envelope, and the **import-path timing statement** (synchronous work inside import at envelope scale — state the math). Verdict rule: *all ACs green + NFRs unmeasured is reported as incomplete, not done* — the Step 8 report carries the NFR verdicts alongside the AC table.
