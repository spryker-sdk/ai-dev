<?php

/**
 * Constant-override detector: project classes that redeclare a constant a vendor ancestor also
 * declares, with a different value.
 *
 * Spryker classes read their settings through `static::CONSTANT`, so a project DependencyProvider,
 * Config, Factory or any other class that redeclares a vendor constant replaces the vendor value.
 * When core changes that value in an upgrade, the project keeps the old one and the core change never
 * takes effect.
 *
 * Every class under src/<Ns>/ (each entry of KernelConstants::PROJECT_NAMESPACES, else src/Pyz) is
 * parsed with token_get_all; its parent chain is resolved through the project classes and
 * vendor/composer/autoload_classmap.php / autoload_psr4.php. For each constant the class declares, the
 * nearest vendor ancestor declaring the same constant supplies the core value. Values are compared as
 * normalised source text; `self::`, `static::` and `parent::` references are resolved along the same
 * class chain, and a value that still references another class or calls a function is compared as an
 * `expression`.
 *
 * Usage:
 *   php $UP/check-constant-overrides.php --snapshot   # Phase 0, before composer moves
 *   php $UP/check-constant-overrides.php              # after the update
 *
 * --snapshot writes constant-overrides-baseline.json (project and core value of every override) and
 * exits 0. The default run writes constant-overrides-report.json: every override whose value differs
 * from core (for review), and every core value that changed or disappeared since the snapshot under a
 * project override (findings, exit 1). Exit 0 when no core value changed, 2 on usage error, missing
 * vendor autoload maps or a missing snapshot.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/bootstrap.php';

const CO_MAX_CHAIN = 25;
const CO_MAX_DEPTH = 10;

/**
 * Resolve a class name as written in source to a fully qualified name without leading backslash.
 * `self`, `static` and `parent` are returned lowercased.
 *
 * @param array<string, string> $uses alias => FQCN
 */
function co_resolve_name(string $name, string $namespace, array $uses): string
{
    $lower = strtolower($name);
    if (in_array($lower, ['self', 'static', 'parent'], true)) {
        return $lower;
    }
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }
    if (str_starts_with($lower, 'namespace\\')) {
        return ltrim($namespace . '\\' . substr($name, 10), '\\');
    }
    $parts = explode('\\', $name);
    if (isset($uses[$parts[0]])) {
        $parts[0] = $uses[$parts[0]];

        return implode('\\', $parts);
    }

    return ltrim($namespace . '\\' . $name, '\\');
}

/**
 * Normalised value tokens of a constant expression: no whitespace or comments, string literals
 * re-encoded with var_export, `array(...)` written as `[...]`, trailing commas dropped, keywords
 * lowercased, and class names in `X::` positions resolved to `\FQCN`.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @param array<string, string> $uses
 *
 * @return list<string>
 */
function co_normalise_tokens(array $tokens, string $namespace, array $uses): array
{
    $tokens = array_values(array_filter(
        $tokens,
        static fn($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));
    $out = [];
    $closers = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $next = $tokens[$i + 1] ?? null;
        if (!is_array($token)) {
            if ($token === '(' || $token === '[') {
                $closers[] = $token === '(' ? ')' : ']';
                $out[] = $token;
            } elseif ($token === ')' || $token === ']') {
                if (end($out) === ',') {
                    array_pop($out);
                }
                $out[] = array_pop($closers) ?? $token;
            } else {
                $out[] = $token;
            }

            continue;
        }
        [$id, $text] = $token;
        if ($id === T_ARRAY && $next === '(') {
            $out[] = '[';
            $closers[] = ']';
            $i++;

            continue;
        }
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $out[] = co_string_literal($text);

            continue;
        }
        if ($id === T_LNUMBER || $id === T_DNUMBER) {
            $out[] = strtolower(str_replace('_', '', $text));

            continue;
        }
        $isName = in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC], true);
        if ($isName && is_array($next) && $next[0] === T_DOUBLE_COLON) {
            $resolved = co_resolve_name($text, $namespace, $uses);
            $out[] = in_array($resolved, ['self', 'static', 'parent'], true) ? $resolved : '\\' . $resolved;

            continue;
        }
        if ($id === T_STRING && in_array(strtolower($text), ['true', 'false', 'null'], true)) {
            $out[] = strtolower($text);

            continue;
        }
        $out[] = $text;
    }

    return $out;
}

/**
 * A PHP string literal re-encoded with var_export, so 'a' and "a" compare equal. A double-quoted
 * string with interpolation is returned unchanged.
 */
function co_string_literal(string $literal): string
{
    $quote = $literal[0];
    $body = substr($literal, 1, -1);
    if ($quote === "'") {
        return var_export(strtr($body, ["\\'" => "'", '\\\\' => '\\']), true);
    }
    if ($quote === '"' && !str_contains($body, '$')) {
        return var_export(stripcslashes($body), true);
    }

    return $literal;
}

/**
 * Classes and interfaces declared in a PHP file: name, parent, and the constants each declares with
 * their raw value tokens and line.
 *
 * @return list<array{name: string, parent: ?string, namespace: string, uses: array<string, string>,
 *                    constants: array<string, array{tokens: list<string>, line: int}>}>
 */
function co_parse_classes(string $code): array
{
    $tokens = token_get_all($code);
    $count = count($tokens);
    $namespace = '';
    $uses = [];
    $classes = [];
    $depth = 0;
    $classDepth = null;
    $current = null;
    $significant = static function (int $from) use ($tokens, $count): int {
        for ($j = $from; $j < $count; $j++) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return $count;
    };
    $previous = null;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;

        if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        } elseif ($text === '}') {
            $depth--;
            if ($current !== null && $classDepth !== null && $depth === $classDepth) {
                $classes[] = $current;
                $current = null;
                $classDepth = null;
            }
        } elseif ($id === T_NAMESPACE && $current === null) {
            $j = $significant($i + 1);
            $name = '';
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $name .= $tokens[$j][1];
                $j = $significant($j + 1);
            }
            if ($name !== '' || ($tokens[$j] ?? null) === '{') {
                $namespace = $name;
                $uses = [];
            }
        } elseif ($id === T_USE && $current === null) {
            $j = $significant($i + 1);
            if (is_array($tokens[$j]) && in_array(strtolower($tokens[$j][1]), ['function', 'const'], true)) {
                $previous = $text;

                continue;
            }
            $prefix = '';
            $name = '';
            $alias = null;
            for (; $j < $count && $tokens[$j] !== ';'; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($t === '{') {
                    $prefix = rtrim($name, '\\') . '\\';
                    $name = '';
                } elseif ($t === ',' || $t === '}') {
                    if ($name !== '') {
                        $full = ltrim($prefix . $name, '\\');
                        $uses[$alias ?? substr($full, (int)strrpos('\\' . $full, '\\'))] = $full;
                    }
                    $name = '';
                    $alias = null;
                } elseif (is_array($t) && $t[0] === T_AS) {
                    $k = $significant($j + 1);
                    $alias = $tokens[$k][1];
                    $j = $k;
                } elseif (is_array($t)) {
                    $name .= $t[1];
                } elseif ($t === '\\') {
                    $name .= '\\';
                }
            }
            if ($name !== '') {
                $full = ltrim($prefix . $name, '\\');
                $uses[$alias ?? substr($full, (int)strrpos('\\' . $full, '\\'))] = $full;
            }
            $i = $j;
        } elseif (($id === T_CLASS || $id === T_INTERFACE) && $current === null
            && !(is_array($previous) ? $previous[0] === T_DOUBLE_COLON || $previous[0] === T_NEW : false)
        ) {
            $j = $significant($i + 1);
            if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                $previous = $token;

                continue;
            }
            $name = ltrim($namespace . '\\' . $tokens[$j][1], '\\');
            $parent = null;
            $k = $significant($j + 1);
            if (is_array($tokens[$k]) && $tokens[$k][0] === T_EXTENDS) {
                $p = $significant($k + 1);
                $parent = co_resolve_name($tokens[$p][1], $namespace, $uses);
            }
            $current = ['name' => $name, 'parent' => $parent, 'namespace' => $namespace, 'uses' => $uses, 'constants' => []];
            $classDepth = $depth;
        } elseif ($id === T_CONST && $current !== null && $depth === $classDepth + 1) {
            $line = $token[2];
            $j = $i + 1;
            while ($j < $count) {
                $nameIndex = null;
                for (; $j < $count && $tokens[$j] !== '='; $j++) {
                    if (is_array($tokens[$j]) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tokens[$j][1]) === 1) {
                        $nameIndex = $j;
                    }
                }
                $valueTokens = [];
                $nesting = 0;
                for ($j++; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (in_array($t, ['(', '['], true) || (is_array($t) && $t[0] === T_CURLY_OPEN)) {
                        $nesting++;
                    } elseif (in_array($t, [')', ']', '}'], true)) {
                        $nesting--;
                    } elseif ($nesting === 0 && ($t === ',' || $t === ';')) {
                        break;
                    }
                    $valueTokens[] = $t;
                }
                if ($nameIndex !== null) {
                    $current['constants'][$tokens[$nameIndex][1]] = [
                        'tokens' => co_normalise_tokens($valueTokens, $namespace, $uses),
                        'line' => $line,
                    ];
                }
                if (($tokens[$j] ?? ';') === ';') {
                    break;
                }
                $j++;
            }
            $i = $j;
        }
        $previous = $token;
    }

    return $classes;
}

/**
 * The lookup context: project classes indexed by FQCN plus the vendor autoload maps.
 *
 * @param list<string> $namespaces
 *
 * @return array{root: string, project: array<string, array>, classmap: array<string, string>,
 *               psr4: array<string, list<string>>, cache: array<string, ?array>}
 */
function co_context(string $root, array $namespaces): array
{
    $project = [];
    foreach ($namespaces as $namespace) {
        $dir = $root . '/src/' . $namespace;
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (co_parse_classes((string)file_get_contents($file->getPathname())) as $class) {
                $project[$class['name']] = $class + ['file' => $file->getPathname(), 'vendor' => false];
            }
        }
    }
    ksort($project);
    $classmapFile = $root . '/vendor/composer/autoload_classmap.php';
    $psr4File = $root . '/vendor/composer/autoload_psr4.php';
    $psr4 = is_file($psr4File) ? (array)(static fn() => require $psr4File)() : [];
    uksort($psr4, static fn($a, $b): int => strlen((string)$b) <=> strlen((string)$a));

    return [
        'root' => $root,
        'project' => $project,
        'classmap' => is_file($classmapFile) ? (array)(static fn() => require $classmapFile)() : [],
        'psr4' => $psr4,
        'cache' => [],
    ];
}

/**
 * The source file of a class from the autoload maps, or null.
 */
function co_class_file(array $context, string $fqcn): ?string
{
    if (isset($context['classmap'][$fqcn]) && is_file($context['classmap'][$fqcn])) {
        return $context['classmap'][$fqcn];
    }
    foreach (array_keys($context['psr4']) as $prefix) {
        if ($prefix === '' || !str_starts_with($fqcn, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix))) . '.php';
        foreach ((array)$context['psr4'][$prefix] as $dir) {
            if (is_file($dir . '/' . $relative)) {
                return $dir . '/' . $relative;
            }
        }
    }

    return null;
}

/**
 * A parsed class: from the project index, else from its vendor file. Null when it cannot be found.
 */
function co_lookup(array &$context, string $fqcn): ?array
{
    if (isset($context['project'][$fqcn])) {
        return $context['project'][$fqcn];
    }
    if (array_key_exists($fqcn, $context['cache'])) {
        return $context['cache'][$fqcn];
    }
    $context['cache'][$fqcn] = null;
    $file = co_class_file($context, $fqcn);
    if ($file === null) {
        return null;
    }
    $real = (string)realpath($file);
    foreach (co_parse_classes((string)file_get_contents($file)) as $class) {
        if ($class['name'] === $fqcn) {
            $context['cache'][$fqcn] = $class + [
                'file' => $real,
                'vendor' => str_starts_with($real, $context['root'] . '/vendor/'),
            ];
        }
    }

    return $context['cache'][$fqcn];
}

/**
 * The ancestors of a class, nearest first, as far as they resolve.
 *
 * @return list<array>
 */
function co_ancestors(array &$context, array $class): array
{
    $chain = [];
    $seen = [$class['name'] => true];
    while (($class['parent'] ?? null) !== null && count($chain) < CO_MAX_CHAIN && !isset($seen[$class['parent']])) {
        $seen[$class['parent']] = true;
        $class = co_lookup($context, $class['parent']);
        if ($class === null) {
            break;
        }
        $chain[] = $class;
    }

    return $chain;
}

/**
 * The class that declares a constant, starting at $class and walking up, or null.
 */
function co_declaring_class(array &$context, array $class, string $constant): ?array
{
    if (isset($class['constants'][$constant])) {
        return $class;
    }
    foreach (co_ancestors($context, $class) as $ancestor) {
        if (isset($ancestor['constants'][$constant])) {
            return $ancestor;
        }
    }

    return null;
}

/**
 * The normalised value of a constant declared in $class, with `self::`, `static::`, `parent::` and
 * `::class` references resolved along its chain and literal string concatenation folded.
 *
 * @return array{text: string, kind: string} kind is `literal` or `expression`
 */
function co_constant_value(array &$context, array $class, string $constant, int $depth = 0): array
{
    $tokens = co_resolve_tokens($context, $class, $class['constants'][$constant]['tokens'] ?? [], $depth);
    $tokens = co_fold_concatenation($tokens);
    $literal = true;
    foreach ($tokens as $token) {
        $isLiteral = in_array($token, ['[', ']', ',', '=>', '-', '+', 'true', 'false', 'null'], true)
            || preg_match('/^(\'.*\'|-?[0-9][0-9a-fx.e+\-]*)$/s', $token) === 1;
        if (!$isLiteral) {
            $literal = false;

            break;
        }
    }

    return ['text' => implode('', $tokens), 'kind' => $literal ? 'literal' : 'expression'];
}

/**
 * Value tokens with `self::`, `static::`, `parent::` and `::class` references replaced by the values
 * they resolve to along the chain of $class; unresolvable references are kept.
 *
 * @param list<string> $tokens
 *
 * @return list<string>
 */
function co_resolve_tokens(array &$context, array $class, array $tokens, int $depth): array
{
    $out = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $scope = $tokens[$i];
        if (!in_array($scope, ['self', 'static', 'parent'], true) || ($tokens[$i + 1] ?? '') !== '::' || !isset($tokens[$i + 2])) {
            $out[] = $scope;

            continue;
        }
        $member = $tokens[$i + 2];
        $start = $class;
        if ($scope === 'parent') {
            $start = $class['parent'] !== null ? co_lookup($context, $class['parent']) : null;
        }
        if (strtolower($member) === 'class' && $start !== null) {
            $out[] = var_export($start['name'], true);
            $i += 2;

            continue;
        }
        $declaring = $start !== null && $depth < CO_MAX_DEPTH ? co_declaring_class($context, $start, $member) : null;
        if ($declaring === null) {
            $out[] = $scope;

            continue;
        }
        $resolved = co_resolve_tokens($context, $declaring, $declaring['constants'][$member]['tokens'], $depth + 1);
        array_push($out, ...co_fold_concatenation($resolved));
        $i += 2;
    }

    return $out;
}

/**
 * Folds `'a' . 'b'` sequences of string and integer literals into one string literal.
 *
 * @param list<string> $tokens
 *
 * @return list<string>
 */
function co_fold_concatenation(array $tokens): array
{
    $isScalar = static fn(string $t): bool => preg_match('/^(\'.*\'|[0-9]+)$/s', $t) === 1;
    $value = static fn(string $t): string => $t[0] === "'" ? strtr(substr($t, 1, -1), ["\\'" => "'", '\\\\' => '\\']) : $t;
    $out = [];
    foreach ($tokens as $i => $token) {
        $count = count($out);
        if ($count >= 2 && $out[$count - 1] === '.' && $isScalar($out[$count - 2]) && $isScalar($token)
            && !in_array($tokens[$i + 1] ?? '', ['+', '-', '*', '/', '%', '**', '<<', '>>'], true)
        ) {
            array_pop($out);
            $left = array_pop($out);
            $out[] = var_export($value($left) . $value($token), true);

            continue;
        }
        $out[] = $token;
    }

    return $out;
}

/**
 * The lane that owns a class: DependencyProviders Lane 3, Config Lane 4, every other class Lane 1.
 */
function co_lane(string $fqcn): string
{
    return match (true) {
        str_ends_with($fqcn, 'DependencyProvider') => 'Lane 3',
        str_ends_with($fqcn, 'Config') => 'Lane 4',
        default => 'Lane 1',
    };
}

/**
 * Every constant a project class declares that its nearest vendor-declaring ancestor also declares.
 *
 * @return list<array{class: string, constant: string, file: string, line: int, projectValue: string,
 *                    projectKind: string, coreClass: string, coreFile: string, coreValue: string,
 *                    coreKind: string, same: bool, comparison: string, lane: string}>
 */
function co_collect_overrides(array &$context): array
{
    $overrides = [];
    foreach ($context['project'] as $class) {
        if ($class['constants'] === []) {
            continue;
        }
        $ancestors = co_ancestors($context, $class);
        if (array_filter($ancestors, static fn(array $a): bool => $a['vendor']) === []) {
            continue;
        }
        foreach (array_keys($class['constants']) as $constant) {
            $core = null;
            foreach ($ancestors as $ancestor) {
                if (isset($ancestor['constants'][$constant])) {
                    $core = $ancestor;

                    break;
                }
            }
            if ($core === null || !$core['vendor']) {
                continue;
            }
            $projectValue = co_constant_value($context, $class, $constant);
            $coreValue = co_constant_value($context, $core, $constant);
            $same = $projectValue['text'] === $coreValue['text'];
            $overrides[] = [
                'class' => $class['name'],
                'constant' => $constant,
                'file' => spryker_upgrade_rel($class['file'], $context['root']),
                'line' => $class['constants'][$constant]['line'],
                'projectValue' => $projectValue['text'],
                'projectKind' => $projectValue['kind'],
                'coreClass' => $core['name'],
                'coreFile' => spryker_upgrade_rel($core['file'], $context['root']),
                'coreValue' => $coreValue['text'],
                'coreKind' => $coreValue['kind'],
                'same' => $same,
                'comparison' => $projectValue['kind'] === 'literal' && $coreValue['kind'] === 'literal' ? 'value' : 'expression',
                'lane' => co_lane($class['name']),
            ];
        }
    }

    return $overrides;
}

/**
 * Core values that changed or disappeared since the snapshot under a project override, plus
 * overrides that appear after the update because core added a constant the project already declared.
 *
 * @param list<array> $baseline
 * @param list<array> $current
 * @param array<string, array> $projectClasses
 *
 * @return array{changed: list<array>, added: list<array>}
 */
function co_core_changes(array $baseline, array $current, array $projectClasses): array
{
    $key = static fn(array $o): string => $o['class'] . '::' . $o['constant'];
    $currentByKey = [];
    foreach ($current as $override) {
        $currentByKey[$key($override)] = $override;
    }
    $baselineByKey = [];
    $changed = [];
    foreach ($baseline as $before) {
        $baselineByKey[$key($before)] = true;
        $now = $currentByKey[$key($before)] ?? null;
        if ($now !== null) {
            if ($now['coreValue'] !== $before['coreValue']) {
                $changed[] = [
                    'type' => 'CORE_VALUE_CHANGED',
                    'class' => $now['class'],
                    'constant' => $now['constant'],
                    'file' => $now['file'],
                    'line' => $now['line'],
                    'projectValue' => $now['projectValue'],
                    'coreValueBefore' => $before['coreValue'],
                    'coreValueNow' => $now['coreValue'],
                    'coreClass' => $now['coreClass'],
                    'coreFile' => $now['coreFile'],
                    'lane' => $now['lane'],
                ];
            }

            continue;
        }
        if (isset($projectClasses[$before['class']]['constants'][$before['constant']])) {
            $changed[] = [
                'type' => 'CORE_CONSTANT_REMOVED',
                'class' => $before['class'],
                'constant' => $before['constant'],
                'file' => $before['file'],
                'line' => $projectClasses[$before['class']]['constants'][$before['constant']]['line'],
                'projectValue' => $before['projectValue'],
                'coreValueBefore' => $before['coreValue'],
                'coreValueNow' => null,
                'coreClass' => $before['coreClass'],
                'coreFile' => $before['coreFile'],
                'lane' => $before['lane'],
            ];
        }
    }
    $added = array_values(array_filter($current, static fn(array $o): bool => !isset($baselineByKey[$key($o)])));

    return ['changed' => $changed, 'added' => $added];
}

function co_main(array $argv): int
{
    $arguments = array_slice($argv, 1);
    foreach ($arguments as $argument) {
        if (!in_array($argument, ['--snapshot', '--help'], true)) {
            fwrite(STDERR, "Unknown argument: $argument\nUsage: php check-constant-overrides.php [--snapshot]\n");

            return 2;
        }
    }
    if (in_array('--help', $arguments, true)) {
        fwrite(STDOUT, "Usage: php check-constant-overrides.php [--snapshot]\n");

        return 0;
    }
    $root = spryker_upgrade_project_root();
    $stateDir = spryker_upgrade_state_dir($root);
    $baselineFile = $stateDir . '/constant-overrides-baseline.json';
    $reportFile = $stateDir . '/constant-overrides-report.json';
    if (!is_file($root . '/vendor/composer/autoload_psr4.php')) {
        fwrite(STDERR, "vendor/composer/autoload_psr4.php not found: run composer install first.\n");

        return 2;
    }

    $namespaces = spryker_upgrade_project_namespaces($root);
    $context = co_context($root, $namespaces);
    $overrides = co_collect_overrides($context);
    $differing = array_values(array_filter($overrides, static fn(array $o): bool => !$o['same']));

    if (in_array('--snapshot', $arguments, true)) {
        file_put_contents($baselineFile, json_encode([
            'createdAt' => date('c'),
            'namespaces' => $namespaces,
            'overrides' => $overrides,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fwrite(STDOUT, sprintf(
            "Snapshot written: %s\n  %d project constant(s) redeclare a vendor constant in src/%s; %d with a different value.\n",
            spryker_upgrade_rel($baselineFile, $root),
            count($overrides),
            implode(', src/', $namespaces),
            count($differing)
        ));

        return 0;
    }

    if (!is_file($baselineFile)) {
        fwrite(STDERR, "No snapshot at " . spryker_upgrade_rel($baselineFile, $root) . " — run --snapshot before composer update.\n");

        return 2;
    }
    $baseline = json_decode((string)file_get_contents($baselineFile), true);
    $changes = co_core_changes($baseline['overrides'] ?? [], $overrides, $context['project']);

    file_put_contents($reportFile, json_encode([
        'createdAt' => date('c'),
        'namespaces' => $namespaces,
        'coreChanges' => $changes['changed'],
        'differing' => $differing,
        'newOverlaps' => $changes['added'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    fwrite(STDOUT, sprintf("%d project constant(s) differ from the vendor value (review list in the report).\n", count($differing)));
    foreach ($changes['added'] as $added) {
        fwrite(STDOUT, sprintf("  [NEW_OVERLAP] %s::%s — core declares %s, the project value %s replaces it\n", $added['class'], $added['constant'], $added['coreValue'], $added['projectValue']));
    }
    if ($changes['changed'] === []) {
        fwrite(STDOUT, "OK: no core value changed under a project override since the snapshot.\n");
        fwrite(STDOUT, 'Full report: ' . spryker_upgrade_rel($reportFile, $root) . "\n");

        return 0;
    }
    fwrite(STDOUT, sprintf("\nFOUND %d core value(s) changed under a project override:\n\n", count($changes['changed'])));
    foreach ($changes['changed'] as $change) {
        fwrite(STDOUT, sprintf(
            "[%s] %s::%s (%s)\n  project: %s  (%s:%d)\n  core before: %s\n  core now:    %s  (%s)\n\n",
            $change['type'],
            $change['class'],
            $change['constant'],
            $change['lane'],
            $change['projectValue'],
            $change['file'],
            $change['line'],
            $change['coreValueBefore'],
            $change['coreValueNow'] ?? '(removed)',
            $change['coreFile']
        ));
    }
    fwrite(STDOUT, 'Full report: ' . spryker_upgrade_rel($reportFile, $root) . "\n");

    return 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(co_main($argv));
}
