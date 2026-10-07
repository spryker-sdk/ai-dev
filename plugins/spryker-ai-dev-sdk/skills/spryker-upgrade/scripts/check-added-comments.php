<?php

/**
 * Added-comments detector: lists comment lines ADDED to the project's own code since the upgrade's
 * base commit.
 *
 * Upgrade code carries no explanatory comments: methods and variables are named so the code explains
 * itself. What stays allowed, because it is type information, tooling or a sanctioned marker:
 *   - docblock tag lines (@param, @return, @throws, @var, @api, @deprecated, @see, @method,
 *     @property, @template, @phpstan-*, @psalm-*, @module, @uses, ...) and their continuation lines
 *   - {@inheritDoc}, bare docblock delimiters
 *   - Facade `Specification:` blocks and their `- ` bullets
 *   - any comment containing the marker `upgrade-debt` (temporary shims the skill tracks)
 *   - tool directives that do not hide a finding (prettier-ignore, eslint-env, global, ...)
 *   - the file license header of a PHP file
 * Suppressions (phpcs:ignore, @phpstan-ignore, @psalm-suppress, eslint-disable, @ts-ignore, ...) are
 * reported as kind `suppression`: an upgrade fixes each finding instead of silencing it.
 *
 * Scope: tracked and untracked files under src/ (php, js, ts, twig) and config/ (php), excluding
 * src/Generated, src/Orm, vendor and node_modules. Added lines come from `git diff -U0 <base>`
 * against the working tree; comments are found by tokenising the full current file.
 *
 * Usage:
 *   php $UP/check-added-comments.php --record-base [--force]   # Phase 0: store HEAD as base ref
 *   php $UP/check-added-comments.php                            # compare against the stored base
 *   php $UP/check-added-comments.php --base <ref>               # compare against an explicit ref
 *
 * Exit 0 clean, 1 when added comments are found, 2 on usage error (no base ref, not a git repo).
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

const AC_ALLOWED_TAGS = '/^@(param|return|returns|throws|var|api|deprecated|see|method|property|property-read|property-write'
    . '|template|template-covariant|extends|implements|mixin|inheritdoc|module|uses|type|typedef|phpstan-[\w-]+|psalm-[\w-]+)\b/i';

const AC_DIRECTIVES = '/^(eslint-env|eslint\s|prettier-ignore|global\s)/i';

const AC_SUPPRESSIONS = '/(phpcs:(ignore|disable)|@phpstan-ignore|@psalm-suppress|@codingStandardsIgnore|@noinspection'
    . '|eslint-disable|@ts-(ignore|expect-error|nocheck)|stylelint-disable|istanbul\s+ignore|@codeCoverageIgnore)/i';

/**
 * Split one comment into its lines and decide, per line, whether it is allowed.
 *
 * @param string $style line|block|doc|twig
 *
 * @return list<array{line: int, text: string, allowed: bool, suppression: bool}>
 */
function ac_classify_comment(string $raw, string $style, int $startLine, bool $isLicenseHeader = false): array
{
    $rawLines = explode("\n", str_replace("\r\n", "\n", $raw));
    $lastIndex = count($rawLines) - 1;
    $wholeAllowed = $isLicenseHeader || stripos($raw, 'upgrade-debt') !== false;

    $stripped = [];
    foreach ($rawLines as $i => $rawLine) {
        $text = $rawLine;
        if ($style === 'line') {
            $text = (string)preg_replace('/^\s*(\/\/+|#)/', '', $text);
        } elseif ($style === 'twig') {
            if ($i === 0) {
                $text = (string)preg_replace('/^\s*\{#-?/', '', $text);
            }
            if ($i === $lastIndex) {
                $text = (string)preg_replace('/-?#\}\s*$/', '', $text);
            }
        } else {
            if ($i === 0) {
                $text = (string)preg_replace('/^\s*\/\*\*?/', '', $text);
            } else {
                $text = (string)preg_replace('/^\s*\*(?!\/)/', '', $text);
            }
            if ($i === $lastIndex) {
                $text = (string)preg_replace('/\*\/\s*$/', '', $text);
            }
        }
        $stripped[] = $text;
    }

    $firstText = '';
    foreach ($stripped as $text) {
        if (trim($text) !== '') {
            $firstText = trim($text);
            break;
        }
    }
    if (in_array($style, ['line', 'block'], true) && preg_match(AC_DIRECTIVES, $firstText)) {
        $wholeAllowed = true;
    }

    $result = [];
    $inSpecification = false;
    $inTag = false;
    foreach ($stripped as $i => $text) {
        $trimmed = trim($text);
        if ($trimmed === '') {
            $inSpecification = false;

            continue;
        }
        $allowed = $wholeAllowed;
        if (!$allowed && $style !== 'line') {
            if (preg_match('/^Specification:?$/i', $trimmed)) {
                $inSpecification = true;
                $inTag = false;
                $allowed = true;
            } elseif (preg_match('/^\{@inheritdoc\}$/i', $trimmed)) {
                $allowed = true;
            } elseif (str_starts_with($trimmed, '@')) {
                $inSpecification = false;
                $inTag = (bool)preg_match(AC_ALLOWED_TAGS, $trimmed);
                $allowed = $inTag;
            } elseif ($inSpecification && (str_starts_with($trimmed, '-') || preg_match('/^\s{2,}/', $text))) {
                $allowed = true;
            } elseif ($inTag) {
                $allowed = true;
            }
        }
        $suppression = (bool)preg_match(AC_SUPPRESSIONS, $trimmed);
        $result[] = ['line' => $startLine + $i, 'text' => $trimmed, 'allowed' => $allowed && !$suppression, 'suppression' => $suppression];
    }

    return $result;
}

/**
 * Comments of a PHP source, from the tokenizer.
 *
 * @return list<array{line: int, style: string, raw: string, trailing: bool, license: bool}>
 */
function ac_php_comments(string $src): array
{
    $comments = [];
    $lastCodeLine = 0;
    $seenCode = false;
    $line = 1;
    foreach (token_get_all($src) as $token) {
        if (!is_array($token)) {
            $lastCodeLine = $line;
            $seenCode = true;

            continue;
        }
        [$id, $text, $line] = $token;
        $endLine = $line + substr_count($text, "\n");
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            $style = $id === T_DOC_COMMENT ? 'doc' : (str_starts_with($text, '/*') ? 'block' : 'line');
            $comments[] = [
                'line' => $line,
                'style' => $style,
                'raw' => rtrim($text, "\r\n"),
                'trailing' => $lastCodeLine === $line,
                'license' => !$seenCode && $comments === [] && (bool)preg_match('/licen[cs]e|copyright/i', $text),
            ];
        } elseif (!in_array($id, [T_WHITESPACE, T_OPEN_TAG, T_INLINE_HTML], true)) {
            $lastCodeLine = $endLine;
            $seenCode = true;
        }
        $line = $endLine;
    }

    return $comments;
}

/**
 * Comments of a JavaScript/TypeScript source. A small lexer that skips strings, template literals
 * and regex literals so `//` inside them is not taken for a comment.
 *
 * @return list<array{line: int, style: string, raw: string, trailing: bool, license: bool}>
 */
function ac_js_comments(string $src): array
{
    $comments = [];
    $length = strlen($src);
    $line = 1;
    $codeOnLine = false;
    $prevChar = '';
    $prevWord = '';
    $i = 0;
    while ($i < $length) {
        $c = $src[$i];
        $next = $src[$i + 1] ?? '';
        if ($c === "\n") {
            $line++;
            $codeOnLine = false;
            $i++;

            continue;
        }
        if (ctype_space($c)) {
            $i++;

            continue;
        }
        if ($c === '/' && $next === '/') {
            $end = strpos($src, "\n", $i);
            $end = $end === false ? $length : $end;
            $comments[] = ['line' => $line, 'style' => 'line', 'raw' => rtrim(substr($src, $i, $end - $i), "\r"), 'trailing' => $codeOnLine, 'license' => false];
            $i = $end;

            continue;
        }
        if ($c === '/' && $next === '*') {
            $end = strpos($src, '*/', $i + 2);
            $end = $end === false ? $length : $end + 2;
            $raw = substr($src, $i, $end - $i);
            $isDoc = str_starts_with($raw, '/**') && $raw !== '/**/';
            $comments[] = ['line' => $line, 'style' => $isDoc ? 'doc' : 'block', 'raw' => $raw, 'trailing' => $codeOnLine, 'license' => false];
            $line += substr_count($raw, "\n");
            $i = $end;

            continue;
        }
        if ($c === '"' || $c === "'" || $c === '`') {
            $i++;
            while ($i < $length && $src[$i] !== $c) {
                if ($src[$i] === '\\') {
                    $i++;
                } elseif ($src[$i] === "\n") {
                    if ($c !== '`') {
                        break;
                    }
                    $line++;
                }
                $i++;
            }
            if ($i < $length && $src[$i] === $c) {
                $i++;
            }
            $codeOnLine = true;
            $prevChar = 'a';
            $prevWord = '';

            continue;
        }
        $regexAllowed = $prevChar === '' || str_contains('(,=:[!&|?{};+-*%<>~^', $prevChar)
            || in_array($prevWord, ['return', 'typeof', 'case', 'in', 'of', 'new', 'delete', 'void', 'throw', 'yield', 'await'], true);
        if ($c === '/' && $regexAllowed) {
            $i++;
            $inClass = false;
            while ($i < $length && $src[$i] !== "\n") {
                $ch = $src[$i];
                if ($ch === '\\') {
                    $i += 2;

                    continue;
                }
                if ($ch === '[') {
                    $inClass = true;
                } elseif ($ch === ']') {
                    $inClass = false;
                } elseif ($ch === '/' && !$inClass) {
                    break;
                }
                $i++;
            }
            if ($i < $length && $src[$i] === '/') {
                $i++;
            }
            while ($i < $length && ctype_alpha($src[$i])) {
                $i++;
            }
            $codeOnLine = true;
            $prevChar = 'a';
            $prevWord = '';

            continue;
        }
        if (ctype_alnum($c) || $c === '_' || $c === '$') {
            $start = $i;
            while ($i < $length && (ctype_alnum($src[$i]) || $src[$i] === '_' || $src[$i] === '$')) {
                $i++;
            }
            $prevWord = substr($src, $start, $i - $start);
            $prevChar = 'a';
            $codeOnLine = true;

            continue;
        }
        $prevChar = $c;
        $prevWord = '';
        $codeOnLine = true;
        $i++;
    }

    return $comments;
}

/**
 * Comments of a Twig template (`{# ... #}`).
 *
 * @return list<array{line: int, style: string, raw: string, trailing: bool, license: bool}>
 */
function ac_twig_comments(string $src): array
{
    $comments = [];
    if (!preg_match_all('/\{#.*?#\}/s', $src, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($matches[0] as [$raw, $offset]) {
        $before = substr($src, 0, $offset);
        $lineStart = strrpos($before, "\n");
        $prefix = $lineStart === false ? $before : substr($before, $lineStart + 1);
        $comments[] = [
            'line' => substr_count($before, "\n") + 1,
            'style' => 'twig',
            'raw' => $raw,
            'trailing' => trim($prefix) !== '',
            'license' => false,
        ];
    }

    return $comments;
}

/**
 * Every disallowed comment line of a file, keyed by line number.
 *
 * @return array<int, array{text: string, kind: string}>
 */
function ac_disallowed_comment_lines(string $src, string $extension): array
{
    $comments = match ($extension) {
        'php' => ac_php_comments($src),
        'js', 'ts' => ac_js_comments($src),
        'twig' => ac_twig_comments($src),
        default => [],
    };

    $lines = [];
    foreach ($comments as $comment) {
        foreach (ac_classify_comment($comment['raw'], $comment['style'], $comment['line'], $comment['license']) as $entry) {
            if ($entry['allowed'] || isset($lines[$entry['line']])) {
                continue;
            }
            $isFirstLine = $entry['line'] === $comment['line'];
            $lines[$entry['line']] = [
                'text' => $entry['text'],
                'kind' => $entry['suppression'] ? 'suppression' : ($isFirstLine && $comment['trailing'] ? 'trailing' : $comment['style']),
            ];
        }
    }

    return $lines;
}

/**
 * Added line numbers per file from `git diff -U0` output.
 *
 * @return array<string, list<int>>
 */
function ac_parse_added_lines(string $diff): array
{
    $added = [];
    $current = null;
    $previous = '';
    foreach (explode("\n", $diff) as $row) {
        $isHeader = str_starts_with($row, '+++ ') && str_starts_with($previous, '--- ');
        $previous = $row;
        if ($isHeader) {
            $path = trim(substr($row, 4), '"');
            $current = $path === '/dev/null' ? null : (string)preg_replace('#^b/#', '', $path);

            continue;
        }
        if ($current !== null && preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $row, $m)) {
            $start = (int)$m[1];
            $count = isset($m[2]) ? (int)$m[2] : 1;
            for ($n = 0; $n < $count; $n++) {
                $added[$current][] = $start + $n;
            }
        }
    }

    return $added;
}

/**
 * Whether a project-relative path is in scope, and its extension when it is.
 */
function ac_in_scope(string $relPath): ?string
{
    if (preg_match('#(^|/)(vendor|node_modules)/#', $relPath) || preg_match('#^src/(Generated|Orm)/#', $relPath)) {
        return null;
    }
    $extension = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
    if (str_starts_with($relPath, 'src/') && in_array($extension, ['php', 'js', 'ts', 'twig'], true)) {
        return $extension;
    }
    if (str_starts_with($relPath, 'config/') && $extension === 'php') {
        return $extension;
    }

    return null;
}

function ac_main(array $argv): int
{
    require_once __DIR__ . '/bootstrap.php';

    $options = getopt('', ['base:', 'record-base', 'force', 'help']) ?: [];
    if (isset($options['help'])) {
        fwrite(STDOUT, "Usage: php check-added-comments.php [--base <ref>] | --record-base [--force]\n");

        return 0;
    }

    $root = spryker_upgrade_project_root();
    $stateDir = spryker_upgrade_state_dir($root);
    $reportFile = $stateDir . '/added-comments-report.json';

    [$code] = spryker_upgrade_git($root, ['rev-parse', '--git-dir']);
    if ($code !== 0) {
        fwrite(STDERR, "$root is not a git work tree.\n");

        return 2;
    }

    if (isset($options['record-base'])) {
        $record = spryker_upgrade_record_base_ref($root, $stateDir, isset($options['force']));
        fwrite(STDOUT, sprintf(
            "%s base ref %s in %s\n",
            $record['written'] ? 'Recorded' : 'Kept existing',
            $record['ref'],
            spryker_upgrade_rel(spryker_upgrade_base_ref_file($stateDir), $root)
        ));

        return 0;
    }

    $base = is_string($options['base'] ?? null) ? $options['base'] : spryker_upgrade_read_base_ref($stateDir);
    if ($base === null) {
        fwrite(STDERR, "No base ref. Pass --base <ref>, or run --record-base in Phase 0.\n");

        return 2;
    }
    [$code, , $stderr] = spryker_upgrade_git($root, ['rev-parse', '--verify', '--quiet', $base . '^{commit}']);
    if ($code !== 0) {
        fwrite(STDERR, "Base ref does not resolve to a commit: $base " . trim($stderr) . "\n");

        return 2;
    }

    [$code, $diff, $stderr] = spryker_upgrade_git(
        $root,
        ['diff', '-U0', '-M', '--no-color', '--no-ext-diff', '--relative', $base, '--', 'src', 'config']
    );
    if ($code !== 0) {
        fwrite(STDERR, 'git diff failed: ' . trim($stderr) . "\n");

        return 2;
    }
    $addedLines = ac_parse_added_lines($diff);

    [, $untracked] = spryker_upgrade_git($root, ['ls-files', '--others', '--exclude-standard', '--', 'src', 'config']);
    foreach (array_filter(explode("\n", $untracked)) as $relPath) {
        $addedLines[$relPath] = null;
    }

    $findings = [];
    $filesScanned = 0;
    ksort($addedLines);
    foreach ($addedLines as $relPath => $lines) {
        $extension = ac_in_scope($relPath);
        $absolute = $root . '/' . $relPath;
        if ($extension === null || !is_file($absolute)) {
            continue;
        }
        $filesScanned++;
        $disallowed = ac_disallowed_comment_lines((string)file_get_contents($absolute), $extension);
        $wanted = $lines === null ? null : array_flip($lines);
        foreach ($disallowed as $lineNumber => $entry) {
            if ($wanted !== null && !isset($wanted[$lineNumber])) {
                continue;
            }
            $findings[] = ['file' => $relPath, 'line' => $lineNumber, 'kind' => $entry['kind'], 'text' => $entry['text']];
        }
    }

    file_put_contents($reportFile, json_encode([
        'createdAt' => date('c'),
        'baseRef' => $base,
        'filesScanned' => $filesScanned,
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    fwrite(STDOUT, sprintf("Comments added since %s: %d file(s) with added lines scanned.\n\n", $base, $filesScanned));
    if ($findings === []) {
        fwrite(STDOUT, "OK: no explanatory comments added.\n");
    } else {
        fwrite(STDOUT, sprintf("ADDED COMMENTS (%d) — remove them; rename the method/variable instead:\n", count($findings)));
        foreach ($findings as $finding) {
            fwrite(STDOUT, sprintf("  %s:%d  [%s] %s\n", $finding['file'], $finding['line'], $finding['kind'], $finding['text']));
        }
        fwrite(STDOUT, "\n");
    }
    fwrite(STDOUT, 'Full report: ' . spryker_upgrade_rel($reportFile, $root) . "\n");

    return $findings === [] ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(ac_main($argv));
}
