---
name: spryker-import-tools
description: "Reference for csv.php and validate.php: edit and validate data-import CSVs and manifests (gate, preflight, refs). Use for the mechanics of a command; project-data decides what changes."
---

# spryker-import-tools

Boundary: for WHAT data to change (add, adapt, reduce, clean up, an ad-hoc CSV edit), use `project-data`; come here for the mechanics of a command.

Two self-contained PHP scripts under `scripts/`, both concept-free (they know nothing about stores/locales/currencies — **you** decide what to do; they do it correctly and print JSON):
- **`csv.php`** — RFC-4180 CSV manipulation.
- **`validate.php`** — pre-boot consistency checks (its own CSV reader; no dependency on csv.php).

**Why use them instead of shell tools or editing by hand:** Spryker CSVs contain multi-line quoted fields (e.g. `cms_page.csv`), so `cut`/`awk`/`sed` corrupt them; and a single bad import row aborts the whole 30–60 min install, which these checks catch statically in seconds. Bulk work in-context burns tokens — the scripts return a terse verdict instead.

## Run

`php <path>/scripts/csv.php <command> <file> [options]` and `php <path>/scripts/validate.php <check> [options]` (host php, or `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php …` if there's no host php). Both print JSON (`{status, …}`, exit `0`/`1`/`2`); read-type commands take `--plain` for clean lines. **Prefer reading the output / the exit code directly over piping through `python`/`ruby`/`jq`** — it's unnecessary here and prompts; the direct read (or `--quiet` + exit code for validate) is the quieter path.

## Invocation & command discipline (authoritative — the one copy; consumer skills summarize and point here)

These rules keep unattended work quiet (no needless permission prompts) and safe. Every skill that drives these tools follows them.

**Locate the scripts:** `<skill-dir>/scripts/csv.php` and `<skill-dir>/scripts/validate.php`, where `<skill-dir>` is the path reported as `Base directory for this skill:` when this skill loaded — that line is correct for every install. Docker `php:8.3-cli` fallback per the Run section if there is no host php.

**Invoke by the literal path from the project cwd — never `cd`, never a shell variable.** `$CSV`/`$VALIDATE` in the consumer skills are shorthand for that literal path — resolve it once *in reasoning* and substitute it inline in every call. A `cd` prefix, an assignment prefix (`CSV=… php …`), or a multi-line assign-then-use all miss the allowlist and prompt on every call.

**One simple command per Bash call — no shell operators.** `cd`, `;`, `|`, `&&`, `||`, `$(…)`, backticks, `for`/`while`, redirects, env-prefixes and multi-line commands can never be allowlisted — they prompt regardless. Instead:
- **One op over many files = one command:** list all files before the flags and add `--in-place` (`set`/`replace`/`scale`/`drop-columns`/`filter`/`delete` all take many files); inspect many with one `columns`/`distinct`/`count <f1> <f2> … --plain`. Never a shell loop.
- **Count with the tool** — `rowCount`/`matchedRows` from the JSON, or `csv count --plain`; never `| grep -c`/`| wc -l`.
- **Explore with the built-in file read/search tools** — never bash `ls`/`find`/`grep`/`cat`/`head`/`sed`, and never bundle inspections with `; echo …`. Those tools are not Bash, so they never prompt.
- **Config/YAML edits use the built-in file edit tool** (anchored, format-preserving) — never regenerate a file with `python`/`ruby`/`sed`.
- `rm`/`rm -rf` are allowlisted where the harness allowlists them. Delete only what git can restore, ignored build output, or a file this run created under `data/import`, `config`, `src`, `frontend`, `public` or `tests` (never under `.ai-dev/`); surface every deletion as an explicit step. Anything else stays in place and is listed for the person in the final report, never forced.

**Project files change through Edit/Write or the php tools (`csv.php`, `gate --save`) — never a shell heredoc (`<<'EOF'`), `sed -i`, or a `>` redirect aimed at a path under the project root.** Edit/Write show each change as a reviewable diff and keep the harness's read-before-write check; a file written from Bash skips both. A redirect also truncates its target before the command runs: `php … gate > .ai-dev/gate-baseline.json` empties the baseline, the gate reads that empty baseline and saves a falsely clean report — use `php <VALIDATE> gate --save <path>` (below) instead. This rule takes precedence over a general preference for `cat`/`sed`/heredocs in file changes.

**A negative finding requires a command that succeeded.** "Searched and found nothing" and "the search did not run" print the same empty output; the shell error is one line and scrolls past. Under zsh `--include=*.php`, `*.{php,twig}`, `**/*.csv` are globbed before the tool runs — `grep -rn '…' src/Pyz/Zed/DataImport/ --include=*.php` dies with `no matches found`, exit non-zero, and reads as "no importer exists". Before writing "X does not exist": confirm exit `0` (prefer the built-in Grep/file-search tools, which cannot glob-fail); quote glob arguments in any Bash search (`--include='*.php'`); and corroborate a negative the next step depends on with a second search of a different shape (`find` vs `grep`, package listing vs source scan, Glob vs Grep).

**Before creating a project override, confirm the target path does not already exist.** Before `Write` (or any create) to a `src/<Ns>/…` path, list the target directory (Glob `src/<Ns>/…/<component>/*`) — a sibling `index.ts`/`.scss` means the override already exists and is probably committed with project logic in it (a generated template written over an existing override shows as ` M` in `git status`, not `??`). Prefer `Edit` on the existing file so the harness's read-before-write guard applies; a heredoc bypasses it. Recovery if it happens: `git checkout HEAD -- <file>`, then merge.

**Staging is the person's decision.** Leave changes unstaged and list the changed files in the report; run `git add <path>` only when the person asks. `.ai-dev/` and the harness's installed-artifact subtrees belong in the developer's ignore rules, not in a commit.

**Destructive-operation policy — the one copy; every other skill points here.** `csv filter`/`delete --in-place` and header-only truncation rewrite/empty files *without* prompting on their own, and `docker/sdk reset`/`clean-data` can wipe DB + search volumes (git does not restore those). So:
- **Always preview and announce.** Before any in-place data removal/truncation or any DB/volume drop, **preview it** (`filter`/`delete` with no `--out`/`--in-place` reports `matchedRows` without writing) and tell the person in one plain line what will be removed — even when the allowlist would let it through silently. Never run a destructive step unannounced.
- **Ask only when real data is at stake that git cannot restore** — a DB/volume drop on a project that already carries real data, or files outside git — **and the confirmed answer set did not already decide it.** Questions where nothing can be lost make approvals routine, so the question is reserved for this case.
- **Everything else proceeds after the announcement:** file edits git can restore; first setup (`boot-and-verify` not yet `done`) and demo clones, whose DB holds only demo data the next boot re-creates; a removal the confirmed answer set already decided (`answers_confirmed_at` — e.g. a demo's `sample_products`, D4 `data.existing_data`, `data.mode: clean`) — log it and go; and trusted rebuilds (`standing_approval: { scope: rebuilds }`, in the person's own words).
Before a rebuild on a project with real data, ask once; a deletion git cannot restore stays in place and goes on the list for the person. (`reset`/`clean-data` weight + the hand-to-developer escape hatch: `boot-and-verify` §3b.)

---

# Commands

`php <path>/scripts/csv.php --help` and `php <path>/scripts/validate.php --help` list every command with its synopsis, the output format and the exit codes. `--help <command>` prints the full explanation of one command, its flags and a worked example. Read the help of a command before its first use in a run; do not guess flags.

Rules that apply to every run (the help of each named command has the detail):
- Files are positional and come before the flags. Batch many files in one command with `--in-place`; never a shell loop. Count with `rowCount`/`matchedRows` or `count --plain`, never `| grep -c`.
- Run `gate` yourself before any `docker/sdk reset|clean-data|up|console data:import` and stop on a gating finding. Capture the baseline on the untouched clone with `gate --save <path>`, never `gate > file`. Use `gate --strict` for the project-data consolidation close-out and for `done`.
- `manifest-refs` is the first net, not full referential integrity: run `refs --ref-file` for each relation whose parent set the project replaced.
- Run `sku-coverage` after every product add. Record `inventory` in the evidence file and classify every line before `done`.

Concept-free by design: new demoshop files/entities need no new code — point the checks at them. A green boot is still the correctness authority; these are the cheap early net. **If a tool cannot do something, a fallback is fine — but prefer the tool** (`awk`/`sed`/`python` corrupt multi-line quoted fields and prompt).

## Data & references — the maintained literals

Two files hold every demo-data literal the consumer skills rely on, so a demo-data bump is one diff here instead of a hunt across prose files. `known-set` keeps both honest.

- **`data/entity-map.yml`** — one row per shipped `data_entity`: its source **filename** (which often differs from the entity name — that mismatch is why the file exists), its **class** (`structural` = theme-independent plumbing kept regardless of catalog; `content` = theme-dependent demo data; `unclassified` = undecided, blocks boot), and, for structural rows, the **`why`**. The authoritative structural keep-set the `clean`/`minimal-baseline` keep-lists derive from.
- **`references/demo-facts.md`** — the concrete **identifiers** (a real SKU pair, the `cms-block-email--` prefix + `block_key` column, the shipped product-label + navigation keys, the `shop_ui` colour keys, the manifest families), each paired with the command that re-derives it. **No record counts** — those are stated as their measuring command, never a stored number.
