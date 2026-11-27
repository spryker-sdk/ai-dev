<?php

declare(strict_types=1);

/** Zero-dependency tests for runtime.php and deploy-file.php. Run: php scripts/runtime.test.php */

require __DIR__ . '/runtime.php';

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

function write_file(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

/**
 * @return array{0: int, 1: string} exit code, combined output
 */
function runtime(string $projectDir, array $args, array $env = []): array
{
    $environment = array_merge(getenv(), $env);
    unset($environment[SR_PASSWORD_ENV]);
    $environment = array_merge($environment, $env);
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/runtime.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectDir, $environment);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string)$output];
}

$tmp = sys_get_temp_dir() . '/runtime_' . getmypid();
@mkdir($tmp, 0777, true);
$tmp = (string)realpath($tmp);

// --- the deploy file reader ---
$yaml = <<<'YAML'
version: '0.1'
namespace: shop_dev   # project namespace
image:
    environment:
        SPRYKER_DYNAMIC_STORE_MODE: 'true'
    php:
        enabled-extensions:
            - otel
            - pcov
groups:
    EU:
        region: EU
        applications:
            yves_eu:
                application: yves
                endpoints:
                    yves.de.shop.local:
                        store: DE
                    yves.at.shop.local:
                        store: AT
                        #store: CH
            glue_eu:
                application: glue
                endpoints:
                    glue.de.shop.local:
                        store: DE
            boffice_eu:
                application: backoffice
                endpoints:
                    backoffice.eu.shop.local:
                        region: EU
                        primal: true
            mp_eu:
                application: merchant-portal
                endpoints:
                    mp.eu.shop.local:
                        region: EU
                        entry-point: MerchantPortal
            backapi_eu:
                application: zed
                endpoints:
                    backend-api.eu.shop.local:
                        region: EU
                        entry-point: BackendApi
    US:
        region: US
        applications:
            yves_us:
                application: yves
                endpoints:
                    yves.us.shop.local:8080:
                        region: US
services:
    broker:
        engine: rabbitmq
        api:
            username: 'spryker'
            password: 'broker-api-secret'
        endpoints:
            queue.shop.local:
            localhost:5672:
                protocol: tcp
    database:
        engine: mysql
        root:
            password: 'db-root-secret'
    search:
        engine: opensearch
    key_value_store:
        engine: valkey
        endpoints:
            localhost:16379:
                protocol: tcp
docker:
    ssl:
        enabled: false
YAML;
$deploy = df_parse_yaml($yaml);
check('yaml: comments, quoted values, sequences and host:port keys', ($deploy['namespace'] ?? '') === 'shop_dev' && ($deploy['image']['php']['enabled-extensions'] ?? []) === ['otel', 'pcov'] && array_key_exists('yves.us.shop.local:8080', $deploy['groups']['US']['applications']['yves_us']['endpoints']) && ($deploy['groups']['EU']['applications']['yves_eu']['endpoints']['yves.at.shop.local'] ?? []) === ['store' => 'AT']);
check('deploy: dynamic store mode and Glue entry points', df_dynamic_store($deploy) && df_glue_entry_points($deploy) === ['Glue']);

$project = $tmp . '/project';
write_file($project . '/deploy.dev.yml', $yaml);
write_file($project . '/deploy.ssl.yml', "namespace: ssl_dev\ngroups:\n    EU:\n        region: EU\n        applications:\n            yves:\n                application: yves\n                endpoints:\n                    yves.de.ssl.local:8443:\n                        store: DE\n            boffice:\n                application: backoffice\n                endpoints:\n                    backoffice.ssl.local:\n                        region: EU\ndocker:\n    ssl:\n        enabled: true\n");

[$envCode, $envOut] = runtime($project, ['env']);
$line = static function (string $output, string $needle): string {
    foreach (explode("\n", $output) as $row) {
        if (str_contains($row, $needle)) {
            return $row;
        }
    }

    return '';
};
check('env: header with namespace, scheme and store mode', $envCode === 0 && str_starts_with($envOut, 'deploy.dev.yml: namespace shop_dev, http (docker.ssl.enabled: not true), dynamic store mode on'));
check('env: one line per endpoint, several stores and regions', preg_match('/^yves\s+DE\s+http:\/\/yves\.de\.shop\.local\s/m', $envOut) === 1 && preg_match('/^yves\s+AT\s+http:\/\/yves\.at\.shop\.local\s/m', $envOut) === 1 && preg_match('/^yves\s+US\s/m', $envOut) === 1 && preg_match('/^backoffice\s+EU\s/m', $envOut) === 1);
check('env: a custom port is in the URL', str_contains($line($envOut, 'yves.us.shop.local'), 'http://yves.us.shop.local:8080 '));
check('env: session cookie per application (host with dots as dashes), none for Glue', str_contains($line($envOut, 'yves.de.shop.local'), 'cookie yves-de-shop-local') && str_contains($line($envOut, 'backoffice.eu'), 'cookie backoffice-eu-shop-local') && str_contains($line($envOut, 'mp.eu'), 'cookie mp-eu-shop-local') && str_contains($line($envOut, 'glue.de'), 'cookie -'));
check('env: container by the project namespace, primal marked', str_contains($line($envOut, 'backoffice.eu'), 'container shop_dev_boffice_eu_1  primal') && str_contains($line($envOut, 'yves.us'), 'container shop_dev_yves_us_1'));
check('env: services by namespace, endpoints, no service credentials', preg_match('/^broker\s+rabbitmq\s+container shop_dev_broker_1\s+http:\/\/queue\.shop\.local, tcp localhost:5672$/m', $envOut) === 1 && str_contains($envOut, 'container shop_dev_database_1') && str_contains($envOut, 'container shop_dev_search_1') && str_contains($envOut, 'container shop_dev_key_value_store_1') && !str_contains($envOut, 'broker-api-secret') && !str_contains($envOut, 'db-root-secret'));
[, $sslOut] = runtime($project, ['env', '--deploy-file', 'deploy.ssl.yml']);
check('env: https from docker.ssl.enabled, custom and default port', str_contains($sslOut, 'https://yves.de.ssl.local:8443 ') && str_contains($sslOut, 'https://backoffice.ssl.local ') && str_contains($sslOut, 'cookie yves-de-ssl-local'));
[$oneCode, $oneOut] = runtime($project, ['env', '--app', 'backend-api']);
check('env --app: one application, no services', $oneCode === 0 && count(explode("\n", trim($oneOut))) === 2 && str_contains($oneOut, 'zed (BackendApi)') && !str_contains($oneOut, 'Services:'));
check('env --app --region', count(explode("\n", trim(runtime($project, ['env', '--app', 'yves', '--region', 'AT'])[1]))) === 2);
[$noAppCode, $noAppOut] = runtime($project, ['env', '--app', 'glue-backend']);
check('env: an application the deploy file does not have -> exit 2, the applications named', $noAppCode === 2 && str_contains($noAppOut, 'deploy.dev.yml has no application glue-backend') && str_contains($noAppOut, 'zed (BackendApi) EU'));
check('env: no deploy file -> exit 2', runtime($project, ['env', '--deploy-file', 'deploy.none.yml'])[0] === 2);

// --- accounts ---
$adminPassword = 'S3cret-admin';
$customerPassword = 'Plain-customer-pw';
$fixturePassword = 'Fixture-default-pw';
write_file($project . '/src/Pyz/Zed/User/UserConfig.php', "<?php\nclass UserConfig\n{\n    public function getInstallerUsers(): array\n    {\n        return [\n            [\n                'firstName' => 'Admin',\n                'username' => 'admin@shop.local',\n                'password' => '$adminPassword',\n                'isAgent' => true,\n            ],\n            [\n                'username' => 'agent-merchant@shop.local',\n                'password' => '$adminPassword',\n                'isMerchantAgent' => 1,\n            ],\n            [\n                'username' => 'harald@shop.local',\n                'password' => '$adminPassword',\n            ],\n        ];\n    }\n}\n");
write_file($project . '/data/import/common/common/marketplace/merchant_user.csv', "merchant_reference,username\nMER000001,harald@shop.local\n");
$hash = password_hash($fixturePassword, PASSWORD_BCRYPT, ['cost' => 4]);
write_file($project . '/data/import/common/common/customer.csv', "customer_reference,email,first_name,password\nDE--1,sonia@shop.local,Sonia,$customerPassword\nDE--2,karl@shop.local,Karl,$hash\nDE--3,nopw@shop.local,No,\n");
write_file($project . '/tests/cypress-tests/cypress/fixtures/b2c/customer.json', json_encode(['defaultPassword' => $fixturePassword, 'customer' => ['email' => 'karl@shop.local']], JSON_PRETTY_PRINT));
write_file($project . '/data/import/production/common/customer.csv', "customer_reference,email,password\n1,real.person@example.com,x\n");
write_file($project . '/data/import/a-robot/customer.csv', "customer_reference,email,password\nR1,robot1@shop.local,\nR2,SONIA@shop.local,\n");
[$accCode, $accOut] = runtime($project, ['accounts']);
check('accounts: a production import folder is real data: skipped and named', !str_contains($accOut, 'real.person@example.com') && str_contains($accOut, 'Skipped data/import/production/common/customer.csv: real data'));
check('accounts: roles, one header line per file, user and line', $accCode === 0 && str_contains($accOut, "backoffice-user, agent (1):\n  src/Pyz/Zed/User/UserConfig.php\n    admin@shop.local line 9; password line 10\n") && str_contains($accOut, 'merchant-agent (1):') && str_contains($accOut, "merchant-user (1):\n  data/import/common/common/marketplace/merchant_user.csv\n    harald@shop.local (MER000001) line 2; password in src/Pyz/Zed/User/UserConfig.php:20\n"));
check('accounts: customers with the password source, never the password', str_contains($accOut, "    sonia@shop.local line 2; password line 2; also in 1 other row(s)\n    karl@shop.local line 3; password hash only (line 3)\n    nopw@shop.local line 4; no password in the repository\n"));
check('accounts: duplicates by user name removed, data/import/common before other folders', str_contains($accOut, "customer (4):\n  data/import/common/common/customer.csv\n    sonia@shop.local") && str_contains($accOut, "  data/import/a-robot/customer.csv\n    robot1@shop.local line 2; no password in the repository\n") && substr_count($accOut, 'sonia@shop.local') + substr_count($accOut, 'SONIA@shop.local') === 1);
check('accounts: no password and no hash on standard output', !str_contains($accOut, $adminPassword) && !str_contains($accOut, $customerPassword) && !str_contains($accOut, $hash));
[, $limitOut] = runtime($project, ['accounts', '--role', 'customer', '--limit', '1']);
check('accounts --role --limit: the hint names --user and the cap', str_starts_with($limitOut, 'customer (4):') && str_contains($limitOut, '(3 more: filter with --user <text>, or --limit up to 200)') && !str_contains($limitOut, 'admin@'));
[$userCode, $userOut] = runtime($project, ['accounts', '--user', 'SONIA']);
check('accounts --user: a part of the user name, any case', $userCode === 0 && str_starts_with($userOut, "customer (1):\n  data/import/common/common/customer.csv\n    sonia@shop.local line 2") && !str_contains($userOut, 'karl@'));
check('accounts --user without a match -> exit 2', runtime($project, ['accounts', '--user', 'nobody-here'])[0] === 2);

$many = $tmp . '/project-many';
write_file($many . '/data/import/common/common/customer.csv', "customer_reference,email,password\n" . implode('', array_map(static fn (int $n): string => "C$n,customer$n@shop.local,\n", range(1, 250))));
[$manyCode, $manyOut] = runtime($many, ['accounts', '--limit', '0']);
check('accounts --limit 0: capped at 200 lines per role, the hint names --user', $manyCode === 0 && substr_count($manyOut, ' line ') === 200 && str_contains($manyOut, '(50 more: filter with --user <text>)') && substr_count($manyOut, 'data/import/common/common/customer.csv') === 1);
check('accounts: no padding', !str_contains($manyOut, '  ;') && preg_match('/\S {2,}\S/', $manyOut) === 0);

$users = $tmp . '/project-users';
write_file($users . '/src/Pyz/Zed/User/UserConfig.php', "<?php\nclass UserConfig\n{\n    public function getInstallerUsers(): array\n    {\n        return [\n            [\n                // the first admin\n                'username' => 'commented@shop.local', // inline\n                'password' => static::ADMIN_PASSWORD,\n                'roles' => ['root', 'acl'],\n            ],\n            [\n                'username' => getenv('ADMIN_USER'),\n                'password' => 'x',\n            ],\n        ];\n    }\n}\n");
[$usersCode, $usersOut] = runtime($users, ['accounts']);
check('accounts UserConfig: comments and nested arrays read, a password that is not a literal named', $usersCode === 0 && str_contains($usersOut, '    commented@shop.local line 9; password not a literal (line 10)'));
check('accounts UserConfig: an entry that cannot be read is named with file and line', str_contains($usersOut, 'Could not read the entry at src/Pyz/Zed/User/UserConfig.php:14'));
$vendorOnly = $tmp . '/project-vendor-user';
write_file($vendorOnly . '/' . SR_VENDOR_USER_CONFIG, "<?php\nnamespace Spryker\\Zed\\User;\nclass UserConfig\n{\n    public function getInstallerUsers(): array\n    {\n        return [\n            [\n                'firstName' => 'Admin',\n                'username' => 'admin@spryker.com',\n                'password' => 'change123',\n            ],\n        ];\n    }\n}\n");
[$vendorCode, $vendorOut] = runtime($vendorOnly, ['accounts']);
check('accounts: no project UserConfig.php -> the vendor UserConfig.php', $vendorCode === 0 && str_contains($vendorOut, "  vendor/spryker/user/src/Spryker/Zed/User/UserConfig.php\n    admin@spryker.com line 10; password line 11") && !str_contains($vendorOut, 'change123'));

write_file($tmp . '/multi-line.csv', "email,note,password\nfirst@shop.local,\"one\ntwo\nthree\",\nsecond@shop.local,x,\n");
$rows = iterator_to_array(sr_csv($tmp . '/multi-line.csv'), false);
check('csv: line numbers count physical lines (a quoted field with line breaks)', array_column($rows, 'line') === [2, 5]);

// --- deploy files outside the YAML subset ---
$anchors = "x-ssl: &ssl\n    enabled: true\nnamespace: anchor_dev\ngroups:\n    EU:\n        applications:\n            yves:\n                application: yves\n                endpoints:\n                    yves.anchor.local: {store: DE}\ndocker:\n    ssl: *ssl\n";
write_file($project . '/deploy.anchors.yml', $anchors);
[$anchorCode, $anchorOut] = runtime($project, ['env', '--deploy-file', 'deploy.anchors.yml']);
check('deploy file with anchors, aliases or flow mappings and no symfony/yaml -> exit 2, the line named', $anchorCode === 2 && str_contains($anchorOut, 'deploy.anchors.yml line 1 uses an anchor (&name)') && !str_contains($anchorOut, 'http://'));
check('deploy file: aliases, merge keys and flow collections are found', str_contains((string)df_unsupported_yaml("a:\n    b: *x\n"), 'an alias') && str_contains((string)df_unsupported_yaml("a:\n    <<: *x\n"), 'a merge key') && str_contains((string)df_unsupported_yaml("a:\n    b: [1, 2]\n"), 'a flow mapping') && df_unsupported_yaml("a: '*.x'\nb: \"{x}\"\nc: */5 * * * *\n") === null);
check('login with such a deploy file -> exit 2', runtime($project, ['login', 'yves', 'sonia@shop.local', '--deploy-file', 'deploy.anchors.yml'])[0] === 2);
$symfony = $tmp . '/project-symfony';
write_file($symfony . '/deploy.dev.yml', $anchors);
write_file($symfony . '/vendor/autoload.php', "<?php\nnamespace Symfony\\Component\\Yaml;\nclass Yaml\n{\n    public static function parse(string \$yaml): array\n    {\n        return ['namespace' => 'anchor_dev', 'docker' => ['ssl' => ['enabled' => true]], 'groups' => ['EU' => ['applications' => ['yves' => ['application' => 'yves', 'endpoints' => ['yves.anchor.local' => ['store' => 'DE']]]]]]];\n    }\n}\n");
[$symfonyCode, $symfonyOut] = runtime($symfony, ['env']);
check('deploy file: symfony/yaml from the project vendor parses the full syntax', $symfonyCode === 0 && str_contains($symfonyOut, 'namespace anchor_dev, https') && str_contains($symfonyOut, 'https://yves.anchor.local '));

// --- login against a local test server ---
$freePort = static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string)stream_socket_get_name($socket, false);
    fclose($socket);

    return (int)substr($name, strrpos($name, ':') + 1);
};
$port = $freePort();
$port2 = $freePort();
$cookieValue = 'cookie-value-0f9e8d';
$tokenValue = 'token-value-7a6b5c';
write_file($tmp . '/server/router.php', '<?php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$users = ["admin@shop.local" => ' . var_export($adminPassword, true) . ', "sonia@shop.local" => ' . var_export($customerPassword, true) . ', "karl@shop.local" => ' . var_export($fixturePassword, true) . '];
$form = static fn (string $prefix): string => "<html><body><form method=\"post\" action=\"/login_check\"><input type=\"hidden\" name=\"{$prefix}[_token]\" value=\"csrf-1\"><input type=\"email\" name=\"{$prefix}[username]\"><input type=\"password\" name=\"{$prefix}[password]\"><button type=\"submit\">Login</button></form></body></html>";
if ($path === "/security-gui/login" || $path === "/login") {
    echo $form($path === "/login" ? "loginForm" : "auth");
    return true;
}
if ($path === "/sso/login") {
    header("Location: http://localhost:" . $_SERVER["SERVER_PORT"] . "/security-gui/login", true, 302);
    return true;
}
if ($path === "/foreign-form/login") {
    echo str_replace("action=\"/login_check\"", "action=\"http://localhost:" . $_SERVER["SERVER_PORT"] . "/login_check\"", $form("auth"));
    return true;
}
if ($path === "/redirect/login" || $path === "/broken/login") {
    echo str_replace("action=\"/login_check\"", "action=\"" . ($path === "/redirect/login" ? "/redirect_check" : "/broken_check") . "\"", $form("auth"));
    return true;
}
if ($path === "/redirect_check") {
    header("Location: http://127.0.0.1:' . $port2 . '/steal", true, 307);
    return true;
}
if ($path === "/broken_check") {
    http_response_code(500);
    echo "Internal error";
    return true;
}
if ($path === "/login_check") {
    file_put_contents(__DIR__ . "/posts.log", "post\n", FILE_APPEND);
    $data = $_POST["auth"] ?? $_POST["loginForm"] ?? [];
    $ok = ($data["_token"] ?? "") === "csrf-1" && isset($users[$data["username"] ?? ""]) && $users[$data["username"]] === ($data["password"] ?? null);
    if ($ok) {
        setcookie("backoffice-shop", ' . var_export($cookieValue, true) . ', 0, "/");
        header("Location: /dashboard", true, 302);
    } else {
        header("Location: " . (isset($_POST["auth"]) ? "/security-gui/login" : "/login"), true, 302);
    }
    return true;
}
if ($path === "/dashboard") {
    echo "Dashboard";
    return true;
}
if ($path === "/access-tokens") {
    $body = json_decode((string)file_get_contents("php://input"), true);
    $attributes = $body["data"]["attributes"] ?? [];
    header("Content-Type: application/vnd.api+json");
    if (isset($users[$attributes["username"] ?? ""]) && $users[$attributes["username"]] === ($attributes["password"] ?? null)) {
        echo json_encode(["data" => ["type" => "access-tokens", "attributes" => ["accessToken" => ' . var_export($tokenValue, true) . ', "expiresIn" => 28800]]]);
    } else {
        http_response_code(401);
        echo json_encode(["errors" => [["status" => 401, "detail" => "Failed to authenticate user."]]]);
    }
    return true;
}
if ($path === "/token") {
    $ok = isset($users[$_POST["username"] ?? ""]) && $users[$_POST["username"]] === ($_POST["password"] ?? null) && ($_POST["grant_type"] ?? "") === "password";
    header("Content-Type: application/json");
    if (!$ok) {
        http_response_code(400);
    }
    echo json_encode($ok ? ["access_token" => ' . var_export($tokenValue, true) . ', "expires_in" => 3600] : ["error" => "invalid_grant"]);
    return true;
}
http_response_code(404);
return true;
');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/server/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $serverPipes);
write_file($tmp . '/server2/router.php', '<?php
file_put_contents(__DIR__ . "/requests.log", $_SERVER["REQUEST_METHOD"] . " " . $_SERVER["REQUEST_URI"] . " " . file_get_contents("php://input") . "\n", FILE_APPEND);
echo "Dashboard";
return true;
');
$server2 = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port2, $tmp . '/server2/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $server2Pipes);
for ($i = 0; $i < 50 && (@fsockopen('127.0.0.1', $port) === false || @fsockopen('127.0.0.1', $port2) === false); $i++) {
    usleep(100000);
}
$local = '127.0.0.1:' . $port;
write_file($project . '/deploy.local.yml', "namespace: local_dev\ngroups:\n    EU:\n        region: EU\n        applications:\n"
    . "            yves:\n                application: yves\n                endpoints:\n                    $local:\n                        store: DE\n"
    . "            boffice:\n                application: backoffice\n                endpoints:\n                    $local:\n                        region: EU\n"
    . "            glue:\n                application: glue\n                endpoints:\n                    $local:\n                        store: DE\n"
    . "            gluebackend:\n                application: glue-backend\n                endpoints:\n                    $local:\n                        region: EU\n"
    . "            gluestorefront:\n                application: glue-storefront\n                endpoints:\n                    $local:\n                        store: DE\n"
    . "docker:\n    ssl:\n        enabled: false\n");
$outputs = [];

[$boCode, $boOut] = runtime($project, ['login', 'backoffice', 'admin@shop.local', '--deploy-file', 'deploy.local.yml']);
$outputs[] = $boOut;
$jar = $project . '/.ai-dev/runtime/sessions/backoffice-admin-shop-local.cookies';
check('login backoffice: logged in, one line with the cookie jar path', $boCode === 0 && count(explode("\n", trim($boOut))) === 1 && str_contains($boOut, "backoffice admin@shop.local at http://$local: logged in (HTTP 200, landed on /dashboard), password from src/Pyz/Zed/User/UserConfig.php:10; cookie jar .ai-dev/runtime/sessions/backoffice-admin-shop-local.cookies"));
check('login backoffice: the cookie jar holds the session, readable by the owner only', is_file($jar) && str_contains((string)file_get_contents($jar), $cookieValue) && (fileperms($jar) & 0777) === 0600);
check('login: .ai-dev/runtime is ignored by git', trim((string)@file_get_contents($project . '/.ai-dev/runtime/.gitignore')) === '*');
[$yvesCode, $yvesOut] = runtime($project, ['login', 'yves', 'sonia@shop.local', '--deploy-file', 'deploy.local.yml']);
$outputs[] = $yvesOut;
check('login yves: the plain password of customer.csv', $yvesCode === 0 && str_contains($yvesOut, 'password from data/import/common/common/customer.csv:2'));
[$failCode, $failOut] = runtime($project, ['login', 'backoffice', 'admin@shop.local', '--deploy-file', 'deploy.local.yml'], [SR_PASSWORD_ENV => 'wrong-password-123']);
$outputs[] = $failOut;
check('login: a refused login -> exit 1, still on the login page, password source named', $failCode === 1 && str_contains($failOut, 'login failed (HTTP 200, landed on /security-gui/login) with the password from the environment variable SPRYKER_RUNTIME_PASSWORD'));
[$glueCode, $glueOut] = runtime($project, ['login', 'glue', 'karl@shop.local', '--deploy-file', 'deploy.local.yml']);
$outputs[] = $glueOut;
$tokenFile = $project . '/.ai-dev/runtime/sessions/glue-karl-shop-local.token';
check('login glue: a hashed password verified with the fixture password, token in a file', $glueCode === 0 && str_contains($glueOut, 'expires in 28800 s') && str_contains($glueOut, 'password from tests/cypress-tests/cypress/fixtures/b2c/customer.json:2 (verified against the hash in data/import/common/common/customer.csv:3)') && (string)@file_get_contents($tokenFile) === $tokenValue && (fileperms($tokenFile) & 0777) === 0600);
[$backendCode, $backendOut] = runtime($project, ['login', 'glue-backend', 'admin@shop.local', '--deploy-file', 'deploy.local.yml']);
$outputs[] = $backendOut;
check('login glue-backend: token from /token', $backendCode === 0 && str_contains($backendOut, 'token from /token (HTTP 200, expires in 3600 s)') && is_file($project . '/.ai-dev/runtime/sessions/glue-backend-admin-shop-local.token'));
[$storefrontCode, $storefrontOut] = runtime($project, ['login', 'glue-storefront', 'karl@shop.local', '--deploy-file', 'deploy.local.yml']);
$outputs[] = $storefrontOut;
check('login glue-storefront: token from /token (form grant), not /access-tokens', $storefrontCode === 0 && str_contains($storefrontOut, 'token from /token (HTTP 200, expires in 3600 s)') && (string)@file_get_contents($project . '/.ai-dev/runtime/sessions/glue-storefront-karl-shop-local.token') === $tokenValue);
[$missingCode, $missingOut] = runtime($project, ['login', 'glue-storefront', 'karl@shop.local', '--deploy-file', 'deploy.local.yml', '--path', '/no-such-token']);
$outputs[] = $missingOut;
check('login --path: another token path; HTTP 404 -> exit 3, not a credentials question', $missingCode === 3 && str_contains($missingOut, 'the login page answered HTTP 404 (/no-such-token)') && !str_contains($missingOut, 'credentials'));
[$brokenCode, $brokenOut] = runtime($project, ['login', 'backoffice', 'admin@shop.local', '--deploy-file', 'deploy.local.yml', '--path', '/broken/login']);
$outputs[] = $brokenOut;
check('login: HTTP 500 after the login form -> exit 3, the login page answered HTTP 500', $brokenCode === 3 && str_contains($brokenOut, 'the login page answered HTTP 500') && !str_contains($brokenOut, 'credentials'));
[$redirectCode, $redirectOut] = runtime($project, ['login', 'backoffice', 'admin@shop.local', '--deploy-file', 'deploy.local.yml', '--path', '/redirect/login']);
$outputs[] = $redirectOut;
$stolen = (string)@file_get_contents($tmp . '/server2/requests.log');
check('login: a 307 of the login POST to another host -> exit 2, not logged in, nothing sent there', $redirectCode === 2 && str_contains($redirectOut, "the login answer redirects to 127.0.0.1:$port2, a host that deploy.local.yml does not name") && !str_contains($redirectOut, 'logged in (') && $stolen === '' && !str_contains($stolen, $adminPassword));
[$insecureCode, $insecureOut] = runtime($project, ['login', 'backoffice', 'admin@shop.local', '--deploy-file', 'deploy.local.yml', '--insecure']);
$outputs[] = $insecureOut;
check('login --insecure: a flag without a value, the login runs', $insecureCode === 0 && str_contains($insecureOut, 'logged in (HTTP 200'));
[$glueFailCode, $glueFailOut] = runtime($project, ['login', 'glue', 'karl@shop.local', '--deploy-file', 'deploy.local.yml'], [SR_PASSWORD_ENV => 'wrong-password-123']);
$outputs[] = $glueFailOut;
check('login glue: a refused token -> exit 1', $glueFailCode === 1 && str_contains($glueFailOut, 'login failed (HTTP 401, no token in the response)'));
[$hostCode, $hostOut] = runtime($project, ['login', 'backoffice', 'admin@shop.local', '--deploy-file', 'deploy.local.yml', '--host', 'evil.example.com']);
$outputs[] = $hostOut;
check('login: a host outside the deploy file -> exit 2, nothing sent', $hostCode === 2 && str_contains($hostOut, 'evil.example.com is not a backoffice host of deploy.local.yml'));
$postsBefore = substr_count((string)@file_get_contents($tmp . '/server/posts.log'), 'post');
[$ssoCode, $ssoOut] = runtime($project, ['login', 'backoffice', 'admin@shop.local', '--deploy-file', 'deploy.local.yml', '--path', '/sso/login']);
$outputs[] = $ssoOut;
check('login: the login page redirects to a host outside the deploy file -> exit 2, the password is not sent', $ssoCode === 2 && str_contains($ssoOut, 'the login page is on localhost') && str_contains($ssoOut, 'The password was not sent') && substr_count((string)@file_get_contents($tmp . '/server/posts.log'), 'post') === $postsBefore);
[$formCode, $formOut] = runtime($project, ['login', 'backoffice', 'admin@shop.local', '--deploy-file', 'deploy.local.yml', '--path', '/foreign-form/login']);
$outputs[] = $formOut;
check('login: a form action on a host outside the deploy file -> exit 2, the password is not sent', $formCode === 2 && str_contains($formOut, 'the login form action is on localhost') && substr_count((string)@file_get_contents($tmp . '/server/posts.log'), 'post') === $postsBefore);
[$noPwCode, $noPwOut] = runtime($project, ['login', 'yves', 'nopw@shop.local', '--deploy-file', 'deploy.local.yml']);
$outputs[] = $noPwOut;
check('login: no password in the repository -> exit 2, names the variable', $noPwCode === 2 && str_contains($noPwOut, 'no password for nopw@shop.local in the repository; set SPRYKER_RUNTIME_PASSWORD'));
[$unknownCode, $unknownOut] = runtime($project, ['login', 'yves', 'stranger@shop.local', '--deploy-file', 'deploy.local.yml']);
check('login: an account that is not seeded -> exit 2', $unknownCode === 2 && str_contains($unknownOut, 'stranger@shop.local is not a seeded account'));
[$noAppLoginCode] = runtime($project, ['login', 'merchant-portal', 'harald@shop.local', '--deploy-file', 'deploy.local.yml']);
check('login: an application the deploy file does not have -> exit 2', $noAppLoginCode === 2);
proc_terminate($server);
proc_close($server);
proc_terminate($server2);
proc_close($server2);
[$downCode, $downOut] = runtime($project, ['login', 'backoffice', 'admin@shop.local', '--deploy-file', 'deploy.local.yml']);
$outputs[] = $downOut;
check('login: the host does not answer -> exit 3', $downCode === 3 && str_contains($downOut, 'the host did not answer'));

$all = implode("\n", array_merge($outputs, [$envOut, $accOut]));
check('no secret on standard output: passwords, hash, cookie value, token', !str_contains($all, $adminPassword) && !str_contains($all, $customerPassword) && !str_contains($all, $fixturePassword) && !str_contains($all, 'wrong-password-123') && !str_contains($all, $hash) && !str_contains($all, $cookieValue) && !str_contains($all, $tokenValue));

$cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($cleanup as $entry) {
    $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($tmp);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
