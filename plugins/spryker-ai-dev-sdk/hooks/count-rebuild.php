<?php

declare(strict_types=1);

/** PostToolUse / Bash — count every rebuild that actually ran. */

require __DIR__ . '/lib.php';

try {
    $payload = hook_payload();
    if (($payload['tool_name'] ?? '') !== 'Bash') {
        exit(0);
    }
    $class = hook_classify_command((string) ($payload['tool_input']['command'] ?? ''));
    if ($class === null || $class['kind'] !== 'rebuild') {
        exit(0);
    }
    $cwd = hook_cwd($payload);
    if (!hook_run_active($cwd)) {
        exit(0);
    }
    if (!is_dir($cwd . '/data/import') || !is_dir($cwd . '/.ai-dev')) {
        exit(0);
    }
    $file = $cwd . '/.ai-dev/rebuild-count';
    $count = hook_rebuild_count($cwd) + 1;
    file_put_contents($file, $count . "\n");
    $log = $cwd . '/.ai-dev/rebuild-log';
    file_put_contents($log, date('Y-m-d H:i:s') . " #{$count} docker/sdk {$class['verb']} — " . str_replace("\n", ' ', (string) ($payload['tool_input']['command'] ?? '')) . "\n", FILE_APPEND);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'spryker-ai-dev-sdk count hook: ' . $e->getMessage() . "\n");
    exit(0);
}
