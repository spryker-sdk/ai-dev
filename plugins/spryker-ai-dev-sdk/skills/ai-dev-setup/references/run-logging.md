# Run logging — the full contract

The detail behind `SKILL.md` → Run logging.

Every run keeps a plain-text trail so a completed onboarding can be audited afterwards: what the mode
decision was, which steps ran, what each one changed, and what was skipped because it was already in
place. This does **not** change any step's behavior — it records what the steps already do.

**Where.** One per-run folder, anchored to the project root Claude Code loaded (`$CLAUDE_PROJECT_DIR`,
with a `$(pwd)` fallback) so it is stable regardless of the current working directory:

```
${CLAUDE_PROJECT_DIR:-$(pwd)}/.ai-dev/ai-dev-setup/<run-id>/
```

`<run-id>` is the UTC start timestamp (`YYYYMMDD-HHMMSS`). Keep **all** run files inside `$SETUP_DIR` —
never scatter them elsewhere.

**When.** Create the folder and the log as the **first action after the mode decision is made** (that
decision is the first thing worth recording), before Requirements / Preflight in onboarding flow or
before Step 5 in update flow.

```bash
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(pwd)}"
SETUP_DIR="$PROJECT_DIR/.ai-dev/ai-dev-setup/$(date -u '+%Y%m%d-%H%M%S')"
mkdir -p "$SETUP_DIR"
SETUP_LOG="$SETUP_DIR/run.log"
printf '[%s] MODE — flow=%s (signalA=%s signalB=%s) | START\n' \
  "$(date '+%Y-%m-%d %H:%M:%S')" "$FLOW" "$SIGNAL_A" "$SIGNAL_B" >> "$SETUP_LOG"
```

**What.** Append one line per step boundary, plus a line for every outcome that a later reader would
need. Use `| START` and `| END <one-line outcome>`, and log skips as explicitly as actions — an
idempotent skip is recorded as a result:

```bash
printf '[%s] STEP 1 — install package | START\n' "$(date '+%Y-%m-%d %H:%M:%S')" >> "$SETUP_LOG"
printf '[%s] STEP 1 — install package | END already installed: spryker-sdk/ai-dev 0.5.0 (skipped)\n' \
  "$(date '+%Y-%m-%d %H:%M:%S')" >> "$SETUP_LOG"
```

Log, at minimum: the mode decision and the two signals behind it; each Requirements / Preflight check
and its result; each step's START/END with what changed or why it was skipped; the actual
`ConsoleDependencyProvider` path edited; the MCP server name registered; each Step 5 artifact decision
(`added` / `overwritten` / `merged` / `skipped` / `failed`) with the user's answer; and every hard stop
(missing Composer, `docker/sdk` down, a failed copy) with the verbatim error.

**How.** Two rules carry over from the rest of this skill:

- **Bulk output goes to a file, not the log line.** When a command produces more than a couple of lines
  (`composer require` output, `claude mcp list`, a failed copy's stderr), redirect it to
  `$SETUP_DIR/<step>.log` and keep the `run.log` line to the one-line outcome plus that file's name.
- **Never log a step green that wasn't.** A skipped, blocked, or partially-completed step is recorded
  as exactly that. The log is evidence, so it records only outcomes that actually happened.

The Final report's last line is the absolute path to `$SETUP_DIR`.
