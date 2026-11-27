<?php

/**
 * The runtime environment of a Spryker project, its seeded accounts, and a login that leaves a
 * session or a token in a file.
 *
 * Usage (from the project root):
 *   php runtime.php env [--deploy-file <file>] [--app <application>] [--region <region or store>]
 *   php runtime.php accounts [--role <role>] [--user <text>] [--limit N]
 *   php runtime.php login <app> <user> [--deploy-file <file>] [--region <region or store>] [--host <host>]
 *                         [--path <login or token path>] [--insecure]
 *
 * env: one line per application endpoint of the deploy file (deploy.dev.yml by default): application,
 *   store or region, URL with scheme and port, session cookie name, container; then the services
 *   (broker, database, search, key-value store, …) with their container and endpoints. Credentials
 *   of the deploy file are not printed.
 * accounts: the accounts the repository seeds — Back Office users and agents (src/<Ns>/Zed/User/
 *   UserConfig.php, else the vendor UserConfig.php), merchant users (data/import/**\/merchant_user.csv),
 *   customers (data/import/**\/customer.csv, data/import/common first) — one line per user name with
 *   the line in its file and where a usable password is (a line, a hash only, or none), under one
 *   header line per file. No password is printed. --user filters by a part of the user name. --limit
 *   per role: default 20, 0 or more than 200 = 200.
 * login: <app> is yves, agent, backoffice, merchant-portal (a session: the cookie jar), glue (a token
 *   from /access-tokens), glue-storefront or glue-backend (a token from /token); --path changes the
 *   login or token path. Only hosts the deploy file names (host and port): when the login page, its
 *   form action or a redirect leads to another host, the script stops there. Redirects after the
 *   login form are followed as GET requests only, at most 5. The password comes from
 *   SPRYKER_RUNTIME_PASSWORD, else from the project's files: a plain password of the account, or,
 *   for a hashed one, a plain password from the project's files that verifies against the hash.
 *   --insecure accepts a self-signed certificate.
 *   Writes .ai-dev/runtime/sessions/<app>-<user>.cookies or .token and prints one line; never the
 *   password, the cookie value or the token.
 *
 * Exit codes. env, accounts: 0, 2 usage, no deploy file / application, or a deploy file the reader
 * cannot parse. login: 0 logged in, 1 the application refused the login, 2 usage, a deploy file the
 * reader cannot parse, a host outside the deploy file (given, or reached by a redirect or a form
 * action), or no password, 3 the host did not answer, showed no login form, or answered HTTP 4xx/5xx
 * on a page that is not the login page.
 */

declare(strict_types=1);

require_once __DIR__ . '/deploy-file.php';
require_once __DIR__ . '/../../spryker-upgrade/scripts/backoffice-smoke.php';

const SR_STATE = '.ai-dev/runtime';
const SR_PASSWORD_ENV = 'SPRYKER_RUNTIME_PASSWORD';
const SR_DEFAULT_LIMIT = 20;
const SR_MAX_LINES = 200;
const SR_MAX_REDIRECTS = 5;
const SR_VENDOR_USER_CONFIG = 'vendor/spryker/user/src/Spryker/Zed/User/UserConfig.php';

/**
 * Data import files larger than this, or under a `production` folder, hold real data, not seeded
 * accounts: the accounts call skips them and names them.
 */
const SR_MAX_SEED_FILE_BYTES = 5 * 1024 * 1024;

/**
 * Login applications: the deploy application, the login or token path, and the kind of login.
 * json-token: JSON:API body to /access-tokens (the legacy Glue REST API). form-token: an OAuth form
 * (grant_type=password) to /token (the Storefront API and the Backend API).
 */
const SR_LOGINS = [
    'yves' => ['application' => 'yves', 'path' => '/login', 'kind' => 'session'],
    'agent' => ['application' => 'yves', 'path' => '/agent/login', 'kind' => 'session'],
    'backoffice' => ['application' => 'backoffice', 'path' => '/security-gui/login', 'kind' => 'session'],
    'merchant-portal' => ['application' => 'merchant-portal', 'path' => '/security-merchant-portal-gui/login', 'kind' => 'session'],
    'glue' => ['application' => 'glue', 'path' => '/access-tokens', 'kind' => 'json-token'],
    'glue-storefront' => ['application' => 'glue-storefront', 'path' => '/token', 'kind' => 'form-token'],
    'glue-backend' => ['application' => 'glue-backend', 'path' => '/token', 'kind' => 'form-token'],
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
 * The data import files of seeded accounts (data/import/common first), and the files skipped as
 * real data.
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
    usort($files, static fn (string $a, string $b): int => [!str_starts_with($a, 'data/import/common/'), $a] <=> [!str_starts_with($b, 'data/import/common/'), $b]);

    return [$files, $skipped];
}

/**
 * The rows of a CSV, keyed by the header, with the physical line where each row starts (a quoted
 * field can hold line breaks).
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
    $next = 1;
    while (($fields = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $line = $next;
        $next += 1 + array_sum(array_map(static fn ($field): int => substr_count((string)$field, "\n"), $fields));
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
 * A password entry: plain (usable), hash (verify a candidate), unreadable (not a literal) or none.
 * The value never leaves the script.
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
 * The offsets of the `[` and the `]` of the innermost array literal around $offset, or null.
 *
 * @return array{0: int, 1: int}|null
 */
function sr_enclosing_array(string $code, int $offset): ?array
{
    $start = null;
    for ($i = $offset, $depth = 0; $i >= 0; $i--) {
        if ($code[$i] === ']') {
            $depth++;
        } elseif ($code[$i] === '[') {
            if ($depth === 0) {
                $start = $i;
                break;
            }
            $depth--;
        }
    }
    if ($start === null) {
        return null;
    }
    for ($i = $start + 1, $depth = 0, $n = strlen($code); $i < $n; $i++) {
        if ($code[$i] === '[') {
            $depth++;
        } elseif ($code[$i] === ']') {
            if ($depth === 0) {
                return [$start, $i];
            }
            $depth--;
        }
    }

    return null;
}

/**
 * The installer users of src/<Ns>/Zed/User/UserConfig.php, or of the vendor UserConfig.php when the
 * project has none: username, flags, password, lines; and the entries that cannot be read (a user
 * name that is not a literal string), as file:line.
 *
 * @return array{users: list<array{user: string, flags: array<string, mixed>, password: array, source: string}>, unreadable: list<string>}
 */
function sr_installer_users(string $root): array
{
    $files = glob($root . '/src/*/Zed/User/UserConfig.php') ?: [];
    if ($files === [] && is_file($root . '/' . SR_VENDOR_USER_CONFIG)) {
        $files = [$root . '/' . SR_VENDOR_USER_CONFIG];
    }
    $users = [];
    $unreadable = [];
    foreach ($files as $file) {
        $code = (string)file_get_contents($file);
        $relative = substr($file, strlen($root) + 1);
        $lineOf = static fn (int $offset): int => substr_count($code, "\n", 0, $offset) + 1;
        preg_match_all('/[\'"]username[\'"]\s*=>/', $code, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [, $offset]) {
            $array = sr_enclosing_array($code, $offset);
            if ($array === null) {
                $unreadable[] = $relative . ':' . $lineOf($offset);
                continue;
            }
            $body = substr($code, $array[0] + 1, $array[1] - $array[0] - 1);
            preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*/', $body, $keys, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $values = [];
            $lines = [];
            foreach ($keys as $key) {
                $name = $key[1][0];
                $lines[$name] = $lineOf($array[0] + 1 + $key[0][1]);
                $rest = substr($body, $key[0][1] + strlen($key[0][0]));
                if (!preg_match('/^(\'[^\'\\\\]*\'|"[^"\\\\$]*"|true|false|\d+)\s*(?:,|$|\/\/|#|\/\*)/i', $rest, $literal)) {
                    $values[$name] = null;
                    continue;
                }
                $raw = $literal[1];
                $values[$name] = match (true) {
                    $raw[0] === '\'' || $raw[0] === '"' => substr($raw, 1, -1),
                    strtolower($raw) === 'true' => true,
                    strtolower($raw) === 'false' => false,
                    default => (int)$raw,
                };
            }
            if (!is_string($values['username'] ?? null)) {
                $unreadable[] = $relative . ':' . $lineOf($offset);
                continue;
            }
            $passwordSource = isset($lines['password']) ? $relative . ':' . $lines['password'] : null;
            $password = array_key_exists('password', $values) && !is_string($values['password'])
                ? ['kind' => 'unreadable', 'value' => null, 'source' => $passwordSource]
                : sr_password($values['password'] ?? null, $passwordSource);
            $users[] = [
                'user' => $values['username'],
                'flags' => $values,
                'password' => $password,
                'source' => $relative . ':' . $lines['username'],
            ];
        }
    }

    return ['users' => $users, 'unreadable' => $unreadable];
}

/**
 * The accounts the repository seeds, every source of each (duplicates included).
 *
 * @return list<array{role: string, user: string, source: string, password: array{kind: string, value: ?string, source: ?string}}>
 */
function sr_accounts(string $root): array
{
    $accounts = [];
    $installer = [];
    foreach (sr_installer_users($root)['users'] as $user) {
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

/**
 * Where the password of an account is, relative to the file of the account.
 */
function sr_password_text(array $password, string $file): string
{
    $source = (string)$password['source'];
    $where = str_starts_with($source, $file . ':') ? 'line ' . substr($source, strlen($file) + 1) : $source;

    return match ($password['kind']) {
        'plain' => 'password ' . ($where === $source ? 'in ' : '') . $where,
        'hash' => 'password hash only (' . $where . ')',
        'unreadable' => 'password not a literal (' . $where . ')',
        default => 'no password in the repository',
    };
}

function sr_print_accounts(string $root, array $accounts, ?string $role, ?string $user, int $limit): int
{
    $limit = $limit === 0 || $limit > SR_MAX_LINES ? SR_MAX_LINES : $limit;
    $skipped = array_merge(sr_seed_files($root, 'merchant_user.csv')[1], sr_seed_files($root, 'customer.csv')[1]);
    $unique = [];
    foreach ($accounts as $account) {
        if (($role !== null && !str_contains($account['role'], $role)) || ($user !== null && stripos($account['user'], $user) === false)) {
            continue;
        }
        $group = (string)preg_replace('/ \(.*\)$/', '', $account['role']);
        $key = $group . '|' . strtolower($account['user']);
        if (isset($unique[$key])) {
            $unique[$key]['others']++;
            continue;
        }
        $unique[$key] = $account + ['group' => $group, 'others' => 0];
    }
    $byRole = [];
    foreach ($unique as $account) {
        $byRole[$account['group']][] = $account;
    }
    foreach (sr_installer_users($root)['unreadable'] as $entry) {
        printf("Could not read the entry at %s: the user name is not a literal string.\n", $entry);
    }
    if ($byRole === []) {
        fwrite(STDERR, match (true) {
            $role === null && $user === null => "No seeded accounts found (UserConfig.php, merchant_user.csv, customer.csv).\n",
            default => sprintf("No seeded account%s%s.\n", $role !== null ? ' has the role ' . $role : '', $user !== null ? ($role !== null ? ' and' : '') . ' matches ' . $user : ''),
        });

        return 2;
    }
    foreach ($byRole as $name => $list) {
        printf("%s (%d):\n", $name, count($list));
        $file = null;
        foreach (array_slice($list, 0, $limit) as $account) {
            [$accountFile, $line] = explode(':', $account['source'], 2) + [1 => ''];
            if ($accountFile !== $file) {
                printf("  %s\n", $accountFile);
                $file = $accountFile;
            }
            $reference = preg_match('/ (\(.*\))$/', $account['role'], $m) ? ' ' . $m[1] : '';
            $roleNote = $account['role'] !== $name && $reference === '' ? ' ' . $account['role'] : '';
            printf(
                "    %s%s%s line %s; %s%s\n",
                $account['user'],
                $reference,
                $roleNote,
                $line,
                sr_password_text($account['password'], $accountFile),
                $account['others'] > 0 ? sprintf('; also in %d other row(s)', $account['others']) : ''
            );
        }
        if (count($list) > $limit) {
            printf("  (%d more: filter with --user <text>%s)\n", count($list) - $limit, $limit < SR_MAX_LINES ? ', or --limit up to ' . SR_MAX_LINES : '');
        }
    }
    foreach ($skipped as $skippedFile) {
        printf("Skipped %s: real data, not seeded accounts (a production folder, or over %d MB).\n", $skippedFile, SR_MAX_SEED_FILE_BYTES / 1024 / 1024);
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
 * One HTTP request that does not follow redirects; cookies stay in the handle and go to the cookie
 * jar. A POST sends $body with $contentType.
 *
 * @return array{status: int, url: string, location: ?string, body: string, error: ?string}
 */
function sr_request(string $url, ?string $body, ?string $contentType, array $settings): array
{
    static $handles = [];
    $key = (string)($settings['cookieJar'] ?? '');
    if (!isset($handles[$key])) {
        $handles[$key] = curl_init();
        if ($key !== '') {
            curl_setopt($handles[$key], CURLOPT_COOKIEFILE, $key);
            curl_setopt($handles[$key], CURLOPT_COOKIEJAR, $key);
        }
    }
    $handle = $handles[$key];
    curl_setopt_array($handle, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $settings['timeout'],
        CURLOPT_SSL_VERIFYPEER => !$settings['insecure'],
        CURLOPT_SSL_VERIFYHOST => $settings['insecure'] ? 0 : 2,
        CURLOPT_USERAGENT => 'spryker-runtime-login',
        CURLOPT_HTTPHEADER => $contentType !== null ? ['Content-Type: ' . $contentType, 'Accept: application/json'] : [],
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    } else {
        curl_setopt($handle, CURLOPT_HTTPGET, true);
    }
    $response = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $location = curl_getinfo($handle, CURLINFO_REDIRECT_URL);
    if ($key !== '') {
        curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
    }

    return [
        'status' => $status,
        'url' => $url,
        'location' => $status >= 300 && $status < 400 && is_string($location) && $location !== '' ? $location : null,
        'body' => is_string($response) ? $response : '',
        'error' => $response === false ? curl_error($handle) : null,
    ];
}

/**
 * Follows the redirects of $response as GET requests, at most SR_MAX_REDIRECTS, and only to hosts
 * the deploy file names. 'refused' holds the first URL on another host; the script does not request it.
 *
 * @return array{response: array, refused: ?string, tooMany: bool}
 */
function sr_follow(array $deploy, array $response, array $settings): array
{
    for ($hops = 0; $response['location'] !== null && $response['error'] === null; $hops++) {
        if (!sr_host_allowed($deploy, $response['location'])) {
            return ['response' => $response, 'refused' => $response['location'], 'tooMany' => false];
        }
        if ($hops >= SR_MAX_REDIRECTS) {
            return ['response' => $response, 'refused' => null, 'tooMany' => true];
        }
        $response = sr_request($response['location'], null, null, $settings);
    }

    return ['response' => $response, 'refused' => null, 'tooMany' => false];
}

/**
 * Are the host and the port of $url the host and the port of an application endpoint of the deploy file?
 */
function sr_host_allowed(array $deploy, string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }
    $port = parse_url($url, PHP_URL_PORT) ?? (strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);
    foreach (df_applications($deploy) as $row) {
        if (strtolower((string)$row['host']) === $host && $row['port'] === $port) {
            return true;
        }
    }

    return false;
}

function sr_host_of(string $url): string
{
    $host = (string)parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_PORT);

    return $host === '' ? '(no host)' : $host . ($port !== null ? ':' . $port : '');
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

function sr_login(string $root, array $deploy, string $deployFile, string $app, string $user, ?string $region, ?string $host, ?string $path, bool $insecure): int
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
    $settings = ['timeout' => 30, 'insecure' => $insecure];
    $loginPath = $path ?? $login['path'];

    if ($login['kind'] === 'session') {
        $jar = sr_session_file($root, $app, $user, 'cookies');
        @unlink($jar);
        touch($jar);
        chmod($jar, 0600);
        $settings['cookieJar'] = $jar;
        $page = sr_follow($deploy, sr_request($endpoint['url'] . $loginPath, null, null, $settings), $settings);
        $loginPage = $page['response'];
        if ($page['refused'] !== null) {
            printf("%s: no login: the login page is on %s, a host that %s does not name. The password was not sent.\n", $label, sr_host_of($page['refused']), $deployFile);

            return 2;
        }
        $form = $loginPage['error'] === null && !$page['tooMany'] ? bo_parse_login_form($loginPage['body'], $loginPage['url']) : null;
        if ($form === null || $form['userField'] === null || $form['passwordField'] === null) {
            printf("%s: no login: %s\n", $label, match (true) {
                $loginPage['error'] !== null => 'the host did not answer (' . $loginPage['error'] . ')',
                $page['tooMany'] => sprintf('more than %d redirects from %s', SR_MAX_REDIRECTS, $loginPath),
                default => sprintf('no login form at %s (HTTP %d)', $loginPath, $loginPage['status']),
            });

            return 3;
        }
        if (!sr_host_allowed($deploy, $form['action'])) {
            printf("%s: no login: the login form action is on %s, a host that %s does not name. The password was not sent.\n", $label, sr_host_of($form['action']), $deployFile);

            return 2;
        }
        $formPath = (string)(parse_url($loginPage['url'], PHP_URL_PATH) ?: $loginPath);
        $fields = $form['fields'];
        $fields[$form['userField']] = $user;
        $fields[$form['passwordField']] = $password;
        // The POST is not redirected: a redirect is followed below as a GET, without the form.
        $after = sr_follow($deploy, sr_request($form['action'], http_build_query($fields), 'application/x-www-form-urlencoded', $settings), $settings);
        $final = $after['response'];
        if ($after['refused'] !== null) {
            printf("%s: not logged in: the login answer redirects to %s, a host that %s does not name. The script did not follow it.\n", $label, sr_host_of($after['refused']), $deployFile);

            return 2;
        }
        $landed = (string)(parse_url($final['url'], PHP_URL_PATH) ?: '/');
        if ($final['error'] !== null || $after['tooMany']) {
            printf("%s: no login: %s\n", $label, $final['error'] !== null ? 'the host did not answer (' . $final['error'] . ')' : sprintf('more than %d redirects after the login form', SR_MAX_REDIRECTS));

            return 3;
        }
        if (bo_is_login_url($final['url'], $formPath)) {
            printf("%s: login failed (HTTP %d, landed on %s) with the password from %s. A failed login is a credentials question: see the skill's rule.\n", $label, $final['status'], $landed, $passwordSource);

            return 1;
        }
        if ($final['status'] >= 400) {
            printf("%s: no login: the login page answered HTTP %d (on %s).\n", $label, $final['status'], $landed);

            return 3;
        }
        if (!sr_host_allowed($deploy, $final['url'])) {
            printf("%s: not logged in: the login ended on %s, a host that %s does not name.\n", $label, sr_host_of($final['url']), $deployFile);

            return 2;
        }
        printf("%s: logged in (HTTP %d, landed on %s), password from %s; cookie jar %s, session cookie %s\n", $label, $final['status'], $landed, $passwordSource, substr($jar, strlen($root) + 1), $endpoint['cookie'] ?? '-');

        return 0;
    }

    $response = $login['kind'] === 'json-token'
        ? sr_request($endpoint['url'] . $loginPath, (string)json_encode(['data' => ['type' => 'access-tokens', 'attributes' => ['username' => $user, 'password' => $password]]]), 'application/vnd.api+json', $settings)
        : sr_request($endpoint['url'] . $loginPath, http_build_query(['grant_type' => 'password', 'username' => $user, 'password' => $password]), 'application/x-www-form-urlencoded', $settings);
    if ($response['error'] !== null) {
        printf("%s: no token: the host did not answer (%s)\n", $label, $response['error']);

        return 3;
    }
    $json = json_decode($response['body'], true);
    $token = $login['kind'] === 'json-token' ? ($json['data']['attributes']['accessToken'] ?? null) : ($json['access_token'] ?? null);
    $expires = $login['kind'] === 'json-token' ? ($json['data']['attributes']['expiresIn'] ?? null) : ($json['expires_in'] ?? null);
    if ($response['status'] >= 300 && !in_array($response['status'], [400, 401, 403], true)) {
        printf("%s: no token: the login page answered HTTP %d (%s).\n", $label, $response['status'], $loginPath);

        return 3;
    }
    if (!is_string($token) || $token === '' || $response['status'] >= 400) {
        printf("%s: login failed (HTTP %d, no token in the response) with the password from %s. A failed login is a credentials question: see the skill's rule.\n", $label, $response['status'], $passwordSource);

        return 1;
    }
    $file = sr_session_file($root, $app, $user, 'token');
    file_put_contents($file, $token);
    chmod($file, 0600);
    printf("%s: token from %s (HTTP %d%s), password from %s; token file %s — send it as: -H \"Authorization: Bearer \$(cat %s)\"\n", $label, $loginPath, $response['status'], $expires !== null ? ', expires in ' . $expires . ' s' : '', $passwordSource, substr($file, strlen($root) + 1), substr($file, strlen($root) + 1));

    return 0;
}

function sr_main(array $argv): int
{
    $mode = $argv[1] ?? '';
    $positional = [];
    $options = ['deploy-file' => 'deploy.dev.yml'];
    $args = array_slice($argv, 2);
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--insecure') {
            $options['insecure'] = true;
            continue;
        }
        if (str_starts_with($args[$i], '--')) {
            $name = substr($args[$i], 2);
            if (!in_array($name, ['deploy-file', 'app', 'region', 'role', 'user', 'limit', 'host', 'path'], true) || !isset($args[$i + 1])) {
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

        return sr_print_accounts($root, sr_accounts($root), $options['role'] ?? null, $options['user'] ?? null, (int)($options['limit'] ?? SR_DEFAULT_LIMIT));
    }
    if (!in_array($mode, ['env', 'login'], true)) {
        fwrite(STDERR, "Usage: php runtime.php env [--deploy-file <file>] [--app <application>] [--region <region>]\n"
            . "       php runtime.php accounts [--role <role>] [--user <text>] [--limit N]\n"
            . "       php runtime.php login <" . implode('|', array_keys(SR_LOGINS)) . "> <user> [--deploy-file <file>] [--region <region>] [--host <host>] [--path <login or token path>] [--insecure]\n");

        return 2;
    }
    $deploy = df_load($root, $options['deploy-file']);
    if ($deploy === null) {
        fwrite(STDERR, "No deploy file {$options['deploy-file']} in the project root: name the active one with --deploy-file.\n");

        return 2;
    }
    if (is_string($deploy)) {
        fwrite(STDERR, $deploy . ".\n");

        return 2;
    }
    if ($mode === 'env') {
        return sr_print_env($deploy, $options['deploy-file'], $options['app'] ?? null, $options['region'] ?? null);
    }
    if (count($positional) !== 2 || !isset(SR_LOGINS[$positional[0]])) {
        fwrite(STDERR, 'Usage: php runtime.php login <' . implode('|', array_keys(SR_LOGINS)) . "> <user> [--region <region>] [--host <host>] [--path <login or token path>] [--insecure]\n");

        return 2;
    }

    return sr_login($root, $deploy, $options['deploy-file'], $positional[0], $positional[1], $options['region'] ?? null, $options['host'] ?? null, $options['path'] ?? null, isset($options['insecure']));
}

if (realpath($argv[0] ?? '') === __FILE__) {
    exit(sr_main($argv));
}
