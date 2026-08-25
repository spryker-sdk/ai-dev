---
name: code-review
description: Use when code review is requested
---

# Workflow

1. Identify the code to review from the context of parameters. If the caller passed an explicit **file list**, that list is the review target instead of the full diff — findings outside it are out of scope and reported as such.
2. If is not provided by the context, ask for it, suggest reviewing changes against the master`git diff $(git merge-base HEAD master)`
3. Determine the **reviewer count**, scaled to the change: a small diff — on the order of a handful of files — gets a **single reviewer**; the 3-5 fan-out is for large or complex diffs. Use the caller's explicit count if one was given; else derive it from a caller-supplied diff `size` — `trivial` (≤~10 changed lines, 1 file) → **1**, `normal` → **3-5**, `complex` → **5**. **With neither supplied, use 3-5** (the unchanged default). A count of 1 is a smaller fan-out, never a skipped review: the reviewer applies the full `spryker-code-reviewer` criteria and its findings gate exactly as 3-5 reviewers' would.
4. Split the code for review among that many spryker-code-reviewer subagents by directories and delegate them code review in parallel
5. Wait for the review to be completed by subagents
6. Combine the code review results and provide it to the User
7. Create an interactive selection interface in the terminal for User to choose which exact issues to fix from the code review (do not group them in selection).
8. Suggest planning to fix code review issues

# Output Format

When reporting issues, always use the clickable file path format:
- Format: `<path_from_git_root>:<line_number>`
- Example: `src/Spryker/Session/src/Spryker/Yves/Session/SessionConfig.php:10`


This format is recognized by most IDEs and terminals, allowing users to click and navigate directly to the issue location.
