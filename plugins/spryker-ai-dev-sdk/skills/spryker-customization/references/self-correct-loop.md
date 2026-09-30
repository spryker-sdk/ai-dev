# Step 7 — the self-correct loop in full

### Inputs to the loop

- The verifier's red ACs (from Step 6) + any test failures.
- An **attempt log** you maintain across iterations — per AC, the list of `{iteration, diagnosed root cause, files touched, fix summary, post-verify verdict}` tuples. Pass it to `spryker-issue-diagnoser` on every iteration so it does not repeat itself.

### Per iteration (for each red AC)

1. Invoke **`spryker-issue-diagnoser`** with: the latest verifier failure detail **plus the attempt log so far**. The diagnoser uses prior attempts to avoid re-proposing what already failed.
2. **If the diagnoser reports *"insufficient signal — need runtime instrumentation"***, invoke the **`ai-runtime-debugging`** skill before applying any fix (add `[AI-DEBUG]`-tagged logs at the suspected code path, re-trigger the flow, read logs back). Once you have runtime evidence, return to step 1 with the new information.
3. Apply the **smallest edit** that addresses the diagnosed root cause. Stay in project layer. Append the edit to the attempt log.
4. Re-run `spryker-refresher` if the edit implied new post-change commands.
5. Re-verify just that AC via `spryker-verifier`. Append the verdict to the attempt log.
6. **If green** → AC done; move to next red AC (or to Step 7b if none left).
7. **If still red** → check stuck conditions before next iteration (below).

### Tests in the loop (when tests phase is on)

Run the project's test suite as part of Step 6's verification pass, and treat failures like red ACs — iterate per test until green using the same loop. "Done" requires *all* AC verdicts green AND all tests green.

### Stuck signals — exit the loop and escalate to the user

The loop exits to the user (does not silently give up) when **any** of these fire:

- **Repeat root cause + repeat fix failure.** The diagnoser reported the same root cause as the previous iteration, the same fix shape was applied, and verification stayed red. (One iteration's progress is normal; two identical = stuck.)
- **Repeat-file-same-edit no-progress.** Two consecutive iterations touched the same file with the same edit type and the verifier verdict didn't change.
- **Persistent insufficient-signal.** The diagnoser returned *"insufficient signal"* twice in a row after `ai-runtime-debugging` was already used — the instrumentation isn't surfacing what we need; further iteration won't help.
- **Hard failsafe — iteration count reaches N = 10 on the same AC.** This is a runaway-loop backstop, not a normal exit. Should rarely fire; if it does, treat as stuck.

### When stuck — escalate, don't silently fail

Surface to the user:
- Which AC(s) are stuck.
- Concise list of what was tried (the attempt log, summarized to one line per iteration).
- The diagnoser's latest hypothesis.
- A specific ask: *"I've tried X, Y, Z — none worked. Should I (a) try a different angle, (b) accept this AC as failed and proceed to commit gate with caveats, or (c) hand over for you to take it manually?"*

Wait for the user's answer before doing anything else. Do not mark the AC `failed-after-retries` unilaterally — the user decides.

**Demo preset:** nobody is asked. Take the first choice that still fits — "try another way" (a genuinely different angle, not the same fix again), then "show it as it is and mention the limitation", then "leave it out of the demo" — log the choice in `decisions.md`, and list it in the report the wizard shows at its final review.

### How this differs from a bounded retry count

- The **default mode is persistence**, not give-up-at-N.
- The loop has **specific exit signals** (each is a real signal that further iteration won't help), not a counter.
- The user **only sees a prompt when there's actually nothing more to try**, not at an arbitrary retry boundary.
- The **attempt log carries across iterations**, so the diagnoser has memory of prior failures within the same workflow run.

### Visual outcomes require explicit user sign-off — never self-assess

Under the demo preset (autonomous or collaborative), the sign-off is collected, not asked: save the screenshots for the demo wizard's final review and move on.

The model cannot reliably judge whether a UI element looks good. Visual quality is subjective and depends on the project's design system, the user's taste, and the surrounding context. The verifier's role on visual ACs is limited to **objective** checks (element renders, uses an atom class from `Theme/default/components/`, no layout breakage); subjective design judgment is the user's call, not the model's.

Outside the demo preset: after **any** UI-touching AC reaches verified-green on its objective checks, present the screenshot and ask the user explicitly before treating that AC as done:

> *"The [element] renders with the [atom] styling. Screenshot at [path]. Does the visual look right, or want changes (colour, weight, position, size)?"*

Do not skip this question on the basis that the feature *"looks fine"* — that judgment is not the model's to make. Iterate only when the user explicitly redirects; never on the model's own visual assessment. Stop when the user signs off.

### Do not invoke `static-validation` inside this loop

The static-validation skill's trigger ("after any PHP code changes") matches each retry iteration's edit. **Do not run it there.** Static-validation is run only at Step 7b, against the final stable diff. Running it inside the self-correct loop:
- Lets `phpcbf` reformat interim code that the verifier hasn't yet checked
- Adds new lint findings to a loop that's already iterating
- Spends time on code that is still changing
