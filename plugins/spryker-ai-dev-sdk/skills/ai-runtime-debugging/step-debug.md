# Step-debugging via XDebug (Part 2)

This file is the detail reference for the **step-debug** technique. Load this only when logging (see `logging.md`) isn't enough — when you genuinely need to pause execution and walk the call stack.

**Requires a DBGp-MCP bridge to be installed in this session.** If none is available, fall back to `logging.md` — it handles the vast majority of real cases without setup.

## Detect what's available first

Check whether any debugger MCP tools are loaded in this session **before** assuming you can step-debug:

```
ToolSearch(query="debugger xdebug breakpoint", max_results=10)
ToolSearch(query="phpstorm dbgp", max_results=10)
```

If neither query surfaces tools, **stop and tell the user**: *"No debugger MCP is installed in this session. Step-debug needs one of the options below; otherwise we'll use logging instead."*

## Known debugger-MCP options

| Option | Tool prefix | Editor | Install |
|---|---|---|---|
| **PHPStorm Debugger MCP Server plugin** | `mcp__phpstorm-debugger__*` | PHPStorm only | JetBrains Marketplace id `29233` |
| **PhpStorm bundled MCP** (2026.1+) | `mcp__phpstorm-debugger__*` (varies) | PHPStorm only | Enable in `Settings → Tools → MCP Server`; no plugin install |
| **koriym/xdebug-mcp** | `mcp__xdebug-*` (varies) | Editor-agnostic (VS Code, PHPStorm, terminal) | <https://github.com/koriym/xdebug-mcp> — bridges DBGp directly to Xdebug, no editor required |
| **kpanuragh/xdebug-mcp** | `mcp__xdebug-*` (varies) | Editor-agnostic | <https://github.com/kpanuragh/xdebug-mcp> — similar standalone DBGp bridge |

VS Code's native `PHP Debug` extension (`felixfbecker.php-debug`) speaks DBGp directly to Xdebug but **is not an MCP server**, so Claude can't drive it. VS Code users should install one of the standalone bridges above.

## One-time human-side setup (ask the user — Claude can't do these)

1. **Editor open** with this project loaded (PHPStorm or VS Code, depending on which bridge you picked).
2. **Debugger MCP bridge installed** — see the table above.
3. **Path mappings** configured in your editor's debugger settings (map host project root ↔ container `/data`).
   - PHPStorm: `Settings → PHP → Servers` → host `localhost`, port `80`, debugger Xdebug, enable *"Use path mappings"*.
   - VS Code with a standalone bridge: typically configured in the bridge's own config file (consult its README).
4. **Debug listener active** (PHPStorm: toolbar phone icon "Start Listening for PHP Debug Connections"; standalone bridges: start the MCP process per its README).
5. **Spryker started with XDebug enabled**:
   ```bash
   docker/sdk boot && docker/sdk up -x         # cold boot with -x
   # or, on a running stack:
   docker/sdk start -x
   ```

Confirm setup is live before working (tool name depends on which bridge you have — likely `list_debug_sessions` or similar; consult `ToolSearch` results from above).

## Worked example — investigating why `FooFacade::updateFoo()` is failing

Tool names below are shown as generic verbs (`<set_breakpoint>`, `<wait_for_pause>`, etc.); replace with your bridge's actual tool names — the verbs are usually the same across bridges.

```
1. ToolSearch(query="select:<your bridge's tool names>", max_results=10)

2. <set_breakpoint>
       file_path: src/Pyz/Zed/Foo/Business/FooFacade.php   # project namespace example
       # — or a vendor path for stepping into core, e.g.:
       # file_path: vendor/spryker/foo/src/Spryker/Zed/Foo/Business/FooFacade.php
       line: <first executable line of updateFoo()>
       # Either a project-relative path or your absolute host path works.

3. Trigger the code from a separate Bash call (NOT through MCP — the breakpoint waits for execution):
       # HTTP endpoint (cookie triggers Xdebug):
       curl -k -b 'XDEBUG_SESSION=PHPSTORM' https://glue.eu.spryker.local/<resource>
       # Console command (the -x flag tells docker/sdk to inject the Xdebug trigger):
       docker/sdk cli -x console <command>
       # PHPUnit / Codeception:
       docker/sdk testing -x codecept run -c <suite> <test>

4. <wait_for_pause>      # blocks until breakpoint hits

5. <get_stack_trace>     # see who called updateFoo()

6. <get_variables>       # inspect locals: $fooTransfer, $this, etc.

7. <evaluate_expression>
       expression: $fooTransfer->getIdMerchant()

8. <step_into> | <step_over> | <step_out>  # walk the call

9. <resume_execution>   # continue or hit next breakpoint

10. <remove_breakpoint>  # clean up before finishing
```

## Triggering Xdebug from each entry point

| Entry point | How to trigger Xdebug |
|---|---|
| HTTP request (Yves / Glue / Backoffice) | Send cookie `XDEBUG_SESSION=<anything>` with the request (e.g. `curl -b 'XDEBUG_SESSION=PHPSTORM' ...`) — Spryker uses `xdebug.start_with_request=trigger` |
| Console command | `docker/sdk cli -x console <command>` (the `-x` injects the trigger env var) |
| Codeception / PHPUnit test | `docker/sdk testing -x codecept run ...` |
| Queue worker | `docker/sdk cli -x console queue:task:start --once` |

For narrowing test runs (positional filter, `-g`, `@depends` gotcha), see `test-narrowing.md`.

## Pitfalls (step-debug-specific)

| Symptom | Cause | Fix |
|---|---|---|
| Breakpoint never hits | (a) editor listener off, (b) no `-x` on docker/sdk, (c) path mapping wrong, (d) request missing `XDEBUG_SESSION` cookie, (e) Linux: `client_host` can't resolve `host.docker.internal` | Verify with `list_debug_sessions` (or your bridge's equivalent). On Linux set `XDEBUG_CONFIG="client_host=172.17.0.1"` |
| Xdebug step-debugging makes everything slow | Step-debug adds 2-5x latency, breaks workers | Only run `docker/sdk up -x` while actively debugging; restart without `-x` after |

## References

- Spryker debugging docs: <https://docs.spryker.com/docs/dg/dev/set-up-spryker-locally/configure-after-installing/configure-debugging/configure-debugging.html>
- Xdebug troubleshooting: <https://docs.spryker.com/docs/dg/dev/troubleshooting/troubleshooting-docker-issues/troubleshooting-debugging-in-docker/xdebug-does-not-work.html>
- PhpStorm MCP Server docs: <https://www.jetbrains.com/help/phpstorm/mcp-server.html>
- Debugger MCP Server plugin: <https://plugins.jetbrains.com/plugin/29233-debugger-mcp-server>
- Standalone DBGp-MCP alternatives: <https://github.com/koriym/xdebug-mcp>, <https://github.com/kpanuragh/xdebug-mcp>
