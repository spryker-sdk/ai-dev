# Step 8 — the Implementation Report template

Present exactly this block at the commit gate, filled from `run.log` and `decisions.md`.

```
## Implementation Report — <feature slug>

Quality bar: <PoC | MVP>

### Acceptance Criteria
| # | AC | Status | Evidence |
|---|----|--------|----------|
| 1 | <short summary> | ✅ green | <screenshot / response / query> |
| 2 | <short summary> | ❌ failed-after-retries | <last failure detail> |

### NFR verdicts (MVP — measured against the Step 0d scale envelope)
| NFR | Target | Measured | Verdict |
|-----|--------|----------|---------|
| Query count, primary flow | <n> round trips | <n> | ✅ / ❌ / not measured |
| Index growth | <budget> | <formula × envelope> | ✅ / ❌ / not measured |

(An unmeasured NFR is reported as **incomplete**, never omitted. PoC: replace the table with the one-line demo-scale caveat.)

### Diff summary
- Files touched: <count>
- Files:
  - src/<project-namespace>/... — <one-line purpose>
  - ...

### Caveats (skipped phases, accepted risks, PoC shortcuts)
- <e.g. solution-design phase skipped (reason), demo-scale accepted, hardcoded values, single-locale coverage, ACL skipped, overruled warnings with their predicted failure>

### Run log
- `<absolute path to $BUILD_DIR/run.log>`

### Stage and commit?
Branch `ai-customize/<slug>`. <X of N> ACs green. Stage and commit these files?
```

## The commit gate

Staging and committing are the user's decision, so the files stay unstaged until the user answers:

1. **List the files you edited** (you've been tracking them throughout the workflow), including new files.
2. **Check the diff:** `git status` to confirm the list matches the working tree; `git diff -- <your files>` for the changes, and read each new file directly (an untracked file has no diff).
3. **Final code review against that diff** (mandatory whenever the code-review phase is on; run it without being asked). Invoke `spryker-code-reviewer` with the output of `git diff -- <your files>` plus the new files, not the in-memory file list. The Step 7b review ran before the last round of self-correct fixes; this final pass is what the user judges the commit on. If the reviewer surfaces new findings, apply the "fix root cause, never work around" rules from Step 7b and re-review. Loop until the reviewer is clean OR the user explicitly accepts remaining findings. Surface the final reviewer report in the commit gate so the user sees it before deciding.
4. Show the commit gate (the report block + the *"Stage and commit these files?"* question). **Demo preset:** return the report block to `demo-prep-wizard` without the question — the wizard shows it at its final review.

**If the user says yes:**
- Stage only the listed files, by path: `git add <your files>`. Never `git add .` or `git add -A` — they also stage unrelated changes on the branch (under the demo preset, the branch carries the preparation's uncommitted changes).
- Then `git commit` with a structured message that lists the ACs in the body.
- Branch stays local. **Do not push.**

**If the user says no, or any AC is red after retries (no commit happens):**
- Leave the files unstaged and unchanged.
- Make the report state clearly: *"Files changed, not staged: <list>. Run `git diff` to review, `git add <files>` to stage, then `git commit`."*
