# Handling code review findings

### Handling code review findings — fix root cause, never work around

For each finding the reviewer surfaces:

- **If a clean fix is local** (rename, extract method, reorder, tighten a type, move a constant, replace a magic literal with a named constant): apply it. Do not add a comment explaining the change — clean code needs no narration of the correction.
- **If the fix requires more scope than the workflow has touched so far** — e.g. a finding about a vendor-side issue, an architectural concern beyond the current diff, a missing test infrastructure piece, a different module that would need parallel changes — **stop**. Do not mask the finding with an `if` / `try` / `catch` / sentinel value / special-case branch / null-check that hides the symptom while leaving the root cause in place. **Workarounds are forbidden.** Surface to the user instead:

  > *"The reviewer flagged X. Cleanly fixing this requires Y, which is outside the diff we've made so far. Options: (a) expand scope and fix properly — adds ~N files / module Z; (b) document the limitation and leave the finding open; (c) revert the change that surfaced the finding."*

- **Never add defensive commentary** — see Step 4's "No defensive comments" rule. After the review fix, the diff should read as clean code, with no comments about the fix. If a future reader needs the *why*, they read the PR description.

**Self-correction signals when handling review:**
- About to write `if ($x === null || $y instanceof FooException)` to make the reviewer's complaint go away? → that's masking, not fixing. Stop.
- About to add `/** @internal addressed code review finding ... */` or `// Per review: ...`? → that's narration, not code. Stop.
- About to add a `try / catch` that swallows the exception silently? → that's workaround, not fix. Stop.
- About to extract a method just to give the masking logic a name? → still masking. Stop.

If the finding can't be fixed cleanly within the workflow's scope, **escalate** rather than masking it.
