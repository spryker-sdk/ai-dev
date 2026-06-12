# Test Case Template

Each test case in a QA coverage plan follows this structure. Use it verbatim when emitting a plan to `spryker-verifier`.

```
ID:           <bucket-letter><index>     # e.g. H1, N2, A1, C3
Bucket:       Happy | Negative | Authorization | Corner
AC ref:       AC <number>

Mode:         DB | Console | API | Chrome | Mailpit | Queue-UI | Redis-UI

Preconditions:
  - Test data required:        <entities + attributes>
  - Actor / role:              <which seeded user, which role>
  - System state:              <e.g. P&S worker stopped, specific config flag set>

Action:
  1. <step>
  2. <step>
  …

Assertion:
  - <observable outcome 1>
  - <observable outcome 2>
  - <no-state-change assertion, if applicable>

Evidence to capture (mode-specific):
  - DB:        <table>, query, expected row count / column value
  - Console:   <command>, expected exit code, expected stdout fragment
  - API:       <HTTP method + path>, expected status code, expected response field(s)
  - Chrome:    <page URL>, <element selector>, expected text / state
  - Mailpit:   expected recipient, subject substring, body substring
  - Queue-UI:  queue name, expected message count and/or message contents
  - Redis-UI:  key pattern, expected value substring
```

## Notes

- One case = one verifier invocation. Keep cases small and independent.
- The verifier returns green/red per case + evidence. The orchestrator aggregates.
- A red case in the Authorization or Corner bucket carries the same weight as a red Happy case — surface them in the final report.
