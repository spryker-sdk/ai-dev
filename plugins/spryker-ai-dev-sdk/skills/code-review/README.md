# code-review

**Parallel code review by fan-out.** Splits the code under review across 3–5
`spryker-code-reviewer` subagents by directory, runs them at once, then combines their findings into
one list you pick from.

The skill itself is deliberately thin — it is the dispatcher. The actual Spryker review criteria
live in the `spryker-code-reviewer` subagent it delegates to.

## When it triggers

Whenever a code review is requested.

## Flow schema

```mermaid
flowchart TD
    A([Code review requested]) --> S1[1 · Identify the code to review<br/>from the context or parameters]
    S1 --> Q{Provided by<br/>the context?}
    Q -- "no" --> ASK["2 · Ask for it — suggest reviewing<br/>changes against master:<br/>git diff $(git merge-base HEAD master)"]
    ASK --> S3
    Q -- "yes" --> S3

    S3[3 · Split by directories across<br/>3–5 spryker-code-reviewer subagents<br/>delegate in PARALLEL]
    S3 --> S4[4 · Wait for every subagent<br/>to finish]
    S4 --> S5[5 · Combine the results<br/>and present them to the user]
    S5 --> S6["6 · Interactive terminal selection —<br/>the user picks which exact issues<br/>to fix, ungrouped"]
    S6 --> S7[7 · Suggest planning<br/>the fixes]
    S7 --> Z(["Combined review<br/>issues as path:line —<br/>clickable in IDEs and terminals"])

    classDef step fill:#1f6feb,stroke:#0b3d91,color:#fff;
    classDef decision fill:#f0ad4e,stroke:#8a6d3b,color:#000;
    classDef terminal fill:#2ea043,stroke:#176f2c,color:#fff;
    class S1,ASK,S3,S4,S5,S6,S7 step;
    class Q decision;
    class A,Z terminal;
```

## Output

Issues are always reported as `<path_from_git_root>:<line_number>` — e.g.
`src/Spryker/Session/src/Spryker/Yves/Session/SessionConfig.php:10` — the format most IDEs and
terminals turn into a clickable jump to the exact line.

The final selection step lists issues **individually, not grouped**, so the user chooses precisely
what gets fixed before any planning starts.

## Packaging note

This skill ships in the `spryker-ai-dev-sdk` plugin under `vendor/spryker-sdk/ai-dev/…`, which is
Composer-managed — `composer update spryker-sdk/ai-dev` may overwrite it. The durable home for edits
is the plugin's own repository (`github.com/spryker-sdk/ai-dev`).
