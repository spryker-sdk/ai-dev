---
name: code-review
description: Use when code review is requested
---

# Workflow

1. Identify the code to review from the context of parameters
2. If is not provided by the context, ask for it, suggest reviewing changes against the master`git diff $(git merge-base HEAD master)`
3. Split the code for review among 3-5 spryker-code-reviewer subagents by directories and delegate them code review in parallel
4. Wait for the review to be completed by subagents
5. Combine the code review results and provide it to the User
6. Create an interactive selection interface in the terminal for User to choose which exact issues to fix from the code review (do not group them in selection).
7. Suggest planning to fix code review issues

# Output Format

When reporting issues, always use the clickable file path format:
- Format: `<path_from_git_root>:<line_number>`
- Example: `src/Spryker/Session/src/Spryker/Yves/Session/SessionConfig.php:10`


This format is recognized by most IDEs and terminals, allowing users to click and navigate directly to the issue location.
