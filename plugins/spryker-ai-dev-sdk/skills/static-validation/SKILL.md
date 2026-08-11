---
name: static-validation
description: >
  Run Spryker static analysis over only the code that changed versus a base branch — PHP
  (phpcbf, phpcs, phpmd/architecture-sniffer, phpstan) AND frontend (eslint, stylelint, prettier
  for js/ts/scss/css) — after implementing or editing code, during project development, before
  commit, or as an interim check while iterating. Trigger on "validate", "lint", "check code",
  "run static analysis", "fix phpcs/phpstan", "run QA on changes", "static check the diff",
  "validate my changes", "run static analysis on what I changed", "lint the changed modules",
  "check js/css/scss I changed", "check code vs master/main", "static-check the branch". Works
  from any git worktree, auto-detects or takes an explicit base branch (master, main,
  or any ref), validates only added/changed files (not the all-files globs in
  package.json), and can group PHP either by individually changed files (files scope) or by every
  full Spryker MODULE that has a changed file (module scope — detects changed modules, not just
  files, and validates the whole module).
---

# static-validation

Static analysis of **only what changed** against a base branch. Wraps the same tools the
project's Spryker QA / `package.json` use — **PHP**: `phpcbf`, `phpcs`, `phpmd`
(architecture-sniffer), `phpstan`; **frontend**: `eslint`, `stylelint`, `prettier` — all
executed inside the container via `docker/sdk cli`.

It replaces fixed, single-base validation scripts with a single flexible engine:

- **Any base branch** — `--base <ref>`, or auto-detected (`master` → `main` →
  remote default). Handles the diff via merge-base (`base...HEAD`) plus uncommitted working-tree
  edits and brand-new untracked files, so in-progress work is fully covered.
- **Worktree-aware** — resolves the current worktree's root with `git rev-parse --show-toplevel`
  for file/diff detection, and locates `docker/sdk` in the **main** working tree via
  `git rev-parse --git-common-dir` (a linked worktree doesn't contain the SDK-provided,
  untracked `docker/sdk`). Tools therefore run correctly from any worktree or subdirectory.
  In a linked worktree the container mounts the main checkout, so the run prints a notice: files
  changed only in the worktree must be committed/synced into the main tree to be analysed.
- **Module-level scope (PHP)** — `--scope module` detects the changed Spryker **modules**
  (`src/{Org}/{Layer}/{Module}`) and validates each whole module directory, not only the touched
  files. Files outside a module shape (e.g. `config/…`) stay as individual paths.
  Generated code (`src/Generated`, `src/Orm`) is always skipped.
- **Frontend, changed-only** — changed files are partitioned by extension and each linter runs on
  just those files (not the `**/*` globs in `package.json`): `.js/.ts` → eslint + prettier,
  `.scss/.css/.less` → stylelint + prettier, `.json/.html` → prettier. Configs are auto-loaded
  (`eslint.config.mjs`, `.stylelintrc.js`, `.prettierrc.json`; `.prettierignore` is honoured).
  `--scope` does not apply to frontend files (they are always validated individually).

## The command

**Locate the engine:** `.claude/skills/static-validation/scripts/static-check-diff.sh` (setup install,
relative to the project cwd) or `${CLAUDE_PLUGIN_ROOT}/skills/static-validation/scripts/static-check-diff.sh`
(plugin install). `$SCD` below is shorthand for whichever resolves — substitute it inline as a literal
path; **never set a shell variable and never `cd` to the skill directory**.

**Run it from the Spryker project root** (the directory containing `docker/sdk`), or point it at the
project with `--repo`. The project to validate comes from your working directory or `--repo` — **never**
from wherever this skill happens to be installed (plugin cache, marketplace install, git clone, or a
Composer `vendor/` tree). Running with the skill directory as cwd is refused with an actionable error.

```bash
# From the project root (preferred):
bash "$SCD" [options]

# From anywhere, naming the project explicitly:
bash "$SCD" --repo /path/to/project [options]
```

| Option | Meaning |
|---|---|
| `-r, --repo <path>` | Project root to validate. Defaults to the current working directory's git repo. Also reads `$STATIC_CHECK_REPO`. |
| `-b, --base <ref>` | Base branch/ref to diff against. Omit to auto-detect. Also reads `$STATIC_CHECK_BASE`. |
| `-s, --scope <mode>` | `files` (default) or `module`. PHP grouping only — frontend files are always individual. |
| `--tools <list>` | Comma subset of `phpcbf,phpcs,phpmd,phpstan,eslint,stylelint,prettier` (default: all). |
| `--fix` | Autofix where supported: `phpcbf` (always fixes), and `eslint --fix` / `stylelint --fix` / `prettier --write`. Also via `STATIC_CHECK_FIX=1`. |
| `--include-tests` | Also run phpcs/phpcbf on `/tests/` files (phpmd/phpstan always skip tests + config). |
| `--dry-run` | Print the resolved base, scope and per-tool paths; run no tools. |
| `-h, --help` | Usage. |

Exit code: `0` = clean (or dry-run / nothing changed), `1` = **code violations** found, `2` = usage
error, environment error, **or a tool that failed to run**.

**Exit 2 is not a code finding.** If a tool crashes before analysing anything (missing `src/Generated`,
missing `node_modules`, unresolvable config), the script says so explicitly and names the tool. Do **not**
report those as violations and do **not** try to "fix" code for them — resolve the environment and re-run:
`docker/sdk cli console transfer:generate` for `src/Generated`, `docker/sdk cli npm install` for
`node_modules`. An unknown `--tools` name and a base that resolves to the same commit as `HEAD` are also
exit 2 — both would otherwise analyse nothing and look like a pass.

### Environment overrides

Tool config and the base branch can be overridden via env vars (project defaults apply otherwise):

| Env var | Default | Overrides |
|---|---|---|
| `STATIC_CHECK_BASE` | auto-detect | Base branch/ref (same as `--base`). |
| `STATIC_CHECK_PHPCS_STANDARD` | `phpcs.xml` | phpcs/phpcbf ruleset. |
| `STATIC_CHECK_PHPMD_RULESET` | `phpmd.xml` | phpmd ruleset (project-level; falls back to `vendor/spryker/architecture-sniffer/src/Project/ruleset.xml` when absent). |
| `STATIC_CHECK_PHPMD_PRIORITY` | `4` | phpmd `--minimumpriority` (project-level baseline). |
| `STATIC_CHECK_PHPSTAN_CONFIG` | `phpstan.neon` | phpstan config file. |
| `STATIC_CHECK_PHPSTAN_LEVEL` | `6` | phpstan level (matches `phpstan.neon`). |
| `STATIC_CHECK_FIX` | `0` | `1` = autofix mode (same as `--fix`). |

| `STATIC_CHECK_REPO` | cwd's git repo | Project root to validate (same as `--repo`). |

```bash
STATIC_CHECK_PHPSTAN_LEVEL=6 bash "$SCD" --tools phpstan
```

## How to use it (agent workflow)

1. **Preview first** with `--dry-run` to confirm the base branch and the exact targets:
   ```bash
   bash "$SCD" --scope module --dry-run
   ```
   Report the detected base branch and the module/file list back to the user.

2. **Run the check.** All tools run by default and validate PHP + frontend changes together.
   - **Check-only (default for FE)**: `phpcbf` still auto-fixes PHP, but eslint/stylelint/prettier
     only *report*. Good as a read-only-ish diff check.
   - **Autofix everything**: add `--fix` — eslint/stylelint fix, prettier writes.
   - **Fully read-only** (touch nothing, e.g. reviewing someone else's diff): exclude the fixers,
     e.g. `--tools phpcs,phpmd,phpstan,eslint,stylelint,prettier` and do **not** pass `--fix`
     (note phpcbf mutates, so drop it: `--tools phpcs,phpmd,phpstan,eslint,stylelint,prettier`).
   ```bash
   # Validate every changed PHP module + all changed FE files:
   bash "$SCD" --scope module

   # Autofix the diff (PHP + FE):
   bash "$SCD" --fix
   ```

3. **Interpret results.** Report `phpcs`/`phpstan`/`phpmd`/`eslint`/`stylelint`/`prettier` findings
   using the project's absolute clickable-path format. If any autofixer ran, tell the user which
   files were modified (they appear in `git status`).

   **First check the exit code.** On exit `2` the script reports an environment failure, not code
   findings — say the gate could not run, name the tool and remediation it printed, and stop. Do not
   loop trying to "fix" a bootstrap fatal or a missing dependency by editing source.

4. **Base branch choice.** If the user names a base ("vs main", "against develop"), pass it with
   `--base`. Otherwise let auto-detect run and state which base it picked.

## Notes & caveats

- Runs against a **running** Spryker environment (`docker/sdk cli`). If containers are down,
  start them first (see the `spryker-docker-sdk` skill).
- **Frontend tools run wherever `node_modules` actually is.** eslint/stylelint must resolve the plugins
  their configs `extend`, so the script probes the container (`/data`) first, then falls back to the
  host, and errors out if neither has them. It invokes the project's own `node_modules/.bin/<tool>` —
  never bare `npx`, which would silently download a different major from the registry and crash on
  plugin resolution. Many Spryker projects install `node_modules` on the **host** only, so the host
  fallback is the normal path, not an edge case.
- Autofixers **modify files**: `phpcbf` always (whenever included); `eslint`/`stylelint`/`prettier`
  only with `--fix`. For a non-mutating check, drop `phpcbf` from `--tools` and omit `--fix`.
- Uses the project configs verbatim — `phpcs.xml`, `phpmd.xml` (priority 4), `phpstan.neon` (level 6),
  plus `eslint.config.mjs`, `.stylelintrc.js`, `.prettierrc.json`/`.prettierignore`.
- **phpmd runs BOTH rulesets, because CI does.** Spryker ships two and they are **disjoint, not
  nested**: `phpmd.xml` (project, priority 4) and `vendor/spryker/architecture-sniffer/src/ruleset.xml`
  (core, priority 2, ~26 rules that exist nowhere else — `FacadeReturnValueRule`, `FacadeArgumentsRule`,
  `SpyEntityUsageRule`, …). Spryker CI typically runs both unconditionally, so checking only the project
  ruleset lets a green local run coexist with a red CI "Run Architecture rules" step. The script runs the
  project ruleset, then the core one when that file exists. Pin a single pair with
  `STATIC_CHECK_PHPMD_RULESET` (+ `STATIC_CHECK_PHPMD_PRIORITY`), which disables the second run.
- Frontend changed-file detection covers committed, uncommitted, and brand-new untracked files.
  `--scope module` groups **PHP only**; FE files are always validated individually.
- The script is `bash 3.2` compatible (macOS default) — no `mapfile`/associative arrays.
