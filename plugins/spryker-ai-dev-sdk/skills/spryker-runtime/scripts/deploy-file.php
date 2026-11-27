<?php

/**
 * The facts of a Spryker deploy file (deploy.*.yml), read with the project's symfony/yaml or, without
 * it, with a YAML subset that refuses syntax outside the subset: the applications
 * with their hosts, scheme, port, session cookie name and container, the services, the Glue entry
 * points and the dynamic multi-store mode. Shared by spryker-runtime and spryker-refresher.
 */

declare(strict_types=1);

/**
 * The applications whose session cookie the skill uses; the cookie name is the host with every `.`
 * replaced by `-`.
 */
const DF_SESSION_APPLICATIONS = ['yves', 'backoffice', 'merchant-portal'];

/**
 * A YAML subset: nested mappings, sequences of scalars, quoted and plain scalars, comments, block
 * scalars (kept as text). Enough for deploy files.
 *
 * @return array<string, mixed>
 */
function df_parse_yaml(string $yaml): array
{
    $lines = [];
    foreach (preg_split('/\r?\n/', $yaml) ?: [] as $raw) {
        $text = df_strip_comment($raw);
        if (trim($text) === '') {
            continue;
        }
        $lines[] = [strlen($text) - strlen(ltrim($text, ' ')), trim($text)];
    }
    $i = 0;
    $result = df_parse_block($lines, $i, $lines[0][0] ?? 0);

    return is_array($result) ? $result : [];
}

function df_strip_comment(string $line): string
{
    $quote = null;
    for ($i = 0, $n = strlen($line); $i < $n; $i++) {
        $char = $line[$i];
        if ($quote !== null) {
            $quote = $char === $quote ? null : $quote;
        } elseif ($char === '"' || $char === "'") {
            $quote = $char;
        } elseif ($char === '#' && ($i === 0 || $line[$i - 1] === ' ')) {
            return rtrim(substr($line, 0, $i));
        }
    }

    return rtrim($line);
}

/**
 * @param list<array{0: int, 1: string}> $lines
 */
function df_parse_block(array $lines, int &$i, int $indent): mixed
{
    $result = [];
    while ($i < count($lines)) {
        [$lineIndent, $text] = $lines[$i];
        if ($lineIndent < $indent) {
            break;
        }
        if ($lineIndent > $indent) {
            $i++;
            continue;
        }
        if ($text === '-' || str_starts_with($text, '- ')) {
            $result[] = df_scalar(trim(substr($text, 1)));
            $i++;
            continue;
        }
        [$key, $value] = df_split_key($text);
        $i++;
        if ($value === '|' || $value === '>' || $value === '|-' || $value === '>-') {
            $block = [];
            while ($i < count($lines) && $lines[$i][0] > $indent) {
                $block[] = $lines[$i][1];
                $i++;
            }
            $result[$key] = implode("\n", $block);
        } elseif ($value !== '') {
            $result[$key] = df_scalar($value);
        } elseif ($i < count($lines) && $lines[$i][0] > $indent) {
            $result[$key] = df_parse_block($lines, $i, $lines[$i][0]);
        } elseif ($i < count($lines) && $lines[$i][0] === $indent && str_starts_with($lines[$i][1], '- ')) {
            $result[$key] = df_parse_block($lines, $i, $indent);
        } else {
            $result[$key] = null;
        }
    }

    return $result;
}

/**
 * `key: value`, `key:`, `host:port:`, `'quoted key': value`.
 *
 * @return array{0: string, 1: string}
 */
function df_split_key(string $text): array
{
    if (preg_match('/^([\'"])(.*?)\1\s*:\s*(.*)$/', $text, $m)) {
        return [$m[2], $m[3]];
    }
    if (str_ends_with($text, ':') && !str_contains(substr($text, 0, -1), ': ')) {
        return [substr($text, 0, -1), ''];
    }
    $position = strpos($text, ': ');

    return $position === false ? [$text, ''] : [substr($text, 0, $position), trim(substr($text, $position + 2))];
}

function df_scalar(string $value): mixed
{
    if (preg_match('/^([\'"])(.*)\1$/', $value, $m)) {
        return $m[2];
    }

    return match (strtolower($value)) {
        'true' => true,
        'false' => false,
        'null', '~' => null,
        default => $value,
    };
}

/**
 * The first line that uses YAML the subset does not parse: anchors (`&name`), aliases (`*name`),
 * merge keys (`<<:`) and flow mappings or sequences as values (`{...}`, `[...]`). Null when there is none.
 */
function df_unsupported_yaml(string $yaml): ?string
{
    foreach (preg_split('/\r?\n/', $yaml) ?: [] as $number => $raw) {
        $text = trim(df_strip_comment($raw));
        $feature = match (true) {
            (bool)preg_match('/^<<\s*:/', $text) => 'a merge key (<<:)',
            (bool)preg_match('/(?:^|:\s|^-\s)\s*&[\w-]+/', $text) => 'an anchor (&name)',
            (bool)preg_match('/(?:^|:\s|^-\s)\s*\*[\w-]+/', $text) => 'an alias (*name)',
            (bool)preg_match('/(?::\s|^-\s)\s*[{\[]/', $text) => 'a flow mapping or sequence ({...} or [...])',
            default => null,
        };
        if ($feature !== null) {
            return sprintf('line %d uses %s', $number + 1, $feature);
        }
    }

    return null;
}

/**
 * The parsed deploy file; null when it does not exist; a string with the reason when it cannot be
 * read. With Symfony\Component\Yaml\Yaml from the project's vendor/autoload.php, the full YAML
 * syntax; without it, the subset of df_parse_yaml() and an error for YAML outside the subset.
 *
 * @return array<string, mixed>|string|null
 */
function df_load(string $root, string $deployFile): array|string|null
{
    $path = $root . '/' . $deployFile;
    if (!is_file($path)) {
        return null;
    }
    $yaml = (string)file_get_contents($path);
    if (!class_exists('Symfony\Component\Yaml\Yaml', false) && is_file($root . '/vendor/autoload.php')) {
        require_once $root . '/vendor/autoload.php';
    }
    if (class_exists('Symfony\Component\Yaml\Yaml')) {
        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml);
        } catch (Throwable $exception) {
            return sprintf('%s cannot be parsed: %s', $deployFile, $exception->getMessage());
        }

        return is_array($parsed) ? $parsed : [];
    }
    $unsupported = df_unsupported_yaml($yaml);
    if ($unsupported !== null) {
        return sprintf('%s %s: without symfony/yaml in the project\'s vendor folder, the deploy file reader does not parse this syntax', $deployFile, $unsupported);
    }

    return df_parse_yaml($yaml);
}

/**
 * Every scalar value stored under $key anywhere in the tree.
 *
 * @return list<mixed>
 */
function df_values(array $tree, string $key): array
{
    $values = [];
    foreach ($tree as $name => $value) {
        if (is_array($value)) {
            $values = array_merge($values, df_values($value, $key));
        } elseif ((string)$name === $key) {
            $values[] = $value;
        }
    }

    return $values;
}

function df_is_true(mixed $value): bool
{
    return $value === true || $value === 1 || (is_string($value) && in_array(strtolower($value), ['true', '1', 'yes', 'on'], true));
}

function df_scheme(array $deploy): string
{
    return df_is_true($deploy['docker']['ssl']['enabled'] ?? false) ? 'https' : 'http';
}

/**
 * Dynamic multi-store mode: SPRYKER_DYNAMIC_STORE_MODE set to true anywhere in the file.
 */
function df_dynamic_store(array $deploy): bool
{
    foreach (df_values($deploy, 'SPRYKER_DYNAMIC_STORE_MODE') as $value) {
        if (df_is_true($value)) {
            return true;
        }
    }

    return false;
}

/**
 * The Glue entry points the deploy file declares: `entry-point: Glue*`, and `application: glue*`
 * as the entry point of that name (`glue-backend` → `GlueBackend`).
 *
 * @return list<string>
 */
function df_glue_entry_points(array $deploy): array
{
    $points = array_filter(df_values($deploy, 'entry-point'), static fn ($v): bool => is_string($v) && str_starts_with($v, 'Glue'));
    foreach (df_values($deploy, 'application') as $application) {
        if (is_string($application) && str_starts_with($application, 'glue')) {
            $points[] = str_replace(' ', '', ucwords(str_replace('-', ' ', $application)));
        }
    }

    return array_values(array_unique($points));
}

/**
 * One line per application endpoint.
 *
 * @return list<array{group: string, region: ?string, store: ?string, key: string, application: string, entryPoint: ?string, host: string, port: int, url: string, cookie: ?string, container: string, primal: bool}>
 */
function df_applications(array $deploy): array
{
    $scheme = df_scheme($deploy);
    $namespace = (string)($deploy['namespace'] ?? '');
    $rows = [];
    foreach ((array)($deploy['groups'] ?? []) as $group => $groupConfig) {
        foreach ((array)($groupConfig['applications'] ?? []) as $key => $application) {
            if (!is_array($application) || !isset($application['application'])) {
                continue;
            }
            foreach ((array)($application['endpoints'] ?? []) as $endpoint => $settings) {
                $settings = is_array($settings) ? $settings : [];
                [$host, $port] = str_contains((string)$endpoint, ':') ? explode(':', (string)$endpoint, 2) : [(string)$endpoint, null];
                $port = $port === null ? ($scheme === 'https' ? 443 : 80) : (int)$port;
                $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
                $rows[] = [
                    'group' => (string)$group,
                    'region' => isset($settings['region']) ? (string)$settings['region'] : (isset($groupConfig['region']) ? (string)$groupConfig['region'] : null),
                    'store' => isset($settings['store']) ? (string)$settings['store'] : null,
                    'key' => (string)$key,
                    'application' => (string)$application['application'],
                    'entryPoint' => isset($settings['entry-point']) ? (string)$settings['entry-point'] : null,
                    'host' => $host,
                    'port' => $port,
                    'url' => $scheme . '://' . $host . ($default ? '' : ':' . $port),
                    'cookie' => in_array($application['application'], DF_SESSION_APPLICATIONS, true) ? str_replace('.', '-', $host) : null,
                    'container' => $namespace . '_' . $key . '_1',
                    'primal' => df_is_true($settings['primal'] ?? false),
                ];
            }
        }
    }

    return $rows;
}

/**
 * The services: name, engine, container, endpoints (`http://host` for a web endpoint,
 * `tcp host:port` for a TCP one). Credentials are left out.
 *
 * @return list<array{name: string, engine: ?string, container: string, endpoints: list<string>}>
 */
function df_services(array $deploy): array
{
    $scheme = df_scheme($deploy);
    $namespace = (string)($deploy['namespace'] ?? '');
    $rows = [];
    foreach ((array)($deploy['services'] ?? []) as $name => $service) {
        $service = is_array($service) ? $service : [];
        $endpoints = [];
        foreach ((array)($service['endpoints'] ?? []) as $endpoint => $settings) {
            $endpoints[] = (is_array($settings) && ($settings['protocol'] ?? null) === 'tcp') ? 'tcp ' . $endpoint : $scheme . '://' . $endpoint;
        }
        $rows[] = [
            'name' => (string)$name,
            'engine' => isset($service['engine']) ? (string)$service['engine'] : null,
            'container' => $namespace . '_' . $name . '_1',
            'endpoints' => $endpoints,
        ];
    }

    return $rows;
}
