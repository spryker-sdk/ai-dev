# Step 7a — Cypress E2E coverage: how to run the phase

Read this when Step 7a's gate conditions hold and the phase runs. The gate conditions and the
verdict-handling rules live in SKILL.md Step 7a; this file is the execution detail.

Invoke the **`cypress-tests`** skill (via the `Skill` tool — it's a skill, not a subagent) and work
from the green AC list plus the verifier's evidence (they are the ready-made scenarios the spec should
encode). Follow the skill's own workflow:

- **Decide fix vs improve vs add** against the existing suite first (the skill's Step 1 orientation):
  1. **Fix** — an existing spec covering the feature's flow went red because of this change → repair
     it against the new intended behavior (only if the change is intended; a spec red for an
     unintended reason is a red AC that belongs back in Step 7, not a spec to rewrite).
  2. **Improve** — an existing spec exercises the flow but asserts none of the new behavior →
     strengthen it to assert the concrete values the feature introduces.
  3. **Add** — no spec covers the flow → author a new one per the skill's conventions (page objects,
     dynamic/static fixture pair, typed fixtures, no selectors in the spec).
  4. **None needed** — the flow is genuinely outside the suite's scope → record that verdict with a
     one-line reason instead of forcing a spec.
- Run the result **targeted** (`npx cypress run --spec "<the spec>"`) and then the suite's quality
  gate (`npm run code:check`), per the skill's Step 3–4 checklist — including the re-run-green and
  no-flake (passed on attempt 1, not on a retry) checks.
- Bulk run output goes to `$BUILD_DIR/cypress-<n>.log`, and `run.log` gets the one-line verdict:
  `pass|fail|skipped(<reason>)` + the action taken (fix/improve/add/none) + the spec paths.
- **Track the spec/fixture/page-object files you touch** — they are part of the feature diff and go
  through Step 8's staging like every other edited file.
