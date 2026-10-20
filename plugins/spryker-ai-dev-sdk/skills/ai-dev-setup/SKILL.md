---
name: ai-dev-setup
description: "Sets up the Spryker AI Dev SDK in a Spryker project. Covers three related flows: first-time onboarding (installs the Composer package, wires console commands, registers the MCP server with the coding agent in use, installs the project instructions file and the Spryker coding rules), updating (refreshes just those two artifacts from the latest bundled content, skipping install and wiring), and rules-only (installs or refreshes the coding rules alone, for a project without Composer or a running container stack). The skill first detects which coding agent is running it, then infers which flow the user wants from their request and from project state. Onboarding requires Composer and a running `docker/sdk` environment; updating only needs the bundled content on disk; rules-only needs neither."
disable-model-invocation: true
user-invocable: true
---

# Spryker AI Dev Setup

You set up the Spryker **AI Dev SDK** (`spryker-sdk/ai-dev`) in a Spryker project. The skill covers three related flows — first-time onboarding, updating bundled artifacts, and installing the coding rules alone — and infers which one the user wants. When fully onboarded, the project has:

1. The `spryker-sdk/ai-dev` package installed as a dev dependency.
2. Two console commands wired in `ConsoleDependencyProvider` (`McpServerConsole`, `AiToolSetupConsole`).
3. The AI Dev MCP server registered with the coding agent in use, so other sessions in this project can use Spryker-aware tools (transfers, OMS, module map, CSV/ODS, etc.).
4. (Optional) The project instructions file at the root and the Spryker coding rules in the harness's rules folder — see the artifact table in Step 5 for the per-harness names.

Everything downstream branches on **one** value: which coding agent is running this skill. Step 0 decides it; call it the **harness**.

You do **not** generate prompts. The `ai-dev:generate-prompts` command exists in the package but is outdated — skip it.

---

## Run logging — what, when, how, where

Every run keeps a plain-text trail — the harness decision, the mode decision, which steps ran, what each changed, and what was skipped because it was already in place — in one per-run folder, `$SETUP_DIR = $(pwd)/.ai-dev/ai-dev-setup/<UTC timestamp>/`, with `run.log` inside it. **Run this skill from the project root** so that path resolves consistently regardless of where the harness started. It records what the steps do and changes none of them. The folder layout, line format and per-step log files: **[references/run-logging.md](references/run-logging.md)** — read it before creating `$SETUP_DIR`.

---

## Step 0 — Detect the harness

**Do this before anything else**, including the mode decision: the mode signals, the MCP registration
in Step 3, and the artifact names in Step 5 all depend on it. The check is cheap, read-only, and
ordered — first match wins.

```bash
if [ -n "${CLAUDECODE:-}" ] || [ -n "${CLAUDE_PROJECT_DIR:-}" ]; then
    HARNESS="claude";  HARNESS_SIGNAL="CLAUDECODE/CLAUDE_PROJECT_DIR"
elif [ -n "${COPILOT_HOME:-}" ] || [ -d "$HOME/.copilot" ]; then
    HARNESS="copilot"; HARNESS_SIGNAL="COPILOT_HOME/~.copilot"
elif [ "${TERM_PROGRAM:-}" = "vscode" ]; then
    HARNESS="vscode";  HARNESS_SIGNAL="TERM_PROGRAM=vscode"
else
    HARNESS="";        HARNESS_SIGNAL="none"
fi
printf 'harness=%s (%s)\n' "${HARNESS:-unknown}" "$HARNESS_SIGNAL"
```

Read the result in this order, and stop at the first signal that matches:

1. **`CLAUDECODE` or `CLAUDE_PROJECT_DIR` set** → Claude Code (`claude`).
2. **`COPILOT_HOME` set, or `~/.copilot` exists** → GitHub Copilot CLI (`copilot`).
3. **`TERM_PROGRAM=vscode`** → VS Code (`vscode`). Treat it as Copilot for artifact purposes unless
   signal 1 already matched — the ordering above guarantees that.
4. **Nothing matched** → ask the user a structured multiple-choice question (use your harness's
   question tool if it has one, otherwise ask in chat and wait for the answer): *"Which coding agent
   are you running this in?"* with the options **Claude Code**, **GitHub Copilot CLI**,
   **VS Code Copilot**, **other**. On *other*, ask which one and which artifact layout it expects;
   if you cannot map it onto one of the three, stop and say so rather than guessing.

**Detection is best-effort, so always confirm before acting on it.** State the result in exactly one
line — *"Detected harness: GitHub Copilot CLI (COPILOT_HOME set). Correct?"* — and let the user
correct you. A wrong harness writes the right content to the wrong paths, which is worse than asking.

Record `HARNESS` and `HARNESS_SIGNAL`, plus whether the user confirmed or corrected the guess, and
write them into `run.log` as the `HARNESS` line as soon as `$SETUP_DIR` exists (see "Run logging").

Use the value everywhere below. `vscode` uses Copilot's artifacts (Step 5) but its own MCP config file
(Step 3) — where the two differ, this file says so.

---

## Mode selection — infer the flow before running anything

This skill operates in one of three flows. **Decide which one is appropriate before doing any work.** The decision uses two signals: (a) what the user said when invoking the skill, and (b) the current state of the project. Do not ask the user "which mode?" as a first question — pick the mode yourself and only confirm if genuinely ambiguous.

### Signal A — read the user's invocation

When the user invokes this skill, scan the message they sent (the prompt that triggered the skill, plus any words on the invocation line) for intent:

- Words like *"update"*, *"refresh"*, *"sync"*, *"pull latest"*, *"get newer"*, *"upgrade rules"*, *"refresh the instructions file"* → user wants **update**.
- Words like *"install"*, *"set up"*, *"onboard"*, *"add"*, *"configure"*, *"first time"*, *"new project"* → user wants **onboarding**.
- Words like *"just the rules"*, *"only the rules"*, *"coding rules"*, *"no setup"*, *"I don't have docker"* → user wants **rules only**.
- Bare invocation with no context (just `/spryker-ai-dev-sdk:ai-dev-setup` and nothing else) → ambiguous, use Signal B.

### Signal B — read the project state

Run cheap read-only checks. The artifact paths depend on the harness from Step 0 — see the table in
Step 5:

```bash
composer show spryker-sdk/ai-dev 2>/dev/null | head -1     # is the package installed?

# Project instructions file (pick the row for your harness)
test -f "$INSTRUCTIONS_FILE" && echo "instructions file present"            # per the Step 5 table
test -f .github/copilot-instructions.md && echo "instructions file present"  # copilot / vscode

# Rules folder — the sync hook records what it installed
test -f .ai-dev/rules.manifest && echo "rules manifest present"
test -d "$RULES_DIR" && ls "$RULES_DIR" 2>/dev/null | wc -l                 # per the Step 5 table
test -d .github/instructions && ls .github/instructions/ 2>/dev/null | wc -l               # copilot / vscode

# MCP server — user-level registration, per harness
claude mcp list 2>/dev/null | grep "^$(basename "$(pwd)"):"     # claude
copilot mcp list 2>/dev/null | grep "^$(basename "$(pwd)"):"    # copilot

# MCP server — project-scoped registration, read by both CLIs
test -f .mcp.json && grep -q 'ai-dev:mcp-server' .mcp.json && echo ".mcp.json present"
```

> The MCP grep uses `^<name>:` (anchored start, colon delimiter) — not `-i "<name>"` — because both CLIs format entries as `<name>: <command> - <status>` and a substring match can false-positive on another entry whose name contains the project's basename (e.g. project `shop` would match an entry named `b2b-shop`).

- **Package installed + MCP server registered + instructions file present + rules folder populated** → almost certainly an **update** request. The user has nothing left to onboard.
- **None of the above present** → onboarding.
- **Mixed state** (e.g. package installed but no instructions file) → onboarding, but the per-step idempotency pre-checks below will skip what's already in place.
- **No `composer` on `PATH`, or no `docker/sdk` in the project** → onboarding is impossible; this is a **rules-only** candidate. Say so and offer it.

### Decide and proceed

Combine Signals A and B:

- **A says update, B confirms (fully set up)** → run **update flow**.
- **A says update, B says nothing is installed** → tell the user "this project does not appear to be onboarded — `spryker-sdk/ai-dev` is not installed. Want me to run the full onboarding flow instead?" Wait for confirmation. Do not silently fall through.
- **A says onboarding, B says fully set up** → tell the user "this project already has the SDK installed and configured. Did you mean to refresh the instructions file and the coding rules instead?" Wait. Do not silently re-run onboarding.
- **A says rules only** → run **rules-only flow**, whatever B says.
- **A says onboarding or update, but B shows no Composer or no `docker/sdk`** → tell the user what is missing and offer **rules-only flow** as the thing that *can* run here. Wait for their answer; do not run a flow whose requirements cannot be met.
- **A is ambiguous or absent**, B fully set up → assume **update flow**, but state your assumption in one line and let the user redirect.
- **A is ambiguous or absent**, B nothing installed → assume **onboarding**, state your assumption in one line.
- **A and B disagree any other way** → state both signals and ask the user which flow they want.

### Update flow

Create `$SETUP_DIR` + `run.log` (see "Run logging" above) and record the harness and mode decisions before jumping to Step 5.

When in update flow, **skip Requirements / Preflight / Step 1 / Step 2 / Step 3 / Step 4 entirely**. Jump straight to Step 5, but apply these overrides:

- The user is **refreshing**, not installing for the first time. Frame the prompts that way. Suggested phrasing for the instructions file: *"Refresh the project instructions file from the bundled content in `vendor/spryker-sdk/ai-dev/data/`? Choose overwrite (replace), merge (append missing sections), or skip."* For the rules: *"Refresh the rules folder from the bundled rule set? Choose overwrite (replace same-named files), merge (only add missing files), or skip."*
- The recommended default in update flow is **overwrite** (the user explicitly asked to refresh). Still ask for confirmation — never overwrite silently. But say "Default: overwrite — confirm with y, or pick merge / skip" rather than the conservative onboarding default of "keep yours".
- If the user wants the *latest from `master`* rather than what Composer has installed, instruct them to run `composer update spryker-sdk/ai-dev` first; the bundled content under `vendor/spryker-sdk/ai-dev/data/` will then reflect the new version. The skill itself only copies what's on disk — it does not fetch from GitHub.
- If the project instructions file does **not** exist when update flow runs, tell the user this looks like a first-time install and ask whether to switch to onboarding. Do not silently install.
- If the rules folder does not exist or is empty when update flow runs, same handling.
- **Artifact 3 is refreshed in update flow too, when the project's own hooks folder for this SDK exists** (a setup install; a plugin install gets the guard from the plugin). Re-copy `hooks/guard-stop.php` and `hooks/lib.php`, and **diff the stop-guard entry of `hooks/settings.example.json` against the project's harness settings file** — report a matcher or command that is missing or different in the project, and offer to add it. Any other file in that folder, and any settings hook entry pointing at one, is outside this artifact: list them for the person to remove.
- The Final report in update flow only mentions which artifacts were refreshed (or skipped) and the bundled version copied from (e.g. `from vendor/spryker-sdk/ai-dev 0.5.0`). Skip the "what was installed (package + version)" and "MCP server registered" lines — those steps did not run.

After Step 5 completes in update flow, **stop**. Do not run anything else in this file.

### Rules-only flow

For a project that wants the Spryker coding rules without a working Spryker environment — a client who installed the plugin but has no Composer, no container stack, or simply does not want the SDK wired in. It is the right flow when Composer or `docker/sdk` are missing, or when the user asked for the rules alone.

Create `$SETUP_DIR` + `run.log` and record the harness and mode decisions, then run **only** the rules sync:

```bash
<plugin-root>/scripts/sync-rules.sh --target "$(pwd)" --harness <claude|copilot> --force
```

`<plugin-root>` is the plugin directory holding this skill — the parent of the `skills/` folder in the path reported as `Base directory for this skill:` when this skill loaded. Substitute the literal absolute path. Pass `--harness claude` for Claude Code and `--harness copilot` for Copilot CLI and VS Code. The script prints a one-line summary of what it added, refreshed, and left alone; put that line in `run.log` and in the report.

Then **stop**. No Composer, no Docker, no MCP registration, no console wiring, no Steps 1–5. Report in two or three lines: the harness, the rules folder written, the script's summary line, and the `run.log` path. Mention — once, without pushing — that full onboarding is available later if the user gets a Spryker environment running.

### Onboarding flow

Create `$SETUP_DIR` + `run.log` (see "Run logging" above) and record the harness and mode decisions before running Requirements.

Run all the sections below in order: Requirements → Preflight → Step 1 → Step 2 → Step 3 → Step 4 → Step 5. The per-step idempotency pre-checks (defined in the Preflight "Idempotency principle" paragraph) automatically skip work that is already in place, so this is also safe to re-run on a partially-onboarded project — the user does not need to manually pick "onboarding" vs. "update" to handle the partial case.

---

## Requirements (hard preconditions)

This onboarder **requires both Composer and a running `docker/sdk` environment**. Neither is optional:

- **Composer** is needed to install the `spryker-sdk/ai-dev` package (Step 1) and to inspect what is already installed.
- **A running `docker/sdk` environment** is needed to generate transfers, list console commands for verification (Step 4), and serve as the MCP server transport (Step 3 registers `docker/sdk console ai-dev:mcp-server` as the command the harness will invoke). If the container stack is not up, the MCP server registration will succeed on disk but the server will fail to start on every session.

Before doing anything else, verify both are available and functional. If either is missing or not running, stop the onboarder, tell the user clearly what is missing and ask them to remedy it before you continue — and offer the **rules-only flow**, which needs neither. **Do not start the containers or install Composer for the user** — these are state-changing on their machine and outside the scope of "onboard the SDK".

### Check Composer

```bash
command -v composer >/dev/null 2>&1 && composer --version
```

- If `composer` is not on `PATH`, ask the user: *"Composer is required for onboarding but is not installed (or not on PATH). Please install Composer (https://getcomposer.org/) or activate it in your shell, then re-run me — or I can install just the Spryker coding rules now, which needs neither Composer nor Docker. Which do you want?"* — stop or switch to rules-only flow on their answer.
- If `composer --version` runs but reports a version older than Composer 2.x, mention it and ask whether to continue (most Spryker tooling assumes Composer 2). Do not auto-upgrade Composer.
- If `composer` is the project-internal wrapper invoked via `docker/sdk cli composer`, that is fine — just note it in the one-line preflight report and prefer the `docker/sdk cli composer` form in Step 1.

### Check `docker/sdk` is run and reachable

A `docker/sdk` *file* on disk is not enough — the environment behind it must be up.

```bash
test -x docker/sdk && docker/sdk help >/dev/null 2>&1 && echo "docker/sdk OK"
```

If the file exists but the command fails (typical signals: "containers are not running", "Cannot connect to the Docker daemon", a non-zero exit with no help text, or a long timeout), the environment is not up. Tell the user:

> "`docker/sdk` is present but does not appear to be running. This onboarder needs the container stack up to generate transfers, list consoles, and run the MCP server. Please run `docker/sdk up` (and any environment-specific bootstrap) in another terminal, then tell me to continue."

Then stop and wait. Do **not** run `docker/sdk up` yourself — it starts long-running local services and is the user's call.

If `docker/sdk` does not exist at all in the project root, ask the user where their Spryker development environment lives. The onboarder can in principle adapt to a non-`docker/sdk` workflow, but only with explicit guidance — stop and ask rather than guessing. Offer the rules-only flow as the alternative that works regardless.

Only proceed past this section once both Composer and `docker/sdk` checks pass (or the user has explicitly waived a check with full awareness of the consequences).


## Preflight — confirm we're in a Spryker project and capture current state

Run these checks before changing anything. If any fail, stop and tell the user what's missing.

1. The current working directory must contain `composer.json` with a `spryker/*` dependency.
2. At least one `ConsoleDependencyProvider` must exist under `src/`. Find candidates with `find src -maxdepth 6 -path "*/Zed/Console/ConsoleDependencyProvider.php" -not -path "*/vendor/*"`. Typical: just `src/Pyz/...`; some projects also have a higher-precedence override (e.g. `src/Acme/Zed/Console/ConsoleDependencyProvider.php`). Step 2 handles the override case explicitly. If none is found at all, the project layout is non-standard — ask the user where console commands are wired before continuing.

(The harness decision lives in **Step 0**, and the Composer and `docker/sdk` checks in the **Requirements** section above; both must already have happened before reaching this point.)

Use the built-in file read/search tools and the shell for these checks. Report what you found in one short sentence before proceeding.

**Idempotency principle (applies to every step below).** Every step in this onboarder MUST first check whether its work is already done correctly. If it is, the agent skips the step, reports the existing state in one line ("already installed: X 1.2.3", "already wired: 2/2 consoles present", "already registered: <name>"), and moves on. If it is partially done, the agent narrates the gap and fills only the gap — never re-runs the full step blindly. The agent should be safe to run repeatedly on the same project without duplicating work, overwriting user changes, or producing different results between runs.


## Step 1 — Install the Composer package

**Pre-check (do this first).** Before installing, determine the current state of `spryker-sdk/ai-dev` in this project:

```bash
composer show spryker-sdk/ai-dev 2>/dev/null | head -20
composer outdated spryker-sdk/ai-dev 2>/dev/null
```

Interpret the result. Note that `composer outdated` only prints a row when the package *is* outdated — empty output is the **success** case, not a failure. Combine with the `composer show` result:

- **`composer show` reports "Package not found"** → not installed. Run the install below.
- **`composer show` reports a version AND `composer outdated` is empty** → installed and up-to-date. Skip the install. Report: `spryker-sdk/ai-dev <version> already installed and up-to-date`. Continue to the transfers check.
- **Installed but a newer version is available** → tell the user the current version and the latest available, and ask whether to upgrade. Only run `composer update spryker-sdk/ai-dev` (not `require`) if the user confirms. If the user declines, keep the existing version and continue.
- **Installed but the constraint in `composer.json` is missing it from `require-dev`** (rare — e.g. dependency-of-a-dependency) → tell the user and ask whether to add it explicitly to `require-dev`.

If installing or upgrading, run:

```bash
composer require spryker-sdk/ai-dev --dev --ignore-platform-reqs
```

If the project uses `docker/sdk` for Composer (common in Spryker dev environments), prefer:

```bash
docker/sdk cli composer require spryker-sdk/ai-dev --dev
```

Pick one based on what the user has been using in this session. If unsure, ask once.

**Transfers check.** After install / on a no-op skip, verify that the transfers shipped by this package have been generated. Look for `src/Generated/Shared/Transfer/AiDev*.php` (or whichever transfer prefix the package ships) via `ls src/Generated/Shared/Transfer/ 2>/dev/null | grep -i aidev`. If the expected transfer files are present, skip the generation step. Only run the generation if they are missing or the install just happened:

```bash
docker/sdk cli console transfer:generate
```

Success looks like *only* the environment banner being printed:

```
-->  DEVELOPMENT MODE
Region: EU | Code bucket: EU | Environment: docker.dev
```

…and nothing else. No progress messages, no diff, no errors. If you see additional output (warnings, errors, stack traces), treat it as a failure and surface it. If `docker/sdk` is unavailable, ask the user to run `docker/sdk up`.


## Step 2 — Wire console commands: McpServerConsole, AiToolSetupConsole only

**Override detection (do this BEFORE the per-console pre-check).** Spryker projects frequently put a project-namespace `ConsoleDependencyProvider` above `Pyz` (e.g. `src/Acme/Zed/Console/ConsoleDependencyProvider.php` extending `Pyz\Zed\Console\ConsoleDependencyProvider`). The class that actually runs is the *leaf* — the one no other ConsoleDependencyProvider extends. Wiring into `Pyz` only works if every override in the chain calls `parent::getConsoleCommands($container)`. Find all candidates and identify the leaf:

```bash
find src -maxdepth 6 -path "*/Zed/Console/ConsoleDependencyProvider.php" -not -path "*/vendor/*"
```

- **One file (the Pyz one)** → wire into it. Standard case.
- **Two or more files** → read each one. The leaf is the class that is not extended by any other file in the list. Determine its namespace and check `grep -l "parent::getConsoleCommands" <each-non-leaf>`:
  - **Every non-leaf in the chain calls `parent::getConsoleCommands($container)`** → safe to wire into `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`. The leaf's `parent::` chain will surface the added consoles.
  - **Any non-leaf does NOT call `parent::getConsoleCommands`** → wiring into Pyz will be silently invisible. Either wire directly into the leaf, or tell the user the override needs a `parent::` call before continuing. Ask the user which approach they prefer.

State explicitly in one line which file you decided to edit and why (e.g. *"Editing src/Pyz/...; project also has src/Acme/... which calls parent::getConsoleCommands, so the wiring flows through"*).

**Pre-check (do this second).** Read the file you decided to edit and inspect what is already wired. For each of the consoles below, determine independently:

- Is the `use SprykerSdk\Zed\AiDev\Communication\Console\<Console>;` import already present?
- Is the corresponding `$commands[] = new <Console>();` (or `class_exists()`-guarded variant) already inside `getConsoleCommands()`?

Produce a small per-console status (imported? registered?) and act per console, not per step:

- **Both import and registration already present** → leave that console alone. Do not touch its lines, do not reformat, do not duplicate the registration.
- **Import missing, registration missing** → add both (per the snippets below).
- **One present, the other missing** → add only the missing piece.
- **An outdated `GeneratePromptsConsole` import or registration is present** → flag it to the user and ask whether to remove it. Do not silently delete.

If after the pre-check both consoles are already wired, skip Step 2 entirely. Report: `ConsoleDependencyProvider already wires all needed ai-dev consoles — no changes needed`.

Edit the leaf `ConsoleDependencyProvider` (see the override detection block above). Add these **two** consoles to the list returned by `getConsoleCommands()`. **Do not add `GeneratePromptsConsole`** — it is outdated.

### Imports to add (top of file)

```php
use SprykerSdk\Zed\AiDev\Communication\Console\AiToolSetupConsole;
use SprykerSdk\Zed\AiDev\Communication\Console\McpServerConsole;
```

### Registrations to add inside `getConsoleCommands()`

The Spryker README pattern uses `class_exists()` guards so the project boots even when the dev dependency is absent (e.g. on production). Match that style:

```php
if (class_exists(McpServerConsole::class)) {
    $commands[] = new McpServerConsole();
}

if (class_exists(AiToolSetupConsole::class)) {
    $commands[] = new AiToolSetupConsole();
}

```


## Step 3 — Register the MCP server with the harness

This is what makes the live Spryker context (transfer structures, OMS transitions, module map) available to the assistant inside this project.

Two invariants hold in **every** branch below:

- **The MCP server name is the project root folder name** — the `basename` of the current working directory — so that developers with multiple Spryker checkouts on the same machine do not collide on a single shared entry in the harness's user-level config. Do not hardcode a generic name like `spryker-project`.
- **The trailing `-q` (quiet) is mandatory.** MCP servers communicate over stdio, and the `docker/sdk console` banner (`--> DEVELOPMENT MODE…`) would otherwise pollute the channel and cause the MCP handshake to fail.

First decide the **scope**, then pick the branch:

- **User-level** (the CLI `mcp add` commands below) — registers for this developer only, on this machine. The default for a solo setup.
- **Project-scoped** (`.mcp.json` at the project root) — checked into the repository and read by both Claude Code and Copilot CLI. **Recommended when the team shares the repo**, because every developer gets the server without re-running this skill. Offer it whenever the project is a shared checkout, and ask before writing a file that lands in the team's diff.

### Pre-check (do this first, in every branch)

Determine whether the MCP server is already registered for this project and whether it actually works. Use the list command for your harness:

```bash
claude mcp list      # harness = claude
copilot mcp list     # harness = copilot
```

Parse the output and look for an entry whose name matches `$(basename "$(pwd)")`. Three cases:

- **Entry exists and the list shows it as connected/healthy (✓ or equivalent)** → the MCP server is already registered and working. Skip the `mcp add` below. Report: `MCP server <name> already registered and reachable — no changes needed`. Still emit the session-restart reminder at the end of this step, because sessions started *before* this one will not see the existing entry either if they predate its addition.
- **Entry exists but shows as failed / unreachable / error** → **do not re-register yet.** Failing entries are almost always a downstream symptom of Step 1 (package not installed) or Step 2 (console not wired) not having run yet — both of which the onboarding flow has already addressed by the time you reach Step 3. The fix is usually that the list command will report the same entry as `✓ Connected` the next time it's run, with no `mcp remove` / `mcp add` cycle needed. Note the failing entry but defer the decision: report `MCP server <name> exists but failing — will re-verify after Step 4`, skip the `mcp add` below, and let Step 4's re-check decide. If after Step 4 the entry is *still* failing, surface the error and ask the user about `<harness> mcp remove <name>` + re-registration. The root cause at that point is usually `docker/sdk` not being runnable or the wiring in Step 2 not having taken effect.
- **No entry exists for this project** → proceed with the registration for your harness below.

Also check whether *any* entry in the list already points at this project's `docker/sdk console ai-dev:mcp-server` under a different name (e.g. a stale `spryker-project` from an older onboarder run). If found, flag it to the user — a stale duplicate may shadow the new entry — and ask whether to remove it. Check `.mcp.json` and `.vscode/mcp.json` for the same kind of duplicate.

### Branch A — Claude Code (harness = `claude`)

Run from the project root:

```bash
claude mcp add "$(basename "$(pwd)")" -- "$(pwd)/docker/sdk" console ai-dev:mcp-server -q
```

Deriving the name inline keeps the command copy-paste safe across projects; spelled out, for `/Users/alice/work/b2b-demo-marketplace` the server name is `b2b-demo-marketplace`.

Verify with:

```bash
claude mcp list
```

The entry whose name matches the project root folder should appear. If it does not, capture the output and show it to the user.

### Branch B — GitHub Copilot CLI (harness = `copilot`)

Same shape, same name, same mandatory `-q`:

```bash
copilot mcp add "$(basename "$(pwd)")" -- "$(pwd)/docker/sdk" console ai-dev:mcp-server -q
```

Verify with:

```bash
copilot mcp list
```

Apply the identical pre-checks from above: skip when the entry is present and healthy, defer when it is present and failing, and flag a stale duplicate pointing at the same command under a different name.

### Branch C — project-scoped `.mcp.json` (offered to both, recommended for shared repos)

Write `.mcp.json` at the project root. Both Claude Code and Copilot CLI read it, so one file covers a mixed team:

```json
{
  "mcpServers": {
    "<project-name>": {
      "type": "stdio",
      "command": "docker/sdk",
      "args": ["console", "ai-dev:mcp-server", "-q"]
    }
  }
}
```

`<project-name>` is the project root folder name, as everywhere else. (`spryker-ai-dev` is an acceptable fixed alternative when the team would rather not depend on the folder each developer clones into — the file is project-scoped, so the two cannot collide across checkouts. Ask the user which they want before writing.)

The relative `command` resolves against the project root, which is what both CLIs use as the working directory. **If it does not resolve** — the harness was started elsewhere, or the file is consumed from a different working directory — use the absolute form `"command": "$(pwd)/docker/sdk"` with the literal expanded path substituted in.

Because this file lands in the team's diff, ask before creating it, and if one already exists, show the user the merge you intend to make rather than replacing the file.

### Branch D — VS Code (harness = `vscode`)

VS Code reads `.vscode/mcp.json`, which uses a **`servers`** key rather than `mcpServers`:

```json
{
  "servers": {
    "<project-name>": {
      "type": "stdio",
      "command": "docker/sdk",
      "args": ["console", "ai-dev:mcp-server", "-q"]
    }
  }
}
```

**`.vscode/mcp.json` is NOT read by Copilot CLI**, and `.mcp.json` is not read by VS Code. A developer who uses both needs **both** files. Say so explicitly when the harness is `vscode`, and offer to write `.mcp.json` as well.

### Branch E — Copilot cloud agent

MCP servers for the Copilot coding agent are configured in the **repository's Copilot coding agent settings on GitHub**, not on any developer's machine — repository *Settings → Copilot → Coding agent → MCP configuration*. Point the user at it; do **not** try to automate it from here. Note also that the cloud agent has no `docker/sdk` environment of its own, so this server is only useful there if the repository provides one.

### Important — session restart required

The `mcp add` commands write the registration to disk immediately, so the list command shows it right away. However, **the currently running session loaded its MCP servers at startup and will not pick up the new one**. The Spryker MCP server's tools (transfer lookups, OMS transitions, module map, etc.) only become available in a **new** session started after this step. Explicitly instruct the user to restart, in the form their harness needs:

- **Claude Code** — `/exit` and reopen, or open a new session in this project directory.
- **GitHub Copilot CLI** — quit and restart the CLI in this project directory.
- **VS Code** — reload the window (*Developer: Reload Window*).

If the new session still shows no Spryker MCP tools, check the harness's `mcp list` for an error status on the entry and inspect the output of `docker/sdk console ai-dev:mcp-server` for startup failures.


## Step 4 — Verify

Run these in order. If any fails, surface the failure and stop — do not silently continue. These checks are also the source of truth for whether earlier steps' "skip — already done" decisions were correct: if a step was skipped because the agent believed it was already done but the verification here fails, the agent must go back and fix it rather than reporting success.

```bash
docker/sdk cli console | grep ai-dev:
```

Expected: exactly two `ai-dev:*` commands — `ai-dev:setup` and `ai-dev:mcp-server`. If fewer commands appear, check Step 2 — the corresponding consoles are not wired (or wired behind a `class_exists()` guard that is failing because the class is actually unavailable, which points back to Step 1). If you also see `ai-dev:generate-prompts`, an outdated wiring is still in place — flag it and offer to remove.

Then re-run the list command for your harness (`claude mcp list`, or `copilot mcp list`). For a project-scoped registration, confirm `.mcp.json` (or `.vscode/mcp.json`) exists, parses as JSON, and names the server — the CLI list commands only show it once a session has loaded the file.

Expected: an entry whose name matches the project root folder (the name chosen in Step 3) is present **and shows as connected/healthy**.

If Step 3 deferred a previously-failing entry, this is the moment to re-check it: re-run the list command and look at the status of that entry specifically.

- **Now `✓ Connected`** → success. The earlier failure was a downstream symptom of Step 1/2 not having run yet. Report `MCP server <name> recovered after Step 1+2 completed` and continue.
- **Still failing** → only now ask the user whether to run the harness's `mcp remove <name>` and re-register. Capture and surface the exact error string from the list output so the user can see what's broken. The most common remaining causes are (a) `docker/sdk` not actually running, (b) the consoles in Step 2 wired in a file that isn't the leaf override (see Step 2's "Override detection" block), or (c) an unrelated MCP transport problem.

A listed-but-failing entry is not a pass — surface the error and stop until the user decides.


## Step 5 — Install artifacts one by one (with user consent per artifact)

The onboarder installs up to three project-level artifacts: the **project instructions file** at the project root, the **Spryker coding rules** in the harness's rules folder, and the **permission rules** from the SDK's `hooks/settings.example.json` plus, for a project that does not use the plugin, its **stop guard** (Artifact 3). The first two differ per harness.

### Per-harness artifact table

Authoritative values; they mirror `TOOL_ARTIFACT_MAP` in `src/SprykerSdk/Zed/AiDev/AiDevConfig.php`.

| Harness (Step 0) | Project instructions file | Rules folder and file names | Rule frontmatter |
|---|---|---|---|
| `claude` — Claude Code | `CLAUDE.md` at the project root | `.claude/rules/<name>.md` | `paths` kept as-is; `name` and `description` are dropped |
| `copilot` — GitHub Copilot CLI | `.github/copilot-instructions.md` | `.github/instructions/<name>.instructions.md` | `paths` becomes `applyTo`; `name` and `description` are dropped; unscoped rules get `applyTo: "**"` |
| `vscode` — VS Code Copilot | `.github/copilot-instructions.md` | `.github/instructions/<name>.instructions.md` | same as `copilot` |

Read the row for the harness from Step 0 and use it for the rest of this step. Everywhere below, "the project instructions file" and "the rules folder" mean that row's values.

**Install with the console or the sync script, never a plain `cp`.** Both rewrite the rule frontmatter per harness and produce identical files:

```bash
docker/sdk cli console ai-dev:setup
```

When the console is not available, the fallback is the **sync script** below, for every harness. A plain `cp` keeps fields the console drops, so the sync script sees the copy as locally edited and never refreshes it; under Copilot a `cp`-ed rule with a `paths:` key is also silently ignored — it looks installed and does nothing.

**Auto mode does NOT bypass Step 5 prompts.** Each artifact decision is a separate consent. Overwriting an existing custom instructions file or replacing files in the rules folder is a destructive action; ask even when the harness is otherwise configured for autonomous execution. (Adding a missing instructions file or populating an empty rules folder is not destructive — those can proceed on a clear yes/no.)

### How the bundled content is delivered

Source-of-truth content for both artifacts lives in the package install at `vendor/spryker-sdk/ai-dev/`:

- `vendor/spryker-sdk/ai-dev/data/agents/AGENTS.example.md` — the bundled project instructions template
- `vendor/spryker-sdk/ai-dev/plugins/spryker-ai-dev-sdk/rules/*.md` — the bundled rule files, in their untransformed source form

The package also ships an interactive console command — `docker/sdk cli console ai-dev:setup` — that walks through copying these into the project. **Prefer `ai-dev:setup` when it exists** so the package owns the placement logic. Some package versions do not ship it, or ship one that prompts in a way that doesn't fit the agent's per-artifact consent flow. Then fall back per the rule above: `cp` for `claude`, the sync script for `copilot` and `vscode`.

### Resolve the bundled-content source

Determine where to read bundled content from. Try in order:

```bash
# 1. Current project's vendor — this is the most reliable source
test -d vendor/spryker-sdk/ai-dev/data && echo "vendor source OK"

# 2. Composer's resolved vendor-dir, in case it's customized
"$(composer config --absolute vendor-dir 2>/dev/null)/spryker-sdk/ai-dev/data"
```

Use the first path that resolves. This is the source for the **project instructions file** only.

Two sources, and they are not interchangeable:

- **The instructions file** comes from the project's own `vendor/spryker-sdk/ai-dev/data/`, resolved
  above. Never read it from `<plugin-root>`: that directory belongs to whichever project the harness
  happened to load the plugin from, which may be a *different* project on this machine.
- **The rules** ship inside the plugin, at `<plugin-root>/rules/`, and
  are installed by `<plugin-root>/scripts/sync-rules.sh` (Artifact 2). That is the correct source for
  them, and it is why the rules work before Composer has ever run. `<plugin-root>` is the parent of
  the `skills/` folder in the path reported as `Base directory for this skill:`.

Skills are out of scope for the onboarder. They load automatically through the plugin mechanism in both harnesses (the `spryker-ai-dev-sdk` plugin) and require no project-level copying.

### On pinning

Files copied from `vendor/spryker-sdk/ai-dev/data/` are pinned to `composer.lock` (whatever version Composer installed). They do not auto-refresh against `master`. If the user wants newer artifacts, they should run `composer update spryker-sdk/ai-dev` first, then re-run this skill in update flow.

### Pre-check — what is already in place

Inspect the project so each per-artifact prompt below can include the current state:

- Does the project instructions file exist at the project root? If so, is it the bundled file (compare first ~10 lines against `vendor/spryker-sdk/ai-dev/data/agents/AGENTS.example.md`) or a custom project file?
- Is the rules folder already populated? **Check `.ai-dev/rules.manifest` first** — see Artifact 2.

### Artifact 1 — Project instructions file

Three cases based on the pre-check. `<instructions-file>` is the value from the table (`CLAUDE.md`, or `.github/copilot-instructions.md`).

**Case A — the file does not exist.** Ask: *"Add `<instructions-file>` to the project from the bundled template?"* If yes, copy (creating the parent directory first when the path has one):

```bash
mkdir -p "$(dirname "<instructions-file>")"
cp vendor/spryker-sdk/ai-dev/data/agents/AGENTS.example.md "<instructions-file>"
```

**Case B — the file exists and matches the bundled file** (first ~10 lines identical → user previously installed it). Ask: *"`<instructions-file>` looks like a previous bundled install. Overwrite with the current bundled version, or keep as-is?"* On overwrite, run the `cp` above.

**Case C — the file exists and is custom** (project-specific content not from the bundled template). Offer THREE choices, not two:

1. **Keep mine (skip)** — leave the file untouched.
2. **Overwrite with bundled** — replace; the user's custom content is lost. Recommend this only when the user explicitly says they want a clean reset.
3. **Merge — append bundled sections that aren't already present** — recommended default. Read both files; identify section headings (`#` and `##`) in the bundled file that do NOT exist in the user's file; append those sections at the end of the user's file under a `# Spryker AI Dev — bundled additions` divider. Do NOT attempt to merge prose paragraphs or rewrite existing sections — only append whole sections the user lacks. Surface the list of sections you appended so the user can review.

Typical sections worth appending from the bundled `AGENTS.example.md` that custom project files usually lack: **Commands cheatsheet** (docker/sdk one-liners), **Layer Directory Structures** (Zed/Yves/Glue/Client/Service/Shared trees), **Component Rules** (Transfer Object suffixes, Entity, Entity Manager, Facade, Mapper/Expander/Hydrator, Gateway Controller, Model, Persistence Schema, Provider/Router, Zed Stub, Widget, Theme, Repository, Module Configuration, Layout), **Abstract Classes Reference** tables.

### Artifact 2 — Spryker coding rules (may already be installed)

**Check before asking.** The plugin ships the rules as plugin data under `<plugin-root>/rules/`. A session-start hook offers to install them once per project but writes nothing by itself, so they are in place only where someone accepted that offer or ran this skill's rules-only flow. An install records what it wrote in `.ai-dev/rules.manifest` — first line `<harness> <plugin-version>`, then one `<sha256> <filename>` line per rule:

```bash
head -1 .ai-dev/rules.manifest 2>/dev/null && tail -n +2 .ai-dev/rules.manifest | wc -l
```

- **Manifest present, its first field matches the harness from Step 0, and the listed files exist in the rules folder** → the rules are installed and current. **Skip this artifact.** Report: `rules: already installed (<N> files in <rules folder>) — skipped`. Do not ask a question the user has nothing to decide.
- **Manifest present but for a different harness** (e.g. `claude` when Step 0 said `copilot` — a developer who switched) → tell the user, and offer the forced refresh below for the current harness. The old folder is left alone; removing it is the user's call.
- **No manifest, or listed files missing** → the rules were never installed here: the session-start hook only offers, and this project was never offered (a fresh clone, CI, `.ai-dev/no-rules-sync`) or the offer was declined or ignored. `.ai-dev/rules.suggested` tells the two apart, but it changes nothing you do — offer to install either way, since the person is here asking for setup.

**To install or force a refresh**, run the script — never a plain `cp`, which skips the frontmatter transform:

```bash
<plugin-root>/scripts/sync-rules.sh --target "$(pwd)" --harness <claude|copilot> --force
```

`<plugin-root>` is the plugin directory holding this skill — the parent of the `skills/` folder in the path reported as `Base directory for this skill:` when this skill loaded. Substitute the literal absolute path. Pass `--harness claude` for Claude Code, `--harness copilot` for both Copilot CLI and VS Code.

What the script guarantees, so you can describe it accurately when asking:

- It **never overwrites a rule file a human has edited** — it compares each file against the sha recorded in the manifest, and files that differ are preserved and reported.
- `--force` only makes it re-scan a project it would otherwise skip; it still preserves locally
  edited files. To deliberately replace an edited rule with the bundled version, the user has to ask
  for it, and the flag is `--discard-local-edits`. Never pass that flag unless they did.
- It writes exactly what the console writes: `name`/`description` dropped for every harness, plus `paths` → `applyTo` (unscoped rules default to `applyTo: "**"`) for `copilot` and `vscode`.
- It prints one summary line: how many were added, refreshed, and left untouched. Put that line in `run.log` and in the report.

Ask before running it: *"Install the Spryker coding rules into `<rules folder>`? Locally edited rule files are preserved."* If the user says the rules should not be installed at all, tell them about the per-project opt-out — create `.ai-dev/no-rules-sync` in the project root and the session-start hook stops touching this project.

When the console command is available and the user prefers it, `docker/sdk cli console ai-dev:setup` does the same transform and is equally correct; check what it offers first:

```bash
docker/sdk cli console ai-dev:setup --help 2>&1 | head -20
```

### Artifact 3 — Stop guard + permission rules (permissions only on a plugin install)

**On a plugin install, skip copying the guard but still offer the `permissions` block.** When the `spryker-ai-dev-sdk` plugin is installed (the harness's plugin list names it — `claude plugin list` or `copilot plugin list`), the plugin's own hook files provide the guard without any project file; offer to merge only the `permissions` block of `hooks/settings.example.json` into the harness settings file. On a setup install, offer the whole artifact, and explain what each part does in one line:

The `permissions` block is Claude Code's format. Under Copilot there is no equivalent project file to merge it into, so on `copilot` or `vscode` state that the guard comes from the plugin and skip the permissions offer.

- **`Stop` hook (`guard-stop.php`)** — while a wizard state file (`.ai-dev/project-setup.md` or `.ai-dev/demo-prep.md`) has `run_mode: autonomous` and its Steps table still has a row that is not `done`/`skipped`, the agent cannot end its turn; the turn may end on a question only the person can answer, a `NEEDS YOU:` line, or a structured question to the user.
- **`permissions`** — `deny` for `sudo`/`docker volume rm`/`docker system prune`/`git push`, and no `ask` rules. `rm`, `python3`/`python`, `magick`, the browser JavaScript tools and the rebuild verbs (`docker/sdk reset|clean-data|up`) are under `allow`; the skills state when the agent uses them (delete only files git can restore or that the run created; `validate.php gate` before a rebuild or `data:import`; ask before a rebuild on a project with real data). A blanket `Bash(docker/sdk:*)` allowlist lets an agent consume hours of rebuild time unprompted.

Install = copy `hooks/guard-stop.php` and `hooks/lib.php` from the SDK (source: the plugin checkout, or `vendor/spryker-sdk/ai-dev/plugins/spryker-ai-dev-sdk/hooks/` when vendored) into the harness's project hooks folder under a `spryker-ai-dev-sdk/` subfolder, and **merge** the stop-guard entry and the `permissions` block of `hooks/settings.example.json` into the harness settings file — never overwrite an existing settings file (show the diff, append the blocks with the file edit tool). Requires host `php` (the hook exits silently without it). Installed skill copies in the harness's skills folder are not edited, in this skill or later; a skill improvement goes to `.ai-dev/skill-improvement-log.md`.

### Rules for running this step as a whole
- Ask in the order above (agents file → rules → Stop hook + permissions). Do not batch the questions into one combined prompt — each artifact is a separate decision.

- Ask in the order above (instructions file → rules). Do not batch the two questions into one combined prompt — each artifact is a separate decision.
- Only act on the artifacts the user approved.
- Each operation must complete cleanly before moving to the next.
- If a copy fails (permission denied, source missing), surface the actual error verbatim and stop — do not retry, do not auto-recover, do not proceed to the next artifact silently.
- If the user said no to all three, skip the rest of Step 5 and report that in the Final report.


## Final report

In **3–5 lines** tell the user:

- The harness that was detected or chosen, since every path below depends on it.
- What was installed (package + version).
- Which file was edited and how many consoles were added (state the *actual* file edited — Pyz or the project-namespace override).
- That the MCP server is registered under the project root folder name (state the actual name used) and in which scope (the harness's user-level config, `.mcp.json`, or `.vscode/mcp.json`).
- The outcome of Step 5 — list each artifact (the project instructions file, the rules, the stop guard + permissions) with one of: `added`, `overwritten`, `merged: <description of what was appended>`, `skipped (user declined)`, `skipped (already present)`, or `failed: <one-line reason>`. Name the actual paths written.
- ALWAYS highlight that the user must **restart their session** for the Spryker MCP tools to become available — Claude Code: `/exit` and reopen; GitHub Copilot CLI: quit and restart; VS Code: reload the window. The running session will not pick them up automatically.
- Name what **this run** changed, and say it needs nothing from the user: tracked `composer.json`, `composer.lock`, the `ConsoleDependencyProvider.php` actually edited, plus `.mcp.json` if one was written; untracked project instructions file, rules folder, `.ai-dev/`. **These are expected install artifacts and every skill tolerates them uncommitted — `project-starter-wizard` included, which recognises this exact shape and proceeds.** Committing them is optional housekeeping, not a step: mention it only in passing, and only alongside `.ai-dev/` in `.gitignore` (it is per-run log state). Never imply anything is blocked until they commit.
- Recommend where to go next, so the entry points are known — name them, never start them. Do **not** invoke, launch, or auto-continue into any of these skills; the user chooses if and when to run one.
  - New project from a demoshop clone → `/spryker-ai-dev-sdk:project-starter-wizard` is the recommended next skill.
  - Feature work on an existing project → `/spryker-ai-dev-sdk:spryker-customization` (from a PRD or acceptance criteria), or `/spryker-ai-dev-sdk:product-requirement-document` first when there is no spec yet.
  - Mention that the Spryker MCP tools only exist after the **session restart** above.
- Suggest setup improvements:
  - A PHP Language Server plugin for the harness in use — for Claude Code, `php-lsp@claude-plugins-official` (https://github.com/anthropics/claude-plugins-official/blob/main/plugins/php-lsp/README.md); for VS Code, the PHP Intelephense or PHP Tools extension.
  - Setup MCP server for Context7 to work with Spryker documentation https://docs.spryker.com/docs/dg/dev/ai/ai-assistants/context7-mcp-server
- As the **last line**, the absolute path to the run log (`$SETUP_DIR/run.log`), so the user can audit
  what ran, what was skipped, and why.

Do **not** print a long summary or restate the steps. The user can read the diff.

## What to refuse / escalate

- If the harness cannot be determined and the user cannot say which one they are using, stop. Do not guess a layout and write files — a wrong guess puts valid content at paths nothing reads.
- If `composer require` fails due to a version conflict, surface the conflict — do **not** force-install with `--ignore-platform-reqs` or `-W` without asking.
- If `ConsoleDependencyProvider.php` is missing or has a structure you cannot safely parse, stop and ask the user where to wire the commands. Do not invent a new file.
- If the harness's `mcp add` subcommand is unavailable (an older CLI, or a harness without one), tell the user and offer the project-scoped JSON snippet from Step 3 Branch C for their MCP config file instead of failing silently.
- If the only thing blocking progress is a missing Composer or a `docker/sdk` environment that is not up, offer the **rules-only flow** rather than stopping flat — it delivers the coding rules without either.
