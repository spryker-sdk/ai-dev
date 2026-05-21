---
name: ai-dev-setup
description: "Sets up the Spryker AI Dev SDK in a Spryker project. Covers two related flows: first-time onboarding (installs the Composer package, wires console commands, registers the MCP server, installs `CLAUDE.md` and `.claude/rules/`) and updating (refreshes just `CLAUDE.md` and/or `.claude/rules/` from the latest upstream content, skipping install/wiring). The skill infers which flow the user wants from their request and from project state. Requires Composer and a running `docker/sdk` environment for first-time onboarding; updating only needs network access (or a vendored copy of the package)."
disable-model-invocation: true
user-invocable: true
allowed-tools: Bash, Read, Edit, Write, Glob, Grep
---

# Spryker AI Dev Setup

You set up the Spryker **AI Dev SDK** (`spryker-sdk/ai-dev`) in a Spryker project. The skill covers two related flows — first-time onboarding and updating bundled artifacts — and infers which one the user wants. When fully onboarded, the project has:

1. The `spryker-sdk/ai-dev` package installed as a dev dependency.
2. Two console commands wired in `ConsoleDependencyProvider` (`McpServerConsole`, `AiToolSetupConsole`).
3. The AI Dev MCP server registered with Claude Code so other Claude sessions in this project can use Spryker-aware tools (transfers, OMS, module map, CSV/ODS, etc.).
4. (Optional) `CLAUDE.md` at the project root and `.claude/rules/` populated from the bundled package content.

You do **not** generate prompts. The `ai-dev:generate-prompts` command exists in the package but is outdated — skip it.

---

## Mode selection — infer onboarding vs. update before running anything

This skill operates in one of two flows. **Decide which one is appropriate before doing any work.** The decision uses two signals: (a) what the user said when invoking the skill, and (b) the current state of the project. Do not ask the user "which mode?" as a first question — pick the mode yourself and only confirm if genuinely ambiguous.

### Signal A — read the user's invocation

When the user invokes this skill, scan the message they sent (the prompt that triggered the skill, plus any words on the invocation line) for intent:

- Words like *"update"*, *"refresh"*, *"sync"*, *"pull latest"*, *"get newer"*, *"upgrade rules"*, *"refresh CLAUDE.md"* → user wants **update**.
- Words like *"install"*, *"set up"*, *"onboard"*, *"add"*, *"configure"*, *"first time"*, *"new project"* → user wants **onboarding**.
- Bare invocation with no context (just `/spryker-ai-dev-setup` and nothing else) → ambiguous, use Signal B.

### Signal B — read the project state

Run cheap read-only checks:

```bash
composer show spryker-sdk/ai-dev 2>/dev/null | head -1     # is the package installed?
test -f CLAUDE.md && echo "CLAUDE.md present"               # does the user already have CLAUDE.md?
test -d .claude/rules && ls .claude/rules/ 2>/dev/null | wc -l  # is .claude/rules/ populated?
claude mcp list 2>/dev/null | grep "^$(basename "$(pwd)"):" # is the MCP server already registered for this project?
```

> The MCP grep uses `^<name>:` (anchored start, colon delimiter) — not `-i "<name>"` — because `claude mcp list` formats entries as `<name>: <command> - <status>` and a substring match can false-positive on another entry whose name contains the project's basename (e.g. project `shop` would match an entry named `b2b-shop`).

- **Package installed + MCP server registered + `CLAUDE.md` present + `.claude/rules/` populated** → almost certainly an **update** request. The user has nothing left to onboard.
- **None of the above present** → onboarding.
- **Mixed state** (e.g. package installed but no `CLAUDE.md`) → onboarding, but the per-step idempotency pre-checks below will skip what's already in place.

### Decide and proceed

Combine Signals A and B:

- **A says update, B confirms (fully set up)** → run **update flow**.
- **A says update, B says nothing is installed** → tell the user "this project does not appear to be onboarded — `spryker-sdk/ai-dev` is not installed. Want me to run the full onboarding flow instead?" Wait for confirmation. Do not silently fall through.
- **A says onboarding, B says fully set up** → tell the user "this project already has the SDK installed and configured. Did you mean to refresh `CLAUDE.md` and `.claude/rules/` instead?" Wait. Do not silently re-run onboarding.
- **A is ambiguous or absent**, B fully set up → assume **update flow**, but state your assumption in one line and let the user redirect.
- **A is ambiguous or absent**, B nothing installed → assume **onboarding**, state your assumption in one line.
- **A and B disagree any other way** → state both signals and ask the user which flow they want.

### Update flow

When in update flow, **skip Requirements / Preflight / Step 1 / Step 2 / Step 3 / Step 4 entirely**. Jump straight to Step 5, but apply these overrides:

- The user is **refreshing**, not installing for the first time. Frame the prompts that way. Suggested phrasing for `CLAUDE.md`: *"Refresh `CLAUDE.md` from the bundled content in `vendor/spryker-sdk/ai-dev/data/`? Choose overwrite (replace), merge (append missing sections), or skip."* For rules: *"Refresh `.claude/rules/` from `vendor/spryker-sdk/ai-dev/data/rules/`? Choose overwrite (replace same-named files), merge (only add missing files), or skip."*
- The recommended default in update flow is **overwrite** (the user explicitly asked to refresh). Still ask for confirmation — never overwrite silently. But say "Default: overwrite — confirm with y, or pick merge / skip" rather than the conservative onboarding default of "keep yours".
- If the user wants the *latest from `master`* rather than what Composer has installed, instruct them to run `composer update spryker-sdk/ai-dev` first; the bundled content under `vendor/spryker-sdk/ai-dev/data/` will then reflect the new version. The skill itself only copies what's on disk — it does not fetch from GitHub.
- If `CLAUDE.md` does **not** exist when update flow runs, tell the user this looks like a first-time install and ask whether to switch to onboarding. Do not silently install.
- If `.claude/rules/` does not exist or is empty when update flow runs, same handling.
- The Final report in update flow only mentions which of the two artifacts were refreshed (or skipped) and the bundled version copied from (e.g. `from vendor/spryker-sdk/ai-dev 0.5.0`). Skip the "what was installed (package + version)" and "MCP server registered" lines — those steps did not run.

After Step 5 completes in update flow, **stop**. Do not run anything else in this file.

### Onboarding flow

Run all the sections below in order: Requirements → Preflight → Step 1 → Step 2 → Step 3 → Step 4 → Step 5. The per-step idempotency pre-checks (defined in the Preflight "Idempotency principle" paragraph) automatically skip work that is already in place, so this is also safe to re-run on a partially-onboarded project — the user does not need to manually pick "onboarding" vs. "update" to handle the partial case.

---

## Requirements (hard preconditions)

This onboarder **requires both Composer and a running `docker/sdk` environment**. Neither is optional:

- **Composer** is needed to install the `spryker-sdk/ai-dev` package (Step 1) and to inspect what is already installed.
- **A running `docker/sdk` environment** is needed to generate transfers, list console commands for verification (Step 4), and serve as the MCP server transport (Step 3 registers `docker/sdk console ai-dev:mcp-server` as the command Claude will invoke). If the container stack is not up, the MCP server registration will succeed on disk but the server will fail to start on every Claude session.

Before doing anything else, verify both are available and functional. If either is missing or not running, stop the onboarder, tell the user clearly what is missing and ask them to remedy it before you continue. **Do not start the containers or install Composer for the user** — these are state-changing on their machine and outside the scope of "onboard the SDK".

### Check Composer

```bash
command -v composer >/dev/null 2>&1 && composer --version
```

- If `composer` is not on `PATH`, ask the user: *"Composer is required but not installed (or not on PATH). Please install Composer (https://getcomposer.org/) or activate it in your shell, then re-run me. Should I stop and wait?"* — stop on confirmation.
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

If `docker/sdk` does not exist at all in the project root, ask the user where their Spryker development environment lives. The onboarder can in principle adapt to a non-`docker/sdk` workflow, but only with explicit guidance — stop and ask rather than guessing.

Only proceed past this section once both Composer and `docker/sdk` checks pass (or the user has explicitly waived a check with full awareness of the consequences).


## Preflight — confirm we're in a Spryker project and capture current state

Run these checks before changing anything. If any fail, stop and tell the user what's missing.

1. The current working directory must contain `composer.json` with a `spryker/*` dependency.
2. At least one `ConsoleDependencyProvider` must exist under `src/`. Find candidates with `find src -maxdepth 6 -path "*/Zed/Console/ConsoleDependencyProvider.php" -not -path "*/vendor/*"`. Typical: just `src/Pyz/...`; some projects also have a higher-precedence override (e.g. `src/GrowerMarketplace/Zed/Console/ConsoleDependencyProvider.php`). Step 2 handles the override case explicitly. If none is found at all, the project layout is non-standard — ask the user where console commands are wired before continuing.

(The Composer and `docker/sdk` checks live in the **Requirements** section above and must already have passed before reaching this point.)

Use Bash + Read for these checks. Report what you found in one short sentence before proceeding.

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

**Override detection (do this BEFORE the per-console pre-check).** Spryker projects frequently put a project-namespace `ConsoleDependencyProvider` above `Pyz` (e.g. `src/GrowerMarketplace/Zed/Console/ConsoleDependencyProvider.php` extending `Pyz\Zed\Console\ConsoleDependencyProvider`). The class that actually runs is the *leaf* — the one no other ConsoleDependencyProvider extends. Wiring into `Pyz` only works if every override in the chain calls `parent::getConsoleCommands($container)`. Find all candidates and identify the leaf:

```bash
find src -maxdepth 6 -path "*/Zed/Console/ConsoleDependencyProvider.php" -not -path "*/vendor/*"
```

- **One file (the Pyz one)** → wire into it. Standard case.
- **Two or more files** → read each one. The leaf is the class that is not extended by any other file in the list. Determine its namespace and check `grep -l "parent::getConsoleCommands" <each-non-leaf>`:
  - **Every non-leaf in the chain calls `parent::getConsoleCommands($container)`** → safe to wire into `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`. The leaf's `parent::` chain will surface the added consoles.
  - **Any non-leaf does NOT call `parent::getConsoleCommands`** → wiring into Pyz will be silently invisible. Either wire directly into the leaf, or tell the user the override needs a `parent::` call before continuing. Ask the user which approach they prefer.

State explicitly in one line which file you decided to edit and why (e.g. *"Editing src/Pyz/...; project also has src/GrowerMarketplace/... which calls parent::getConsoleCommands, so the wiring flows through"*).

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


## Step 3 — Register the MCP server with Claude Code

This is what makes the live Spryker context (transfer structures, OMS transitions, module map) available to Claude inside this project.

**Pre-check (do this first).** Determine whether the MCP server is already registered for this project and whether it actually works:

```bash
claude mcp list
```

Parse the output and look for an entry whose name matches `$(basename "$(pwd)")`. Three cases:

- **Entry exists and `claude mcp list` shows it as connected/healthy (✓ or equivalent)** → the MCP server is already registered and working. Skip the `claude mcp add` below. Report: `MCP server <name> already registered and reachable — no changes needed`. Still emit the session-restart reminder at the end of this step, because Claude sessions started *before* this one will not see the existing entry either if they predate its addition.
- **Entry exists but shows as failed / unreachable / error** → **do not re-register yet.** Failing entries are almost always a downstream symptom of Step 1 (package not installed) or Step 2 (console not wired) not having run yet — both of which the onboarding flow has already addressed by the time you reach Step 3. The fix is usually that `claude mcp list` will report the same entry as `✓ Connected` the next time it's run, with no `mcp remove` / `mcp add` cycle needed. Note the failing entry but defer the decision: report `MCP server <name> exists but failing — will re-verify after Step 4`, skip the `claude mcp add` below, and let Step 4's `claude mcp list` re-check decide. If after Step 4 the entry is *still* failing, surface the error and ask the user about `claude mcp remove <name>` + re-registration. The root cause at that point is usually `docker/sdk` not being runnable or the wiring in Step 2 not having taken effect.
- **No entry exists for this project** → proceed with the `claude mcp add` instructions below.

Also check whether *any* entry in `claude mcp list` already points at this project's `docker/sdk console ai-dev:mcp-server` under a different name (e.g. a stale `spryker-project` from an older onboarder run). If found, flag it to the user — a stale duplicate may shadow the new entry — and ask whether to remove it.

**The MCP server name MUST be unique per project** so that developers with multiple Spryker checkouts on the same machine do not collide on a single shared entry in `~/.claude.json`. Use the project's root folder name (the `basename` of the current working directory) as the MCP server name. Do not hardcode a generic name like `spryker-project`.

Run from the project root, substituting `<project-name>` with the basename of the project root directory (e.g. for `/Users/alice/work/b2b-demo-marketplace`, use `b2b-demo-marketplace`):

```bash
claude mcp add <project-name> -- "$(pwd)/docker/sdk" console ai-dev:mcp-server -q
```

Equivalently, derive it inline so the command is copy-paste safe across projects:

```bash
claude mcp add "$(basename "$(pwd)")" -- "$(pwd)/docker/sdk" console ai-dev:mcp-server -q
```

The trailing `-q` (quiet) is mandatory: MCP servers communicate over stdio, and the `docker/sdk console` banner (`--> DEVELOPMENT MODE…`) would otherwise pollute the channel and cause the MCP handshake to fail.

Verify with:

```bash
claude mcp list
```

The entry whose name matches the project root folder (the `<project-name>` chosen above) should appear. If it does not, capture the output and show it to the user.

**Important — session restart required.** `claude mcp add` writes the registration to disk immediately, so `claude mcp list` shows it right away. However, the **currently running Claude Code session loaded its MCP servers at startup and will not pick up the new one**. The Spryker MCP server's tools (transfer lookups, OMS transitions, module map, etc.) will only become available in a **new** Claude Code session started after this step. Explicitly instruct the user to exit and reopen Claude Code (or open a new session in this project directory) before expecting those tools to work. If the new session still shows no Spryker MCP tools, check `claude mcp list` for an error status on the entry and inspect the output of `docker/sdk console ai-dev:mcp-server` for startup failures.


## Step 4 — Verify

Run these in order. If any fails, surface the failure and stop — do not silently continue. These checks are also the source of truth for whether earlier steps' "skip — already done" decisions were correct: if a step was skipped because the agent believed it was already done but the verification here fails, the agent must go back and fix it rather than reporting success.

```bash
docker/sdk cli console | grep ai-dev:
```

Expected: exactly two `ai-dev:*` commands — `ai-dev:setup` and `ai-dev:mcp-server`. If fewer commands appear, check Step 2 — the corresponding consoles are not wired (or wired behind a `class_exists()` guard that is failing because the class is actually unavailable, which points back to Step 1). If you also see `ai-dev:generate-prompts`, an outdated wiring is still in place — flag it and offer to remove.

```bash
claude mcp list
```

Expected: an entry whose name matches the project root folder (the `<project-name>` chosen in Step 3) is present **and shows as connected/healthy**.

If Step 3 deferred a previously-failing entry, this is the moment to re-check it: re-run `claude mcp list` and look at the status of that entry specifically.

- **Now `✓ Connected`** → success. The earlier failure was a downstream symptom of Step 1/2 not having run yet. Report `MCP server <name> recovered after Step 1+2 completed` and continue.
- **Still failing** → only now ask the user whether to `claude mcp remove <name>` and re-register. Capture and surface the exact error string from `claude mcp list` so the user can see what's broken. The most common remaining causes are (a) `docker/sdk` not actually running, (b) the consoles in Step 2 wired in a file that isn't the leaf override (see Step 2's "Override detection" block), or (c) an unrelated MCP transport problem.

A listed-but-failing entry is not a pass — surface the error and stop until the user decides.


## Step 5 — Install artifacts one by one (with user consent per artifact)

The onboarder installs two project-level artifacts: `CLAUDE.md` at the project root, and bundled rules under `.claude/rules/`.

**Auto mode does NOT bypass Step 5 prompts.** Each artifact decision is a separate consent. Overwriting an existing custom `CLAUDE.md` or replacing files in `.claude/rules/` is a destructive action; ask even when the harness is otherwise configured for autonomous execution. (Adding a missing `CLAUDE.md` or populating an empty `.claude/rules/` is not destructive — those can proceed on a clear yes/no.)

### How the bundled content is delivered

Source-of-truth content for both artifacts lives in the package install at `vendor/spryker-sdk/ai-dev/data/`:

- `vendor/spryker-sdk/ai-dev/data/agents/AGENTS.example.md` — bundled agents/CLAUDE file
- `vendor/spryker-sdk/ai-dev/data/rules/*.md` — bundled rule files

The package also ships an interactive console command — `docker/sdk cli console ai-dev:setup` — that walks through copying these into the project. **Prefer `ai-dev:setup` when it exists** so the package owns the placement logic. Some package versions do not yet ship a setup command, or ship one that prompts in a way that doesn't fit the agent's per-artifact consent flow. In that case, fall back to a direct `cp` from `vendor/spryker-sdk/ai-dev/data/`.

### Resolve the bundled-content source

Determine where to read bundled content from. Try in order:

```bash
# 1. Current project's vendor — this is the most reliable source
test -d vendor/spryker-sdk/ai-dev/data && echo "vendor source OK"

# 2. Composer's resolved vendor-dir, in case it's customized
"$(composer config --absolute vendor-dir 2>/dev/null)/spryker-sdk/ai-dev/data"

# 3. The plugin's install location ($CLAUDE_PLUGIN_ROOT) — only if Step 1 hasn't run yet
# and you need bundled content from the plugin install (rare; Step 1 should be done by now)
"${CLAUDE_PLUGIN_ROOT}/data"   # may not exist; the plugin dir is for the skill itself, not for project artifacts
```

Use the first path that resolves. **Do not assume `${CLAUDE_PLUGIN_ROOT}` points at this project** — it points at whichever Spryker project Claude Code loaded the plugin from, which may be a *different* project on the same machine. The current project's `vendor/spryker-sdk/ai-dev/data/` is the authoritative source after Step 1 has run.

Skills are out of scope for the onboarder. They load automatically through the Claude Code plugin mechanism (`spryker-ai-dev` plugin) and require no project-level copying.

### On pinning

Files copied from `vendor/spryker-sdk/ai-dev/data/` are pinned to `composer.lock` (whatever version Composer installed). They do not auto-refresh against `master`. If the user wants newer artifacts, they should run `composer update spryker-sdk/ai-dev` first, then re-run this skill in update flow.

### Pre-check — what is already in place

Inspect the project so each per-artifact prompt below can include the current state:

- Does `CLAUDE.md` exist at the project root? If so, is it the bundled file (compare first ~10 lines against `vendor/spryker-sdk/ai-dev/data/agents/AGENTS.example.md`) or a custom project file?
- Does `.claude/rules/` exist and is it populated? Count files with `ls .claude/rules/ 2>/dev/null | wc -l`.

### Artifact 1 — Agents file (`CLAUDE.md`)

Three cases based on the pre-check:

**Case A — `CLAUDE.md` does not exist.** Ask: *"Add `CLAUDE.md` to the project root from the bundled template?"* If yes, copy:

```bash
cp vendor/spryker-sdk/ai-dev/data/agents/AGENTS.example.md CLAUDE.md
```

**Case B — `CLAUDE.md` exists and matches the bundled file** (first ~10 lines identical → user previously installed it). Ask: *"`CLAUDE.md` looks like a previous bundled install. Overwrite with the current bundled version, or keep as-is?"* On overwrite, run the `cp` above.

**Case C — `CLAUDE.md` exists and is custom** (project-specific content not from the bundled template). Offer THREE choices, not two:

1. **Keep mine (skip)** — leave the file untouched.
2. **Overwrite with bundled** — replace; the user's custom content is lost. Recommend this only when the user explicitly says they want a clean reset.
3. **Merge — append bundled sections that aren't already present** — recommended default. Read both files; identify section headings (`#` and `##`) in the bundled file that do NOT exist in the user's file; append those sections at the end of the user's file under a `# Spryker AI Dev — bundled additions` divider. Do NOT attempt to merge prose paragraphs or rewrite existing sections — only append whole sections the user lacks. Surface the list of sections you appended so the user can review.

Typical sections worth appending from the bundled `AGENTS.example.md` that custom project files usually lack: **Commands cheatsheet** (docker/sdk one-liners), **Layer Directory Structures** (Zed/Yves/Glue/Client/Service/Shared trees), **Component Rules** (Transfer Object suffixes, Entity, Entity Manager, Facade, Mapper/Expander/Hydrator, Gateway Controller, Model, Persistence Schema, Provider/Router, Zed Stub, Widget, Theme, Repository, Module Configuration, Layout), **Abstract Classes Reference** tables.

### Artifact 2 — Rules

Ask: *"Add the Spryker rules into `.claude/rules/`?"*

If `.claude/rules/` already exists and contains files, additionally ask: *"`.claude/rules/` already has N files. Overwrite same-named files with the bundled versions, merge (add only missing files), or cancel?"* and offer three options:

- **Overwrite** → copy all bundled rules, replacing existing same-named files.
- **Merge** → copy only the rule files that are missing in the target; keep existing files untouched.
- **Cancel** → skip this artifact.

Prefer the package's setup command when available:

```bash
# Check if the package ships a non-interactive setup that handles rules:
docker/sdk cli console ai-dev:setup --help 2>&1 | head -20
```

Otherwise fall back to direct copy from the project's vendor:

```bash
mkdir -p .claude/rules

# Overwrite mode (replace same-named files)
cp vendor/spryker-sdk/ai-dev/data/rules/*.md .claude/rules/

# Merge mode (only copy missing files)
for f in vendor/spryker-sdk/ai-dev/data/rules/*.md; do
  target=".claude/rules/$(basename "$f")"
  test -f "$target" || cp "$f" "$target"
done

# Report counts
echo "rules: $(ls .claude/rules/ | wc -l | tr -d ' ') files total"
```

### Rules for running this step as a whole

- Ask in the order above (agents file → rules). Do not batch the two questions into one combined prompt — each artifact is a separate decision.
- Only act on the artifacts the user approved.
- Each operation must complete cleanly before moving to the next.
- If a copy fails (permission denied, source missing), surface the actual error verbatim and stop — do not retry, do not auto-recover, do not proceed to the next artifact silently.
- If the user said no to both, skip the rest of Step 5 and report that in the Final report.


## Final report

In **3–5 lines**, tell the user:

- What was installed (package + version).
- Which file was edited and how many consoles were added (state the *actual* file edited — Pyz or the project-namespace override).
- That the MCP server is registered under the project root folder name (state the actual name used).
- That the user must **restart Claude Code** (or open a new session in this project directory) for the Spryker MCP tools to become available in-session — the running session will not pick them up automatically.
- The outcome of Step 5 — list each of the two artifacts (`CLAUDE.md`, rules) with one of: `added`, `overwritten`, `merged: <description of what was appended>`, `skipped (user declined)`, `skipped (already present)`, or `failed: <one-line reason>`.

Do **not** print a long summary or restate the steps. The user can read the diff.


## What to refuse / escalate

- If `composer require` fails due to a version conflict, surface the conflict — do **not** force-install with `--ignore-platform-reqs` or `-W` without asking.
- If `ConsoleDependencyProvider.php` is missing or has a structure you cannot safely parse, stop and ask the user where to wire the commands. Do not invent a new file.
- If `claude mcp add` is unavailable (older Claude Code CLI), tell the user and offer the manual JSON snippet for their MCP config file instead of failing silently.
