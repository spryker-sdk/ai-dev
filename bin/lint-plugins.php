#!/usr/bin/env php
<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

/**
 * Validates the plugin marketplace so that both Claude Code and GitHub Copilot can install it:
 * manifests, skill and agent frontmatter, and harness-specific wording inside skill bodies.
 *
 * Usage:
 *   php bin/lint-plugins.php             Report errors (exit 1) and warnings (exit 0).
 *   php bin/lint-plugins.php --strict    Treat warnings as errors too.
 */

declare(strict_types=1);

const REPO_ROOT = __DIR__ . '/..';
const MARKETPLACE = REPO_ROOT . '/.claude-plugin/marketplace.json';
const PLUGINS_DIR = REPO_ROOT . '/plugins';
const ALLOWLIST = __DIR__ . '/lint-plugins-allow.txt';

/**
 * Frontmatter keys both harnesses understand on a SKILL.md.
 */
const ALLOWED_SKILL_KEYS = [
    'name',
    'description',
    'license',
    'compatibility',
    'metadata',
    'user-invocable',
    'disable-model-invocation',
    'argument-hint',
];

/**
 * Frontmatter keys both harnesses understand on an agent file. "model" and "tools" are excluded
 * on purpose: the two products name models and tools differently, so either one misleads one harness.
 */
const ALLOWED_AGENT_KEYS = [
    'name',
    'description',
    'user-invocable',
    'disable-model-invocation',
];

/**
 * Wording that only one harness understands. Anything genuinely needed stays, via the allowlist.
 */
const HARNESS_TOKENS = [
    'mcp__claude-in-chrome__' => 'names a Claude-only MCP server; describe browser actions by tool name instead',
    '${CLAUDE_PLUGIN_ROOT}' => 'not expanded by Copilot; anchor paths to the skill base directory instead',
    'CLAUDE_PROJECT_DIR' => 'Claude-only variable; use the project root instead',
    'CLAUDE_SKILL_DIR' => 'Claude-only variable; use the skill base directory instead',
    'ToolSearch' => 'Claude-only tool; say "if your harness defers MCP tools, load them first"',
    'subagent_type' => 'Claude-only argument; describe delegation in harness-neutral terms',
    'Skill(' => 'Claude-only call syntax; name the skill and its slash command instead',
    'AskUserQuestion' => 'Claude-only tool; say "ask a structured multiple-choice question"',
    '.claude/' => 'hardcodes one harness; name the folder for the harness in use',
    'claude mcp add' => 'Claude-only command; branch per harness',
    'CLAUDE.md' => 'Claude-only file; name the instructions file for the harness in use',
    'Read / Grep / Glob' => 'Claude-only tool names; say "the built-in file read/search tools"',
    'Read tool' => 'Claude-only tool name (Copilot: view); say "the file read tool"',
    'Write tool' => 'Claude-only tool name (Copilot: create); say "the file write tool"',
    'Edit tool' => 'Claude-only tool name; say "the file edit tool"',
    'Grep tool' => 'Claude-only tool name; say "the content-search tool"',
    'Glob tool' => 'Claude-only tool name; say "the file-search tool"',
];

const SOFT_TOKENS = [
    'Claude' => 'prefer "your coding agent" or "the harness" in prose',
    'TaskCreate' => 'Claude-only tool name; describe the task list generically',
    'TaskUpdate' => 'Claude-only tool name; describe the task list generically',
    'TaskList' => 'Claude-only tool name; describe the task list generically',
    'WebFetch' => 'Claude-only tool name; say "web fetch tool"',
    'WebSearch' => 'Claude-only tool name; say "web search tool"',
    'subagent_type=' => 'Claude-only argument',
];

$errors = [];
$warnings = [];

function error(string $path, string $message): void
{
    global $errors;
    $errors[] = sprintf('%s: %s', $path, $message);
}

function warn(string $path, string $message): void
{
    global $warnings;
    $warnings[] = sprintf('%s: %s', $path, $message);
}

function relative(string $path): string
{
    $root = realpath(REPO_ROOT);
    $real = realpath($path) ?: $path;

    return $root !== false && str_starts_with($real, $root . '/') ? substr($real, strlen($root) + 1) : $path;
}

/**
 * @return array<string, string>
 */
function frontmatter(string $contents): array
{
    if (!preg_match('/\A---\R(.*?)\R---\R/s', $contents, $matches)) {
        return [];
    }

    $fields = [];

    foreach (preg_split('/\R/', $matches[1]) as $line) {
        if (!preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $field)) {
            continue;
        }

        $value = trim($field[2]);
        $first = $value === '' ? '' : $value[0];

        if (strlen($value) >= 2 && $first === substr($value, -1) && in_array($first, ['"', "'"], true)) {
            $value = substr($value, 1, -1);
        }

        $fields[$field[1]] = $value;
    }

    return $fields;
}

function hasFrontmatter(string $contents): bool
{
    return (bool)preg_match('/\A---\R/', $contents);
}

/**
 * Allowlist lines are "path|token|count": the exact number of deliberate uses of that token in that
 * file. Pinning the count means an allowlisted token cannot quietly grow new, unreviewed uses.
 *
 * @return array<string, array<string, int>>
 */
function loadAllowlist(): array
{
    if (!is_file(ALLOWLIST)) {
        return [];
    }

    $allowed = [];

    foreach (file(ALLOWLIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $number => $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$path, $token, $count] = array_pad(explode('|', $line, 3), 3, null);

        if ($token === null || $count === null || !ctype_digit(trim($count))) {
            error(relative(ALLOWLIST), sprintf('line %d is not "path|token|count"', $number + 1));

            continue;
        }

        $allowed[trim($path)][trim($token)] = (int)trim($count);
    }

    return $allowed;
}

// --- marketplace -------------------------------------------------------------
$marketplace = json_decode((string)file_get_contents(MARKETPLACE), true);

if (!is_array($marketplace)) {
    error(relative(MARKETPLACE), 'not valid JSON');
    $marketplace = ['plugins' => []];
}

foreach (['name', 'description', 'owner', 'plugins'] as $key) {
    if (!isset($marketplace[$key])) {
        error(relative(MARKETPLACE), sprintf('missing "%s"', $key));
    }
}

$marketplaceVersions = [];

foreach ($marketplace['plugins'] ?? [] as $index => $entry) {
    $label = sprintf('%s [plugin %d]', relative(MARKETPLACE), $index);

    foreach (['name', 'description', 'version', 'source'] as $key) {
        if (!isset($entry[$key]) || $entry[$key] === '') {
            error($label, sprintf('missing "%s"', $key));
        }
    }

    $name = $entry['name'] ?? '';
    $source = $entry['source'] ?? '';

    if ($name !== '' && !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
        error($label, sprintf('name "%s" is not kebab-case', $name));
    }

    if ($source !== '') {
        $manifest = REPO_ROOT . '/' . ltrim((string)$source, './') . '/.claude-plugin/plugin.json';

        if (!is_file($manifest)) {
            error($label, sprintf('source "%s" has no .claude-plugin/plugin.json', $source));
        }
    }

    if ($name !== '') {
        $marketplaceVersions[$name] = $entry['version'] ?? null;
    }
}

// --- plugin manifests --------------------------------------------------------
$pluginDirs = array_filter(glob(PLUGINS_DIR . '/*') ?: [], 'is_dir');

foreach ($pluginDirs as $pluginDir) {
    $manifestPath = $pluginDir . '/.claude-plugin/plugin.json';

    if (!is_file($manifestPath)) {
        error(relative($pluginDir), 'no .claude-plugin/plugin.json');

        continue;
    }

    $manifest = json_decode((string)file_get_contents($manifestPath), true);

    if (!is_array($manifest)) {
        error(relative($manifestPath), 'not valid JSON');

        continue;
    }

    foreach (['name', 'version', 'description', 'author', 'license'] as $key) {
        if (!isset($manifest[$key]) || $manifest[$key] === '') {
            error(relative($manifestPath), sprintf('missing "%s"', $key));
        }
    }

    $name = (string)($manifest['name'] ?? '');
    $version = (string)($manifest['version'] ?? '');

    if ($name !== '' && !preg_match('/^[a-z0-9]+([-.][a-z0-9]+)*$/', $name)) {
        error(relative($manifestPath), sprintf('name "%s" is not kebab-case', $name));
    }

    if ($name !== '' && $name !== basename($pluginDir)) {
        error(relative($manifestPath), sprintf('name "%s" does not match directory "%s"', $name, basename($pluginDir)));
    }

    if ($version !== '' && !preg_match('/^\d+\.\d+\.\d+/', $version)) {
        error(relative($manifestPath), sprintf('version "%s" is not semver', $version));
    }

    if (isset($manifest['$schema']) && str_contains((string)$manifest['$schema'], 'agent-plugins.org')) {
        error(
            relative($manifestPath),
            'declares the Agent Plugins 1.0 schema, which moves agents under com.github.copilot/ and hides them from Claude Code',
        );
    }

    if ($name !== '' && array_key_exists($name, $marketplaceVersions) && $marketplaceVersions[$name] !== $version) {
        error(
            relative($manifestPath),
            sprintf('version "%s" does not match the marketplace entry "%s"', $version, (string)$marketplaceVersions[$name]),
        );
    }

    if ($name !== '' && !array_key_exists($name, $marketplaceVersions)) {
        error(relative($manifestPath), 'plugin is not listed in .claude-plugin/marketplace.json');
    }
}

// --- skills ------------------------------------------------------------------
foreach ($pluginDirs as $pluginDir) {
    foreach (glob($pluginDir . '/skills/*', GLOB_ONLYDIR) ?: [] as $skillDir) {
        $skillPath = $skillDir . '/SKILL.md';

        if (!is_file($skillPath)) {
            error(relative($skillDir), 'no SKILL.md');

            continue;
        }

        $contents = (string)file_get_contents($skillPath);
        $path = relative($skillPath);

        if (!hasFrontmatter($contents)) {
            error($path, 'no frontmatter');

            continue;
        }

        $fields = frontmatter($contents);
        $name = $fields['name'] ?? '';

        if ($name === '') {
            error($path, 'missing "name"');
        } elseif ($name !== basename($skillDir)) {
            error($path, sprintf('name "%s" does not match directory "%s"', $name, basename($skillDir)));
        } elseif (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) || strlen($name) > 64) {
            error($path, sprintf('name "%s" must be kebab-case and at most 64 characters', $name));
        }

        $description = $fields['description'] ?? '';

        if ($description === '') {
            error($path, 'missing "description"');
        } elseif (strlen($description) > 1536) {
            // Claude Code truncates the description in its skill listing at 1536 characters,
            // so anything past that is invisible when the harness decides to load the skill.
            error($path, sprintf('description is %d characters; Claude Code truncates at 1536', strlen($description)));
        } elseif (strlen($description) > 1024) {
            warn($path, sprintf('description is %d characters; every listing pays that context cost', strlen($description)));
        }

        foreach (array_keys($fields) as $key) {
            if (in_array($key, ALLOWED_SKILL_KEYS, true)) {
                continue;
            }

            $reason = $key === 'allowed-tools'
                ? 'Copilot warns about tool names it does not know'
                : 'not understood by both harnesses';
            error($path, sprintf('frontmatter key "%s" is not portable (%s)', $key, $reason));
        }
    }
}

// --- agents ------------------------------------------------------------------
foreach ($pluginDirs as $pluginDir) {
    foreach (glob($pluginDir . '/agents/*') ?: [] as $agentPath) {
        if (!is_file($agentPath)) {
            continue;
        }

        $path = relative($agentPath);
        $fileName = basename($agentPath);

        if (!str_ends_with($fileName, '.agent.md')) {
            error($path, 'agent files must end in ".agent.md" so GitHub Copilot loads them');

            continue;
        }

        $stem = substr($fileName, 0, -strlen('.agent.md'));
        $contents = (string)file_get_contents($agentPath);

        if (!hasFrontmatter($contents)) {
            error($path, 'no frontmatter');

            continue;
        }

        $fields = frontmatter($contents);
        $name = $fields['name'] ?? '';

        if ($name === '') {
            error($path, 'missing "name"; Claude Code would otherwise register the agent as "' . $stem . '.agent"');
        } elseif ($name !== $stem) {
            error($path, sprintf('name "%s" does not match the file name "%s"', $name, $stem));
        }

        if (($fields['description'] ?? '') === '') {
            error($path, 'missing "description"');
        }

        foreach (array_keys($fields) as $key) {
            if (in_array($key, ALLOWED_AGENT_KEYS, true)) {
                continue;
            }

            error($path, sprintf('frontmatter key "%s" is not portable across harnesses', $key));
        }
    }
}

// --- harness-specific wording ------------------------------------------------
$allowlist = loadAllowlist();

$markdown = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PLUGINS_DIR, FilesystemIterator::SKIP_DOTS));

foreach ($markdown as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.md')) {
        continue;
    }

    $path = relative($file->getPathname());
    $contents = (string)file_get_contents($file->getPathname());
    $allowedHere = $allowlist[$path] ?? [];

    foreach (HARNESS_TOKENS + SOFT_TOKENS as $token => $reason) {
        $count = substr_count($contents, $token);
        $allowance = $allowedHere[$token] ?? null;
        // A harness-specific token breaks the other harness's reader, so it fails the build unless
        // it is an allowlisted dual-harness mention. The soft list is style, so it only warns.
        $isHard = array_key_exists($token, HARNESS_TOKENS);

        if ($count === 0) {
            if ($allowance !== null) {
                warn($path, sprintf('allowlist expects %dx "%s" but the file has none — drop the entry', $allowance, $token));
            }

            continue;
        }

        if ($allowance === null) {
            $message = sprintf('%dx "%s" — %s', $count, $token, $reason);
            $isHard ? error($path, $message) : warn($path, $message);

            continue;
        }

        if ($count !== $allowance) {
            error($path, sprintf(
                '%dx "%s" but the allowlist pins %d — review the new uses, then update bin/lint-plugins-allow.txt',
                $count,
                $token,
                $allowance,
            ));
        }
    }
}

// --- report ------------------------------------------------------------------
$strict = in_array('--strict', $argv, true);

if ($warnings !== []) {
    echo sprintf("Warnings (%d):\n", count($warnings));

    foreach ($warnings as $warning) {
        echo '  ' . $warning . "\n";
    }

    echo "\n";
}

if ($errors !== []) {
    echo sprintf("Errors (%d):\n", count($errors));

    foreach ($errors as $errorMessage) {
        echo '  ' . $errorMessage . "\n";
    }

    exit(1);
}

if ($strict && $warnings !== []) {
    echo "Warnings are errors under --strict.\n";

    exit(1);
}

echo sprintf("Plugin lint passed (%d warnings).\n", count($warnings));
