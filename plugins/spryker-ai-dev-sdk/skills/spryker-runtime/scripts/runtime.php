<?php

/**
 * The runtime environment of a Spryker project, its seeded accounts, and a login that leaves a
 * session or a token in a file.
 *
 * Usage (from the project root):
 *   php runtime.php env [--deploy-file <file>] [--app <application>] [--region <region or store>]
 *   php runtime.php accounts [--role <role>] [--limit N]
 *   php runtime.php login <app> <user> [--deploy-file <file>] [--region <region or store>] [--host <host>]
 *                         [--path <login path>]
 *
 * env: one line per application endpoint of the deploy file (deploy.dev.yml by default): application,
 *   store or region, URL with scheme and port, session cookie name, container; then the services
 *   (broker, database, search, key-value store, …) with their container and endpoints. Credentials
 *   of the deploy file are not printed.
 * accounts: the accounts the repository seeds — Back Office users and agents (src/<Ns>/Zed/User/
 *   UserConfig.php), merchant users (data/import/**\/merchant_user.csv), customers
 *   (data/import/**\/customer.csv) — with the file and line of each, and where a usable password is
 *   (file and line, a hash only, or none). No password is printed. --limit per role (default 20,
 *   0 = all).
 * login: <app> is yves, agent, backoffice, merchant-portal (a session: the cookie jar), glue,
 *   glue-storefront or glue-backend (a token). Only hosts the deploy file names: when the login page
 *   or its form action is on another host (a redirect to single sign-on), nothing is sent. The password comes
 *   from SPRYKER_RUNTIME_PASSWORD, else from the project's files: a plain password of the account, or,
 *   for a hashed one, a plain password from the project's files that verifies against the hash.
 *   Writes .ai-dev/runtime/sessions/<app>-<user>.cookies or .token and prints one line; never the
 *   password, the cookie value or the token.
 *
 * Exit codes. env, accounts: 0, 2 usage or no deploy file / application. login: 0 logged in, 1 the
 * application refused the login, 2 usage, a host outside the deploy file (given, or reached by the
 * login page), or no password, 3 the host
 * did not answer or showed no login form.
 */

declare(strict_types=1);

require_once __DIR__ . '/deploy-file.php';
require_once __DIR__ . '/../../spryker-upgrade/scripts/backoffice-smoke.php';

const SR_STATE = '.ai-dev/runtime';
const SR_PASSWORD_ENV = 'SPRYKER_RUNTIME_PASSWORD';
const SR_DEFAULT_LIMIT = 20;

/**
 * Data import files larger than this, or under a `production` folder, hold real data, not seeded
 * accounts: the accounts call skips them and names them.
 */
const SR_MAX_SEED_FILE_BYTES = 5 * 1024 * 1024;

/**
 * Login applications: the deploy application, the login path, and the kind of login.
 */
const SR_LOGINS = [
    'yves' => ['application' => 'yves', 'path' => '/login', 'kind' => 'session'],
    'agent' => ['application' => 'yves', 'path' => '/agent/login', 'kind' => 'session'],
    'backoffice' => ['application' => 'backoffice', 'path' => '/security-gui/login', 'kind' => 'session'],
    'merchant-portal' => ['application' => 'merchant-portal', 'path' => '/security-merchant-portal-gui/login', 'kind' => 'session'],
    'glue' => ['application' => 'glue', 'path' => '/access-tokens', 'kind' => 'glue-token'],
    'glue-storefront' => ['application' => 'glue-storefront', 'path' => '/access-tokens', 'kind' => 'glue-token'],
    'glue-backend' => ['application' => 'glue-backend', 'path' => '/token', 'kind' => 'backend-token'],
];

/**
 * @return list<string> files under $dir (relative to $root) whose name matches $name
 */
function sr_find_files(string $root, string $dir, string $name): array
{
    if (!is_dir($root . '/' . $dir)) {
        return [];
    }
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (fnmatch($name, $file->getFilename())) {
            $found[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($found);

    return $found;
}

/**
 * The data import files of seeded accounts, and the files skipped as real data.
 *
 * @return array{0: list<string>, 1: list<string>}
 */
function sr_seed_files(string $root, string $name): array
{
    $files = [];
    $skipped = [];
    foreach (sr_find_files($root, 'data/import', $name) as $file) {
        if (preg_match('#(^|/)production(/|$)#', $file) || filesize($root . '/' . $file) > SR_MAX_SEED_FILE_BYTES) {
            $skipped[] = $file;
            continue;
        }
        $files[] = $file;
    }

    return [$files, $skipped];
}

/**
 * The rows of a CSV with their line numbers, keyed by the header.
 *
 * @return iterable<array{line: int, row: array<string, string>}>
 */
function sr_csv(string $path): iterable
{
    $handle = @fopen($path, 'r');
    if ($handle === false) {
        return;
    }
    $header = null;
    $line = 0;
    while (($fields = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $line++;
        if ($header === null) {
            $header = array_map(static fn ($h): string => strtolower(trim((string)$h, " \t\xEF\xBB\xBF")), $fields);
            continue;
        }
        if ($fields === [null]) {
            continue;
        }
        yield ['line' => $line, 'row' => array_combine($header, array_pad(array_slice($fields, 0, count($header)), count($header), ''))];
    }
    fclose($handle);
}

function sr_is_hash(string $password): bool
{
    return (bool)preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $password);
}

/**
 * A password entry: plain (usable), hash (verify a candidate) or none. The value never leaves the
 * script.
 *
 * @return array{kind: string, value: ?string, source: ?string}
 */
function sr_password(?string $value, ?string $source): array
{
    $value = $value === null ? '' : trim($value);
    if ($value === '') {
        return ['kind' => 'none', 'value' => null, 'source' => null];
    }

    return ['kind' => sr_is_hash($value) ? 'hash' : 'plain', 'value' => $value, 'source' => $source];
}

/**
 * The installer users of src/<Ns>/Zed/User/UserConfig.php: username, flags, password, lines.
 *
 * @return list<array{user: string, flags: array<string, mixed>, password: array, source: string}>
 */
function sr_installer_users(string $root): array
{
    $users = [];
    foreach (glob($root . '/src/*/Zed/User/UserConfig.php') ?: [] as $file) {
        $code = (string)file_get_contents($file);
        $relative = substr($file, strlen($root) + 1);
        preg_match_all('/\[((?:\s*[\'"]\w+[\'"]\s*=>\s*(?:[\'"][^\'"]*[\'"]|true|false|\d+)\s*,?)+\s*)\]/', $code, $entries, PREG_OFFSET_CAPTURE);
        foreach ($entries[1] as [$body, $offset]) {
            preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*(?:[\'"]([^\'"]*)[\'"]|(true|false|\d+))/', $body, $pairs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $values = [];
            $lines = [];
            foreach ($pairs as $pair) {
                $values[$pair[1][0]] = ($pair[2][1] ?? -1) >= 0 ? $pair[2][0] : $pair[3][0];
                $lines[$pair[1][0]] = substr_count($code, "\n", 0, $offset + $pair[0][1]) + 1;
            }
            if (!isset($values['username'])) {
                continue;
            }
            $users[] = [
                'user' => $values['username'],
                'flags' => $values,
                'password' => sr_password($values['password'] ?? null, isset($lines['password']) ? $relative . ':' . $lines['password'] : null),
                'source' => $relative . ':' . $lines['username'],
            ];
        }
    }

    return $users;
}

/**
 * The accounts the repository seeds.
 *
 * @return list<array{role: string, user: string, source: string, password: array{kind: string, value: ?string, source: ?string}}>
 */
function sr_accounts(string $root): array
{
    $accounts = [];
    $installer = [];
    foreach (sr_installer_users($root) as $user) {
        $installer[strtolower($user['user'])] = $user;
        $flags = $user['flags'];
        $role = match (true) {
            df_is_true($flags['isMerchantAgent'] ?? false) => 'merchant-agent',
            df_is_true($flags['isAgent'] ?? false) => 'backoffice-user, agent',
            default => 'backoffice-user',
        };
        $accounts[] = ['role' => $role, 'user' => $user['user'], 'source' => $user['source'], 'password' => $user['password']];
    }
    [$merchantFiles] = sr_seed_files($root, 'merchant_user.csv');
    foreach ($merchantFiles as $file) {
        foreach (sr_csv($root . '/' . $file) as ['line' => $line, 'row' => $row]) {
            $user = trim($row['username'] ?? '');
            if ($user === '') {
                continue;
            }
            $password = sr_password($row['password'] ?? null, $file . ':' . $line);
            if ($password['kind'] !== 'plain' && isset($installer[strtolower($user)]) && $installer[strtolower($user)]['password']['kind'] === 'plain') {
                $password = $installer[strtolower($user)]['password'];
            }
            $reference = trim($row['merchant_reference'] ?? '');
            $accounts[] = ['role' => 'merchant-user' . ($reference !== '' ? ' (' . $reference . ')' : ''), 'user' => $user, 'source' => $file . ':' . $line, 'password' => $password];
        }
    }
    [$customerFiles] = sr_seed_files($root, 'customer.csv');
    foreach ($customerFiles as $file) {
        foreach (sr_csv($root . '/' . $file) as ['line' => $line, 'row' => $row]) {
            $email = trim($row['email'] ?? '');
            if ($email !== '') {
                $accounts[] = ['role' => 'customer', 'user' => $email, 'source' => $file . ':' . $line, 'password' => sr_password($row['password'] ?? null, $file . ':' . $line)];
            }
        }
    }

    return $accounts;
}

/**
 * Plain passwords the project's files hold: the accounts' plain passwords and the E2E fixtures'
 * `password` / `defaultPassword` values, each with its source.
 *
 * @return array<string, string> password => source
 */
function sr_password_candidates(string $root, array $accounts): array
{
    $candidates = [];
    foreach ($accounts as $account) {
        if ($account['password']['kind'] === 'plain') {
            $candidates[$account['password']['value']] ??= (string)$account['password']['source'];
        }
    }
    foreach (sr_find_files($root, 'tests', '*.json') as $file) {
        if (!str_contains($file, '/fixtures/')) {
            continue;
        }
        $code = (string)file_get_contents($root . '/' . $file);
        if (preg_match_all('/"(?:defaultPassword|password)"\s*:\s*"([^"]+)"/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$value, $offset]) {
                $candidates[$value] ??= $file . ':' . (substr_count($code, "\n", 0, $offset) + 1);
            }
        }
    }

    return $candidates;
}

function sr_print_env(array $deploy, string $deployFile, ?string $app, ?string $region): int
{
    $rows = df_applications($deploy);
    $matches = static fn (array $row): bool => ($app === null || strcasecmp($row['application'], $app) === 0 || strcasecmp((string)$row['entryPoint'], $app) === 0
            || ($app === 'backend-api' && $row['entryPoint'] === 'BackendApi'))
        && ($region === null || strcasecmp((string)$row['region'], $region) === 0 || strcasecmp((string)$row['store'], $region) === 0);
    $selected = array_values(array_filter($rows, $matches));
    if ($selected === []) {
        fwrite(STDERR, sprintf(
            "%s has no application %s%s. Applications: %s\n",
            $deployFile,
            $app ?? '(any)',
            $region === null ? '' : ' in ' . $region,
            implode(', ', array_unique(array_map(static fn (array $r): string => $r['application'] . ($r['entryPoint'] !== null ? ' (' . $r['entryPoint'] . ')' : '') . ' ' . ($r['store'] ?? $r['region']), $rows)))
        ));

        return 2;
    }
    printf(
        "%s: namespace %s, %s (docker.ssl.enabled: %s), dynamic store mode %s\n",
        $deployFile,
        $deploy['namespace'] ?? '(none)',
        df_scheme($deploy),
        df_scheme($deploy) === 'https' ? 'true' : 'not true',
        df_dynamic_store($deploy) ? 'on' : 'off'
    );
    foreach ($selected as $row) {
        printf(
            "%-33s %-6s %-48s cookie %-34s container %s%s\n",
            $row['application'] . ($row['entryPoint'] !== null ? ' (' . $row['entryPoint'] . ')' : ''),
            $row['store'] ?? $row['region'] ?? '-',
            $row['url'],
            $row['cookie'] ?? '-',
            $row['container'],
            $row['primal'] ? '  primal' : ''
        );
    }
    if ($app === null) {
        echo "Services:\n";
        foreach (df_services($deploy) as $service) {
            printf("%-16s %-16s container %-40s %s\n", $service['name'], $service['engine'] ?? '-', $service['container'], implode(', ', $service['endpoints']) ?: '-');
        }
    }

    return 0;
}

function sr_print_accounts(string $root, array $accounts, ?string $role, int $limit): int
{
    $skipped = array_merge(sr_seed_files($root, 'merchant_user.csv')[1], sr_seed_files($root, 'customer.csv')[1]);
    $byRole = [];
    foreach ($accounts as $account) {
        if ($role === null || str_contains($account['role'], $role)) {
            $byRole[preg_replace('/ \(.*\)$/', '', $account['role'])][] = $account;
        }
    }
    if ($byRole === []) {
        fwrite(STDERR, $role === null ? "No seeded accounts found (UserConfig.php, merchant_user.csv, customer.csv).\n" : "No seeded account has the role $role.\n");

        return 2;
    }
    foreach ($byRole as $name => $list) {
        printf("%s (%d):\n", $name, count($list));
        foreach ($limit === 0 ? $list : array_slice($list, 0, $limit) as $account) {
            $password = match ($account['password']['kind']) {
                'plain' => 'password in ' . $account['password']['source'],
                'hash' => 'password hash only (' . $account['password']['source'] . ')',
                default => 'no password in the repository',
            };
            printf("  %-40s %-26s %s; %s\n", $account['user'], $account['role'], $account['source'], $password);
        }
        if ($limit !== 0 && count($list) > $limit) {
            printf("  (%d more: --role %s --limit 0)\n", count($list) - $limit, $name);
        }
    }
    foreach ($skipped as $file) {
        printf("Skipped %s: real data, not seeded accounts (a production folder, or over %d MB).\n", $file, SR_MAX_SEED_FILE_BYTES / 1024 / 1024);
    }

    return 0;
}

/**
 * The password to log in with, and where it comes from; null with the reason when there is none.
 *
 * @return array{0: ?string, 1: string}
 */
function sr_login_password(string $root, string $user): array
{
    $fromEnv = getenv(SR_PASSWORD_ENV);
    if (is_string($fromEnv) && $fromEnv !== '') {
        return [$fromEnv, 'the environment variable ' . SR_PASSWORD_ENV];
    }
    $accounts = sr_accounts($root);
    $own = array_values(array_filter($accounts, static fn (array $a): bool => strcasecmp($a['user'], $user) === 0));
    foreach ($own as $account) {
        if ($account['password']['kind'] === 'plain') {
            return [$account['password']['value'], (string)$account['password']['source']];
        }
    }
    foreach ($own as $account) {
        if ($account['password']['kind'] === 'hash') {
            foreach (sr_password_candidates($root, $accounts) as $candidate => $source) {
                if (password_verify($candidate, (string)$account['password']['value'])) {
                    return [$candidate, $source . ' (verified against the hash in ' . $account['password']['source'] . ')'];
                }
            }

            return [null, sprintf('no password in the project\'s files verifies against the hash in %s; set %s', $account['password']['source'], SR_PASSWORD_ENV)];
        }
    }

    return [null, $own === []
        ? sprintf('%s is not a seeded account; set %s', $user, SR_PASSWORD_ENV)
        : sprintf('no password for %s in the repository; set %s', $user, SR_PASSWORD_ENV)];
}

/**
 * @return array{status: int, body: string, error: ?string}
 */
function sr_post(string $url, string $body, string $contentType, int $timeout): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType, 'Accept: application/json'],
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $response = curl_exec($handle);

    return [
        'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'body' => is_string($response) ? $response : '',
        'error' => $response === false ? curl_error($handle) : null,
    ];
}

/**
 * Is the host of $url the host of an application endpoint of the deploy file?
 */
function sr_host_allowed(array $deploy, string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }
    foreach (df_applications($deploy) as $row) {
        if (strtolower((string)$row['host']) === $host) {
            return true;
        }
    }

    return false;
}

function sr_session_file(string $root, string $app, string $user, string $extension): string
{
    $dir = $root . '/' . SR_STATE . '/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    if (!is_file($root . '/' . SR_STATE . '/.gitignore')) {
        file_put_contents($root . '/' . SR_STATE . '/.gitignore', "*\n");
    }

    return $dir . '/' . $app . '-' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($user)), '-') . '.' . $extension;
}

function sr_login(string $root, array $deploy, string $deployFile, string $app, string $user, ?string $region, ?string $host, ?string $path): int
{
    $login = SR_LOGINS[$app];
    $endpoints = array_values(array_filter(
        df_applications($deploy),
        static fn (array $row): bool => $row['application'] === $login['application']
            && ($region === null || strcasecmp((string)$row['region'], $region) === 0 || strcasecmp((string)$row['store'], $region) === 0)
    ));
    if ($host !== null) {
        $endpoints = array_values(array_filter($endpoints, static fn (array $row): bool => strcasecmp($row['host'], $host) === 0 || strcasecmp($row['host'] . ':' . $row['port'], $host) === 0));
        if ($endpoints === []) {
            fwrite(STDERR, sprintf("%s is not a %s host of %s: this script logs in only to hosts the deploy file names.\n", $host, $login['application'], $deployFile));

            return 2;
        }
    }
    if ($endpoints === []) {
        fwrite(STDERR, sprintf("%s has no %s application%s.\n", $deployFile, $login['application'], $region === null ? '' : ' in ' . $region));

        return 2;
    }
    usort($endpoints, static fn (array $a, array $b): int => $b['primal'] <=> $a['primal']);
    $endpoint = $endpoints[0];
    [$password, $passwordSource] = sr_login_password($root, $user);
    if ($password === null) {
        fwrite(STDERR, "$app $user: not logged in: $passwordSource.\n");

        return 2;
    }
    $label = sprintf('%s %s at %s', $app, $user, $endpoint['url']);
    $timeout = 30;

    if ($login['kind'] === 'session') {
        $jar = sr_session_file($root, $app, $user, 'cookies');
        @unlink($jar);
        touch($jar);
        chmod($jar, 0600);
        $settings = ['timeout' => $timeout, 'cookieJar' => $jar, 'insecure' => false];
        $loginPage = bo_request($endpoint['url'] . ($path ?? $login['path']), null, [], $settings);
        $form = bo_parse_login_form($loginPage['body'], $loginPage['url'] ?: $endpoint['url']);
        if ($loginPage['error'] !== null || $form === null || $form['userField'] === null || $form['passwordField'] === null) {
            printf("%s: no login: %s\n", $label, $loginPage['error'] !== null ? 'the host did not answer (' . $loginPage['error'] . ')' : sprintf('no login form at %s (HTTP %d)', $path ?? $login['path'], $loginPage['status']));

            return 3;
        }
        // A redirect or a form action can lead to another host (single sign-on): the password goes
        // only to a host the deploy file names.
        foreach (['login page' => $loginPage['url'], 'login form action' => $form['action']] as $what => $url) {
            if (!sr_host_allowed($deploy, (string)$url)) {
                printf("%s: no login: the %s is on %s, a host that %s does not name. The password was not sent.\n", $label, $what, parse_url((string)$url, PHP_URL_HOST) ?: '(no host)', $deployFile);

                return 2;
            }
        }
        $loginPath = (string)(parse_url($loginPage['url'], PHP_URL_PATH) ?: $login['path']);
        $fields = $form['fields'];
        $fields[$form['userField']] = $user;
        $fields[$form['passwordField']] = $password;
        $after = bo_request($form['action'], $fields, [], $settings);
        $landed = (string)(parse_url($after['url'], PHP_URL_PATH) ?: '/');
        if ($after['error'] !== null || $after['status'] >= 400 || bo_is_login_url($after['url'], $loginPath)) {
            printf("%s: login failed (HTTP %d, %s) with the password from %s. A failed login is a credentials question: see the skill's rule.\n", $label, $after['status'], $after['error'] ?? 'landed on ' . $landed, $passwordSource);

            return 1;
        }
        printf("%s: logged in (HTTP %d, landed on %s), password from %s; cookie jar %s, session cookie %s\n", $label, $after['status'], $landed, $passwordSource, substr($jar, strlen($root) + 1), $endpoint['cookie'] ?? '-');

        return 0;
    }

    $response = $login['kind'] === 'glue-token'
        ? sr_post($endpoint['url'] . '/access-tokens', (string)json_encode(['data' => ['type' => 'access-tokens', 'attributes' => ['username' => $user, 'password' => $password]]]), 'application/vnd.api+json', $timeout)
        : sr_post($endpoint['url'] . '/token', http_build_query(['grant_type' => 'password', 'username' => $user, 'password' => $password]), 'application/x-www-form-urlencoded', $timeout);
    if ($response['error'] !== null) {
        printf("%s: no token: the host did not answer (%s)\n", $label, $response['error']);

        return 3;
    }
    $json = json_decode($response['body'], true);
    $token = $login['kind'] === 'glue-token' ? ($json['data']['attributes']['accessToken'] ?? null) : ($json['access_token'] ?? null);
    $expires = $login['kind'] === 'glue-token' ? ($json['data']['attributes']['expiresIn'] ?? null) : ($json['expires_in'] ?? null);
    if (!is_string($token) || $token === '' || $response['status'] >= 400) {
        printf("%s: login failed (HTTP %d, no token in the response) with the password from %s. A failed login is a credentials question: see the skill's rule.\n", $label, $response['status'], $passwordSource);

        return 1;
    }
    $file = sr_session_file($root, $app, $user, 'token');
    file_put_contents($file, $token);
    chmod($file, 0600);
    printf("%s: token (HTTP %d%s), password from %s; token file %s — send it as: -H \"Authorization: Bearer \$(cat %s)\"\n", $label, $response['status'], $expires !== null ? ', expires in ' . $expires . ' s' : '', $passwordSource, substr($file, strlen($root) + 1), substr($file, strlen($root) + 1));

    return 0;
}

function sr_main(array $argv): int
{
    $mode = $argv[1] ?? '';
    $positional = [];
    $options = ['deploy-file' => 'deploy.dev.yml'];
    $args = array_slice($argv, 2);
    for ($i = 0; $i < count($args); $i++) {
        if (str_starts_with($args[$i], '--')) {
            $name = substr($args[$i], 2);
            if (!in_array($name, ['deploy-file', 'app', 'region', 'role', 'limit', 'host', 'path'], true) || !isset($args[$i + 1])) {
                fwrite(STDERR, "Unknown option or missing value: {$args[$i]}\n");

                return 2;
            }
            $options[$name] = $args[++$i];
            continue;
        }
        $positional[] = $args[$i];
    }
    $root = (string)realpath((string)getcwd());

    if ($mode === 'accounts') {
        if (isset($options['limit']) && !ctype_digit($options['limit'])) {
            fwrite(STDERR, "--limit takes a number\n");

            return 2;
        }

        return sr_print_accounts($root, sr_accounts($root), $options['role'] ?? null, (int)($options['limit'] ?? SR_DEFAULT_LIMIT));
    }
    if (!in_array($mode, ['env', 'login'], true)) {
        fwrite(STDERR, "Usage: php runtime.php env [--deploy-file <file>] [--app <application>] [--region <region>]\n"
            . "       php runtime.php accounts [--role <role>] [--limit N]\n"
            . "       php runtime.php login <" . implode('|', array_keys(SR_LOGINS)) . "> <user> [--deploy-file <file>] [--region <region>] [--host <host>] [--path <login path>]\n");

        return 2;
    }
    $deploy = df_load($root, $options['deploy-file']);
    if ($deploy === null) {
        fwrite(STDERR, "No deploy file {$options['deploy-file']} in the project root: name the active one with --deploy-file.\n");

        return 2;
    }
    if ($mode === 'env') {
        return sr_print_env($deploy, $options['deploy-file'], $options['app'] ?? null, $options['region'] ?? null);
    }
    if (count($positional) !== 2 || !isset(SR_LOGINS[$positional[0]])) {
        fwrite(STDERR, 'Usage: php runtime.php login <' . implode('|', array_keys(SR_LOGINS)) . "> <user> [--region <region>] [--host <host>]\n");

        return 2;
    }

    return sr_login($root, $deploy, $options['deploy-file'], $positional[0], $positional[1], $options['region'] ?? null, $options['host'] ?? null, $options['path'] ?? null);
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(sr_main($argv));
}
