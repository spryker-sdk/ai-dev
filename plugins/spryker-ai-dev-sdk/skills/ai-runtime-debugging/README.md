# ai-runtime-debugging

**Inspect Spryker runtime state as an AI agent** — `error_log()` and `var_dump()` are useless here,
because their output disappears into Docker containers you can't see. This skill gives three
techniques that an agent can actually read back: tagged logging, XDebug step-debugging through an
MCP bridge, and narrowing a Codeception run to one method.

The hard part in Spryker isn't emitting a log line — it's knowing **where it went**. The same
`getLogger()->info()` lands on disk when run under `docker/sdk cli` and on `php://stderr` (i.e.
`docker logs`) when the same code runs in an FPM container. Half of this skill is that routing map;
the other half is cleaning the instrumentation back out before it reaches a commit.

## When it triggers

"I can't tell why this code is doing X", "add some debug output", "step through this", "set a
breakpoint", "what does this variable contain at runtime", `error_log` not working, can't see
`var_dump` output — and "how do I run just this one test".

## Flow schema

```mermaid
flowchart TD
    A([Runtime question]) --> Q{What kind<br/>of question?}

    Q -- "what is this variable /<br/>does this code run /<br/>what's the flow" --> LOG["LOGGING · logging.md<br/>DEFAULT — no setup needed"]
    Q -- "pause and inspect<br/>locals interactively" --> BR{Debugger MCP<br/>available?<br/>ToolSearch first}
    Q -- "run just one test" --> TN["TEST NARROWING · test-narrowing.md<br/>-c &lt;dir&gt; &lt;Suite&gt; &lt;Class&gt;:&lt;method&gt;<br/>single colon, no .php"]

    BR -- "no" --> TELL["Tell the user: no debugger MCP<br/>installed — fall back to logging"]
    TELL --> LOG
    BR -- "yes" --> SD["STEP-DEBUG · step-debug.md<br/>human-side setup: listener on,<br/>path mappings, docker/sdk up -x"]

    LOG --> TAG["Add [AI-DEBUG]-tagged lines<br/>LoggerTrait -&gt;info() with structured context<br/>NOT -&gt;debug() — LOG_LEVEL is INFO"]
    TAG --> WHERE{Which container<br/>ran the code?}
    WHERE -- "docker/sdk cli" --> FILE["Read data/logs/&lt;APP&gt;/application.log"]
    WHERE -- "FPM: yves / glue /<br/>boffice / backgw" --> STDERR["docker logs &lt;container&gt; | grep '[AI-DEBUG]'<br/>discover real names with docker ps"]
    WHERE -- "bootstrap / static /<br/>queue delaying writes" --> FPC["file_put_contents fallback<br/>-&gt; data/tmp/ai-debug.log"]

    FILE --> SEEN
    STDERR --> SEEN
    FPC --> SEEN
    SEEN{Line visible?}
    SEEN -- "no" --> PIT["Check the pitfalls table:<br/>wrong destination · -&gt;debug filtered ·<br/>QueueHandler async · generated class"]
    PIT --> TAG
    SEEN -- "yes · still unclear" --> Q
    SEEN -- "yes · answered" --> CLEAN

    SD --> BP["set_breakpoint, trigger from a<br/>SEPARATE bash call, wait_for_pause"]
    BP --> INSPECT["get_stack_trace · get_variables ·<br/>evaluate_expression · step_into/over/out"]
    INSPECT --> HIT{Breakpoint hit?}
    HIT -- "no" --> BPFIX["listener off? no -x?<br/>path mapping? missing XDEBUG_SESSION cookie?<br/>Linux client_host?"]
    BPFIX --> BP
    HIT -- "yes · answered" --> CLEAN

    TN --> DEP{Test SKIPPED —<br/>'depends on … to pass'?}
    DEP -- "yes" --> OPT["Run the whole class, OR tag the<br/>target + its whole @depends chain<br/>with @group AITestCase"]
    OPT --> TN
    DEP -- "no" --> CLEAN

    CLEAN["UNIVERSAL CLEANUP — before declaring done<br/>grep [AI-DEBUG] · file_put_contents ai-debug ·<br/>LoggerTrait uses you added · @group AITestCase ·<br/>remove_breakpoint · restart without -x"]
    CLEAN --> Z([Answer reported,<br/>zero instrumentation left behind])

    classDef step fill:#1f6feb,stroke:#0b3d91,color:#fff;
    classDef decision fill:#f0ad4e,stroke:#8a6d3b,color:#000;
    classDef terminal fill:#2ea043,stroke:#176f2c,color:#fff;
    class LOG,SD,TN,TAG,FILE,STDERR,FPC,PIT,BP,INSPECT,BPFIX,OPT,TELL,CLEAN step;
    class Q,BR,WHERE,SEEN,HIT,DEP decision;
    class A,Z terminal;
```

## Files

| File | Role |
|------|------|
| [`SKILL.md`](SKILL.md) | The technique-selection table and decision tree, the **universal cleanup checklist**, and a "verify this skill in a fresh session" sanity check (does `LoggerTrait` still live where documented, is `LOG_FILE_PATH` still wired, does the FPM container still export `SPRYKER_LOG_STDERR`, does `docker/sdk --help` still document `-x`). |
| [`logging.md`](logging.md) | The default technique. The two log destinations (cli → file on disk vs. FPM → `php://stderr`), `LoggerTrait` usage and where it does/doesn't work, the `file_put_contents` fallback, copy-paste read-back commands, and a logging pitfalls table. |
| [`step-debug.md`](step-debug.md) | XDebug via a DBGp-MCP bridge — how to detect whether one is loaded, the four known bridge options, the human-side setup only the user can do, a worked breakpoint→inspect→cleanup example, how to trigger Xdebug from each entry point, and step-debug pitfalls. |
| [`test-narrowing.md`](test-narrowing.md) | Running one class or one method with `docker/sdk testing`, the exact positional syntax and why `--filter`/`--grep` don't work, the `@depends` skip gotcha, and the `@group AITestCase` workaround. |

## Techniques at a glance

| Technique | Use when | Effort |
|---|---|---|
| **Logging** | You need a trace of values across code paths, or just to confirm a path is reached | Low — edit one line, run, read a file |
| **XDebug step-debug** | You need to walk the call stack, inspect locals at a pause, evaluate ad-hoc expressions | Medium — needs a DBGp-MCP bridge installed |
| **Narrow test runs** | Debugging through tests; one method instead of the whole suite | Low |

**Default to logging.** Step-debug only when you genuinely need to pause execution. Test-narrowing
is independent — useful whenever you run tests, debugging or not.

## Design decisions baked in

- **Never `error_log()`.** It lands in PHP's SAPI log inside the container, with no easy way to read
  it back.
- **`->info()`, not `->debug()`.** `LOG_LEVEL` is `Logger::INFO` in this dev setup, so `->debug()`
  lines are filtered out and look like the code never ran.
- **Tag everything `[AI-DEBUG]`.** The tag is what makes the cleanup grep exhaustive.
- **Structured context as the second argument**, not concatenated into the message — Monolog renders
  it as scannable JSON.
- **Never instrument generated code.** Transfers and `src/Generated/` are rebuilt by
  `transfer:generate` / `propel:install`, so the log line silently disappears. Log from the caller.
- **Trigger the breakpoint from a separate Bash call**, not through MCP — the breakpoint blocks
  waiting for execution.
- **Leftover instrumentation is a blocker**, not a nit: it must not reach a commit, a review, or
  production — and Xdebug must be turned back off, since it adds 2–5x latency and breaks workers.

## Packaging note

This skill ships in the `spryker-ai-dev-sdk` plugin under `vendor/spryker-sdk/ai-dev/…`, which is
Composer-managed — `composer update spryker-sdk/ai-dev` may overwrite it. The durable home for edits
is the plugin's own repository (`github.com/spryker-sdk/ai-dev`).
