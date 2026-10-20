#!/usr/bin/env bash
#
# Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
# Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
#
# Installs the Spryker coding rules into a project as path-scoped instruction files.
#
# Runs as a session-start hook for Claude Code and GitHub Copilot, and can be run by hand:
#
#   scripts/sync-rules.sh --target "$(pwd)" [--harness claude|copilot] [--force]
#
# As a hook it writes no rule files: it offers once per project, stamps .ai-dev/rules.suggested and
# is silent from then on. Installing is the caller's move, with --target. A project opts out
# entirely with .ai-dev/no-rules-sync. The hook also does nothing outside a Spryker project or in CI.
#
# A rule file that differs from what this script last shipped is treated as edited by a human and is
# never overwritten -- not by the hook, and not by --force, which only re-scans a project the fast
# path would otherwise skip. Pass --discard-local-edits to overwrite such files deliberately.

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
RULES_DIR="$PLUGIN_ROOT/rules"

TARGET=""
HARNESS=""
FORCE=0
DISCARD_LOCAL=0
MANUAL=0

while [ $# -gt 0 ]; do
    case "$1" in
        --target) TARGET="${2:-}"; MANUAL=1; shift 2 ;;
        --harness) HARNESS="${2:-}"; MANUAL=1; shift 2 ;;
        --force) FORCE=1; MANUAL=1; shift ;;
        --discard-local-edits) DISCARD_LOCAL=1; FORCE=1; MANUAL=1; shift ;;
        --help|-h)
            sed -n '6,14p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) shift ;;
    esac
done

# --- read the hook payload ---------------------------------------------------
# Both harnesses send a JSON object on stdin. Claude Code uses snake_case
# ("cwd", "hook_event_name"), Copilot camelCase ("cwd", "sessionId").
STDIN_JSON=""
if [ $MANUAL -eq 0 ] && [ ! -t 0 ]; then
    STDIN_JSON="$(cat 2>/dev/null || true)"
fi

json_string() {
    # json_string <key> — first string value for that key, no jq dependency.
    printf '%s' "$STDIN_JSON" \
        | tr -d '\n' \
        | sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" \
        | head -1
}

if [ -z "$TARGET" ]; then
    TARGET="$(json_string cwd)"
fi
if [ -z "$TARGET" ]; then
    # As a hook, the project is whatever the harness said on stdin. Falling back to the current
    # directory would let a malformed payload install rules into some unrelated checkout, so a hook
    # invocation with no usable cwd does nothing. Only an explicit --target may name the project.
    if [ $MANUAL -eq 1 ]; then
        TARGET="${CLAUDE_PROJECT_DIR:-$PWD}"
    else
        exit 0
    fi
fi

# Who is running us, decided from what this invocation carries -- the payload first, then the
# environment the harness itself exports. A directory in $HOME says which tools are installed,
# not which one is running, so it is not a signal. Nothing matching leaves HARNESS empty, and
# harness_spec below refuses it.
if [ -z "$HARNESS" ]; then
    if [ -n "$(json_string hook_event_name)" ]; then
        HARNESS="claude"
    elif [ -n "$(json_string sessionId)" ]; then
        HARNESS="copilot"
    elif [ -n "${CLAUDE_PLUGIN_ROOT:-}" ] || [ -n "${CLAUDE_PROJECT_DIR:-}" ]; then
        HARNESS="claude"
    elif [ -n "${COPILOT_HOME:-}" ]; then
        HARNESS="copilot"
    fi
fi

# --- guards ------------------------------------------------------------------
# Silence is the contract: any reason not to act exits 0 without output, so a
# session never fails or gets noisy because of this hook.
[ -d "$RULES_DIR" ] || exit 0
[ -d "$TARGET" ] || exit 0

# Cloud agents (Copilot coding agent, CI) must not get rule files committed into
# their pull requests; a skill that needs a rule reads it from the plugin instead.
if [ $MANUAL -eq 0 ] && [ "${GITHUB_ACTIONS:-}" = "true" ]; then
    exit 0
fi

# Per-project opt-out.
[ -e "$TARGET/.ai-dev/no-rules-sync" ] && exit 0

# Only act inside a Spryker project.
if [ $FORCE -eq 0 ]; then
    if [ ! -f "$TARGET/composer.json" ] || ! grep -q '"spryker[/-]' "$TARGET/composer.json" 2>/dev/null; then
        exit 0
    fi
fi

# One row per harness: rules dir | file suffix | frontmatter | hook stdout.
# Adding a tool is adding a row; an unlisted harness is refused rather than assumed.
harness_spec() {
    case "$1" in
        claude)         printf '%s' '.claude/rules|.md|paths|text' ;;
        copilot|vscode) printf '%s' '.github/instructions|.instructions.md|applyTo|json' ;;
        *)              return 1 ;;
    esac
}

SPEC="$(harness_spec "$HARNESS")" || {
    [ $MANUAL -eq 1 ] && printf 'sync-rules.sh: unknown harness "%s"\n' "$HARNESS" >&2
    exit $MANUAL
}
IFS='|' read -r RULES_SUBDIR SUFFIX FRONTMATTER STDOUT_FORMAT <<EOF
$SPEC
EOF
RULES_TARGET_DIR="$TARGET/$RULES_SUBDIR"

MANIFEST_DIR="$TARGET/.ai-dev"
MANIFEST="$MANIFEST_DIR/rules.manifest"
SUGGESTED="$MANIFEST_DIR/rules.suggested"

# Escape a message for embedding in a JSON string literal: Copilot parses this script's stdout.
json_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# --- as a hook, suggest once and write nothing -------------------------------
if [ $MANUAL -eq 0 ]; then
    # Already installed, or already offered: nothing to say.
    [ -f "$MANIFEST" ] && exit 0
    [ -e "$SUGGESTED" ] && exit 0

    # No stamp means the offer would repeat every session, so stay quiet instead.
    mkdir -p "$MANIFEST_DIR" 2>/dev/null || exit 0
    date -u '+%Y-%m-%dT%H:%M:%SZ' > "$SUGGESTED" 2>/dev/null || exit 0

    RULE_COUNT="$(find "$RULES_DIR" -name '*.md' -type f 2>/dev/null | wc -l | tr -d ' ')"
    RELATIVE_DIR="${RULES_TARGET_DIR#$TARGET/}"
    OFFER="This is a Spryker project and the Spryker coding rules are not installed in it. Offer the person the choice, once, and do not raise it again: installing writes ${RULE_COUNT} rule files to ${RELATIVE_DIR}/, which the harness then applies on its own to the files each rule governs. To accept, run: ${SCRIPT_DIR}/sync-rules.sh --target '${TARGET}' --harness ${HARNESS}. If they decline or say nothing, carry on with the task; the rules can still be read one at a time from the plugin's own rules directory without installing anything."

    if [ "$STDOUT_FORMAT" = "json" ]; then
        printf '{"additionalContext":"%s"}\n' "$(json_escape "$OFFER")"
    else
        printf '%s\n' "$OFFER"
    fi
    exit 0
fi
PLUGIN_VERSION="$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$PLUGIN_ROOT/.claude-plugin/plugin.json" 2>/dev/null | head -1)"
MANIFEST_KEY="$HARNESS ${PLUGIN_VERSION:-unknown}"

# Fast path: same harness, same plugin version, every shipped file still present.
if [ $FORCE -eq 0 ] && [ -f "$MANIFEST" ] && [ "$(head -1 "$MANIFEST" 2>/dev/null)" = "$MANIFEST_KEY" ]; then
    STALE=0
    while IFS=' ' read -r _sha name; do
        [ -n "${name:-}" ] || continue
        [ -f "$RULES_TARGET_DIR/$name" ] || { STALE=1; break; }
    done < <(tail -n +2 "$MANIFEST")
    [ $STALE -eq 0 ] && exit 0
fi

sha_of() {
    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | cut -d' ' -f1
    elif command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        cksum "$1" | cut -d' ' -f1
    fi
}

previous_sha() {
    [ -f "$MANIFEST" ] || return 0
    awk -v want="$1" '$2 == want { print $1; exit }' "$MANIFEST"
}

# --- transform ---------------------------------------------------------------
# Both modes drop name/description, which neither harness reads. "paths" keeps the scope key as
# it is; "applyTo" renames it and falls back to "**" when the rule is unscoped. The output must
# match AiDevConfig::TOOL_FRONTMATTER_SPEC byte for byte -- RuleInstallerParityTest checks it.
render_rule() {
    local source_file="$1" destination="$2"

    if [ "$FRONTMATTER" = "paths" ]; then
        awk '/^---[[:space:]]*$/{c++} !(c==1 && /^(name|description):/)' "$source_file" > "$destination"

        return
    fi

    local paths
    paths="$(awk '/^---[[:space:]]*$/{c++; next} c==1 && /^paths:/{sub(/^paths:[[:space:]]*/, ""); gsub(/^["'"'"']|["'"'"']$/, ""); print; exit}' "$source_file")"
    [ -n "$paths" ] || paths="**"

    {
        printf -- '---\n'
        printf 'applyTo: "%s"\n' "$paths"
        printf -- '---\n'
        awk 'BEGIN{c=0} /^---[[:space:]]*$/{c++; if (c<=2) next} c>=2' "$source_file"
    } > "$destination"
}

mkdir -p "$RULES_TARGET_DIR" "$MANIFEST_DIR" 2>/dev/null || exit 0

ADDED=0
UPDATED=0
PRESERVED=0
NEW_MANIFEST="$MANIFEST_KEY"

for source_file in "$RULES_DIR"/*.md; do
    [ -f "$source_file" ] || continue

    base="$(basename "$source_file" .md)"
    name="$base$SUFFIX"
    destination="$RULES_TARGET_DIR/$name"
    temporary="$destination.ai-dev-tmp"

    render_rule "$source_file" "$temporary" || { rm -f "$temporary"; continue; }
    shipped_sha="$(sha_of "$temporary")"

    if [ ! -f "$destination" ]; then
        mv "$temporary" "$destination"
        ADDED=$((ADDED + 1))
    else
        current_sha="$(sha_of "$destination")"
        recorded_sha="$(previous_sha "$name")"

        if [ "$current_sha" = "$shipped_sha" ]; then
            rm -f "$temporary"
        elif [ $DISCARD_LOCAL -eq 1 ] || { [ -n "$recorded_sha" ] && [ "$current_sha" = "$recorded_sha" ]; }; then
            # Untouched since we shipped it. Only --discard-local-edits overwrites one that differs.
            mv "$temporary" "$destination"
            UPDATED=$((UPDATED + 1))
        else
            # Edited by a human, or pre-existing and unknown to us. Leave it alone.
            rm -f "$temporary"
            PRESERVED=$((PRESERVED + 1))
            shipped_sha="$current_sha"
        fi
    fi

    NEW_MANIFEST="$NEW_MANIFEST
$shipped_sha $name"
done

printf '%s\n' "$NEW_MANIFEST" > "$MANIFEST"

CHANGED=$((ADDED + UPDATED))
if [ $CHANGED -eq 0 ]; then
    exit 0
fi

RELATIVE_DIR="${RULES_TARGET_DIR#$TARGET/}"
MESSAGE="Spryker coding rules installed to $RELATIVE_DIR/ ($ADDED added, $UPDATED refreshed"
[ $PRESERVED -gt 0 ] && MESSAGE="$MESSAGE, $PRESERVED locally edited and left untouched"
MESSAGE="$MESSAGE). Commit them or add them to .gitignore; opt out with .ai-dev/no-rules-sync."

if [ "$STDOUT_FORMAT" = "json" ] && [ $MANUAL -eq 0 ]; then
    printf '{"additionalContext":"%s"}\n' "$(json_escape "$MESSAGE")"
else
    printf '%s\n' "$MESSAGE"
fi

exit 0
