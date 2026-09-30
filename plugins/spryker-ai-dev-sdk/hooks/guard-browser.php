<?php

declare(strict_types=1);

/** PreToolUse / browser tools — local hosts are driven through Claude in Chrome, not the built-in browser. */

require __DIR__ . '/lib.php';

try {
    $payload = hook_payload();
    $tool = (string) ($payload['tool_name'] ?? '');
    $input = (array) ($payload['tool_input'] ?? []);
    hook_cwd($payload);
    $transcript = (string) ($payload['transcript_path'] ?? '');

    if (str_starts_with($tool, 'mcp__Claude_Browser__')) {
        $url = (string) ($input['url'] ?? '');
        foreach ((array) ($input['actions'] ?? []) as $action) {
            $u = (string) ($action['input']['url'] ?? '');
            if ($u !== '' && hook_is_local_url($u)) {
                $url = $u;
                break;
            }
        }
        if ($url === '' && str_ends_with($tool, '__preview_start') && ($input['name'] ?? '') !== '') {
            $url = 'http://localhost/';
        }
        if ($url === '' || in_array($url, ['back', 'forward'], true)) {
            $url = (string) (hook_browser_tab_url($transcript, (string) ($input['tabId'] ?? ''), 'mcp__Claude_Browser__') ?? '');
        }
        if ($url !== '' && hook_is_local_url($url)) {
            hook_decide('deny', 'Blocked: the built-in browser on a local host, where it asks the person to approve every action. Use Claude in Chrome instead (`mcp__claude-in-chrome__navigate`, `computer`, `read_page`, `find`, `javascript_tool`, `gif_creator`). If it is not connected, say so in the final report and continue with server-side checks.');
        }
        exit(0);
    }

    if (preg_match('~__javascript_tool$~', $tool) === 1) {
        $url = hook_browser_tab_url($transcript, (string) ($input['tabId'] ?? ''), (string) substr($tool, 0, (int) strrpos($tool, '__') + 2));
        if ($url !== null && hook_is_local_url($url)) {
            hook_decide('allow', 'JavaScript on a local page (' . (parse_url($url, PHP_URL_HOST) ?: $url) . ').');
        }
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'spryker-ai-dev-sdk browser guard: ' . $e->getMessage() . "\n");
    exit(0);
}
