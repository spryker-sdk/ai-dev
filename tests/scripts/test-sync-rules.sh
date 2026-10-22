#!/usr/bin/env bash
#
# Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
# Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
#
# Behaviour tests for plugins/spryker-ai-dev-sdk/scripts/sync-rules.sh.

set -uo pipefail

unset GITHUB_ACTIONS CLAUDECODE CLAUDE_PLUGIN_ROOT CLAUDE_PROJECT_DIR COPILOT_HOME

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
SYNC="$REPO_ROOT/plugins/spryker-ai-dev-sdk/scripts/sync-rules.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

FAILURES=0
RULE_COUNT="$(find "$REPO_ROOT/plugins/spryker-ai-dev-sdk/rules" -name '*.md' | wc -l | tr -d ' ')"

pass() { printf '  ok   %s\n' "$1"; }
fail() { printf '  FAIL %s\n      %s\n' "$1" "$2"; FAILURES=$((FAILURES + 1)); }

assert_eq() {
    if [ "$2" = "$3" ]; then
        pass "$1"
    else
        fail "$1" "expected '$3', got '$2'"
    fi
}

new_project() {
    local dir="$WORK/$1"
    rm -rf "$dir"
    mkdir -p "$dir"
    printf '{"require":{"spryker/kernel":"^3.0"}}\n' > "$dir/composer.json"
    printf '%s' "$dir"
}

count_files() { find "$1" -type f 2>/dev/null | wc -l | tr -d ' '; }

# What the agent runs once the person accepts the hook's offer.
accept() { bash "$SYNC" --target "$1" --harness "$2" > /dev/null; }

echo "sync-rules.sh"

# --- The hook offers once, writes nothing, and never asks again --------------
project="$(new_project offer)"
output="$(printf '{"sessionId":"s1","cwd":"%s"}' "$project" | bash "$SYNC")"
assert_eq "the hook writes no rule files" "$(count_files "$project/.github/instructions")" "0"
assert_eq "the hook records that it has offered" "$([ -f "$project/.ai-dev/rules.copilot.suggested" ] && echo yes || echo no)" "yes"
assert_eq "the offer names the rule count" "$(printf '%s' "$output" | grep -c "writes $RULE_COUNT rule files")" "1"
assert_eq "the offer names the project, not the agent's cwd" "$(printf '%s' "$output" | grep -c -- "--target '$project'")" "1"
assert_eq "copilot wraps the offer in additionalContext JSON" "$(printf '%s' "$output" | grep -c '"additionalContext"')" "1"
if printf '%s' "$output" | python3 -c 'import json,sys; json.load(sys.stdin)' 2>/dev/null; then
    pass "the offer is valid JSON for copilot"
else
    fail "the offer is valid JSON for copilot" "stdout did not parse"
fi

second_offer="$(printf '{"sessionId":"s2","cwd":"%s"}' "$project" | bash "$SYNC")"
assert_eq "the offer is not repeated" "$second_offer" ""

assert_eq "declining leaves the project clean" "$(count_files "$project/.github/instructions")" "0"

# --- Each tool has its own offer and record ----------------------------------
project="$(new_project mixed-team)"
accept "$project" copilot
claude_offer="$(printf '{"hook_event_name":"SessionStart","session_id":"s","cwd":"%s"}' "$project" | bash "$SYNC")"
assert_eq "a project with Copilot's rules still offers Claude Code its own" "$(printf '%s' "$claude_offer" | grep -c '.claude/rules/')" "1"

project="$(new_project declined-once)"
printf '{"sessionId":"s","cwd":"%s"}' "$project" | bash "$SYNC" > /dev/null
claude_offer="$(printf '{"hook_event_name":"SessionStart","session_id":"s","cwd":"%s"}' "$project" | bash "$SYNC")"
assert_eq "an offer made in Copilot does not silence the one in Claude Code" "$(printf '%s' "$claude_offer" | grep -c 'not installed')" "1"

project="$(new_project console-installed)"
mkdir -p "$project/.github/instructions"
cp "$REPO_ROOT/plugins/spryker-ai-dev-sdk/rules/controller.md" "$project/.github/instructions/controller.instructions.md"
quiet="$(printf '{"sessionId":"s","cwd":"%s"}' "$project" | bash "$SYNC")"
assert_eq "rules put there by another installer, with no record, get no offer" "$quiet" ""

# --- Copilot: accepting the offer installs transformed rules -----------------
project="$(new_project copilot)"
accept "$project" copilot
assert_eq "copilot installs every rule" "$(count_files "$project/.github/instructions")" "$RULE_COUNT"

rule="$project/.github/instructions/dependency-provider.instructions.md"
assert_eq "copilot rewrites paths to applyTo" "$(grep -c '^applyTo: "src/\*\*/\*DependencyProvider.php"$' "$rule")" "1"
assert_eq "copilot drops the name field" "$(grep -c '^name:' "$rule")" "0"
assert_eq "copilot drops the description field" "$(grep -c '^description:' "$rule")" "0"
body_hits="$(grep -c 'late-binding closures' "$rule")"
if [ "$body_hits" -ge 1 ]; then pass "copilot keeps the rule body"; else fail "copilot keeps the rule body" "body text missing"; fi

# --- Idempotency -------------------------------------------------------------
second="$(printf '{"sessionId":"s2","cwd":"%s"}' "$project" | bash "$SYNC")"
assert_eq "a session after the install is silent" "$second" ""

# --- Locally edited files are never overwritten ------------------------------
edited="$project/.github/instructions/controller.instructions.md"
printf '\n# local edit\n' >> "$edited"
rm "$project/.github/instructions/plugins.instructions.md"
accept "$project" copilot
assert_eq "local edit survives" "$(tail -1 "$edited")" "# local edit"
assert_eq "deleted rule is restored" "$(count_files "$project/.github/instructions")" "$RULE_COUNT"

# --- Claude: paths kept, name and description dropped -----------------------
project="$(new_project claude)"
accept "$project" claude
assert_eq "claude installs every rule" "$(count_files "$project/.claude/rules")" "$RULE_COUNT"

rule="$project/.claude/rules/dependency-provider.md"
assert_eq "claude keeps paths" "$(grep -c '^paths:' "$rule")" "1"
assert_eq "claude drops name" "$(grep -c '^name:' "$rule")" "0"
assert_eq "claude drops description" "$(grep -c '^description:' "$rule")" "0"
assert_eq "claude writes no applyTo" "$(grep -c '^applyTo:' "$rule")" "0"

# --- A re-run must not destroy local edits ------------------------------------
project="$(new_project force)"
accept "$project" copilot
edited="$project/.github/instructions/controller.instructions.md"
printf '\n# local edit\n' >> "$edited"

bash "$SYNC" --target "$project" --harness copilot > /dev/null
assert_eq "a re-run preserves a locally edited rule" "$(tail -1 "$edited")" "# local edit"

bash "$SYNC" --target "$project" --harness copilot --discard-local-edits > /dev/null
if [ "$(tail -1 "$edited")" = "# local edit" ]; then
    fail "--discard-local-edits overwrites a locally edited rule" "the edit survived"
else
    pass "--discard-local-edits overwrites a locally edited rule"
fi

# --- Protection lasts: a kept file is still kept on every later run --------------
sha() { shasum -a 256 "$1" 2>/dev/null | cut -d' ' -f1 || sha256sum "$1" | cut -d' ' -f1; }
bump_version() { sed -i.bak '1s/.*/copilot 0.0.1/' "$1/.ai-dev/rules.copilot.manifest" && rm -f "$1/.ai-dev/rules.copilot.manifest.bak"; }

project="$(new_project team-rule)"
mkdir -p "$project/.github/instructions"
printf -- '---\napplyTo: "src/**"\n---\n\nteam rule\n' > "$project/.github/instructions/controller.instructions.md"
accept "$project" copilot
bash "$SYNC" --target "$project" --harness copilot > /dev/null
bump_version "$project"
accept "$project" copilot
assert_eq "a same-named team rule survives repeated runs and a plugin update" "$(tail -1 "$project/.github/instructions/controller.instructions.md")" "team rule"

project="$(new_project edited-twice)"
accept "$project" copilot
edited="$project/.github/instructions/plugins.instructions.md"
printf '\n# local edit\n' >> "$edited"
bash "$SYNC" --target "$project" --harness copilot > /dev/null
bash "$SYNC" --target "$project" --harness copilot > /dev/null
bump_version "$project"
accept "$project" copilot
assert_eq "an edited rule survives repeated runs and a plugin update" "$(tail -1 "$edited")" "# local edit"
report="$(bash "$SYNC" --target "$project" --harness copilot)"
assert_eq "a requested refresh reports the kept edit even when nothing changed" "$(printf '%s' "$report" | grep -c '1 locally edited and left untouched')" "1"
quiet="$(printf '{"sessionId":"s","cwd":"%s"}' "$project" | bash "$SYNC")"
assert_eq "the hook stays silent once the rules are installed" "$quiet" ""

# An untouched rule must still be updated: pretend we shipped older content, recorded as ours.
project="$(new_project refresh)"
accept "$project" copilot
stale="$project/.github/instructions/mapper-pattern.instructions.md"
printf 'old shipped content\n' > "$stale"
old_sha="$(sha "$stale")"
sed -i.bak "s/^[0-9a-f]* mapper-pattern.instructions.md$/$old_sha mapper-pattern.instructions.md/" "$project/.ai-dev/rules.copilot.manifest" && rm -f "$project/.ai-dev/rules.copilot.manifest.bak"
bump_version "$project"
accept "$project" copilot
assert_eq "an untouched rule is updated to the shipped version" "$(grep -c 'old shipped content' "$stale")" "0"

# --- Guards ------------------------------------------------------------------
project="$WORK/not-spryker"
mkdir -p "$project"
printf '{"require":{"laravel/framework":"^11"}}\n' > "$project/composer.json"
printf '{"sessionId":"s","cwd":"%s"}' "$project" | bash "$SYNC" > /dev/null
assert_eq "non-Spryker project is untouched, not even stamped" "$(count_files "$project")" "1"

project="$WORK/no-composer"
mkdir -p "$project"
printf '{"sessionId":"s","cwd":"%s"}' "$project" | bash "$SYNC" > /dev/null
assert_eq "project without composer.json is untouched" "$(count_files "$project")" "0"

project="$(new_project optout)"
mkdir -p "$project/.ai-dev"
touch "$project/.ai-dev/no-rules-sync"
printf '{"sessionId":"s","cwd":"%s"}' "$project" | bash "$SYNC" > /dev/null
assert_eq "opt-out marker is honoured" "$(count_files "$project/.github/instructions")" "0"

project="$(new_project cloud)"
cloud_out="$(printf '{"sessionId":"s","cwd":"%s"}' "$project" | GITHUB_ACTIONS=true bash "$SYNC")"
assert_eq "cloud agent writes nothing" "$(count_files "$project/.github/instructions")" "0"
assert_eq "cloud agent is not even offered the rules" "$cloud_out" ""

# --- A hook payload that names no project must write nothing -----------------
guard_dir="$WORK/guard"
mkdir -p "$guard_dir"
printf '{"require":{"spryker/kernel":"^3.0"}}\n' > "$guard_dir/composer.json"

( cd "$guard_dir" && printf 'not json at all {{{' | bash "$SYNC" > /dev/null 2>&1 )
assert_eq "malformed stdin writes nothing" "$(count_files "$guard_dir")" "1"

( cd "$guard_dir" && printf '' | bash "$SYNC" > /dev/null 2>&1 )
assert_eq "empty stdin writes nothing" "$(count_files "$guard_dir")" "1"

( cd "$guard_dir" && printf '{"sessionId":"s"}' | bash "$SYNC" > /dev/null 2>&1 )
assert_eq "a payload without cwd writes nothing" "$(count_files "$guard_dir")" "1"

# --- Manual invocation -------------------------------------------------------
project="$WORK/manual"
mkdir -p "$project"
bash "$SYNC" --target "$project" --harness copilot > /dev/null
again="$(bash "$SYNC" --target "$project" --harness copilot)"
assert_eq "a requested run on an up-to-date project still reports" "$(printf '%s' "$again" | grep -c 'already up to date')" "1"

project="$(new_project optout-manual)"
mkdir -p "$project/.ai-dev"
touch "$project/.ai-dev/no-rules-sync"
accept "$project" copilot
assert_eq "a requested install is not blocked by the hook opt-out" "$(count_files "$project/.github/instructions")" "$RULE_COUNT"
assert_eq "a requested install works without a Spryker composer.json" "$(count_files "$project/.github/instructions")" "$RULE_COUNT"

echo
if [ $FAILURES -gt 0 ]; then
    echo "$FAILURES assertion(s) failed."
    exit 1
fi

echo "All assertions passed."
