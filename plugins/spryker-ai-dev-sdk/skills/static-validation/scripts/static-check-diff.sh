#!/usr/bin/env bash
#
# static-check-diff.sh — Spryker static analysis of code changed vs a base branch.
#
# Runs PHP static tools (phpcbf, phpcs, phpmd, phpstan) AND frontend linters
# (eslint, stylelint, prettier) over only the PHP/JS/TS/CSS/SCSS that differs
# between the current branch/worktree and a base branch — added or changed only.
#
# Highlights:
#   * Flexible base branch  — master, main, or any ref (auto-detected).
#   * Worktree aware        — resolves the real repo root, works from any worktree.
#   * Module-level scope     — with --scope=module it detects changed Spryker MODULES
#                              (src/{Org}/{Layer}/{Module}) and validates each whole module,
#                              not only the individually-touched files.
#   * Frontend, changed-only — eslint/stylelint/prettier run on just the changed FE files,
#                              not the all-files globs in package.json.
#
# Usage:
#   static-check-diff.sh [options]
#
# Run this from your SPRYKER PROJECT ROOT (the directory containing docker/sdk),
# or point it at the project with --repo. The project to validate is NEVER
# inferred from where this script itself is installed.
#
# Options:
#   -r, --repo <path>      Project root to validate (default: the current working
#                            directory's git repo). Also reads $STATIC_CHECK_REPO.
#   -b, --base <ref>       Base branch/ref to diff against (default: auto-detect).
#   -w, --working-tree     Validate ONLY uncommitted + untracked changes against HEAD.
#                            No base ref involved. For a fresh branch whose work is not
#                            yet committed (the normal shape mid-build). Mutually
#                            exclusive with --base. The report states this mode was used.
#   -s, --scope <mode>     files | module   (default: files) — PHP module grouping only.
#                            files  — validate only the changed PHP files.
#                            module — validate every module that has a changed file.
#       --include-tests    Include /tests/ files in phpcs/phpcbf (default: excluded from phpmd/phpstan only).
#       --tools <list>     Comma subset of: phpcbf,phpcs,phpmd,phpstan,eslint,stylelint,prettier
#                            (default: all except phpcbf, which mutates source — opt in via --fix or by naming it).
#       --fix              Autofix where supported (phpcbf always; eslint/stylelint/prettier --fix/--write).
#       --dry-run          Print what would be validated, run nothing.
#   -h, --help             Show this help.
#
# Exit codes:
#   0  all selected tools passed (or dry-run / nothing to validate)
#   1  at least one tool reported violations
#   2  usage / environment error, or a tool failed to RUN (nothing was analysed)
#
set -uo pipefail

# ----------------------------------------------------------------------------
# Defaults
# ----------------------------------------------------------------------------
BASE_REF=""
WORKING_TREE=0
SCOPE="files"
INCLUDE_TESTS=0
DRY_RUN=0
FIX="${STATIC_CHECK_FIX:-0}"
REPO_OVERRIDE="${STATIC_CHECK_REPO:-}"
# PHP: phpcbf,phpcs,phpmd,phpstan   Frontend: eslint,stylelint,prettier
# phpcbf REWRITES source files, so it is deliberately NOT in the default set — a tool whose
# job is "validate my changes" must not mutate the working tree on a bare invocation. It is
# pulled in by --fix, or selected explicitly via --tools phpcbf. Still in KNOWN_TOOLS so both
# of those keep working.
TOOLS="phpcs,phpmd,phpstan,eslint,stylelint,prettier"
KNOWN_TOOLS="phpcbf phpcs phpmd phpstan eslint stylelint prettier"

# Tool config — overridable via environment, with project defaults below.
#   STATIC_CHECK_PHPCS_STANDARD   phpcs/phpcbf ruleset       (default: phpcs.xml)
#   STATIC_CHECK_PHPMD_RULESET    phpmd ruleset               (default: phpmd.xml, project-level)
#   STATIC_CHECK_PHPMD_PRIORITY   phpmd minimum priority      (default: 4, project-level)
#   STATIC_CHECK_PHPSTAN_CONFIG   phpstan config file         (default: phpstan.neon)
#   STATIC_CHECK_PHPSTAN_LEVEL    phpstan level               (default: 6, matches phpstan.neon)
#   STATIC_CHECK_FIX              1 = autofix (phpcbf always fixes; eslint/stylelint/prettier
#                                 switch to --fix/--write)   (default: 0 = check only for FE)
PHPCS_STANDARD="${STATIC_CHECK_PHPCS_STANDARD:-phpcs.xml}"
# Project-level architecture ruleset. Spryker documents two distinct rulesets:
#   - project development: phpmd.xml (or the Project/ruleset.xml it imports), priority 4
#   - core/framework dev:  vendor/spryker/architecture-sniffer/src/ruleset.xml, priority 2
# This skill validates project code (src/Pyz, src/Demo, …), so it uses the project ruleset.
# See https://docs.spryker.com/docs/dg/dev/sdks/sdk/development-tools/architecture-sniffer
PHPMD_RULESET="${STATIC_CHECK_PHPMD_RULESET:-phpmd.xml}"
PHPMD_PRIORITY="${STATIC_CHECK_PHPMD_PRIORITY:-4}"
PHPSTAN_CONFIG="${STATIC_CHECK_PHPSTAN_CONFIG:-phpstan.neon}"
PHPSTAN_LEVEL="${STATIC_CHECK_PHPSTAN_LEVEL:-6}"

# ----------------------------------------------------------------------------
# Helpers
# ----------------------------------------------------------------------------
log()  { printf '%s\n' "$*"; }
info() { printf '\033[0;36m%s\033[0m\n' "$*"; }
warn() { printf '\033[0;33m%s\033[0m\n' "$*" >&2; }
err()  { printf '\033[0;31m%s\033[0m\n' "$*" >&2; }

usage() {
    # Print the leading comment banner: every '#' line from line 3 up to (but not
    # including) the `set -uo pipefail` line. Derived, not a hardcoded range, so
    # editing the banner cannot silently truncate --help.
    sed -n '3,/^set -uo pipefail/p' "$0" | sed '/^set -uo pipefail/d' | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

# ----------------------------------------------------------------------------
# Parse arguments
# ----------------------------------------------------------------------------
while [ $# -gt 0 ]; do
    case "$1" in
        -r|--repo)          REPO_OVERRIDE="${2:-}"; shift 2 ;;
        --repo=*)           REPO_OVERRIDE="${1#*=}"; shift ;;
        -b|--base)          BASE_REF="${2:-}"; shift 2 ;;
        --base=*)           BASE_REF="${1#*=}"; shift ;;
        -w|--working-tree)  WORKING_TREE=1; shift ;;
        -s|--scope)         SCOPE="${2:-}"; shift 2 ;;
        --scope=*)          SCOPE="${1#*=}"; shift ;;
        --include-tests)    INCLUDE_TESTS=1; shift ;;
        --tools)            TOOLS="${2:-}"; shift 2 ;;
        --tools=*)          TOOLS="${1#*=}"; shift ;;
        --fix)              FIX=1; shift ;;
        --dry-run)          DRY_RUN=1; shift ;;
        -h|--help)          usage 0 ;;
        *)                  err "Unknown option: $1"; usage 2 ;;
    esac
done

case "$SCOPE" in
    files|module) ;;
    *) err "Invalid --scope '$SCOPE' (expected: files | module)"; exit 2 ;;
esac

# Validate --tools. An unrecognised name would otherwise match no tool block, run
# nothing, and still print "✓ Static analysis passed" — a silent false green.
if [ -z "$TOOLS" ]; then
    err "--tools was given an empty list (expected any of: $KNOWN_TOOLS)"
    exit 2
fi
_saved_ifs="$IFS"; IFS=','
for _t in $TOOLS; do
    case " $KNOWN_TOOLS " in
        *" $_t "*) ;;
        *) IFS="$_saved_ifs"
           err "Unknown tool '$_t' in --tools (expected any of: $KNOWN_TOOLS)"
           exit 2 ;;
    esac
done
IFS="$_saved_ifs"

# --fix means "also fix PHP", so pull the PHP fixer in. phpcbf is not in the default
# TOOLS set (it rewrites files), so without this --fix would silently stop fixing PHP.
if [ "$FIX" -eq 1 ]; then
    case ",$TOOLS," in
        *,phpcbf,*) ;;
        *) TOOLS="phpcbf,$TOOLS" ;;
    esac
fi

# ----------------------------------------------------------------------------
# Resolve repo root (worktree-aware) and cd into it.
#
# The project to validate comes from --repo/$STATIC_CHECK_REPO, or from the
# CALLER'S working directory — never from where this script is installed. This
# skill ships as a Claude Code plugin, so its own location may be a plugin cache,
# a marketplace install, a git clone, or a Composer vendor/ tree; any of those
# would resolve to the wrong repo (or none at all).
# `git rev-parse --show-toplevel` returns the CURRENT worktree's root — correct
# for diffs and file paths, which are relative to where the user is working.
# ----------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)"

if [ -n "$REPO_OVERRIDE" ]; then
    if [ ! -d "$REPO_OVERRIDE" ]; then
        err "--repo path does not exist or is not a directory: $REPO_OVERRIDE"
        exit 2
    fi
    cd "$REPO_OVERRIDE" || { err "Cannot cd to --repo path: $REPO_OVERRIDE"; exit 2; }
fi

if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
    if [ -n "$REPO_OVERRIDE" ]; then
        err "--repo path is not inside a git repository: $REPO_OVERRIDE"
    else
        err "Not inside a git repository."
        err "cd to your Spryker project root (the directory containing docker/sdk),"
        err "or pass --repo <project-root>."
    fi
    exit 2
fi

# Refuse to validate the SKILL'S OWN checkout. This fires when the resolved repo is
# the plugin/SDK repo itself — i.e. the caller ran with the skill dir as cwd and the
# skill lives in its own git repo (a dev clone, or a Composer install that kept .git).
#
# Note this is deliberately NOT a path-containment test: a Composer install puts the
# plugin under the project's own vendor/, so the script legitimately lives inside the
# project it validates. Only a DISTINCT enclosing repo means we resolved the wrong one.
_skill_repo="$(cd "$SCRIPT_DIR" 2>/dev/null && git rev-parse --show-toplevel 2>/dev/null)"
if [ -n "$_skill_repo" ] && [ "$_skill_repo" = "$REPO_ROOT" ] && [ ! -e "$REPO_ROOT/docker/sdk" ]; then
    err "Resolved repo root ($REPO_ROOT) is this skill's own checkout, not a Spryker project."
    err "You are running from the skill's install location."
    err "cd to your project root (the directory containing docker/sdk),"
    err "or pass --repo <project-root>."
    exit 2
fi

cd "$REPO_ROOT" || { err "Cannot cd to repo root: $REPO_ROOT"; exit 2; }

# ----------------------------------------------------------------------------
# Resolve the MAIN working tree — the one that holds `docker/sdk`.
# `docker/sdk` is SDK-provided and NOT tracked in git, so a linked worktree does
# not contain it. `git rev-parse --git-common-dir` points at the shared .git of
# the main checkout; its parent is that main working tree. We run docker/sdk from
# there while still validating the current worktree's file paths (paths are the
# same repo-relative strings, and the container mounts the main checkout).
# In the main working tree, this resolves back to REPO_ROOT.
# ----------------------------------------------------------------------------
_common_git_dir="$(git rev-parse --git-common-dir 2>/dev/null)"
case "$_common_git_dir" in
    /*) ;;                                   # already absolute
    *)  _common_git_dir="$REPO_ROOT/$_common_git_dir" ;;
esac
MAIN_ROOT="$(cd "$_common_git_dir/.." 2>/dev/null && pwd)"
[ -z "$MAIN_ROOT" ] && MAIN_ROOT="$REPO_ROOT"

# Locate the docker/sdk launcher in the main working tree.
DOCKER_SDK="$MAIN_ROOT/docker/sdk"
if [ ! -x "$DOCKER_SDK" ]; then
    # Fall back to the current root (covers non-standard layouts), then PATH.
    if [ -x "$REPO_ROOT/docker/sdk" ]; then
        DOCKER_SDK="$REPO_ROOT/docker/sdk"
    elif command -v docker/sdk >/dev/null 2>&1; then
        DOCKER_SDK="docker/sdk"
    else
        err "Cannot find an executable 'docker/sdk' launcher."
        err "Looked in main working tree: $MAIN_ROOT/docker/sdk"
        [ "$MAIN_ROOT" != "$REPO_ROOT" ] && err "and current worktree: $REPO_ROOT/docker/sdk"
        err ""
        err "Two possible causes:"
        err "  1. Wrong repo — the resolved root ($REPO_ROOT) is not a Spryker project."
        err "     cd to your project root, or pass --repo <project-root>."
        err "  2. Right repo, environment down — start/rebuild it (see spryker-docker-sdk skill)."
        exit 2
    fi
fi

# In a linked worktree the container mounts the MAIN checkout, not this worktree.
# Files changed only here that are not present in the main tree/container can't be
# analysed. Surface this so results are never silently based on the wrong copy.
if [ "$MAIN_ROOT" != "$REPO_ROOT" ]; then
    warn "Linked worktree detected."
    warn "  worktree (files/diff): $REPO_ROOT"
    warn "  docker/sdk + container mount: $MAIN_ROOT"
    warn "  Tools run against the main checkout's mount; commit/sync your worktree"
    warn "  changes into it if analysis of only-in-worktree files looks off."
fi
info "Repo root (worktree): $REPO_ROOT"

# ----------------------------------------------------------------------------
# Determine the base ref.
# Priority: --base flag > env STATIC_CHECK_BASE > auto-detect.
# Auto-detect order: master, main, origin/HEAD default branch.
# We only pick a candidate that actually resolves in this repo.
# ----------------------------------------------------------------------------
ref_exists() { git rev-parse --verify --quiet "$1^{commit}" >/dev/null 2>&1; }

if [ "$WORKING_TREE" -eq 1 ] && [ -n "$BASE_REF" ]; then
    err "--working-tree and --base are mutually exclusive: working-tree mode diffs against HEAD only."
    exit 2
fi

if [ "$WORKING_TREE" -eq 1 ]; then
    _wt_changes="$(git diff --name-only --diff-filter=d HEAD 2>/dev/null; git ls-files --others --exclude-standard 2>/dev/null)"
    if [ -z "$_wt_changes" ]; then
        if [ -n "$(git diff --name-only HEAD 2>/dev/null)" ]; then
            err "--working-tree: the only working-tree changes are deletions — there is nothing lintable to validate."
        else
            err "--working-tree: the working tree is clean — there are no uncommitted or untracked changes to validate."
        fi
        exit 2
    fi
    info "Mode: WORKING TREE — validating uncommitted + untracked changes against HEAD."
    info "Committed history is NOT analysed in this mode; the result covers only the working tree."
fi

if [ "$WORKING_TREE" -eq 0 ] && [ -z "$BASE_REF" ] && [ -n "${STATIC_CHECK_BASE:-}" ]; then
    BASE_REF="$STATIC_CHECK_BASE"
fi

if [ "$WORKING_TREE" -eq 0 ] && [ -z "$BASE_REF" ]; then
    for candidate in master main; do
        if ref_exists "$candidate"; then BASE_REF="$candidate"; break; fi
    done
fi

# Fall back to the remote's default branch (e.g. origin/HEAD -> origin/main).
if [ "$WORKING_TREE" -eq 0 ] && [ -z "$BASE_REF" ]; then
    default_remote_branch="$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null)"
    if [ -n "$default_remote_branch" ] && ref_exists "$default_remote_branch"; then
        BASE_REF="$default_remote_branch"
    fi
fi

if [ "$WORKING_TREE" -eq 0 ]; then
    if [ -z "$BASE_REF" ]; then
        err "Could not auto-detect a base branch. Pass one with --base <ref>, or use --working-tree"
        err "to validate only uncommitted + untracked changes."
        exit 2
    fi

    if ! ref_exists "$BASE_REF"; then
        err "Base ref '$BASE_REF' does not resolve in this repository."
        err "Available local branches:"; git branch --format='  %(refname:short)' >&2
        exit 2
    fi

    # Guard: comparing a branch against itself yields no committed diff, so a run that
    # analysed nothing would be reported as "passed". Compare resolved SHAs — this also
    # catches a differently-named branch sitting on the same commit.
    current_ref="$(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
    if [ "$(git rev-parse "$BASE_REF" 2>/dev/null)" = "$(git rev-parse HEAD 2>/dev/null)" ]; then
        err "Base '$BASE_REF' resolves to the same commit as HEAD — there is nothing to diff."
        _guard_wt="$(git diff --name-only --diff-filter=d HEAD 2>/dev/null; git ls-files --others --exclude-standard 2>/dev/null)"
        if [ -n "$_guard_wt" ]; then
            err "The working tree DOES carry uncommitted/untracked changes. To validate exactly"
            err "those (a fresh branch mid-build is the normal case), re-run with --working-tree."
        else
            err "Pass an explicit --base <upstream-ref>."
        fi
        exit 2
    fi
    info "Comparing: ${current_ref:-<detached>}  vs  base '$BASE_REF'"
else
    current_ref="$(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
    info "Comparing: working tree of ${current_ref:-<detached>}  vs  HEAD (no base ref)"
fi

# ----------------------------------------------------------------------------
# Collect changed files (existing on disk, added/modified — not deleted).
# Uses the merge-base (three-dot) so we only see what THIS branch changed,
# not commits that landed on the base after we branched. Also picks up
# uncommitted working-tree edits and brand-new untracked (non-ignored) files.
# ----------------------------------------------------------------------------
# Collected as a newline-delimited string then split — keeps us bash-3.2 safe
# (no mapfile, no associative arrays: macOS ships bash 3.2).
_raw_changes="$(
    # Committed diff vs base — skipped entirely in --working-tree mode (no base ref).
    [ "$WORKING_TREE" -eq 0 ] && git diff --name-only --diff-filter=d "${BASE_REF}...HEAD" 2>/dev/null
    # Also include uncommitted working-tree changes so in-progress edits are covered.
    git diff --name-only --diff-filter=d HEAD 2>/dev/null
    # And brand-new untracked files (not git-ignored) — freshly created code during
    # development is invisible to `git diff`, but still needs validating.
    git ls-files --others --exclude-standard 2>/dev/null
)"

# Partition changed files by kind:
#   changed_php   — *.php                     (phpcbf/phpcs/phpmd/phpstan)
#   changed_jsts  — *.js *.ts                 (eslint + prettier)
#   changed_style — *.scss *.css *.less       (stylelint + prettier)
#   changed_fmt   — *.json *.html + js/ts/style (prettier)
changed_php=()
changed_jsts=()
changed_style=()
changed_fmt=()
_seen_files=""
while IFS= read -r f; do
    [ -z "$f" ] && continue
    [ -f "$f" ] || continue
    case "$_seen_files" in *"|$f|"*) continue ;; esac
    _seen_files="${_seen_files}|$f|"
    case "$f" in
        *.php)                      changed_php+=("$f") ;;
        *.js|*.ts)                  changed_jsts+=("$f"); changed_fmt+=("$f") ;;
        *.scss|*.css|*.less)        changed_style+=("$f"); changed_fmt+=("$f") ;;
        *.json|*.html)              changed_fmt+=("$f") ;;
    esac
done <<EOF
$_raw_changes
EOF

if [ "${#changed_php[@]}" -eq 0 ] && [ "${#changed_jsts[@]}" -eq 0 ] \
   && [ "${#changed_style[@]}" -eq 0 ] && [ "${#changed_fmt[@]}" -eq 0 ]; then
    if [ "$WORKING_TREE" -eq 1 ]; then
        warn "No changed PHP/JS/TS/CSS/SCSS files found in the working tree (vs HEAD)."
    else
        warn "No changed PHP/JS/TS/CSS/SCSS files found between '$BASE_REF' and HEAD (incl. working tree)."
    fi
    exit 0
fi

# ----------------------------------------------------------------------------
# Module detection.
# A Spryker module path looks like: src/{Org}/{Layer}/{Module}/...
#   Org    e.g. Pyz, Demo, CustomNamespace
#   Layer  e.g. Zed, Yves, Glue, Client, Service, Shared
# For any changed file under such a path we take the module root and validate
# the whole module directory. Files outside that shape (config/, tests/ root,
# etc.) are kept as individual paths.
# Generated/Orm code is skipped — it is auto-generated, not hand-written.
# ----------------------------------------------------------------------------
module_root_of() {
    # echoes the module root dir if $1 matches src/Org/Layer/Module/..., else nothing
    local p="$1"
    if [[ "$p" =~ ^(src/[^/]+/(Zed|Yves|Glue|Client|Service|Shared)/[^/]+)/ ]]; then
        printf '%s' "${BASH_REMATCH[1]}"
    fi
}

scope_paths=()
_seen_scope=""
_add_scope() {
    # append $1 to scope_paths if not already present
    local v="$1"
    [ -z "$v" ] && return
    case "$_seen_scope" in *"|$v|"*) return ;; esac
    _seen_scope="${_seen_scope}|$v|"
    scope_paths+=("$v")
}

if [ "${#changed_php[@]}" -gt 0 ]; then
    for f in "${changed_php[@]}"; do
        # Skip generated code entirely — it is not hand-written.
        case "$f" in src/Generated/*|src/Orm/*) continue ;; esac
        if [ "$SCOPE" = "module" ]; then
            root="$(module_root_of "$f")"
            if [ -n "$root" ] && [ -d "$root" ]; then
                _add_scope "$root"
            else
                _add_scope "$f"
            fi
        else
            _add_scope "$f"
        fi
    done
fi

# ----------------------------------------------------------------------------
# Report the plan (PHP scope + frontend files).
# ----------------------------------------------------------------------------
info ""
if [ "${#scope_paths[@]}" -gt 0 ]; then
    info "PHP scope: $SCOPE — ${#scope_paths[@]} target(s):"
    for p in "${scope_paths[@]}"; do log "  - $p"; done
    if [ "$SCOPE" = "module" ]; then
        info "(module scope validates every file under each module directory above)"
    fi
else
    info "PHP: no changed PHP targets."
fi

_report_fe() {
    local label="$1"; shift
    if [ "$#" -gt 0 ]; then
        info "$label — $# file(s):"
        local x; for x in "$@"; do log "  - $x"; done
    fi
}
[ "${#changed_jsts[@]}"  -gt 0 ] && _report_fe "JS/TS (eslint)"       "${changed_jsts[@]}"
[ "${#changed_style[@]}" -gt 0 ] && _report_fe "CSS/SCSS (stylelint)" "${changed_style[@]}"
[ "${#changed_fmt[@]}"   -gt 0 ] && _report_fe "Formatting (prettier)" "${changed_fmt[@]}"
:  # keep exit status clean for `set -e`-free flow

# Path sets:
#   phpcs/phpcbf — all scope paths (optionally excluding tests)
#   phpmd/phpstan — exclude tests and config always
# Test-path detection. Paths here come from `git diff --name-only`, i.e. they are
# REPO-RELATIVE with no leading slash — so patterns must anchor on `^` as well as
# `/`, otherwise a repo-root test tree (tests/PyzTest/...) never matches.
is_test_path() {
    [[ "$1" =~ (^|/)tests?/ ]] && return 0
    [[ "$1" =~ (^|/)[A-Za-z0-9]*Tests?/ ]] && return 0
    return 1
}

cs_paths=()
strict_paths=()   # for phpmd + phpstan
if [ "${#scope_paths[@]}" -gt 0 ]; then
    for p in "${scope_paths[@]}"; do
        if [ "$INCLUDE_TESTS" -eq 0 ] && is_test_path "$p"; then
            :  # excluded from cs when tests excluded
        else
            cs_paths+=("$p")
        fi
        # strict: never tests, never config
        if is_test_path "$p" || [[ "$p" =~ ^config/ ]]; then
            continue
        fi
        strict_paths+=("$p")
    done
    # NOTE: deliberately no "fall back to scope_paths" here. If excluding tests
    # empties cs_paths, the correct behaviour is to run no phpcs/phpcbf and say so —
    # silently re-including the excluded files would let phpcbf REWRITE them.
    if [ "${#cs_paths[@]}" -eq 0 ]; then
        warn "phpcs/phpcbf: all changed PHP paths are test files — skipped (use --include-tests to analyse them)."
    fi
fi

# ----------------------------------------------------------------------------
# Frontend path filtering.
#
# The ROOT eslint/stylelint/prettier configs describe the PROJECT's frontend only.
# Two kinds of file must not be handed to them:
#
#   1. Files inside a NESTED npm project (its own package.json above them, e.g.
#      tests/cypress-boilerplate/). That project ships its own eslint config and its
#      own conventions; linting its CommonJS tool-config with the root's browser/ESM
#      config yields bogus `no-undef` errors on module/require/__dirname — findings no
#      code change can fix, in files the project's own lint script never touches.
#   2. Test trees, unless --include-tests — matching the PHP side's behaviour, so
#      --include-tests governs BOTH halves of the gate rather than only PHP.
# ----------------------------------------------------------------------------
in_nested_npm_project() {
    _d="$(dirname "$1")"
    while [ "$_d" != "." ] && [ "$_d" != "/" ]; do
        if [ -f "$_d/package.json" ]; then return 0; fi
        _d="$(dirname "$_d")"
    done
    return 1
}

_fe_filter() {   # $1 = name of the array to filter, echoes the kept entries
    for _f in "$@"; do
        [ "$INCLUDE_TESTS" -eq 0 ] && is_test_path "$_f" && continue
        in_nested_npm_project "$_f" && continue
        printf '%s\n' "$_f"
    done
}

_fe_skipped=0
for _set in changed_jsts changed_style changed_fmt; do
    eval "_before=\${#$_set[@]}"
    _kept=()
    while IFS= read -r _k; do
        [ -n "$_k" ] && _kept+=("$_k")
    done <<EOF
$(eval "_fe_filter \"\${$_set[@]:-}\"")
EOF
    eval "$_set=(\"\${_kept[@]:-}\")"
    # A single empty element is bash 3.2's residue of an empty array — normalise it.
    eval "[ \${#$_set[@]} -eq 1 ] && [ -z \"\${$_set[0]}\" ] && $_set=()"
    eval "_after=\${#$_set[@]}"
    _fe_skipped=$(( _fe_skipped + _before - _after ))
done
if [ "$_fe_skipped" -gt 0 ]; then
    info "Frontend: skipped $_fe_skipped file(s) in nested npm projects or test trees"
    info "  (they have their own linter configs; the root config does not describe them)."
fi

if [ "$DRY_RUN" -eq 1 ]; then
    info ""
    info "[dry-run] Tools: $TOOLS"
    info "[dry-run] phpcs/phpcbf paths:  ${cs_paths[*]:-<none>}"
    info "[dry-run] phpmd/phpstan paths: ${strict_paths[*]:-<none>}"
    info "[dry-run] eslint paths:        ${changed_jsts[*]:-<none>}"
    info "[dry-run] stylelint paths:     ${changed_style[*]:-<none>}"
    info "[dry-run] prettier paths:      ${changed_fmt[*]:-<none>}"
    exit 0
fi

# ----------------------------------------------------------------------------
# Runner. All analysis runs inside the container via the main working tree's
# `docker/sdk cli`. It is invoked from MAIN_ROOT (where docker/sdk lives and
# what the container mounts as /data); the repo-relative file paths we pass are
# the same strings in either tree.
# ----------------------------------------------------------------------------
has_tool() { case ",$TOOLS," in *",$1,"*) return 0 ;; *) return 1 ;; esac; }

run() {
    info ""
    info "\$ docker/sdk cli $*"
    ( cd "$MAIN_ROOT" && "$DOCKER_SDK" cli "$@" )
}

overall_rc=0   # 1 = a tool reported code violations
env_rc=0       # 1 = a tool failed to RUN (nothing was analysed)
ran_any=0      # 1 = at least one tool was actually invoked
env_failures=""

# Classify a tool result. Static-analysis tools distinguish "found problems" from
# "could not run"; collapsing both into "violations" sends agents chasing phantom
# code findings that no edit can ever clear. $1=tool $2=exit code $3=captured output
classify() {
    local tool="$1" rc="$2" out="$3"
    ran_any=1
    [ "$rc" -eq 0 ] && return 0

    # Exit codes that always mean "could not run", per tool.
    case "$tool:$rc" in
        eslint:2|stylelint:78|prettier:2|phpcs:3|phpcbf:3)
            env_failures="${env_failures}${tool} "; env_rc=1; return 0 ;;
    esac
    # Output markers that mean the tool crashed before analysing anything.
    case "$out" in
        *ERR_MODULE_NOT_FOUND*|*"Cannot find package"*|*ConfigurationError*|\
        *"while loading bootstrap file"*|*"Failed opening required"*|\
        *"could not be found"*|*"does not exist"*)
            env_failures="${env_failures}${tool} "; env_rc=1; return 0 ;;
    esac

    overall_rc=1
}

# Run a tool, echo its output live, and classify the result.
run_tool() {
    local tool="$1"; shift
    local out rc
    out="$(run "$@" 2>&1)"; rc=$?
    printf '%s\n' "$out"
    classify "$tool" "$rc" "$out"
}

# --- PHP -------------------------------------------------------------------
if has_tool phpcbf && [ "${#cs_paths[@]}" -gt 0 ]; then
    warn "phpcbf WILL REWRITE the files/directories listed above — it is a fixer, not a checker."
    # phpcbf exits 1 when it FIXED something — that is success, not a violation.
    out="$(run vendor/bin/phpcbf --standard="$PHPCS_STANDARD" -p -s --extensions=php "${cs_paths[@]}" 2>&1)"; rc=$?
    printf '%s\n' "$out"
    ran_any=1
    # 0 = nothing to fix, 1 = fixed some, 2 = fixed some + some unfixable, 3 = could not run.
    # Anything else is a crash, and a crash mid-fix can leave files partially rewritten.
    case "$rc" in
        0|1|2) ;;
        3)     env_failures="${env_failures}phpcbf "; env_rc=1 ;;
        *)     err "phpcbf exited unexpectedly (rc=$rc) — files may be partially fixed."
               env_failures="${env_failures}phpcbf "; env_rc=1 ;;
    esac
    case "$out" in
        *"PHP Fatal error"*|*"Allowed memory size of"*)
            env_failures="${env_failures}phpcbf "; env_rc=1 ;;
    esac
fi

if has_tool phpcs && [ "${#cs_paths[@]}" -gt 0 ]; then
    run_tool phpcs vendor/bin/phpcs --standard="$PHPCS_STANDARD" -p -s --extensions=php "${cs_paths[@]}"
fi

if has_tool phpmd; then
    if [ "${#strict_paths[@]}" -gt 0 ]; then
        # Fall back to the vendored project ruleset when the repo has no root phpmd.xml
        # (only when the default is in play — an explicit override is always respected).
        phpmd_ruleset="$PHPMD_RULESET"
        if [ -z "${STATIC_CHECK_PHPMD_RULESET:-}" ] && [ ! -f "$REPO_ROOT/$phpmd_ruleset" ]; then
            phpmd_ruleset="vendor/spryker/architecture-sniffer/src/Project/ruleset.xml"
            warn "phpmd: no phpmd.xml at repo root — falling back to $phpmd_ruleset"
        fi
        # phpmd takes a comma-separated list as a single arg.
        strict_csv="$(IFS=,; printf '%s' "${strict_paths[*]}")"
        run_tool phpmd vendor/bin/phpmd "$strict_csv" text "$phpmd_ruleset" --minimumpriority "$PHPMD_PRIORITY"

        # CI enforces the CORE architecture ruleset in addition to the project one,
        # and the two are disjoint (not nested) — a green project-only run can still
        # hit a red CI "Run Architecture rules" step. Run both unless pinned.
        core_ruleset="vendor/spryker/architecture-sniffer/src/ruleset.xml"
        if [ -z "${STATIC_CHECK_PHPMD_RULESET:-}" ] && [ -f "$MAIN_ROOT/$core_ruleset" ]; then
            run_tool phpmd vendor/bin/phpmd "$strict_csv" text "$core_ruleset" --minimumpriority 2
        fi
    elif [ "${#changed_php[@]}" -gt 0 ]; then
        warn "phpmd: no non-test/non-config paths to analyse — skipped."
    fi
fi

if has_tool phpstan; then
    if [ "${#strict_paths[@]}" -gt 0 ]; then
        run_tool phpstan vendor/bin/phpstan analyse "${strict_paths[@]}" -l "$PHPSTAN_LEVEL" -c "$PHPSTAN_CONFIG"
    elif [ "${#changed_php[@]}" -gt 0 ]; then
        warn "phpstan: no non-test/non-config paths to analyse — skipped."
    fi
fi

# --- Frontend (JS/TS/CSS/SCSS) --------------------------------------------
# Runs the same linters as package.json, but scoped to changed files only.
# eslint auto-loads eslint.config.mjs; stylelint auto-loads .stylelintrc.js;
# prettier auto-loads .prettierrc.json and honours .prettierignore.
# FIX=1 (via --fix or STATIC_CHECK_FIX=1) turns on autofix (eslint --fix,
# stylelint --fix, prettier --write); otherwise all three run in check mode.
#
# WHERE they run is decided at runtime, not assumed. eslint/stylelint must resolve
# the plugins their config `extends`, so they need a real node_modules. Depending on
# the project, that exists in the container (/data), on the host, or neither — a bare
# `npx` in a tree without it silently downloads a DIFFERENT major from the registry
# and crashes on plugin resolution, which then reads as "violations".

_fe_wanted=0
{ has_tool eslint    && [ "${#changed_jsts[@]}"  -gt 0 ]; } && _fe_wanted=1
{ has_tool stylelint && [ "${#changed_style[@]}" -gt 0 ]; } && _fe_wanted=1
{ has_tool prettier  && [ "${#changed_fmt[@]}"   -gt 0 ]; } && _fe_wanted=1

if [ "$_fe_wanted" -eq 1 ]; then
    fe_mode=""
    if ( cd "$MAIN_ROOT" && "$DOCKER_SDK" cli "test -d node_modules" ) >/dev/null 2>&1; then
        fe_mode="container"
    elif [ -d "$MAIN_ROOT/node_modules" ]; then
        fe_mode="host"
        warn "node_modules found on the host but not in the container — running frontend linters on the host."
    else
        err "No node_modules in the container (/data) or on the host ($MAIN_ROOT)."
        err "Install them (docker/sdk cli npm install, or npm ci on the host) before"
        err "running eslint/stylelint/prettier. Skipping frontend linters."
        env_failures="${env_failures}eslint/stylelint/prettier(no-node_modules) "
        env_rc=1
    fi

    # Invoke the project's OWN pinned binaries. Never bare `npx`, which would fetch
    # an arbitrary latest version when the local install is missing.
    run_fe() {
        local tool="$1"; shift
        local out rc
        if [ "$fe_mode" = "container" ]; then
            out="$(run "node_modules/.bin/$tool" "$@" 2>&1)"; rc=$?
        else
            info ""
            info "\$ (host) node_modules/.bin/$tool $*"
            out="$( cd "$MAIN_ROOT" && "./node_modules/.bin/$tool" "$@" 2>&1 )"; rc=$?
        fi
        printf '%s\n' "$out"
        # An "ignored because no matching configuration" finding means eslint did NOT
        # analyse that file — it matched no `files:` block in the config. Silent empty
        # coverage reads as a pass, so say so loudly; it usually means the config's globs
        # are shaped for a different repo layout (e.g. vendor/monorepo paths like
        # src/*/*/src/*/Yves/** vs a demoshop's src/Pyz/Yves/**).
        if [ "$tool" = "eslint" ] && \
           printf '%s' "$out" | grep -q 'File ignored because no matching configuration'; then
            warn "eslint: some files matched NO config block and were therefore NOT analysed."
            warn "  Check the \`files:\` globs in the project's eslint config cover this layout."
        fi
        classify "$tool" "$rc" "$out"
    }

    if [ -n "$fe_mode" ]; then
        if has_tool eslint && [ "${#changed_jsts[@]}" -gt 0 ]; then
            if [ "$FIX" -eq 1 ]; then
                run_fe eslint --no-warn-ignored --fix "${changed_jsts[@]}"
            else
                run_fe eslint --no-warn-ignored "${changed_jsts[@]}"
            fi
        fi

        if has_tool stylelint && [ "${#changed_style[@]}" -gt 0 ]; then
            if [ "$FIX" -eq 1 ]; then
                run_fe stylelint --allow-empty-input --fix "${changed_style[@]}"
            else
                run_fe stylelint --allow-empty-input "${changed_style[@]}"
            fi
        fi

        if has_tool prettier && [ "${#changed_fmt[@]}" -gt 0 ]; then
            if [ "$FIX" -eq 1 ]; then
                run_fe prettier --ignore-unknown --write "${changed_fmt[@]}"
            else
                run_fe prettier --ignore-unknown --check "${changed_fmt[@]}"
            fi
        fi
    fi
fi

info ""
if [ "$env_rc" -ne 0 ]; then
    err "✗ Tool(s) failed to RUN (environment/config error): ${env_failures}"
    err "  No code was analysed by those tools — these are NOT code findings."
    err "  Do not attempt code fixes for them. Fix the environment and re-run, e.g.:"
    err "    missing src/Generated  -> docker/sdk cli console transfer:generate"
    err "    missing src/Generated/Client/Ide/AutoCompletion.php (phpstan bootstrap)"
    err "                           -> docker/sdk cli composer phpstan-setup"
    err "                              (= vendor/bin/console dev:ide-auto-completion:generate)"
    err "    missing node_modules   -> docker/sdk cli npm install  (or npm ci on the host)"
    [ "$overall_rc" -ne 0 ] && err "  (Other tools also reported real violations — see output above.)"
    exit 2
fi

if [ "$ran_any" -eq 0 ]; then
    err "✗ No tool was actually invoked — nothing was analysed."
    err "  Selected tools: $TOOLS"
    err "  This is not a pass. Check --tools and the changed-file list above."
    exit 2
fi

if [ "$overall_rc" -eq 0 ]; then
    info "✓ Static analysis passed."
else
    err "✗ Static analysis reported violations (see output above)."
fi
exit "$overall_rc"
