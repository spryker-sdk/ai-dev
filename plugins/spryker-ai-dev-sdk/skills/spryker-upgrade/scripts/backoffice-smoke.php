<?php

/**
 * Back Office smoke check: logs in and requests every page linked from the Back Office navigation,
 * plus every table data endpoint on those pages.
 *
 * A vendor-to-vendor conflict can break every Back Office page with a table while the pages the
 * upgrade touched still render. A check limited to the touched pages misses that; this visits
 * all of them. Run it in Phase 0 (baseline) and again after the upgrade.
 *
 * Usage:
 *   SPRYKER_BACKOFFICE_USER=... SPRYKER_BACKOFFICE_PASSWORD=... \
 *   php $UP/backoffice-smoke.php --url http://backoffice.eu.spryker.local [--max 200] [--timeout 30]
 *                                [--baseline] [--insecure]
 *
 *   --baseline  write backoffice-smoke-baseline.json instead of backoffice-smoke-report.json
 *   --insecure  skip TLS certificate verification (self-signed local certificates)
 *
 * Exit 0 when every request succeeded, 1 on any problem, 2 on usage error, an unreachable login page,
 * a login page without a form or a failed login; exit 2 writes no report and no baseline.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

const BO_DESTRUCTIVE = '/(delete|remove|cancel|logout|deactivate)/i';

/**
 * Split a URL into scheme://host[:port] origin and the rest.
 *
 * @return array{origin: string, path: string}|null
 */
function bo_split_url(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

    return ['origin' => $origin, 'path' => $parts['path'] ?? '/'];
}

/**
 * Resolve an href against the page it was found on. Returns null for non-navigational hrefs.
 */
function bo_resolve_url(string $href, string $pageUrl): ?string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
    $hashAt = strpos($href, '#');
    if ($hashAt !== false) {
        $href = substr($href, 0, $hashAt);
    }
    if ($href === '' || preg_match('/^(javascript|mailto|tel|data):/i', $href)) {
        return null;
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $page = bo_split_url($pageUrl);
    if ($page === null) {
        return null;
    }
    if (str_starts_with($href, '//')) {
        return strtok($page['origin'], ':') . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        return $page['origin'] . $href;
    }
    if (str_starts_with($href, '?')) {
        return $page['origin'] . $page['path'] . $href;
    }
    $directory = substr($page['path'], 0, (int)strrpos($page['path'], '/') + 1);

    return $page['origin'] . ($directory === '' ? '/' : $directory) . $href;
}

function bo_same_origin(string $url, string $baseUrl): bool
{
    $a = bo_split_url($url);
    $b = bo_split_url($baseUrl);

    return $a !== null && $b !== null && $a['origin'] === $b['origin'];
}

/**
 * Whether requesting the URL could change state (logout, delete, cancel, ...).
 */
function bo_is_destructive(string $url): bool
{
    $parts = parse_url($url);
    $target = ($parts['path'] ?? '') . '?' . ($parts['query'] ?? '');

    return (bool)preg_match(BO_DESTRUCTIVE, $target);
}

function bo_dom(string $html): ?DOMXPath
{
    if (!class_exists(DOMDocument::class) || trim($html) === '') {
        return null;
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/**
 * The login form: its action, the default values of all its fields and the names of the
 * user, password and CSRF token fields.
 *
 * @return array{action: string, fields: array<string, string>, userField: ?string, passwordField: ?string, csrfField: ?string}|null
 */
function bo_parse_login_form(string $html, string $pageUrl): ?array
{
    $xpath = bo_dom($html);
    if ($xpath === null) {
        return null;
    }
    $form = $xpath->query('//form[.//input[@type="password"]]')->item(0);
    if (!$form instanceof DOMElement) {
        return null;
    }
    $fields = [];
    $userField = null;
    $passwordField = null;
    $csrfField = null;
    foreach ($xpath->query('.//input[@name]', $form) as $input) {
        if (!$input instanceof DOMElement) {
            continue;
        }
        $name = $input->getAttribute('name');
        $type = strtolower($input->getAttribute('type') ?: 'text');
        if (in_array($type, ['submit', 'button', 'image', 'reset'], true)) {
            continue;
        }
        if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) {
            continue;
        }
        $fields[$name] = $input->getAttribute('value');
        if ($type === 'password') {
            $passwordField ??= $name;
        } elseif ($type === 'hidden' && preg_match('/token|csrf/i', $name)) {
            $csrfField ??= $name;
        } elseif (in_array($type, ['text', 'email'], true)) {
            $userField ??= $name;
        }
    }
    $action = $form->getAttribute('action');

    return [
        'action' => $action === '' ? $pageUrl : (bo_resolve_url($action, $pageUrl) ?? $pageUrl),
        'fields' => $fields,
        'userField' => $userField,
        'passwordField' => $passwordField,
        'csrfField' => $csrfField,
    ];
}

/**
 * The CSRF token of the login form as [field name, value], or null when the form has none.
 *
 * @return array{name: string, value: string}|null
 */
function bo_extract_csrf(string $html, string $pageUrl = 'http://localhost/'): ?array
{
    $form = bo_parse_login_form($html, $pageUrl);
    if ($form === null || $form['csrfField'] === null) {
        return null;
    }

    return ['name' => $form['csrfField'], 'value' => $form['fields'][$form['csrfField']]];
}

/**
 * Same-origin, non-destructive links of the Back Office navigation. Falls back to every link on the
 * page when no navigation container is found.
 *
 * @return list<string>
 */
function bo_extract_nav_links(string $html, string $pageUrl): array
{
    $xpath = bo_dom($html);
    if ($xpath === null) {
        return [];
    }
    $containers = '//nav | //aside | //*[@id="side-menu"] | //*[@role="navigation"]'
        . ' | //*[contains(concat(" ", normalize-space(@class), " "), " sidebar ")]'
        . ' | //*[contains(concat(" ", normalize-space(@class), " "), " navbar-static-side ")]';
    $anchors = $xpath->query('(' . $containers . ')//a[@href]');
    if ($anchors === false || $anchors->length === 0) {
        $anchors = $xpath->query('//a[@href]');
    }

    $links = [];
    foreach ($anchors ?: [] as $anchor) {
        if (!$anchor instanceof DOMElement) {
            continue;
        }
        $url = bo_resolve_url($anchor->getAttribute('href'), $pageUrl);
        if ($url === null || !bo_same_origin($url, $pageUrl) || bo_is_destructive($url)) {
            continue;
        }
        $links[$url] = true;
    }

    return array_keys($links);
}

/**
 * Data endpoints of the tables on a page (`data-ajax` / `data-url`).
 *
 * @return list<string>
 */
function bo_extract_table_endpoints(string $html, string $pageUrl): array
{
    $xpath = bo_dom($html);
    if ($xpath === null) {
        return [];
    }
    $nodes = $xpath->query(
        '//table[@data-ajax or @data-url]'
        . ' | //*[contains(@class, "table")][@data-ajax or @data-url]'
    );
    $endpoints = [];
    foreach ($nodes ?: [] as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        foreach (['data-ajax', 'data-url'] as $attribute) {
            $value = $node->getAttribute($attribute);
            $url = $value === '' ? null : bo_resolve_url($value, $pageUrl);
            if ($url !== null && bo_same_origin($url, $pageUrl) && !bo_is_destructive($url)) {
                $endpoints[$url] = true;
            }
        }
    }

    return array_keys($endpoints);
}

/**
 * The exception marker found in an HTML response, or null when it looks like a normal page.
 */
function bo_detect_exception(string $html): ?string
{
    foreach (['Whoops', 'Stack trace', 'Fatal error', 'Uncaught'] as $marker) {
        if (str_contains($html, $marker)) {
            return $marker;
        }
    }
    preg_match_all('#<(title|h1)\b[^>]*>(.*?)</\1>#is', $html, $headings);
    foreach ($headings[2] as $heading) {
        $text = trim(strip_tags($heading));
        if (str_contains($text, 'Exception')) {
            return 'Exception in title/h1: ' . $text;
        }
    }

    return null;
}

/**
 * Whether a final URL is the login page.
 */
function bo_is_login_url(string $url, string $loginPath): bool
{
    $path = parse_url($url, PHP_URL_PATH) ?: '/';

    return rtrim($path, '/') === rtrim($loginPath, '/') || (bool)preg_match('#/(login|auth)(/|$)#i', $path);
}

/**
 * The problem with a response, or null when it is a success.
 *
 * @param array{status: int, url: string, body: string, contentType: string, error: ?string} $response
 */
function bo_problem(array $response, string $kind, string $loginPath): ?string
{
    if ($response['error'] !== null) {
        return 'request failed: ' . $response['error'];
    }
    if ($response['status'] < 200 || $response['status'] >= 300) {
        return 'HTTP ' . $response['status'];
    }
    if (bo_is_login_url($response['url'], $loginPath)) {
        return 'redirected to login';
    }
    if ($kind === 'table') {
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            $marker = bo_detect_exception($response['body']);

            return 'not JSON' . ($marker !== null ? " ({$marker})" : '');
        }

        return null;
    }
    $marker = bo_detect_exception($response['body']);

    return $marker === null ? null : 'exception page: ' . $marker;
}

/**
 * One HTTP request with the shared cookie jar; follows redirects.
 *
 * @param array<string, string>|null $post
 * @param list<string> $headers
 *
 * @return array{status: int, url: string, body: string, contentType: string, error: ?string}
 */
function bo_request(string $url, ?array $post, array $headers, array $settings): array
{
    if (function_exists('curl_init')) {
        static $handle = null;
        $handle ??= curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $settings['timeout'],
            CURLOPT_COOKIEFILE => $settings['cookieJar'],
            CURLOPT_COOKIEJAR => $settings['cookieJar'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => !$settings['insecure'],
            CURLOPT_SSL_VERIFYHOST => $settings['insecure'] ? 0 : 2,
            CURLOPT_USERAGENT => 'spryker-upgrade-backoffice-smoke',
        ]);
        if ($post !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        } else {
            curl_setopt($handle, CURLOPT_HTTPGET, true);
        }
        $body = curl_exec($handle);
        $error = $body === false ? curl_error($handle) : null;

        return [
            'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'url' => (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
            'body' => is_string($body) ? $body : '',
            'contentType' => (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
            'error' => $error,
        ];
    }

    $bodyFile = $settings['cookieJar'] . '.body';
    $command = [
        'curl', '-sS', '-L', '--max-redirs', '5', '--max-time', (string)$settings['timeout'],
        '-b', $settings['cookieJar'], '-c', $settings['cookieJar'], '-o', $bodyFile,
        '-A', 'spryker-upgrade-backoffice-smoke',
        '-w', '%{http_code}\n%{url_effective}\n%{content_type}',
    ];
    if ($settings['insecure']) {
        $command[] = '-k';
    }
    foreach ($headers as $header) {
        array_push($command, '-H', $header);
    }
    if ($post !== null) {
        array_push($command, '--data-binary', '@-');
    }
    $command[] = $url;
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['status' => 0, 'url' => $url, 'body' => '', 'contentType' => '', 'error' => 'could not start curl'];
    }
    fwrite($pipes[0], $post !== null ? http_build_query($post) : '');
    fclose($pipes[0]);
    $meta = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $body = is_file($bodyFile) ? (string)file_get_contents($bodyFile) : '';
    @unlink($bodyFile);
    $metaParts = explode("\n", $meta, 3);

    return [
        'status' => (int)($metaParts[0] ?? 0),
        'url' => ($metaParts[1] ?? '') !== '' ? $metaParts[1] : $url,
        'body' => $body,
        'contentType' => trim($metaParts[2] ?? ''),
        'error' => $code === 0 ? null : trim($stderr),
    ];
}

function bo_option(array $argv, string $name): ?string
{
    foreach ($argv as $i => $arg) {
        if ($arg === '--' . $name) {
            return $argv[$i + 1] ?? null;
        }
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return null;
}

function bo_main(array $argv): int
{
    require_once __DIR__ . '/bootstrap.php';

    if (in_array('--help', $argv, true)) {
        fwrite(STDOUT, "Usage: SPRYKER_BACKOFFICE_USER=.. SPRYKER_BACKOFFICE_PASSWORD=.. php backoffice-smoke.php --url <zed url> [--max 200] [--timeout 30] [--baseline] [--insecure]\n");

        return 0;
    }
    $baseUrl = rtrim((string)bo_option($argv, 'url'), '/');
    $user = getenv('SPRYKER_BACKOFFICE_USER');
    $password = getenv('SPRYKER_BACKOFFICE_PASSWORD');
    if ($baseUrl === '' || bo_split_url($baseUrl) === null) {
        fwrite(STDERR, "--url <Back Office base URL> is required, e.g. --url http://backoffice.eu.spryker.local\n");

        return 2;
    }
    if (!is_string($user) || $user === '' || !is_string($password) || $password === '') {
        fwrite(STDERR, "Set SPRYKER_BACKOFFICE_USER and SPRYKER_BACKOFFICE_PASSWORD in the environment.\n");

        return 2;
    }
    $max = (int)(bo_option($argv, 'max') ?? 200);
    $timeout = (int)(bo_option($argv, 'timeout') ?? 30);
    if ($max < 1 || $timeout < 1) {
        fwrite(STDERR, "--max and --timeout must be positive integers.\n");

        return 2;
    }
    $isBaseline = in_array('--baseline', $argv, true);

    $root = spryker_upgrade_project_root();
    $stateDir = spryker_upgrade_state_dir($root);
    $reportFile = $stateDir . ($isBaseline ? '/backoffice-smoke-baseline.json' : '/backoffice-smoke-report.json');
    $baselineFile = $stateDir . '/backoffice-smoke-baseline.json';
    $settings = [
        'timeout' => $timeout,
        'cookieJar' => $stateDir . '/backoffice-smoke.cookies',
        'insecure' => in_array('--insecure', $argv, true),
    ];
    @unlink($settings['cookieJar']);

    $results = [];
    $record = static function (string $url, string $kind, array $response, ?string $problem) use (&$results): void {
        $results[] = ['url' => $url, 'status' => $response['status'], 'kind' => $kind, 'problem' => $problem];
    };

    $loginPage = bo_request($baseUrl . '/', null, [], $settings);
    $form = bo_parse_login_form($loginPage['body'], $loginPage['url'] ?: $baseUrl . '/');
    if ($form === null || $form['userField'] === null || $form['passwordField'] === null) {
        $isHttpFailure = $loginPage['error'] !== null || $loginPage['status'] < 200 || $loginPage['status'] >= 300;
        $problem = $isHttpFailure
            ? (string)bo_problem($loginPage, 'page', '/security-gui/login')
            : 'no login form found' . (($marker = bo_detect_exception($loginPage['body'])) !== null ? " ({$marker})" : '');
        fwrite(STDERR, sprintf("Login page %s: %s. No report or baseline written.\n", $loginPage['url'] ?: $baseUrl, $problem));

        return 2;
    }
    $loginPath = (string)(parse_url($loginPage['url'], PHP_URL_PATH) ?: '/security-gui/login');
    $fields = $form['fields'];
    $fields[$form['userField']] = $user;
    $fields[$form['passwordField']] = $password;
    $afterLogin = bo_request($form['action'], $fields, [], $settings);
    if ($afterLogin['error'] !== null || $afterLogin['status'] < 200 || $afterLogin['status'] >= 300) {
        fwrite(STDERR, sprintf("Login request to %s failed: %s. No report or baseline written.\n", $form['action'], (string)bo_problem($afterLogin, 'page', $loginPath)));

        return 2;
    }
    if (bo_is_login_url($afterLogin['url'], $loginPath)) {
        fwrite(STDERR, "Login failed (still on the login page). Check SPRYKER_BACKOFFICE_USER / SPRYKER_BACKOFFICE_PASSWORD. No report or baseline written.\n");

        return 2;
    }

    $dashboard = bo_request($baseUrl . '/', null, [], $settings);
    if ($dashboard['error'] === null && bo_is_login_url($dashboard['url'], $loginPath)) {
        fwrite(STDERR, "Login did not keep a session: the Back Office start page redirects to the login page. No report or baseline written.\n");

        return 2;
    }
    $record($baseUrl . '/', 'page', $dashboard, bo_problem($dashboard, 'page', $loginPath));
    $pages = array_values(array_filter(
        bo_extract_nav_links($dashboard['body'], $dashboard['url'] ?: $baseUrl . '/'),
        static fn(string $url): bool => !bo_is_login_url($url, $loginPath) && rtrim($url, '/') !== $baseUrl
    ));

    $requested = 1;
    $truncated = false;
    $seenTables = [];
    $queue = array_merge([['url' => $baseUrl . '/', 'response' => $dashboard]], array_map(static fn(string $u): array => ['url' => $u, 'response' => null], $pages));
    foreach ($queue as $entry) {
        $response = $entry['response'];
        if ($response === null) {
            if ($requested >= $max) {
                $truncated = true;
                break;
            }
            $response = bo_request($entry['url'], null, [], $settings);
            $requested++;
            $record($entry['url'], 'page', $response, bo_problem($response, 'page', $loginPath));
        }
        foreach (bo_extract_table_endpoints($response['body'], $response['url'] ?: $entry['url']) as $tableUrl) {
            if (isset($seenTables[$tableUrl])) {
                continue;
            }
            if ($requested >= $max) {
                $truncated = true;
                break 2;
            }
            $seenTables[$tableUrl] = true;
            $tableResponse = bo_request($tableUrl, null, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], $settings);
            $requested++;
            $record($tableUrl, 'table', $tableResponse, bo_problem($tableResponse, 'table', $loginPath));
        }
    }

    return bo_finish($results, $reportFile, $baseUrl, $truncated, $root, $isBaseline ? null : $baselineFile);
}

/**
 * Write the report, print the summary and return the exit code.
 *
 * @param list<array{url: string, status: int, kind: string, problem: ?string}> $results
 */
function bo_finish(array $results, string $reportFile, string $baseUrl, bool $truncated, string $root, ?string $baselineFile): int
{
    $baselineProblems = [];
    if ($baselineFile !== null && is_file($baselineFile)) {
        $baseline = json_decode((string)file_get_contents($baselineFile), true);
        foreach ($baseline['results'] ?? [] as $row) {
            if (($row['problem'] ?? null) !== null) {
                $baselineProblems[$row['url']] = true;
            }
        }
    }
    foreach ($results as &$row) {
        $row['inBaseline'] = $row['problem'] !== null && isset($baselineProblems[$row['url']]);
    }
    unset($row);

    $problems = array_values(array_filter($results, static fn(array $r): bool => $r['problem'] !== null));
    $pages = count(array_filter($results, static fn(array $r): bool => $r['kind'] === 'page'));
    file_put_contents($reportFile, json_encode([
        'createdAt' => date('c'),
        'baseUrl' => $baseUrl,
        'requested' => count($results),
        'pages' => $pages,
        'tables' => count($results) - $pages,
        'truncated' => $truncated,
        'problems' => count($problems),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    fwrite(STDOUT, sprintf("Back Office smoke: %d page(s), %d table endpoint(s) requested on %s\n\n", $pages, count($results) - $pages, $baseUrl));
    if ($problems === []) {
        fwrite(STDOUT, "OK: every page and table endpoint succeeded.\n");
    } else {
        fwrite(STDOUT, sprintf("PROBLEMS (%d):\n", count($problems)));
        foreach ($problems as $problem) {
            fwrite(STDOUT, sprintf(
                "  [%-5s %3d] %s  %s%s\n",
                $problem['kind'],
                $problem['status'],
                $problem['url'],
                $problem['problem'],
                $problem['inBaseline'] ? '  (also in baseline)' : ''
            ));
        }
    }
    if ($truncated) {
        fwrite(STDOUT, "\nNOTE: stopped at --max; raise it to cover the remaining pages.\n");
    }
    fwrite(STDOUT, "\nFull report: " . spryker_upgrade_rel($reportFile, $root) . "\n");

    return $problems === [] ? 0 : 1;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(bo_main($argv));
}
