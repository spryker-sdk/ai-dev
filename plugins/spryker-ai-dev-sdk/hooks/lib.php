<?php

declare(strict_types=1);

/** Shared helpers for the plugin's Claude Code hooks. */

/** @return array<string,mixed> */
function hook_payload(): array
{
    $raw = stream_get_contents(STDIN);
    $data = $raw === false || trim($raw) === '' ? null : json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/** The project root the tool call runs in (remembered for the decision log). */
function hook_cwd(array $payload): string
{
    $cwd = (string) ($payload['cwd'] ?? '');
    if ($cwd === '' || !is_dir($cwd)) {
        $cwd = (string) (getenv('CLAUDE_PROJECT_DIR') ?: getcwd());
    }
    $GLOBALS['hook_cwd'] = rtrim($cwd, '/');

    return $GLOBALS['hook_cwd'];
}

/**
 * Locate validate.php: plugin install (CLAUDE_PLUGIN_ROOT), this checkout (relative to the hooks
 * dir), or a setup install (`.claude/skills/...` in the project).
 */
function hook_validator(string $cwd): ?string
{
    $candidates = [];
    $root = getenv('CLAUDE_PLUGIN_ROOT');
    if (is_string($root) && $root !== '') {
        $candidates[] = rtrim($root, '/') . '/skills/spryker-import-tools/scripts/validate.php';
    }
    $candidates[] = dirname(__DIR__) . '/skills/spryker-import-tools/scripts/validate.php';
    $candidates[] = $cwd . '/.claude/skills/spryker-import-tools/scripts/validate.php';
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }

    return null;
}

/**
 * The plugin's skill names — the installed copies that must stay read-only.
 *
 * @return list<string>
 */
function hook_plugin_skills(): array
{
    $dirs = [];
    $root = getenv('CLAUDE_PLUGIN_ROOT');
    foreach ([is_string($root) && $root !== '' ? rtrim($root, '/') . '/skills' : null, dirname(__DIR__) . '/skills'] as $skills) {
        if ($skills !== null && is_dir($skills)) {
            foreach (glob($skills . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                $dirs[basename($d)] = true;
            }
        }
    }
    $list = __DIR__ . '/plugin-skills.txt';
    if (is_file($list)) {
        foreach (preg_split('/\R/', trim((string) file_get_contents($list))) ?: [] as $name) {
            if (trim($name) !== '') {
                $dirs[trim($name)] = true;
            }
        }
    }

    return array_keys($dirs);
}

/**
 * Run the gate in-process.
 *
 * @return array<string,mixed>|null
 */
function hook_run_gate(string $cwd, bool $strict): ?array
{
    $validator = hook_validator($cwd);
    if ($validator === null) {
        fwrite(STDERR, "spryker-ai-dev-sdk hook: validate.php not found — gate skipped\n");

        return null;
    }
    require_once $validator;
    $manifests = validate_discover_manifests($cwd);
    if ($manifests === []) {
        return null;
    }
    $baseline = null;
    foreach (['/.ai-dev/gate-baseline.json', '/.ai-dev/preflight-baseline.json'] as $auto) {
        if (is_file($cwd . $auto)) {
            $baseline = $cwd . $auto;
            break;
        }
    }
    $result = validate_gate($manifests, $cwd, [], $baseline, [], $strict);
    if (is_dir($cwd . '/.ai-dev')) {
        @file_put_contents($cwd . '/.ai-dev/gate-last.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    return $result;
}

/** A compact, readable digest of a gate result for a permission reason. */
function hook_gate_digest(array $gate, int $max = 8): string
{
    $lines = [implode(' · ', (array) ($gate['summary'] ?? []))];
    $shown = 0;
    foreach ((array) ($gate['checks'] ?? []) as $name => $check) {
        if (($check['status'] ?? '') !== 'error') {
            continue;
        }
        foreach ((array) ($check['findings'] ?? []) as $f) {
            if ($shown++ >= $max) {
                break 2;
            }
            $parts = [];
            foreach ((array) $f as $k => $v) {
                $parts[] = $k . '=' . (is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : (string) $v);
            }
            $lines[] = "  - {$name}: " . implode(' ', $parts);
        }
    }
    foreach ((array) ($gate['errors'] ?? []) as $e) {
        $lines[] = '  - error: ' . $e;
    }
    $total = (int) ($gate['gatingCount'] ?? 0);
    if ($total > $shown) {
        $lines[] = '  … ' . ($total - $shown) . ' more in .ai-dev/gate-last.json';
    }

    return implode("\n", $lines);
}

/** Record which rule decided, when `AIDEV_HOOK_TRACE` names a file. */
function hook_trace(string $decision): void
{
    $dest = (string) getenv('AIDEV_HOOK_TRACE');
    if ($dest === '') {
        return;
    }
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
        if (!in_array((string) ($frame['function'] ?? ''), ['hook_decide', 'stop_decide'], true)) {
            continue;
        }
        @file_put_contents($dest, basename((string) ($frame['file'] ?? '?')) . ':' . (int) ($frame['line'] ?? 0) . ' ' . $decision . "\n", FILE_APPEND);

        return;
    }
}

/** Emit a PreToolUse decision and exit. */
function hook_decide(string $decision, string $reason): never
{
    hook_trace($decision);
    $cwd = (string) ($GLOBALS['hook_cwd'] ?? '');
    if ($decision !== 'allow' && $cwd !== '' && is_dir($cwd . '/.ai-dev')) {
        $first = strtok($reason, "\n") ?: $reason;
        @file_put_contents($cwd . '/.ai-dev/hooks.log', date('Y-m-d H:i:s') . ' ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'hook')) . " {$decision} — " . mb_substr($first, 0, 200) . "\n", FILE_APPEND);
    }
    echo json_encode([
        'hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'permissionDecision' => $decision,
            'permissionDecisionReason' => $reason,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

/**
 * Classify a Bash command: which docker/sdk state change it performs, if any.
 *
 * @return array{kind: 'rebuild'|'import', verb: string}|null
 */
function hook_classify_command(string $command): ?array
{
    $noHeredoc = hook_strip_heredocs($command);
    $noQuotes = preg_replace('~"(?:\\\\.|[^"\\\\])*"|\'[^\']*\'~', "''", $noHeredoc) ?? $noHeredoc;
    if (preg_match('~(?:^|[\s;&|(])(?:\./)?docker/sdk\s+(?:-{1,2}\S+\s+)*(reset|clean-data|up)(?=\s|$)([^;&|\n]*)~', $noQuotes, $m) === 1) {
        if ($m[1] === 'up' && preg_match('~--assets\b~', $m[2]) === 1 && preg_match('~--(?:build|data)\b~', $m[2]) !== 1) {
            return null; // an asset build — no data wiped, no containers rebuilt
        }
        return ['kind' => 'rebuild', 'verb' => $m[1]];
    }
    if (preg_match('~(?:docker/sdk\s+(?:cli\s+)?console|vendor/bin/console|docker/sdk\s+cli\s+["\']?[^"\']*console)\s+data:import(?::[\w-]+)*(?=\s|$|["\'])~', $noHeredoc) === 1) {
        return ['kind' => 'import', 'verb' => 'data:import'];
    }

    return null;
}

/** Remove every heredoc body (`<<EOF` … `EOF`, quoted or `<<-` forms) from a shell command. */
function hook_strip_heredocs(string $command): string
{
    $lines = explode("\n", $command);
    $out = [];
    $delim = null;
    foreach ($lines as $line) {
        if ($delim !== null) {
            if (trim($line) === $delim) {
                $delim = null;
            }
            continue;
        }
        if (preg_match('~<<-?\s*["\']?([A-Za-z_][A-Za-z0-9_]*)["\']?~', $line, $m) === 1) {
            $delim = $m[1];
        }
        $out[] = $line;
    }

    return implode("\n", $out);
}

/**
 * Did the person say, in their own message, that rebuilds may run without asking? A state-file line
 * alone is not enough: the agent can write that line itself.
 */
function hook_user_granted_rebuilds(string $transcriptPath): bool
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return false;
    }
    $fh = fopen($transcriptPath, 'rb');
    if ($fh === false) {
        return false;
    }
    $negation = '~\b(?:never|not|don\'?t|do not|no longer|stop)\b[^.\n]{0,40}\b(?:rebuilds?|resets?|clean-data|trust|approve)|\b(?:rebuilds?|resets?)\b[^.\n]{0,40}\b(?:must|should|(?<!no )need to)\b[^.\n]{0,20}\b(?:ask|confirm)|\b(?:rebuilds?|resets?)\b[^.\n]{0,60}\b(?:not|never)\b~i';
    $grant = '~\b(?:rebuilds?|resets?|clean-data)\b[^.\n]{0,60}\b(?:always|without asking|don\'?t ask|do not ask|no need to ask|are fine|are ok|approved?|trusted?)\b|\b(?:always|approve|trust)\b[^.\n]{0,40}\b(?:rebuilds?|resets?)\b~i';
    $found = false;
    while (($line = fgets($fh)) !== false) {
        $d = json_decode($line, true);
        if (!is_array($d) || ($d['type'] ?? '') !== 'user') {
            continue;
        }
        $content = $d['message']['content'] ?? null;
        $texts = is_string($content) ? [$content] : [];
        foreach (is_array($content) ? $content : [] as $c) {
            if (($c['type'] ?? '') === 'text') {
                $texts[] = (string) ($c['text'] ?? '');
            }
        }
        foreach ($texts as $t) {
            if (str_starts_with(ltrim($t), 'Base directory for this skill:')) {
                continue;
            }
            foreach (preg_split('~(?<=[.!?\n])\s+~', $t) ?: [] as $sentence) {
                if (preg_match($grant, $sentence) === 1 && preg_match($negation, $sentence) !== 1) {
                    $found = true;
                    break 3;
                }
            }
        }
    }
    fclose($fh);

    return $found;
}

/**
 * Why a rebuild may run without a prompt, or null when it must ask: a first-setup or demo clone
 * carries no data anyone could lose, and a recorded standing approval covers rebuilds explicitly.
 */
function hook_rebuild_trusted(string $cwd, string $transcriptPath = ''): ?string
{
    foreach (['project-setup.md', 'demo-prep.md'] as $name) {
        $path = $cwd . '/.ai-dev/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $body = (string) file_get_contents($path);
        $approval = preg_match('~^standing_approval:(.*)$~mi', $body, $sa) === 1 ? str_replace(['"', "'"], '', preg_replace('~\s#.*$~', '', $sa[1]) ?? '') : '';
        if (preg_match('~\brebuilds?\b~i', $approval) === 1 && preg_match('~\b(?:none|never|not|no longer)\b|\bmust\b[^,\n]*\b(?:confirm|ask)~i', $approval) !== 1 && hook_user_granted_rebuilds($transcriptPath)) {
            return 'the person approved rebuilds for this project';
        }
        if ($name === 'demo-prep.md' || preg_match('~\bpurpose:\s*demo\b~', $body) === 1) {
            return 'this is a demo clone — its data is rebuilt from the project files';
        }
        if (!hook_step_is_done($body, 'boot-and-verify')) {
            return 'this is first setup — nothing has been booted into this shop yet that could be lost';
        }
    }

    return null;
}

/**
 * Is a wizard run in progress here (a run state file exists)? Outside a run the hooks keep only the
 * rules that protect every project; `SPRYKER_AI_DEV_ENFORCE=1` switches the full set on regardless.
 */
function hook_run_active(string $cwd): bool
{
    if (getenv('SPRYKER_AI_DEV_ENFORCE') === '1') {
        return true;
    }
    foreach (['project-setup.md', 'demo-prep.md'] as $name) {
        if (is_file(rtrim($cwd, '/') . '/.ai-dev/' . $name)) {
            return true;
        }
    }

    return false;
}

/**
 * True when the person's message asks for one of $words (a regex alternation), in a sentence that
 * does not negate it.
 */
function hook_person_asked_for(?string $text, string $words): bool
{
    if ($text === null) {
        return false;
    }
    foreach (preg_split('~(?<=[.!?\n])\s+~', $text) ?: [] as $sentence) {
        if (preg_match('~\b(?:' . $words . ')~i', $sentence) === 1
            && preg_match('~\b(?:do not|don\'t|dont|never|no|without|not|stay on)\b[^.!?]*\b(?:' . $words . ')~i', $sentence) !== 1) {
            return true;
        }
    }

    return false;
}

/**
 * True when the person's message asks for staging or a commit, in a sentence that does not negate it.
 */
function hook_person_asked_to_stage(?string $text): bool
{
    return hook_person_asked_for($text, 'stage|staging|git (?:add|rm|mv)|commit');
}

/**
 * True while an upgrade run is in progress: the upgrade skill keeps its baselines in this directory.
 */
function hook_upgrade_active(string $cwd): bool
{
    return is_dir(rtrim($cwd, '/') . '/.spryker-upgrade/state');
}

/**
 * True for the project's own code and tests, where explanatory comments are not written.
 */
function hook_is_commented_code_file(string $rel): bool
{
    return preg_match('~^(?:src|config|tests)/~', $rel) === 1
        && preg_match('~^src/(?:Generated|Orm)/~', $rel) !== 1
        && preg_match('~\.(?:php|js|ts|twig)$~', $rel) === 1;
}

/**
 * Explanatory comment lines and suppressions in $text. Docblock tags, {@inheritDoc}, Spryker
 * `Specification:` blocks, the license header and docblocks carrying the `upgrade-debt` marker pass.
 *
 * @return list<string>
 */
function hook_comment_lines(string $text, string $ext): array
{
    $out = [];
    $block = null;
    $flush = static function (array $lines) use (&$out): void {
        foreach ($lines as $line) {
            if (preg_match('~phpcs:(?:ignore|disable)|@phpstan-ignore|@psalm-suppress|@codingStandardsIgnore|eslint-disable|@ts-(?:ignore|expect-error|nocheck)~i', $line) === 1) {
                $out[] = $line;
            }
        }
        if (preg_grep('~upgrade-debt|This file is part of|LICENSE file~i', $lines) !== []) {
            return;
        }
        $inSpec = false;
        foreach ($lines as $line) {
            if (preg_match('~phpcs:(?:ignore|disable)|@phpstan-ignore|@psalm-suppress|@codingStandardsIgnore|eslint-disable|@ts-(?:ignore|expect-error|nocheck)~i', $line) === 1) {
                continue;
            }
            if ($line === '' || preg_match('~^(?:@|\{@inheritDoc\})~i', $line) === 1) {
                $inSpec = $inSpec && $line === '';

                continue;
            }
            if (preg_match('~^Specification:?$~i', $line) === 1) {
                $inSpec = true;

                continue;
            }
            if ($inSpec && str_starts_with($line, '-')) {
                continue;
            }
            $out[] = $line;
        }
    };
    $clean = static fn (string $s): string => trim(preg_replace('~^\s*(?:/\*\*?|\*/?|\{#)|(?:\*/|#\})\s*$~', '', trim($s)) ?? '');

    foreach (preg_split('/\R/', $text) ?: [] as $raw) {
        $line = trim($raw);
        if ($ext === 'twig') {
            if ($block !== null) {
                $block[] = $clean($line);
                if (str_contains($line, '#}')) {
                    $flush($block);
                    $block = null;
                }
            } elseif (preg_match('~\{#(.*?)(#\})?$~', $line, $m) === 1) {
                $block = [$clean('{#' . $m[1])];
                if (isset($m[2])) {
                    $flush($block);
                    $block = null;
                }
            }

            continue;
        }
        if ($block !== null) {
            $block[] = $clean($line);
            if (str_contains($line, '*/')) {
                $flush($block);
                $block = null;
            }

            continue;
        }
        if (str_starts_with($line, '/*')) {
            $block = [$clean($line)];
            if (str_contains(substr($line, 2), '*/')) {
                $flush($block);
                $block = null;
            }

            continue;
        }
        if (str_starts_with($line, '*')) {
            $block = [$clean($line)];
            if (str_contains($line, '*/')) {
                $flush($block);
                $block = null;
            }

            continue;
        }
        if (str_starts_with($line, '//') || ($ext === 'php' && str_starts_with($line, '#') && !str_starts_with($line, '#['))) {
            $flush([trim(ltrim($line, '/#'))]);

            continue;
        }
        if (preg_match('~[;{}(),]\s*//\s*(.+)$~', $line, $m) === 1) {
            $flush([trim($m[1])]);
        }
    }
    if ($block !== null) {
        $flush($block);
    }

    return $out;
}

/**
 * Explanatory comment lines $new adds over $old (moved or unchanged comments are not added).
 *
 * @return list<string>
 */
function hook_added_comments(string $old, string $new, string $ext): array
{
    $before = array_count_values(hook_comment_lines($old, $ext));
    $added = [];
    foreach (hook_comment_lines($new, $ext) as $line) {
        if (($before[$line] ?? 0) > 0) {
            $before[$line]--;

            continue;
        }
        $added[] = $line;
    }

    return $added;
}

/** Current rebuild count (`.ai-dev/rebuild-count`), 0 when absent. */
function hook_rebuild_count(string $cwd): int
{
    $f = $cwd . '/.ai-dev/rebuild-count';

    return is_file($f) ? (int) trim((string) file_get_contents($f)) : 0;
}

/**
 * Open a transcript positioned near its END, for the rules that only care about the last exchange.
 *
 * @return resource|false
 */
function hook_open_tail(string $transcriptPath, int $bytes = 2000000)
{
    $fh = fopen($transcriptPath, 'rb');
    if ($fh === false) {
        return false;
    }
    $size = (int) filesize($transcriptPath);
    if ($size > $bytes) {
        fseek($fh, $size - $bytes);
        fgets($fh);
    }

    return $fh;
}

/**
 * The person's LAST message in the transcript, when it is a question: the text ends with `?`
 * (trailing quotes/spaces ignored).
 */
function hook_last_user_question(string $transcriptPath): ?string
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return null;
    }
    $fh = hook_open_tail($transcriptPath);
    if ($fh === false) {
        return null;
    }
    $last = null;
    $answered = false;
    while (($line = fgets($fh)) !== false) {
        $d = json_decode($line, true);
        if (!is_array($d)) {
            continue;
        }
        $type = (string) ($d['type'] ?? '');
        $content = $d['message']['content'] ?? null;
        if ($type === 'assistant' && $last !== null && is_array($content)) {
            foreach ($content as $c) {
                if (($c['type'] ?? '') === 'text' && trim((string) ($c['text'] ?? '')) !== '') {
                    $answered = true;
                }
            }
            continue;
        }
        if ($type !== 'user') {
            continue;
        }
        $texts = [];
        if (is_string($content)) {
            $texts[] = $content;
        } elseif (is_array($content)) {
            foreach ($content as $c) {
                if (($c['type'] ?? '') === 'text') {
                    $texts[] = (string) ($c['text'] ?? '');
                }
            }
        }
        foreach ($texts as $t) {
            $t = trim($t);
            if ($t === '' || str_starts_with($t, 'Base directory for this skill:') || str_starts_with($t, '[Request interrupted') || str_starts_with($t, '<')) {
                continue;
            }
            $last = $t;
            $answered = false;
        }
    }
    fclose($fh);
    if ($last === null || $answered) {
        return null;
    }
    $tail = rtrim($last, " \t\n\"'”’)*_");
    if (!str_ends_with($tail, '?') || hook_is_polite_request($last)) {
        return null;
    }

    return $last;
}

/** Is a message ending in `?` really an instruction ("can you prepare…?", "please …?")? */
function hook_is_polite_request(string $text): bool
{
    $sentences = preg_split('~(?<=[.!?])\s+~', trim($text)) ?: [];
    $last = trim((string) end($sentences));
    if (preg_match('~^(?:ok(?:ay)?,?\s+|so,?\s+|and\s+)?(?:please\s+)?(?:can|could|would|will)\s+you\s+(?:please\s+)?(not\s+)?(\w+)~i', $last, $m) === 1) {
        return ($m[1] ?? '') === '' && preg_match('~^(?:prepare|add|fix|run|build|make|create|set|update|change|remove|delete|rename|move|copy|install|start|continue|go|do|apply|implement|write|generate|import|translate|restyle|rebuild|reset|boot|deploy|stage|revert|finish|proceed|try|use|put|replace|clean|redo|restart|resume|bring|hide|show|switch)$~i', $m[2]) === 1
            && preg_match('~^show\s+me\b~i', substr($last, (int) stripos($last, $m[2]))) !== 1;
    }

    return preg_match('~^(?:please\b|let\'?s\b)~i', $last) === 1;
}

/** Does a Bash command change project state (files, git index, containers)? */
function hook_bash_mutates(string $command): bool
{
    $c = ' ' . $command . ' ';
    if (preg_match('~(^|[\s;&|(])(rm|mv|cp|mkdir|touch|chmod|chown|ln|rsync|tee|truncate|install)\s~', $c) === 1) {
        return true;
    }
    if (preg_match('~\bsed\s+(-[a-zA-Z]*i|--in-place)~', $c) === 1 || hook_shell_write_target($command) !== null) {
        return true;
    }
    if (preg_match('~\bgit\s+(mv|rm|add|commit|checkout|reset|restore|stash|clean|rebase|merge|cherry-pick|push)\b~', $c) === 1) {
        return true;
    }
    if (preg_match('~\b(csv\.php)\b.*--(in-place|out)\b~', $c) === 1) {
        return true;
    }
    if (preg_match('~\b(python3?|php|perl|ruby|node)\b~', $c) === 1 && (str_contains($c, '<<') || preg_match('~\s-(c|r|e)\s~', $c) === 1)) {
        return true;
    }
    if (hook_classify_command($command) !== null) {
        return true;
    }

    return false;
}

/**
 * The file a shell command writes through `>`/`>>`/`tee`/`sed -i`, when that file lives in the
 * project (not /dev/null, not a temp dir).
 */
function hook_shell_write_target(string $command): ?string
{
    $targets = [];
    $scan = hook_strip_heredocs($command);
    if (preg_match('~\bdocker/sdk\s+cli\s+(["\'])(.*?)(?<!\\\\)\1~s', $command, $wrap) === 1
        || preg_match('~\b(?:sh|bash)\s+-c\s+(["\'])(.*?)(?<!\\\\)\1~s', $command, $wrap) === 1) {
        $scan .= "\n" . str_replace('\\"', '"', $wrap[2]);
    }
    $len = strlen($scan);
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = $scan[$i];
        if ($quote !== null) {
            if ($ch === '\\' && $quote === '"') {
                $i++;
                continue;
            }
            if ($ch === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            continue;
        }
        if ($ch !== '>') {
            continue;
        }
        $prev = $i > 0 ? $scan[$i - 1] : '';
        if ($prev === '&' || $prev === '<' || $prev === '>' || ctype_digit($prev)) {
            continue;
        }
        $j = $i + 1;
        $append = false;
        if ($j < $len && $scan[$j] === '>') {
            $append = true;
            $j++;
        }
        while ($j < $len && ($scan[$j] === ' ' || $scan[$j] === "\t")) {
            $j++;
        }
        if ($j >= $len || $scan[$j] === '&') {
            continue;
        }
        $q = ($scan[$j] === '"' || $scan[$j] === "'") ? $scan[$j] : null;
        if ($q !== null) {
            $j++;
        }
        $target = '';
        while ($j < $len) {
            $c = $scan[$j];
            if ($q !== null ? $c === $q : ($c === ' ' || $c === "\t" || $c === "\n" || $c === '|' || $c === ';' || $c === '&' || $c === '<' || $c === '>')) {
                break;
            }
            $target .= $c;
            $j++;
        }
        if ($target !== '') {
            $targets[] = [$target, $append];
        }
        $i = $j;
    }
    if (preg_match_all('~\btee\s+(?:-a\s+)?(["\']?)([^\s"\'|;&]+)\1~', $scan, $m) > 0) {
        foreach ($m[2] as $t) {
            $targets[] = [$t, false];
        }
    }
    foreach (hook_command_segments($scan) as $segment) {
        $words = hook_shell_words($segment);
        if (($words[0] ?? '') === 'sed' && preg_match('~^(?:-[a-zA-Z]*i|--in-place)~', (string) ($words[1] ?? '')) === 1 && count($words) >= 3) {
            $targets[] = [(string) end($words), false];
        }
    }
    $inContainer = preg_match('~\bdocker(?:/sdk\s+cli|\s+exec|-compose\s+exec)~', $command) === 1;
    $vars = [];
    if (preg_match_all('~(?:^|[\s;&|(])([A-Za-z_][A-Za-z0-9_]*)=(["\']?)([^\s;|&"\']+)\2~', $scan, $vm, PREG_SET_ORDER) > 0) {
        foreach ($vm as $v) {
            $vars[$v[1]] = $v[3];
        }
    }
    foreach ($targets as [$t, $append]) {
        $t = trim($t);
        if (preg_match('~^\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?(/.*)?$~', $t, $vmatch) === 1) {
            if (!isset($vars[$vmatch[1]])) {
                continue;
            }
            $t = $vars[$vmatch[1]] . ($vmatch[2] ?? '');
        }
        if ($t === '' || str_starts_with($t, '/dev/') || str_starts_with($t, '/tmp/') || str_starts_with($t, '/private/tmp/') || str_starts_with($t, '/var/folders/') || str_starts_with($t, '$TMPDIR') || str_starts_with($t, '${TMPDIR')) {
            continue;
        }
        if ($inContainer && str_starts_with($t, '/')) {
            if (!str_starts_with($t, '/data/')) {
                continue;
            }
            $t = substr($t, strlen('/data/'));
        }
        if ($append && preg_match('~(^|/)\.ai-dev/~', $t) === 1 && !hook_guarded_state_file($t)) {
            continue;
        }

        return $t;
    }

    return null;
}

/** The person's LAST message, question or not. */
function hook_last_user_text(string $transcriptPath): ?string
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return null;
    }
    $fh = hook_open_tail($transcriptPath);
    if ($fh === false) {
        return null;
    }
    $last = null;
    while (($line = fgets($fh)) !== false) {
        $d = json_decode($line, true);
        if (!is_array($d) || ($d['type'] ?? '') !== 'user') {
            continue;
        }
        $content = $d['message']['content'] ?? null;
        $texts = [];
        if (is_string($content)) {
            $texts[] = $content;
        } elseif (is_array($content)) {
            foreach ($content as $c) {
                if (($c['type'] ?? '') === 'text') {
                    $texts[] = (string) ($c['text'] ?? '');
                }
            }
        }
        foreach ($texts as $t) {
            $t = trim($t);
            if ($t === '' || str_starts_with($t, 'Base directory for this skill:') || str_starts_with($t, '[Request interrupted') || str_starts_with($t, '<')) {
                continue;
            }
            $last = $t;
        }
    }
    fclose($fh);

    return $last;
}

/**
 * True when the person's latest answer says yes to the agent's own question about staging or
 * committing (typed, or through AskUserQuestion).
 */
function hook_person_confirmed_stage(string $transcriptPath): bool
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return false;
    }
    $fh = hook_open_tail($transcriptPath);
    if ($fh === false) {
        return false;
    }
    $asksStage = static fn (string $t): bool => preg_match('~\b(?:stage|commit)\w*\b[^?]*\?~i', $t) === 1;
    $yes = '~^\W*(?:yes|y|yep|yeah|ok|okay|sure|go ahead|do it|please do|confirm\w*|approved?|stage and commit)\b~i';
    $pending = false;
    $confirmed = false;
    while (($line = fgets($fh)) !== false) {
        $d = json_decode($line, true);
        if (!is_array($d)) {
            continue;
        }
        $content = $d['message']['content'] ?? null;
        $blocks = is_array($content) ? $content : (is_string($content) ? [['type' => 'text', 'text' => $content]] : []);
        if (($d['type'] ?? '') === 'assistant') {
            foreach ($blocks as $b) {
                $t = ($b['type'] ?? '') === 'text' ? (string) ($b['text'] ?? '') : ((($b['type'] ?? '') === 'tool_use' && ($b['name'] ?? '') === 'AskUserQuestion') ? json_encode($b['input'] ?? []) : '');
                if ($t !== '' && $asksStage($t)) {
                    $pending = true;
                }
            }
            continue;
        }
        if (($d['type'] ?? '') !== 'user') {
            continue;
        }
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'text') {
                $t = trim((string) ($b['text'] ?? ''));
                if ($t === '' || str_starts_with($t, '<') || str_starts_with($t, 'Base directory for this skill:')) {
                    continue;
                }
                $confirmed = $pending && preg_match($yes, $t) === 1;
                $pending = false;
            } elseif (($b['type'] ?? '') === 'tool_result' && $pending) {
                $r = is_array($b['content'] ?? null) ? json_encode($b['content']) : (string) ($b['content'] ?? '');
                if (preg_match('~(?:stage|commit)[^=]*=\s*[\\\\"]*\s*(?:yes|stage and commit|commit|ok|confirm)~i', $r) === 1) {
                    $confirmed = true;
                    $pending = false;
                }
            }
        }
    }
    fclose($fh);

    return $confirmed;
}

/**
 * The agent's own last message, when it ends in a question and no message from the person followed.
 */
function hook_unanswered_agent_question(string $transcriptPath): ?string
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return null;
    }
    $fh = hook_open_tail($transcriptPath);
    if ($fh === false) {
        return null;
    }
    $pending = null;
    while (($line = fgets($fh)) !== false) {
        $d = json_decode($line, true);
        if (!is_array($d)) {
            continue;
        }
        $type = (string) ($d['type'] ?? '');
        $content = $d['message']['content'] ?? null;
        if ($type === 'user') {
            $texts = [];
            if (is_string($content)) {
                $texts[] = $content;
            } elseif (is_array($content)) {
                foreach ($content as $c) {
                    if (($c['type'] ?? '') === 'text') {
                        $texts[] = (string) ($c['text'] ?? '');
                    }
                }
            }
            foreach ($texts as $t) {
                $t = trim($t);
                if ($t === '' || str_starts_with($t, 'Base directory for this skill:') || str_starts_with($t, '[Request interrupted') || str_starts_with($t, '<')) {
                    continue;
                }
                $pending = null;
            }
            continue;
        }
        if ($type !== 'assistant' || !is_array($content)) {
            continue;
        }
        foreach ($content as $c) {
            if (($c['type'] ?? '') !== 'text') {
                continue;
            }
            $t = trim((string) ($c['text'] ?? ''));
            if ($t === '') {
                continue;
            }
            $t = preg_replace('~```.*?```|`[^`]*`|"[^"\n]*"|“[^”\n]*”~s', '', $t) ?? $t;
            $tail = rtrim($t, " \t\n\"'”’)*_");
            $sentences = preg_split('~(?<=[.!?])\s+~', $tail) ?: [];
            $asked = trim((string) end($sentences));
            $pending = str_ends_with($tail, '?') && preg_match('~\b(?:you|your|shall I|should I|may I|can I|OK|okay|confirm|proceed|go ahead)\b~i', $asked) === 1 ? $t : null;
        }
    }
    fclose($fh);

    return $pending;
}

/** Guarded token families a command must not reach by concatenation. */
function hook_disguised_token(string $command): bool
{
    return preg_match('~(["\'])(data|import|reset|clean-data|up|sdk|docker)\1\s*[+.]\s*(["\'])~', $command) === 1;
}

/** The write verb of an SQL statement passed to a database client, or null. */
function hook_sql_write(string $command): ?string
{
    if (preg_match('~\b(mariadb|mysql|psql|postgres)\b~', $command) !== 1) {
        return null;
    }
    if (preg_match('~\b(UPDATE|DELETE\s+(?:\w+\s+)?FROM|DELETE\s+\w+\s+FROM|INSERT\s+INTO|REPLACE\s+INTO|TRUNCATE|ALTER\s+TABLE|DROP\s+(?:TABLE|DATABASE))\b~i', $command, $m) === 1) {
        return strtoupper(preg_replace('~\s+~', ' ', $m[1]));
    }
    if (preg_match('~(<\s*[^\s<>|;&]+|\$\(\s*cat\b|`\s*cat\b)~', $command) === 1) {
        return 'SQL FROM A FILE';
    }

    return null;
}

/** A destructive key-value command (`redis-cli DEL`, `valkey-cli FLUSHALL`), or null. */
function hook_kv_write(string $command): ?string
{
    if (preg_match('~\b(redis-cli|valkey-cli)\b~', $command) !== 1) {
        return null;
    }
    if (preg_match('~\b(DEL|UNLINK|FLUSHALL|FLUSHDB|SET|HSET|RENAME|EXPIRE)\b~i', $command, $m) === 1) {
        return strtoupper($m[1]);
    }

    return null;
}

/**
 * `.ai-dev` files that carry their own checks in the Edit/Write guard and must not be
 * shell-written.
 */
function hook_guarded_state_file(string $path): bool
{
    return preg_match('~\.ai-dev/(project-setup\.md|demo-prep\.md|rehearsal\.md|design-acceptance\.md|verifier-report\.md|rebuild-count|rebuild-log|[^/]*baseline[^/]*\.json)$~', $path) === 1;
}

/** Project subtrees whose files are the project's own source of truth. */
function hook_project_write_dirs(): array
{
    return ['data/import', 'src/', 'config/', 'frontend/', 'public/', 'deploy.', 'composer.json'];
}

/**
 * Script text a Bash command would execute: heredoc bodies, `-c`/`-r`/`-e` payloads, and the
 * contents of a script file it runs.
 */
function hook_script_text(string $command, string $cwd): string
{
    $text = '';
    $lines = explode("\n", $command);
    $delim = null;
    foreach ($lines as $line) {
        if ($delim !== null) {
            if (trim($line) === $delim) {
                $delim = null;
                continue;
            }
            $text .= $line . "\n";
            continue;
        }
        if (preg_match('~<<-?\s*["\']?([A-Za-z_][A-Za-z0-9_]*)["\']?~', $line, $m) === 1) {
            $delim = $m[1];
        }
    }
    if (preg_match_all('~\s-(?:c|r|e)\s+(["\'])((?:\\\\.|(?!\1).)*)\1~s', $command, $m) > 0) {
        foreach ($m[2] as $payload) {
            $text .= $payload . "\n";
        }
    }
    if (preg_match_all('~\b(?:python3?|php|perl|ruby|node|bash|sh|zsh)\s+(?:-\S+\s+)*([^\s;|&]+\.(?:py|php|pl|rb|js|mjs|sh))\b~', $command, $m) > 0) {
        foreach ($m[1] as $script) {
            if (str_contains($script, 'csv.php') || str_contains($script, 'validate.php') || str_contains($script, '.test.php')) {
                continue;
            }
            $path = str_starts_with($script, '/') ? $script : $cwd . '/' . preg_replace('~^(?:\./)+~', '', $script);
            if (is_file($path) && filesize($path) < 400000) {
                $text .= (string) file_get_contents($path) . "\n";
            }
        }
    }

    return $text;
}

/**
 * The paths an `rm` in this command deletes (every `rm` segment of a compound command), `['*']` when the
 * deletion cannot be read target by target, or null when nothing is deleted.
 *
 * @return list<string>|null
 */
function hook_rm_targets(string $command): ?array
{
    $cmd = trim($command);
    if (preg_match('~(?:^|[;&|(\s\\\\/])rm\s|\bxargs\s+(?:-\S+\s+)*rm\b|\bfind\b[^;&|]*\s-(?:delete\b|exec\s+rm\b)|\bunlink\s~', $cmd) !== 1) {
        return null;
    }
    // Deletion through a pipe, a subshell, find or xargs cannot be read target by target. Quoted text
    // (an SQL query's `\`col\``, a sed script) is data, not a substitution.
    $bare = preg_replace('~\\\\.|\'[^\']*\'~', '', $cmd) ?? $cmd;
    if (preg_match('~\$\(|`|\bxargs\s+(?:-\S+\s+)*rm\b|\bfind\b[^;&|]*\s-(?:delete\b|exec\s+rm\b)|\bunlink\b|(?:^|[;&|\n])\s*\(~', $bare) === 1 || preg_match('~\|[^|]*\brm\s~', $bare) === 1) {
        return ['*'];
    }
    $targets = [];
    $dir = '';
    foreach (hook_command_segments($cmd) as $segment) {
        $words = hook_shell_words($segment);
        if ($words === []) {
            continue;
        }
        if (in_array($words[0], ['cd', 'pushd'], true)) {
            $to = $words[1] ?? '';
            if ($to === '' || $to === '-' || hook_word_unreadable($to)) {
                return ['*'];
            }
            $dir = preg_match('#^(?:/|~|\$HOME|\$\{HOME\})#', $to) === 1 ? $to : ($dir === '' ? $to : $dir . '/' . $to);
            continue;
        }
        if ($words[0] !== 'rm') {
            continue;
        }
        $options = true;
        foreach (array_slice($words, 1) as $token) {
            if ($options && $token === '--') {
                $options = false;
                continue;
            }
            if ($token === '' || ($options && str_starts_with($token, '-'))) {
                continue;
            }
            if (hook_word_unreadable($token)) {
                return ['*'];
            }
            $targets[] = $dir === '' || preg_match('#^(?:/|~|\$HOME|\$\{HOME\})#', $token) === 1 ? $token : $dir . '/' . $token;
        }
    }

    return $targets;
}

/**
 * The simple commands of a shell line (split on `&&`, `||`, `;`, `&`, `|` and newlines), each with a
 * leading `command` / `builtin` / `env VAR=…` / `sudo` wrapper removed and `\rm` or `/bin/rm` read as `rm`.
 *
 * @return list<string>
 */
function hook_command_segments(string $command): array
{
    $out = [];
    $parts = [];
    $cur = '';
    $quote = null;
    $text = hook_strip_heredocs($command);
    for ($i = 0, $len = strlen($text); $i < $len; $i++) {
        $ch = $text[$i];
        if ($quote === null && $ch === '\\' && $i + 1 < $len) {
            $cur .= $ch . $text[++$i];
            continue;
        }
        if ($quote !== null) {
            $quote = $ch === $quote ? null : $quote;
            $cur .= $ch;
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            $cur .= $ch;
            continue;
        }
        if (in_array($ch, [';', '&', '|', "\n"], true)) {
            $parts[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    $parts[] = $cur;
    foreach ($parts as $segment) {
        $segment = trim($segment);
        do {
            $before = $segment;
            $segment = preg_replace('~^(?:command|builtin|sudo|env(?:\s+-\S+)*(?:\s+\w+=\S*)*)\s+~', '', $segment) ?? $segment;
        } while ($segment !== $before);
        $segment = preg_replace('~^(?:\\\\|/usr/bin/|/bin/)rm\b~', 'rm', $segment) ?? $segment;
        if ($segment !== '') {
            $out[] = $segment;
        }
    }

    return $out;
}

/**
 * Shell words of one simple command, with quotes removed.
 *
 * @return list<string>
 */
function hook_shell_words(string $segment): array
{
    preg_match_all('~"((?:\\\\.|[^"\\\\])*)"|\'([^\']*)\'|((?:\\\\.|[^\s"\'])+)~', $segment, $m, PREG_SET_ORDER);
    $words = [];
    foreach ($m as $w) {
        $words[] = ($w[1] ?? '') !== '' ? $w[1] : ((($w[2] ?? '') !== '') ? $w[2] : (string) ($w[3] ?? ''));
    }

    return $words;
}

/** Does a shell word expand to something only the shell knows (a variable, brace list, `~user`)? */
function hook_word_unreadable(string $word): bool
{
    $word = preg_replace('#^(?:\$HOME|\$\{HOME\})(?=/|$)#', '~', $word) ?? $word;

    return preg_match('#\$|\{|\(|^~[^/]#', $word) === 1;
}

/** Is every simple command in the line a `cd`, `pushd`, `popd`, `rm` or a harmless print (`echo`, `printf`, `true`, `ls`, `pwd`)? */
function hook_command_only_deletes(string $command): bool
{
    foreach (hook_command_segments($command) as $segment) {
        if (preg_match('~^(?:cd|pushd|popd|rm|echo|printf|true|ls|pwd)(?:\s|$)~', $segment) !== 1) {
            return false;
        }
    }

    return true;
}

/**
 * Each target whose deletion git could not undo, with the reason; empty when every target is recoverable.
 * Recoverable: ignored build output, `data/cache`, a file under the folders a run builds that was
 * created during this session, or a path git tracks with nothing untracked inside it.
 *
 * @param list<string> $targets
 * @return list<string>
 */
function hook_rm_unrecoverable(array $targets, string $cwd, int $since = 0): array
{
    $root = rtrim($cwd, '/');
    $bad = [];
    foreach ($targets as $t) {
        if (preg_match('~[*?\[]~', $t) === 1) {
            $bad[] = "`{$t}` (a wildcard — the files cannot be named)";
            continue;
        }
        $abs = hook_command_workdir('cd ' . escapeshellarg($t) . ' && x', $root) ?? '';
        if ($abs !== $root && !str_starts_with($abs . '/', $root . '/') && preg_match('~^(?:/private)?/tmp/claude-[^/]+/~', $abs) === 1) {
            continue; // the session scratchpad — nothing the project needs
        }
        if ($abs === $root || !str_starts_with($abs . '/', $root . '/')) {
            $bad[] = "`{$t}` (outside the project)";
            continue;
        }
        $rel = substr($abs, strlen($root) + 1);
        if (preg_match('~^(?:\.git|\.claude|\.ai-dev)(?:/|$)~', $rel) === 1) {
            $bad[] = "`{$rel}` (inside `.git`, `.claude` or `.ai-dev`)";
            continue;
        }
        if (!str_contains($rel, '/') && is_dir($abs)) {
            $bad[] = "`{$rel}` (a whole top-level folder)";
            continue;
        }
        if (!file_exists($abs) || preg_match('~^data/cache/~', $rel) === 1) {
            continue;
        }
        if ($since > 0 && preg_match('~^(?:data/import|config|src|frontend|public|tests)/~', $rel) === 1 && hook_created_since($abs, $since)) {
            continue; // made during this session — the run's own file
        }
        $git = 'git -C ' . escapeshellarg($root);
        if (trim((string) shell_exec($git . ' check-ignore -- ' . escapeshellarg($rel) . ' 2>/dev/null')) !== '') {
            continue; // ignored build output — regenerated, nothing to lose
        }
        $tracked = trim((string) shell_exec($git . ' ls-files -- ' . escapeshellarg($rel) . ' 2>/dev/null')) !== '';
        $untracked = trim((string) shell_exec($git . ' ls-files --others --exclude-standard -- ' . escapeshellarg($rel) . ' 2>/dev/null')) !== '';
        if ((!$tracked || $untracked) && !hook_untracked_content_in_git($root, $rel)) {
            $bad[] = "`{$rel}` (not in git, so it cannot be restored)";
        }
    }

    return $bad;
}

/**
 * True when every untracked file at `$rel` (a file, or each untracked file under a folder) has the
 * same content as a file git tracks, as after an `mv` or `cp -r` of tracked files: git can restore it.
 */
function hook_untracked_content_in_git(string $root, string $rel): bool
{
    $git = 'git -C ' . escapeshellarg($root);
    $files = array_values(array_filter(explode("\n", (string) shell_exec($git . ' ls-files --others --exclude-standard -- ' . escapeshellarg($rel) . ' 2>/dev/null'))));
    if ($files === [] || count($files) > 5000) {
        return false;
    }
    $known = [];
    foreach (explode("\n", (string) shell_exec($git . ' ls-files -s 2>/dev/null')) as $line) {
        if (preg_match('~^\d+ ([0-9a-f]{40,64}) ~', $line, $m) === 1) {
            $known[$m[1]] = true;
        }
    }
    $proc = proc_open(['git', '-C', $root, 'hash-object', '--stdin-paths'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return false;
    }
    fwrite($pipes[0], implode("\n", $files) . "\n");
    fclose($pipes[0]);
    $hashes = array_values(array_filter(explode("\n", (string) stream_get_contents($pipes[1]))));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if (count($hashes) !== count($files)) {
        return false;
    }
    foreach ($hashes as $hash) {
        if (!isset($known[trim($hash)])) {
            return false;
        }
    }

    return true;
}

/** Was every file at `$abs` (a file, or each file under a folder) created at or after `$since`? */
function hook_created_since(string $abs, int $since): bool
{
    if (is_file($abs)) {
        return hook_file_birth($abs) >= $since;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $f) {
        if ($f->isFile() && hook_file_birth($f->getPathname()) < $since) {
            return false;
        }
    }

    return true;
}

/** When the file was created (birth time where the filesystem records it, else its mtime). */
function hook_file_birth(string $abs): int
{
    $cmd = PHP_OS_FAMILY === 'Darwin' ? 'stat -f %B ' : 'stat -c %W ';
    $birth = (int) trim((string) shell_exec($cmd . escapeshellarg($abs) . ' 2>/dev/null'));

    return $birth > 0 ? $birth : (int) filemtime($abs);
}

/** Unix time of the session's first transcript entry, or 0 when unknown. */
function hook_session_start(string $transcriptPath): int
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return 0;
    }
    $fh = fopen($transcriptPath, 'rb');
    if ($fh === false) {
        return 0;
    }
    $start = 0;
    for ($i = 0; $i < 20 && ($line = fgets($fh)) !== false; $i++) {
        if (preg_match('~"timestamp"\s*:\s*"([^"]+)"~', $line, $m) === 1) {
            $start = (int) strtotime($m[1]);
            break;
        }
    }
    fclose($fh);

    return $start;
}

/**
 * The URL the browser tab was last sent to in this session (a navigate, or a preview started from a local
 * dev server), or null when the transcript does not say. A tab id narrows it to that tab.
 */
function hook_browser_tab_url(string $transcriptPath, string $tabId = '', string $toolPrefix = ''): ?string
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return null;
    }
    $fh = hook_open_tail($transcriptPath);
    if ($fh === false) {
        return null;
    }
    $last = null;
    $lastForTab = null;
    while (($line = fgets($fh)) !== false) {
        if (!str_contains($line, 'navigate') && !str_contains($line, 'preview_start')) {
            continue;
        }
        $d = json_decode($line, true);
        if (!is_array($d) || ($d['type'] ?? '') !== 'assistant') {
            continue;
        }
        foreach ((array) ($d['message']['content'] ?? []) as $c) {
            if (($c['type'] ?? '') !== 'tool_use') {
                continue;
            }
            $name = (string) ($c['name'] ?? '');
            if ($toolPrefix !== '' && !str_starts_with($name, $toolPrefix)) {
                continue;
            }
            $input = (array) ($c['input'] ?? []);
            if (str_ends_with($name, '__browser_batch')) {
                foreach ((array) ($input['actions'] ?? []) as $action) {
                    $u = (string) ($action['input']['url'] ?? '');
                    if (($action['name'] ?? '') === 'navigate' && $u !== '' && !in_array($u, ['back', 'forward'], true)) {
                        $last = $u;
                        if ($tabId !== '' && (string) ($action['input']['tabId'] ?? '') === $tabId) {
                            $lastForTab = $u;
                        }
                    }
                }
                continue;
            }
            $url = null;
            if (preg_match('~__navigate$~', $name) === 1) {
                $url = (string) ($input['url'] ?? '');
                if ($url === '' || in_array($url, ['back', 'forward'], true)) {
                    continue;
                }
            } elseif (preg_match('~__preview_start$~', $name) === 1) {
                $url = (string) ($input['url'] ?? '');
                if ($url === '' && ($input['name'] ?? '') !== '') {
                    $url = 'http://localhost/';
                }
            }
            if ($url === null || $url === '') {
                continue;
            }
            $last = $url;
            if ($tabId !== '' && (string) ($input['tabId'] ?? '') === $tabId) {
                $lastForTab = $url;
            }
        }
    }
    fclose($fh);

    return $lastForTab ?? $last;
}

/** Is this a local address — localhost, a loopback IP, or a `.local` / `.localhost` / `.test` host? */
function hook_is_local_url(string $url): bool
{
    if (!str_contains($url, '://')) {
        $url = 'https://' . $url;
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    return $host !== '' && preg_match('~^(?:localhost|127(?:\.\d+){3}|\[?::1\]?|[a-z0-9.-]+\.(?:local|localhost|test))$~', $host) === 1;
}

/** The directory a command switches to with a leading `cd <dir> &&`, resolved, or null. */
function hook_command_workdir(string $command, string $cwd): ?string
{
    if (preg_match('~^\s*cd\s+(?:"([^"]+)"|\'([^\']+)\'|([^\s;&|]+))\s*(?:&&|;)~', $command, $m) !== 1) {
        return null;
    }
    $dir = ($m[1] ?? '') !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
    $home = (string) (getenv('HOME') ?: '');
    $dir = preg_replace('#^(?:\$HOME|\$\{HOME\}|~)(?=/|$)#', $home, $dir) ?? $dir;
    if (!str_starts_with($dir, '/')) {
        $dir = rtrim($cwd, '/') . '/' . $dir;
    }
    $parts = [];
    foreach (explode('/', $dir) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }

    return '/' . implode('/', $parts);
}

/** Does the command run from a directory outside the project (a leading `cd` elsewhere)? */
function hook_runs_outside_project(string $command, string $cwd): bool
{
    $dir = hook_command_workdir($command, $cwd);

    return $dir !== null && $dir !== rtrim($cwd, '/') && !str_starts_with($dir . '/', rtrim($cwd, '/') . '/');
}

/**
 * Does every file an inline script writes resolve outside the project — a leading `cd` elsewhere, or only
 * absolute targets outside it? A target that cannot be read literally counts as inside.
 */
function hook_script_writes_outside_project(string $command, string $cwd): bool
{
    if (hook_runs_outside_project($command, $cwd)) {
        return true;
    }
    $patterns = [
        '~open\s*\(\s*([\'"])(.*?)\1\s*,\s*[\'"][wax]~',
        '~file_put_contents\s*\(\s*([\'"])(.*?)\1~',
        '~to_csv\s*\(\s*([\'"])(.*?)\1~',
        '~Path\s*\(\s*([\'"])(.*?)\1\s*\)\s*\.write_(?:text|bytes)~',
    ];
    $targets = [];
    foreach ($patterns as $re) {
        if (preg_match_all($re, $command, $m) > 0) {
            array_push($targets, ...$m[2]);
        }
    }
    $writes = preg_match_all('~open\s*\([^)]*[\'"][wax]\+?[\'"]|file_put_contents\s*\(|\.to_csv\s*\(|write_(?:text|bytes)\s*\(|shutil\.|os\.(?:rename|replace|remove|unlink)~', $command); // csv/json writers write to a handle `open()` already counted
    if ($targets === [] || count($targets) < $writes) {
        return false;
    }
    $root = rtrim($cwd, '/') . '/';
    foreach ($targets as $t) {
        if (!str_starts_with($t, '/') || str_starts_with($t . '/', $root)) {
            return false;
        }
    }

    return true;
}


/** The project path an ad-hoc script writes, or null. */
function hook_script_project_write(string $command, string $cwd): ?string
{
    $text = hook_script_text($command, $cwd);
    if (trim($text) === '') {
        return null;
    }
    $writes = '~(open\s*\([^)]*[\'"][wa]\+?[\'"]|csv\.(?:writer|DictWriter)|write_text\s*\(|file_put_contents\s*\(|json\.dump\s*\(|yaml\.(?:dump|safe_dump)\s*\(|shutil\.(?:copy|move)|os\.(?:rename|replace|remove|unlink)|\.to_csv\s*\(|fopen\s*\([^)]*[\'"][wa])~';
    if (preg_match($writes, $text) !== 1) {
        return null;
    }
    if (preg_match_all('~[\'"]([^\'"]*\.ai-dev/[^\'"]+)[\'"]~', $text, $all) > 0) {
        foreach ($all[1] as $candidate) {
            if (hook_guarded_state_file($candidate)) {
                return $candidate;
            }
        }
    }
    if (hook_script_writes_outside_project($command, $cwd)) {
        return null;
    }
    $writeCalls = '~(?:open|fopen)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"][wax]|file_put_contents\s*\(\s*[\'"]([^\'"]+)[\'"]|to_csv\s*\(\s*[\'"]([^\'"]+)[\'"]|Path\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*\.write_(?:text|bytes)|shutil\.(?:copy\w*|move)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]|os\.(?:rename|replace)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]|os\.(?:remove|unlink)\s*\(\s*[\'"]([^\'"]+)[\'"]~';
    if (preg_match_all($writeCalls, $text, $all, PREG_SET_ORDER) > 0) {
        foreach ($all as $m) {
            $target = (string) (array_values(array_filter(array_slice($m, 1), 'strlen'))[0] ?? '');
            foreach (hook_project_write_dirs() as $dir) {
                if (str_contains($target, $dir)) {
                    return $target;
                }
            }
        }
    }

    return null;
}

/** Is `$path` (relative to the project) tracked by git? */
function hook_git_tracked(string $cwd, string $path): bool
{
    $rel = str_starts_with($path, $cwd . '/') ? substr($path, strlen($cwd) + 1) : $path;
    $rel = preg_replace('~^(?:\./)+~', '', $rel);
    if ($rel === '') {
        return false;
    }
    $out = [];
    $code = 1;
    @exec('cd ' . escapeshellarg($cwd) . ' && git ls-files --error-unmatch -- ' . escapeshellarg($rel) . ' 2>/dev/null', $out, $code);

    return $code === 0 && $out !== [];
}

/** Is `$path` matched by the project's ignore rules? */
function hook_git_ignored(string $cwd, string $path): bool
{
    $rel = str_starts_with($path, $cwd . '/') ? substr($path, strlen($cwd) + 1) : $path;
    $rel = preg_replace('~^(?:\./)+~', '', $rel);
    if ($rel === '' || str_starts_with($rel, '/')) {
        return false;
    }
    $out = [];
    $code = 1;
    @exec('cd ' . escapeshellarg($cwd) . ' && git check-ignore -q -- ' . escapeshellarg($rel) . ' 2>/dev/null', $out, $code);

    return $code === 0;
}

/** Is a rebuild (`docker/sdk reset|clean-data|up`) running right now? */
function hook_rebuild_running(string $cwd = ''): bool
{
    $out = [];
    $code = 1;
    @exec("pgrep -f 'docker/sdk (reset|clean-data|up)' 2>/dev/null", $out, $code);
    if ($code !== 0 || $out === []) {
        return false;
    }
    if ($cwd === '') {
        return true;
    }
    // Only a rebuild whose working directory is this project counts; a rebuild of another project on the same machine does not.
    foreach ($out as $pid) {
        $dir = trim((string) @shell_exec('lsof -a -p ' . (int) $pid . ' -d cwd -Fn 2>/dev/null | sed -n "s/^n//p"'));
        if ($dir !== '' && ($dir === rtrim($cwd, '/') || str_starts_with($dir . '/', rtrim($cwd, '/') . '/'))) {
            return true;
        }
    }

    return false;
}

/**
 * `rm -rf <path>` targets under data/cache that do not exist, with what does exist beside them.
 */
function hook_missing_cache_target(string $command, string $cwd): ?array
{
    if (preg_match_all('~\brm\s+(?:-[a-zA-Z]+\s+)*([^\s;|&]*data/cache[^\s;|&]*)~', $command, $m) === 0) {
        return null;
    }
    foreach ($m[1] as $target) {
        $clean = trim($target, "\"'");
        $abs = str_starts_with($clean, '/') ? $clean : $cwd . '/' . preg_replace('~^(?:\./)+~', '', $clean);
        if (is_dir($abs) || is_file($abs)) {
            continue;
        }
        if (str_starts_with($clean, '/') && preg_match('~\bdocker(?:/sdk\s+cli|\s+exec|-compose\s+exec)~', $command) === 1) {
            continue;
        }
        $parent = dirname($abs);
        $siblings = is_dir($parent) ? array_values(array_diff((array) scandir($parent), ['.', '..'])) : [];

        return ['target' => $clean, 'parent' => $parent, 'siblings' => $siblings];
    }

    return null;
}

/** The destination of a `cp`/`mv` when it is a tracked project file, or null. */
function hook_overwrite_target(string $cwd, string $command): ?string
{
    if (preg_match_all('~(?:^|[\s;&|(])(cp|mv)\s+((?:-[a-zA-Z]+\s+)*)([^;&|]+)~', $command, $m, PREG_SET_ORDER) === 0) {
        return null;
    }
    foreach ($m as $match) {
        $args = preg_split('~\s+~', trim($match[3])) ?: [];
        $args = array_values(array_filter($args, static fn ($a) => $a !== '' && !str_starts_with($a, '-')));
        if (count($args) < 2) {
            continue;
        }
        $dst = trim((string) end($args), "\"'");
        if ($dst === '' || str_contains($dst, '$')) {
            continue;
        }
        $abs = str_starts_with($dst, '/') ? $dst : $cwd . '/' . preg_replace('~^(?:\./)+~', '', $dst);
        if (!is_file($abs)) {
            continue;
        }
        if (hook_git_tracked($cwd, $abs)) {
            return str_starts_with($abs, $cwd . '/') ? substr($abs, strlen($cwd) + 1) : $abs;
        }
    }

    return null;
}

/** Does the command move, delete or copy over a gate/preflight baseline? */
function hook_touches_baseline(string $command): bool
{
    if (preg_match('~(?:^|[\s;&|(])(rm|mv|cp)\s~', $command) !== 1) {
        return false;
    }

    return preg_match('~\.ai-dev/[^\s;|&]*baseline[^\s;|&]*\.json~', $command) === 1;
}

/** Skills whose presence in the transcript means customization work was actually requested. */
function hook_customization_skills(): array
{
    return ['spryker-customization', 'payment-template', 'propel-schema', 'data-import', 'spryker-bugfix', 'codecept-functional', 'ai-runtime-debugging', 'spryker-upgrade',
        'define-stores', 'configure-codebase', 'configure-services', 'project-ci-generator', 'project-starter-wizard'];
}

/** Was one of `$names` loaded via the Skill tool anywhere in the transcript? */
function hook_skill_loaded(string $transcriptPath, array $names): bool
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return false;
    }
    $fh = fopen($transcriptPath, 'rb');
    if ($fh === false) {
        return false;
    }
    $found = false;
    while (($line = fgets($fh)) !== false) {
        if (!str_contains($line, 'Skill')) {
            continue;
        }
        $d = json_decode($line, true);
        if (!is_array($d) || ($d['type'] ?? '') !== 'assistant') {
            continue;
        }
        foreach ((array) ($d['message']['content'] ?? []) as $c) {
            if (($c['type'] ?? '') !== 'tool_use' || ($c['name'] ?? '') !== 'Skill') {
                continue;
            }
            $skill = (string) ($c['input']['skill'] ?? '');
            $skill = str_contains($skill, ':') ? substr($skill, (int) strrpos($skill, ':') + 1) : $skill;
            if (in_array($skill, $names, true)) {
                $found = true;
                break 2;
            }
        }
    }
    fclose($fh);

    return $found;
}

/** Is this path a new PHP file that changes how the shop behaves (class 4, customization)? */
function hook_is_new_customization_file(string $cwd, string $rel): bool
{
    if (preg_match('~^src/([A-Za-z0-9_]+)/(Zed|Yves|Client|Glue|Service|Shared)/.+\.(php|transfer\.xml|schema\.xml)$~', $rel, $m) !== 1) {
        return false;
    }
    $vendorNs = ['Generated', 'Orm', 'Spryker', 'SprykerShop', 'SprykerEco', 'SprykerFeature', 'SprykerSdk', 'SprykerMerchantPortal'];
    if (in_array($m[1], $vendorNs, true)) {
        return false;
    }
    if (preg_match('~/(Theme|Presentation)/~', $rel) === 1) {
        return false;
    }
    if (preg_match('~(Config|DependencyProvider)\.php$~', $rel) === 1) {
        return true;
    }

    return !is_file($cwd . '/' . $rel);
}

/**
 * The project namespaces this codebase actually carries, in precedence order.
 *
 * @return list<string>
 */
function hook_project_namespaces(string $cwd): array
{
    $out = [];
    $config = $cwd . '/config/Shared/config_default.php';
    if (is_file($config)) {
        $body = (string) file_get_contents($config);
        if (preg_match('~KernelConstants::PROJECT_NAMESPACES\]\s*=\s*\[(.*?)\]~s', $body, $m) === 1
            && preg_match_all('~[\'"]([A-Za-z0-9_]+)[\'"]~', $m[1], $names) > 0) {
            $out = $names[1];
        }
    }
    if ($out !== []) {
        return $out;
    }
    $composer = $cwd . '/composer.json';
    if (is_file($composer)) {
        $json = json_decode((string) file_get_contents($composer), true);
        foreach (array_keys((array) ($json['autoload']['psr-4'] ?? [])) as $ns) {
            $ns = rtrim((string) $ns, '\\');
            if ($ns === '' || in_array($ns, ['Generated', 'Orm'], true) || str_starts_with($ns, 'Spryker')) {
                continue;
            }
            $out[] = $ns;
        }
    }

    return $out;
}

/** Is `$rel` a storefront template or stylesheet (the design class of work)? */
function hook_is_frontend_file(string $rel): bool
{
    if (preg_match('~^src/[A-Za-z0-9_]+/(Yves|Shared)/.+/(Theme|Presentation)/.+\.(twig|scss|css|js|ts)$~', $rel) === 1) {
        return true;
    }

    return preg_match('~^data/configuration/(shop_ui|gui|zed_ui)\.configuration\.yml$~', $rel) === 1;
}

/** Skills that own design work — any one of them satisfies the frontend gate. */
function hook_design_skills(): array
{
    return ['yves-atomic-frontend', 'match-reference-design', 'brand-project', 'demo-intake'];
}

/**
 * Text of every `Agent`/`Task` prompt in the payload, plus the subagent type.
 *
 * @return array{type:string, prompt:string, description:string}
 */
function hook_agent_dispatch(array $payload): array
{
    $input = (array) ($payload['tool_input'] ?? []);

    return [
        'type' => hook_bare_agent_type((string) ($input['subagent_type'] ?? '')),
        'prompt' => (string) ($input['prompt'] ?? ''),
        'description' => (string) ($input['description'] ?? ''),
    ];
}

/** Agent type without its plugin prefix (`spryker-ai-dev-sdk:spryker-verifier` → `spryker-verifier`). */
function hook_bare_agent_type(string $type): string
{
    $pos = strrpos($type, ':');

    return $pos === false ? $type : substr($type, $pos + 1);
}

/**
 * Does the last assistant message assert that the user cancelled or killed the run's own work?
 */
function hook_claims_user_cancelled_work(string $lastMessage): bool
{
    return preg_match('~\b(you (?:keep|kept) (?:stopping|killing)|you(?:\'ve| have) (?:now )?(?:killed|stopped)|work you keep stopping|killed (?:a|the|my) (?:sub-?agent|agent|translator|verifier))~i', $lastMessage) === 1;
}

/** Surfaces a design pass must evidence before it can be called done. */
function hook_design_surfaces(): array
{
    return ['header', 'dropdown', 'hero', 'homepage', 'plp', 'pdp', 'cart', 'checkout', 'search', 'footer', 'mobile', 'tablet'];
}

/**
 * Which design surfaces `$body` does NOT evidence.
 *
 * @return list<string>
 */
function hook_design_gaps(string $body): array
{
    $gaps = [];
    $lines = preg_split('/\R/', $body) ?: [];
    $seen = [];
    foreach (hook_design_surfaces() as $surface) {
        $ok = false;
        foreach ($lines as $line) {
            if (stripos($line, $surface) === false) {
                continue;
            }
            if (preg_match('~(\d+\s*[x×]\s*\d+|\d+\s*(px|rem|%)\b|#[0-9a-f]{3,8}\b|\d+\s*/\s*\d+\b|https?://|/[A-Z]{2}/[a-z]{2}\b|[\w./-]+\.(png|jpe?g|svg|webp|twig|scss|css|yml))~i', $line) !== 1) {
                continue;
            }
            $token = strtolower(trim(preg_replace('~[|*`]~', ' ', str_ireplace($surface, '', $line)) ?? ''));
            $token = preg_replace('~\s+~', ' ', $token) ?? $token;
            if ($token !== '' && isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $ok = true;
            break;
        }
        if (!$ok) {
            $gaps[] = $surface;
        }
    }

    return $gaps;
}

/** Did a markdown steps table flip the row named `$step` to a done-ish status? */
function hook_step_is_done(string $body, string $step): bool
{
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        if (!str_starts_with(ltrim($line), '|')) {
            continue;
        }
        $cells = array_map('trim', explode('|', trim($line, " \t|")));
        $nameAt = null;
        foreach ($cells as $i => $cell) {
            $c = strtolower(trim(preg_replace('~[*`]~', '', $cell) ?? $cell));
            if ($c === strtolower($step) || str_starts_with($c, strtolower($step) . ' (')) {
                $nameAt = $i;
                break;
            }
        }
        if ($nameAt === null) {
            continue;
        }
        for ($i = $nameAt + 1; $i < count($cells); $i++) {
            $c = strtolower(trim(preg_replace('~[*`]~', '', $cells[$i]) ?? $cells[$i]));
            if ($c === '') {
                continue;
            }
            if (preg_match('~^(done|skipped|n/a|not needed)\b~', $c) === 1) {
                return true;
            }

            return false;
        }
    }

    return false;
}

/** Does this edit flip `$step` to done (done in the new text, not in the old)? */
function hook_step_newly_done(string $newText, string $oldText, string $step): bool
{
    return hook_step_is_done($newText, $step) && !hook_step_is_done($oldText, $step);
}

/** Does the last assistant message end the turn because of a permission or allowlist limit? */
function hook_stops_on_permissions(string $lastMessage): bool
{
    if (preg_match('~(report (?:is )?written|written to [`\[]?\.ai-dev|recorded (?:the rest|them|it)|listed under \*?\*?blocked|rather than stopping|worked entirely within the allow)~i', $lastMessage) === 1) {
        return false;
    }
    foreach (preg_split('/(?<=[.!?\n])\s+/', $lastMessage) ?: [] as $sentence) {
        if (preg_match('~\b(allow-?list|allowlisted|permission prompt|permissions?)\b~i', $sentence) !== 1) {
            continue;
        }
        if (preg_match('~\b(so I (?:am |\'m )?stopp|stopping here|cannot (?:continue|proceed|go further)|can(?:not|\'t) do (?:any|this) more|blocked (?:on|by) (?:this|that|it|permission)|(?:this|the) step is blocked|handing back|until you (?:allow|approve|add))~i', $sentence) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Is `$path` real evidence, or a file that merely exists?
 *
 * @return string|null null when it is real evidence, otherwise why it is not
 */
function hook_evidence_missing(string $abs, string $verdictPattern = '~\b(PASS|FAIL|BLOCKED)\b~'): ?string
{
    if (!is_file($abs)) {
        return 'it does not exist';
    }
    $body = trim((string) file_get_contents($abs));
    if ($body === '') {
        return 'it is empty';
    }
    if (strlen($body) < 200) {
        return 'it is ' . strlen($body) . ' bytes (the minimum is 200)';
    }
    if ($verdictPattern !== '' && preg_match($verdictPattern, $body) !== 1) {
        return 'it has no verdict line';
    }

    return null;
}

/** Was `$abs` last written before the last rebuild (older than `.ai-dev/rebuild-log`)? */
function hook_evidence_stale(string $cwd, string $abs): bool
{
    $log = $cwd . '/.ai-dev/rebuild-log';
    if (!is_file($log) || !is_file($abs)) {
        return false;
    }

    return (int) filemtime($abs) < (int) filemtime($log);
}

/** Did this session dispatch the `spryker-verifier` agent? */
function hook_verifier_dispatched(string $transcriptPath): bool
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return false;
    }
    $fh = fopen($transcriptPath, 'rb');
    if ($fh === false) {
        return false;
    }
    $found = false;
    while (($line = fgets($fh)) !== false) {
        if (!str_contains($line, 'spryker-verifier')) {
            continue;
        }
        $d = json_decode($line, true);
        if (!is_array($d) || ($d['type'] ?? '') !== 'assistant') {
            continue;
        }
        foreach ((array) ($d['message']['content'] ?? []) as $c) {
            if (($c['type'] ?? '') !== 'tool_use' || !in_array($c['name'] ?? '', ['Agent', 'Task'], true)) {
                continue;
            }
            if (hook_bare_agent_type((string) ($c['input']['subagent_type'] ?? '')) === 'spryker-verifier') {
                $found = true;
                break 2;
            }
        }
    }
    fclose($fh);

    return $found;
}

/** Did this session edit a frontend file? */
function hook_frontend_edited(string $transcriptPath, string $cwd = ''): bool
{
    if ($transcriptPath === '' || !is_file($transcriptPath)) {
        return false;
    }
    $fh = fopen($transcriptPath, 'rb');
    if ($fh === false) {
        return false;
    }
    $found = false;
    while (($line = fgets($fh)) !== false) {
        if (!str_contains($line, 'file_path')) {
            continue;
        }
        $d = json_decode($line, true);
        if (!is_array($d) || ($d['type'] ?? '') !== 'assistant') {
            continue;
        }
        foreach ((array) ($d['message']['content'] ?? []) as $c) {
            if (($c['type'] ?? '') !== 'tool_use' || !in_array($c['name'] ?? '', ['Edit', 'Write', 'MultiEdit'], true)) {
                continue;
            }
            $fp = (string) ($c['input']['file_path'] ?? '');
            $rel = $cwd !== '' && str_starts_with($fp, rtrim($cwd, '/') . '/') ? substr($fp, strlen(rtrim($cwd, '/')) + 1) : (preg_replace('~^.*/(src|data)/~', '$1/', $fp) ?? $fp);
            if ($fp !== '' && hook_is_frontend_file(ltrim($rel, '/'))) {
                $found = true;
                break 2;
            }
        }
    }
    fclose($fh);

    return $found;
}

/** The newest mtime among the project's frontend files, or 0. */
function hook_frontend_mtime(string $cwd): int
{
    $newest = 0;
    foreach ([$cwd . '/src', $cwd . '/data/configuration'] as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = substr($f->getPathname(), strlen($cwd) + 1);
            if (hook_is_frontend_file($rel)) {
                $newest = max($newest, (int) $f->getMTime());
            }
        }
    }

    return $newest;
}

/** Has any row in a steps table moved off `pending`? */
function hook_any_step_started(string $body): bool
{
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        if (!str_starts_with(ltrim($line), '|')) {
            continue;
        }
        $cells = array_map('trim', explode('|', trim($line, " \t|")));
        if (preg_match('~\|\s*`?(?:wizard:interview|demo-intake|intake|routing)`?\s*\|~i', $line) === 1) {
            continue;
        }
        foreach ($cells as $cell) {
            $c = strtolower(trim(preg_replace('~[*`]~', '', $cell) ?? $cell));
            if (preg_match('~^(done|skipped|in-progress|in progress)\b~', $c) === 1) {
                return true;
            }
        }
    }

    return false;
}

/** Sub-agent types that load the SDK's own rules. */
function hook_skill_aware_agents(): array
{
    return ['spryker-verifier'];
}
