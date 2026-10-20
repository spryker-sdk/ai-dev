<?php

declare(strict_types=1);

/**
 * The two hook files must declare the same hooks.
 *
 * Claude Code reads `hooks/hooks.json` only. GitHub Copilot reads the plugin root's `hooks.json`
 * first and never looks at `hooks/hooks.json` once it exists, so a hook added to one file and not
 * the other is silently missing on one harness. The two formats differ, so this compares the
 * events they declare and the script each one runs, not the files themselves.
 */

const EVENT_PAIRS = [
    'SessionStart' => 'sessionStart',
    'Stop' => 'agentStop',
    'SubagentStop' => 'subagentStop',
    'UserPromptSubmit' => 'userPromptSubmitted',
    'PreToolUse' => 'preToolUse',
    'PostToolUse' => 'postToolUse',
];

$pluginRoot = dirname(__DIR__) . '/plugins/spryker-ai-dev-sdk';
$errors = [];

/** The script path a hook command runs, with the harness's root variable stripped. */
function scripts(string $command): array
{
    preg_match_all('~\$\{(?:CLAUDE_)?PLUGIN_ROOT\}/([A-Za-z0-9._/-]+)~', $command, $m);

    return $m[1];
}

$claudeFile = $pluginRoot . '/hooks/hooks.json';
$copilotFile = $pluginRoot . '/hooks.json';
foreach ([$claudeFile, $copilotFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing hook file: {$file}\n");
        exit(1);
    }
}

$claude = json_decode((string) file_get_contents($claudeFile), true);
$copilot = json_decode((string) file_get_contents($copilotFile), true);
if (!is_array($claude) || !is_array($copilot)) {
    fwrite(STDERR, "a hook file is not valid JSON\n");
    exit(1);
}

if (($copilot['version'] ?? null) !== 1) {
    $errors[] = 'hooks.json needs "version": 1 — Copilot rejects the whole file without it, and then loads no hooks at all';
}

/** @return list<string> */
$claudeScripts = static function (array $entries): array {
    $found = [];
    foreach ($entries as $matcherGroup) {
        foreach ($matcherGroup['hooks'] ?? [] as $hook) {
            $found = array_merge($found, scripts((string) ($hook['command'] ?? '')));
        }
    }

    return $found;
};

/** @return list<string> */
$copilotScripts = static function (array $entries): array {
    $found = [];
    foreach ($entries as $hook) {
        $found = array_merge($found, scripts((string) ($hook['bash'] ?? '')));
    }

    return $found;
};

foreach (EVENT_PAIRS as $claudeEvent => $copilotEvent) {
    $inClaude = $claude['hooks'][$claudeEvent] ?? null;
    $inCopilot = $copilot['hooks'][$copilotEvent] ?? null;
    if ($inClaude === null && $inCopilot === null) {
        continue;
    }
    if ($inClaude === null) {
        $errors[] = "hooks.json declares {$copilotEvent} but hooks/hooks.json has no {$claudeEvent} — the hook never runs under Claude Code";

        continue;
    }
    if ($inCopilot === null) {
        $errors[] = "hooks/hooks.json declares {$claudeEvent} but hooks.json has no {$copilotEvent} — the hook never runs under Copilot, which reads hooks.json instead";

        continue;
    }
    $a = $claudeScripts((array) $inClaude);
    $b = $copilotScripts((array) $inCopilot);
    sort($a);
    sort($b);
    if ($a !== $b) {
        $errors[] = sprintf('%s/%s run different scripts: Claude Code %s, Copilot %s', $claudeEvent, $copilotEvent, json_encode($a), json_encode($b));
    }
}

foreach ($copilot['hooks'] ?? [] as $event => $entries) {
    foreach ((array) $entries as $hook) {
        if (!isset($hook['bash']) && !isset($hook['powershell'])) {
            $errors[] = "hooks.json {$event}: Copilot needs a \"bash\" (or \"powershell\") key, not \"command\"";
        }
        if (isset($hook['timeout'])) {
            $errors[] = "hooks.json {$event}: Copilot reads \"timeoutSec\", not \"timeout\"";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Hook parity errors:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, '  ' . $error . "\n");
    }
    exit(1);
}

echo "Hook files agree (" . count(array_intersect_key(EVENT_PAIRS, (array) ($claude['hooks'] ?? []))) . " events).\n";
