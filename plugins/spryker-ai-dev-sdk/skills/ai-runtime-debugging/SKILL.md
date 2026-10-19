---
name: ai-runtime-debugging
description: >
  Use when an AI agent needs to inspect Spryker runtime state — adding
  temporary debug logs the agent can read back, or stepping through code
  with XDebug like a human developer. Covers LoggerTrait, file_put_contents
  fallback, log file locations for cli vs FPM containers, and driving an
  XDebug-capable MCP bridge (PHPStorm or standalone). Also covers narrowing
  test runs with codecept positional filters and @group AITestCase.
  Triggers - "I can't tell why this code is doing X", "add some debug
  output", "step through this", "set a breakpoint", "what does this
  variable contain at runtime", error_log not working, can't see var_dump
  output.
---

# AI Runtime Debugging in Spryker

All host paths in this skill are written **relative to the project root** (the directory containing `docker/sdk` and `composer.json`). Run shell snippets from that directory. Container paths (anything starting with `/data/`) are absolute inside the Docker container.

## Overview

`error_log()` and `var_dump()` are useless here — output disappears into Docker containers you can't see. Use one of three techniques, each with its own detail file in this folder:

| Technique | Use when | Effort | Detail file |
|---|---|---|---|
| **Logging** (LoggerTrait / `file_put_contents`) | You need a trace of values across many code paths, or to confirm a code path is reached at all | Low — edit one line, run, read file | [`logging.md`](logging.md) |
| **XDebug step-debug** | You need to walk the call stack interactively, inspect locals at a paused breakpoint, evaluate ad-hoc expressions | Medium — needs a DBGp-MCP bridge installed (PHPStorm plugin or standalone) | [`step-debug.md`](step-debug.md) |
| **Narrow test runs** | Debugging through tests; want one method instead of the whole suite | Low | [`test-narrowing.md`](test-narrowing.md) |

**Default to logging.** Use step-debug only when you genuinely need to pause execution. Test-narrowing is independent — useful whenever you run tests, even outside debugging.

## Decision tree

```
Is the question "what is this variable / does this code run / what's the
flow"?  →  logging.md
Is the question "I need to pause and inspect locals interactively"?
                                       →  step-debug.md (check MCP bridge first)
Is the question "how do I run just this one test"?
                                       →  test-narrowing.md
```

When in doubt, start with logging — it works without setup and answers most questions.

## Universal cleanup checklist (run before declaring work done)

Whatever technique you used, sweep before the next stage of your workflow:

```bash
# 1. Tagged log lines you added (logging.md)
grep -rn '\[AI-DEBUG\]' src/ tests/

# 2. file_put_contents fallback writes (logging.md)
git diff -- src/ | grep -E '^\+.*file_put_contents.*ai-debug'

# 3. `use LoggerTrait;` lines you added (only ones YOU added — others were there already)
git diff --name-only | xargs grep -l 'use Spryker\\Shared\\Log\\LoggerTrait' 2>/dev/null

# 4. @group AITestCase tags (test-narrowing.md)
grep -rn '@group AITestCase' src/ tests/

# 5. Breakpoints set via MCP bridge (step-debug.md) — call <list_breakpoints> + <remove_breakpoint>
# 6. Stop Spryker with -x and restart without it (step-debug.md) — Xdebug is slow/breaks workers
```

Any match → remove that change (or `git checkout --` the file if every change in it was instrumentation). Treat leftover instrumentation as a blocker: it must not reach a commit, a code review, or production.

## How to verify this skill in a fresh session

Sanity-check before trusting the detail files:

```bash
# 1. LoggerTrait still exists in the documented location?
test -f vendor/spryker/log/src/Spryker/Shared/Log/LoggerTrait.php && echo OK

# 2. File-path constants still wired the same way?
grep -n 'LOG_FILE_PATH' config/Shared/common/config_logs-files.php

# 3. FPM container still routes to stderr? (substitute a real container name from `docker ps`)
docker exec <yves-container-name> sh -c 'env | grep SPRYKER_LOG'
# Expect: SPRYKER_LOG_STDERR=php://stderr

# 4. docker/sdk -x flag still documented?
docker/sdk --help 2>&1 | grep -i xdebug
```

If any of these fails, the skill is stale — update the relevant detail file before continuing.

## Sources (verified against this codebase at 2026-05-11)

- Trait: `vendor/spryker/log/src/Spryker/Shared/Log/LoggerTrait.php`
- Factory + channel loaders: `vendor/spryker/log/src/Spryker/Shared/Log/LoggerFactory.php`, `LoggerConfig/LoggerConfigLoader{Default,Glue,Yves,Zed,ZedCli}.php`
- Constants: `vendor/spryker/log/src/Spryker/Shared/Log/LogConstants.php`
- File paths: `config/Shared/common/config_logs-files.php`
- Log level & channel plugins: `config/Shared/config_default.php` (search `LOGGER_CONFIG_`), `config/Shared/config_default-docker.dev.php` (line ~113)
