<?php

/**
 * Coverage gaps finder for the project code in src/ (every dir except Generated, Orm, SprykerConfig).
 * Static analysis, no test run needed. Gives per-method detail for a result of the Phase 0.5 gate check-test-coverage.php.
 * Running, flags, report and limits: coverage-gaps.md; the analysis rules: coverage-gaps-logic.md (both next to this file).
 * Requirements: PHP >= 8.1, Composer vendor/, nikic/php-parser ^5.0, a Spryker project (checked at startup).
 *
 * Finds:
 *   - project classes (no core parent) whose methods are not reached by any test;
 *   - classes extending a core class whose own/overridden methods are not reached by any test;
 *   - the entry points (Facade / Client / Service / Plugin / Controller) to test them through;
 *   - unused code: methods not reachable from any framework entry in production code (tests excluded);
 *   - classes and files that could not be analysed (load failure, parse error).
 *
 * "Reached by a test" = a method is called from a test method in tests/<Org>Test directly or transitively:
 * Facade -> Factory -> new Model(...) -> constructor dependencies -> plugin stacks from DependencyProviders
 * -> Zed stubs -> Gateway controllers. A path through a mocked factory, a mocked factory method or a mock of a
 * project class does not count; a skipped test method counts only up to the skip; a path from a test method with
 * a conditional skip or a replaced dependency is "reachable, not proven". HTTP-based tests are not followed.
 *
 * Project classes are never loaded into this process: each one is first loaded in a child PHP process. A class
 * that fails there (fatal error, missing parent) is reported under "Could not analyse".
 *
 * Usage (from the project root; $UP is the scripts directory of the spryker-upgrade skill):
 *   php $UP/coverage-gaps/coverage-gaps.php [--layer=Zed] [--module=Cart] [--only-untested] [--no-entries] [--no-unused]
 *       [--hide-unused] [--skip-ui-definitions] [--show-skipped] [--skip-layer=Yves,Glue] [--why=Pyz/Zed/Cart/Business/Foo]
 *       [--root=DIR] [--limit=N] [--json]
 *
 * Output: a verdict line with the counts, at most --limit findings per section (default 10), then the path of the
 * full JSON report (<state dir>/coverage-gaps-report.json). --json prints the full report to stdout instead.
 * Progress goes to stderr.
 *
 * Exit codes: 0 no core-extended class without a test and every class analysed; 1 core-extended classes without any
 * test; 2 usage or requirements error; 3 classes or files could not be analysed (the report is incomplete).
 *
 * Tests (maintainer tooling, not in the plugin): vendor/bin/phpunit --no-configuration tests/scripts/coverage-gaps/CoverageGapsTest.php
 */

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

const COVERAGE_GAPS_EXIT_OK = 0;
const COVERAGE_GAPS_EXIT_CORE_EXTENDED_UNTESTED = 1;
const COVERAGE_GAPS_EXIT_USAGE = 2;
const COVERAGE_GAPS_EXIT_NOT_ANALYSED = 3;
const COVERAGE_GAPS_LOAD_CHECK_ENV = 'COVERAGE_GAPS_LOAD_CHECK';
const COVERAGE_GAPS_PARSER_AUTOLOAD_ENV = 'COVERAGE_GAPS_PARSER_AUTOLOAD';
const COVERAGE_GAPS_MARK = '@@coverage-gaps ';

ini_set('memory_limit', '-1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/**
 * Autoloads the classes of the project namespaces from <root>/src/<Org>/, before Composer, so a stale or partial
 * autoload map does not hide project classes.
 *
 * @param list<string> $orgs
 */
function coverageGapsRegisterSrcAutoloader(string $root, array $orgs): void
{
    spl_autoload_register(static function (string $class) use ($root, $orgs): void {
        $org = strstr($class, '\\', true);
        $file = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
        if (in_array($org, $orgs, true) && is_file($file)) {
            require_once $file;
        }
    }, true, true);
}

/**
 * Child process mode: loads the classes named on stdin one by one and prints one line per class.
 * A fatal error stops the process after the BEGIN line of the class that caused it.
 */
function coverageGapsLoadCheckChild(): void
{
    $input = json_decode((string)stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    require_once $input['autoload'];
    coverageGapsRegisterSrcAutoloader($input['root'], $input['orgs']);
    foreach ($input['classes'] as $class) {
        echo COVERAGE_GAPS_MARK . 'BEGIN ' . $class . "\n";
        try {
            $loaded = class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
            echo COVERAGE_GAPS_MARK . ($loaded ? 'OK ' : 'ABSENT ') . $class . "\n";
        } catch (Throwable $exception) {
            echo COVERAGE_GAPS_MARK . 'ERROR ' . $class . "\t" . get_class($exception) . ': '
                . (string)preg_replace('/\s+/', ' ', $exception->getMessage()) . "\n";
        }
    }
    echo COVERAGE_GAPS_MARK . "DONE\n";
}

/**
 * Loads each class in a child PHP process, never in this one: a compile-time fatal error in a project class
 * (an incompatible signature, for example) would stop this process. After a fatal error the check goes on in a
 * new child process with the next class.
 *
 * @param list<string> $orgs
 * @param list<string> $classes
 *
 * @return array{failed: array<string, string>, absent: list<string>} failed: class => reason
 */
function coverageGapsLoadCheck(string $autoload, string $root, array $orgs, array $classes): array
{
    $failed = [];
    $absent = [];
    $pending = array_values($classes);
    while ($pending !== []) {
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stdout', '-d', 'log_errors=0', '-d', 'html_errors=0', '-d', 'memory_limit=-1', __FILE__],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [COVERAGE_GAPS_LOAD_CHECK_ENV => '1'] + getenv(),
        );
        if (!is_resource($process)) {
            foreach ($pending as $class) {
                $failed[$class] = 'could not start a PHP process to load the class';
            }

            break;
        }
        fwrite($pipes[0], json_encode(['autoload' => $autoload, 'root' => $root, 'orgs' => $orgs, 'classes' => $pending], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $current = null;
        $done = false;
        $output = [];
        $checked = [];
        foreach (explode("\n", $stdout) as $line) {
            if (!str_starts_with($line, COVERAGE_GAPS_MARK)) {
                if (trim($line) !== '') {
                    $output[] = trim($line);
                }

                continue;
            }
            [$status, $rest] = explode(' ', substr($line, strlen(COVERAGE_GAPS_MARK)), 2) + [1 => ''];
            if ($status === 'DONE') {
                $done = true;

                continue;
            }
            $class = strstr($rest, "\t", true) ?: $rest;
            if ($status === 'BEGIN') {
                $current = $class;
                $output = [];

                continue;
            }
            $checked[$class] = true;
            $current = null;
            if ($status === 'ERROR') {
                $failed[$class] = substr($rest, strlen($class) + 1);
            } elseif ($status === 'ABSENT') {
                $absent[] = $class;
            }
        }
        if ($done) {
            break;
        }
        if ($current === null) {
            foreach ($pending as $class) {
                if (!isset($checked[$class])) {
                    $failed[$class] = sprintf('the PHP process stopped before it loaded the class (exit %d): %s', $exitCode, trim($stderr . ' ' . implode(' ', $output)));
                }
            }

            break;
        }
        $errors = preg_grep('/(Fatal|Parse) error/', $output) ?: [];
        $failed[$current] = $errors !== []
            ? trim((string)preg_replace('/^.*?((Fatal|Parse) error)/', '$1', (string)end($errors)))
            : sprintf('the PHP process stopped while it loaded the class (exit %d)', $exitCode);
        $pending = array_slice($pending, (int)array_search($current, $pending, true) + 1);
    }
    ksort($failed);

    return ['failed' => $failed, 'absent' => $absent];
}

if (getenv(COVERAGE_GAPS_LOAD_CHECK_ENV) === '1') {
    coverageGapsLoadCheckChild();
    exit(0);
}

require __DIR__ . '/../bootstrap.php';

$projectRoot = spryker_upgrade_project_root();
$root = $projectRoot;
defined('APPLICATION_ROOT_DIR') || define('APPLICATION_ROOT_DIR', $root);

/**
 * Requirements (see coverage-gaps.md):
 *   - PHP >= 8.1
 *   - Composer dependencies installed (vendor/autoload.php in the project root)
 *   - nikic/php-parser ^5.0: the version of the project, or one installed outside the project and named
 *     by COVERAGE_GAPS_PARSER_AUTOLOAD (path of its vendor/autoload.php)
 *   - spryker/kernel             the analysed project must be a Spryker project
 * Optional: symfony/form and spryker/gui (only used by --skip-ui-definitions rules).
 *
 * @return list<string> problems, empty when everything is installed
 */
function coverageGapsRequirementProblems(string $root): array
{
    if (PHP_VERSION_ID < 80100) {
        return [sprintf('PHP >= 8.1 is required, running %s.', PHP_VERSION)];
    }
    if (!is_file($root . '/vendor/autoload.php')) {
        return [sprintf('Composer dependencies are not installed: %s/vendor/autoload.php not found. Run: composer install', $root)];
    }
    require_once $root . '/vendor/autoload.php';
    $parserAutoload = getenv(COVERAGE_GAPS_PARSER_AUTOLOAD_ENV);
    if (is_string($parserAutoload) && $parserAutoload !== '') {
        if (!is_file($parserAutoload)) {
            return [sprintf('%s does not point to a file: %s', COVERAGE_GAPS_PARSER_AUTOLOAD_ENV, $parserAutoload)];
        }
        // Composer prepends its autoloader: the parser installed outside the project wins over the project's copy.
        require_once $parserAutoload;
    }

    $problems = [];
    $installHint = sprintf(
        'Use the version the project has (composer show nikic/php-parser), or install it outside the project and set %s to its vendor/autoload.php (see coverage-gaps.md).',
        COVERAGE_GAPS_PARSER_AUTOLOAD_ENV,
    );
    $installed = static fn (string $package): ?string => class_exists(Composer\InstalledVersions::class) && Composer\InstalledVersions::isInstalled($package)
        ? Composer\InstalledVersions::getPrettyVersion($package)
        : null;
    if (!class_exists(PhpParser\ParserFactory::class)) {
        $problems[] = 'nikic/php-parser is not installed. ' . $installHint;
    } elseif (!method_exists(PhpParser\ParserFactory::class, 'createForNewestSupportedVersion')) {
        $problems[] = sprintf('nikic/php-parser ^5.0 is required, installed: %s. %s', $installed('nikic/php-parser') ?? 'an older version', $installHint);
    }
    if (!class_exists('Spryker\\Zed\\Kernel\\Business\\AbstractFacade')) {
        $problems[] = 'spryker/kernel is not installed: this tool analyses Spryker projects (Spryker\\Zed\\Kernel\\Business\\AbstractFacade not found).';
    }

    return $problems;
}

$requirementProblems = coverageGapsRequirementProblems($root);
if ($requirementProblems !== []) {
    fwrite(STDERR, "coverage-gaps.php: requirements not met\n  - " . implode("\n  - ", $requirementProblems) . "\n");
    exit(COVERAGE_GAPS_EXIT_USAGE);
}

final class Ast
{
    private Parser $parser;

    private NodeFinder $finder;

    /** @var array<string, array{stmts: array, methods: array<string, list<Stmt\ClassMethod>>}> */
    private array $cache = [];

    /** @var array<string, string> path => parse error of every file that could not be parsed */
    public array $errors = [];

    public function __construct(private int $limit = 600)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    public function file(string $path): array
    {
        return $this->load($path)['stmts'];
    }

    /**
     * Parses a file without keeping its tree; records a parse error in $errors.
     */
    public function check(string $path): void
    {
        if (isset($this->cache[$path]) || isset($this->errors[$path])) {
            return;
        }
        try {
            $this->parser->parse((string)file_get_contents($path));
        } catch (Throwable $exception) {
            $this->errors[$path] = $exception->getMessage();
        }
    }

    public function method(string $path, string $name, int $line): ?Stmt\ClassMethod
    {
        $best = null;
        foreach ($this->load($path)['methods'][strtolower($name)] ?? [] as $method) {
            if ($best === null || abs($method->getStartLine() - $line) < abs($best->getStartLine() - $line)) {
                $best = $method;
            }
        }

        return $best;
    }

    private function load(string $path): array
    {
        if (isset($this->cache[$path])) {
            $entry = $this->cache[$path];
            unset($this->cache[$path]);

            return $this->cache[$path] = $entry;
        }
        if (count($this->cache) >= $this->limit) {
            unset($this->cache[array_key_first($this->cache)]);
        }

        $stmts = [];
        try {
            $stmts = $this->parser->parse((string)file_get_contents($path)) ?? [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $stmts = $traverser->traverse($stmts);
        } catch (Throwable $exception) {
            $this->errors[$path] = $exception->getMessage();
            $stmts = [];
        }

        $methods = [];
        foreach ($this->finder->findInstanceOf($stmts, Stmt\ClassMethod::class) as $method) {
            $methods[$method->name->toLowerString()][] = $method;
        }

        return $this->cache[$path] = ['stmts' => $stmts, 'methods' => $methods];
    }
}

final class Kernel
{
    /** Same order as KernelConstants::CORE_NAMESPACES in config_default.php. */
    public const CORE_ORGS = ['SprykerShop', 'SprykerEco', 'Spryker', 'SprykerSdk', 'SprykerFeature'];

    /** src/ dirs that are generated or configuration, not project code. */
    public const NON_PROJECT_DIRS = ['Generated', 'Orm', 'SprykerConfig'];

    /** @var list<string> project namespaces first (they override core), then core */
    public array $orgs;

    private string $modulePattern;

    private const KERNEL_PATTERN = '/^Spryker\\\\\w+\\\\Kernel\\\\/';

    public const KERNEL_GETTERS = [
        'getFacade', 'getBusinessFactory', 'getFactory', 'getClient', 'getService', 'getRepository',
        'getEntityManager', 'getQueryContainer', 'getConfig', 'getProvidedDependency', 'getLocator',
    ];

    private array $exists = [];

    /**
     * @param list<string> $projectOrgs
     */
    public function __construct(public array $projectOrgs)
    {
        $this->orgs = array_merge($projectOrgs, self::CORE_ORGS);
        $this->modulePattern = '/^(' . implode('|', array_map('preg_quote', $this->orgs)) . ')\\\\(Zed|Client|Yves|Glue|Service|Shared)\\\\(\w+)\\\\(.+)$/';
    }

    /**
     * Project namespaces = directories in src/ except generated/config ones.
     *
     * @return list<string>
     */
    public static function detectProjectOrgs(string $root): array
    {
        $orgs = [];
        foreach (glob($root . '/src/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!in_array(basename($dir), self::NON_PROJECT_DIRS, true)) {
                $orgs[] = basename($dir);
            }
        }

        return $orgs;
    }

    public function isProject(string $class): bool
    {
        return in_array(strstr(ltrim(self::base($class), '\\') . '\\', '\\', true), $this->projectOrgs, true);
    }

    private array $refl = [];

    private array $resolved = [];

    /**
     * Strips the call-site suffix of an object token ("Class#Factory::method" -> "Class").
     */
    public static function base(string $type): string
    {
        $position = strpos($type, '#');

        return $position === false ? $type : substr($type, 0, $position);
    }

    /**
     * Project classes that failed to load in the child process: class => reason. They are never loaded here.
     *
     * @var array<string, string>
     */
    public array $unloadable = [];

    public function exists(string $class): bool
    {
        $class = ltrim(self::base($class), '\\');
        if (!isset($this->exists[$class])) {
            if (isset($this->unloadable[$class]) || ($this->isProject($class) && !isset($this->loadChecked[$class]))) {
                return $this->exists[$class] = false;
            }
            try {
                $this->exists[$class] = class_exists($class) || interface_exists($class) || trait_exists($class);
            } catch (Throwable $exception) {
                $this->exists[$class] = false;
                if ($this->isProject($class)) {
                    $this->unloadable[$class] = get_class($exception) . ': ' . $exception->getMessage();
                }
            }
        }

        return $this->exists[$class];
    }

    /** @var array<string, true> project classes that loaded without error in the child process */
    private array $loadChecked = [];

    /**
     * @param list<string> $loaded project classes that loaded without error in the child process
     * @param array<string, string> $failed class => reason
     */
    public function setLoadCheck(array $loaded, array $failed): void
    {
        $this->loadChecked = array_fill_keys($loaded, true);
        $this->unloadable = $failed;
        $this->exists = [];
        $this->refl = [];
        $this->resolved = [];
    }

    public function refl(string $class): ?ReflectionClass
    {
        $class = self::base($class);
        if (!array_key_exists($class, $this->refl)) {
            $this->refl[$class] = $this->exists($class) ? new ReflectionClass($class) : null;
        }

        return $this->refl[$class];
    }

    /**
     * @return array{org: string, layer: string, module: string, part: string}|null
     */
    public function moduleOf(string $class): ?array
    {
        if (!preg_match($this->modulePattern, ltrim(self::base($class), '\\'), $m)) {
            return null;
        }
        $rest = explode('\\', $m[4]);

        return ['org' => $m[1], 'layer' => $m[2], 'module' => $m[3], 'part' => count($rest) > 1 ? $rest[0] : ''];
    }

    public function isKernel(string $class): bool
    {
        return (bool)preg_match(self::KERNEL_PATTERN, $class);
    }

    public function isTracked(string $class): bool
    {
        return $this->moduleOf($class) !== null && !$this->isKernel($class);
    }

    public function isConcrete(string $class): bool
    {
        $rc = $this->refl($class);

        return $rc !== null && !$rc->isInterface() && !$rc->isAbstract() && !$rc->isTrait();
    }

    public function resolve(string $layer, string $module, string $relative): ?string
    {
        $key = "$layer|$module|$relative";
        if (!array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = null;
            foreach ($this->orgs as $org) {
                $class = "$org\\$layer\\$module\\$relative";
                if ($this->isConcrete($class)) {
                    $this->resolved[$key] = $class;

                    break;
                }
            }
        }

        return $this->resolved[$key];
    }

    /**
     * Concrete class for a type: itself (Pyz override first) or, for `XInterface`, the class `X` of the same module.
     */
    public function implementationOf(string $type): ?string
    {
        $type = ltrim($type, '\\');
        if (!$this->isConcrete($type) && str_ends_with($type, 'Interface')) {
            $type = substr($type, 0, -strlen('Interface'));
        }
        $info = $this->moduleOf($type);
        if ($info === null || $this->isKernel($type)) {
            return null;
        }
        $relative = substr($type, strlen("{$info['org']}\\{$info['layer']}\\{$info['module']}\\"));

        return $this->resolve($info['layer'], $info['module'], $relative);
    }

    /**
     * Controller classes of a module in all organizations (Pyz and core).
     *
     * @return list<string>
     */
    public function controllersOf(string $layer, string $module): array
    {
        $controllers = [];
        foreach ($this->orgs as $org) {
            $file = null;
            foreach (["{$module}DependencyProvider", "{$module}Config", "{$module}Factory", "{$module}BusinessFactory"] as $anchor) {
                $rc = $this->refl("$org\\$layer\\$module\\$anchor");
                if ($rc !== null && $rc->getFileName()) {
                    $file = $rc->getFileName();

                    break;
                }
            }
            if ($file === null) {
                continue;
            }
            $controllerDir = dirname($file) . ($layer === 'Zed' ? '/Communication/Controller' : '/Controller');
            $namespace = "$org\\$layer\\$module\\" . ($layer === 'Zed' ? 'Communication\\Controller\\' : 'Controller\\');
            foreach (glob($controllerDir . '/*Controller.php') ?: [] as $controllerFile) {
                $class = $namespace . basename($controllerFile, '.php');
                if ($this->isConcrete($class)) {
                    $controllers[] = $class;
                }
            }
        }

        return $controllers;
    }

    public function isKernelCall(string $class, string $method): bool
    {
        if (!in_array($method, self::KERNEL_GETTERS, true)) {
            return false;
        }
        $rc = $this->refl($class);
        if ($rc === null || !$rc->hasMethod($method)) {
            return true;
        }

        return $this->isKernel($rc->getMethod($method)->getDeclaringClass()->getName());
    }

    /**
     * @return list<string>
     */
    public function kernelTypes(string $class, string $getter): array
    {
        $m = $this->moduleOf($class);
        if ($m === null) {
            return [];
        }

        $resolved = $this->getterTarget($m['layer'], $m['module'], $m['part'], $getter);

        return $resolved === null ? [] : [$resolved];
    }

    public function getterTarget(string $layer, string $module, string $part, string $getter): ?string
    {
        return match ($getter) {
            'getFacade' => $this->resolve('Zed', $module, "Business\\{$module}Facade"),
            'getBusinessFactory' => $this->resolve('Zed', $module, "Business\\{$module}BusinessFactory"),
            'getFactory' => $this->factoryOf($layer, $module, $part),
            'getClient' => $this->resolve('Client', $module, "{$module}Client"),
            'getService' => $this->resolve('Service', $module, "{$module}Service"),
            'getRepository' => $this->resolve('Zed', $module, "Persistence\\{$module}Repository"),
            'getEntityManager' => $this->resolve('Zed', $module, "Persistence\\{$module}EntityManager"),
            'getQueryContainer' => $this->resolve('Zed', $module, "Persistence\\{$module}QueryContainer"),
            'getConfig' => $this->resolve($layer, $module, "{$module}Config"),
            default => null,
        };
    }

    public function locatorTarget(string $module, string $kind): ?string
    {
        return match ($kind) {
            'facade' => $this->resolve('Zed', $module, "Business\\{$module}Facade"),
            'client' => $this->resolve('Client', $module, "{$module}Client"),
            'service' => $this->resolve('Service', $module, "{$module}Service"),
            'queryContainer' => $this->resolve('Zed', $module, "Persistence\\{$module}QueryContainer"),
            default => null,
        };
    }

    private function factoryOf(string $layer, string $module, string $part): ?string
    {
        return match ($layer) {
            'Zed' => match ($part) {
                'Business' => $this->resolve('Zed', $module, "Business\\{$module}BusinessFactory"),
                'Persistence' => $this->resolve('Zed', $module, "Persistence\\{$module}PersistenceFactory"),
                default => $this->resolve('Zed', $module, "Communication\\{$module}CommunicationFactory"),
            },
            'Service' => $this->resolve('Service', $module, "{$module}ServiceFactory"),
            'Client', 'Yves', 'Glue' => $this->resolve($layer, $module, "{$module}Factory"),
            default => null,
        };
    }
}

final class Scope
{
    /**
     * @param array<string, list<string>> $vars
     * @param array{0: string, 1: string}|null $testModule [layer, module] of a test class
     */
    public function __construct(
        public string $runtime,
        public string $declaring,
        public array $vars = [],
        public ?array $testModule = null,
        public string $site = '',
        public ?string $node = null,
    ) {
    }
}

/**
 * Builds a call graph between methods (nodes "Runtime::method@Declaring") with a light type inference.
 */
final class Analyzer
{
    private const TEST_PREFIX = '@test:';

    private const SKIP_METHODS = ['markTestSkipped', 'markTestIncomplete'];

    private const SET_UP_METHODS = ['setUp', '_before', '_setUp'];

    private const MOCK_METHODS = [
        'createMock', 'createStub', 'createPartialMock', 'createConfiguredMock', 'createTestProxy', 'getMockBuilder',
        'getMockForAbstractClass', 'make', 'makeEmpty', 'makeEmptyExcept', 'construct', 'constructEmpty',
    ];

    private const TEST_GETTERS = ['getFacade', 'getClient', 'getService', 'getFactory', 'getBusinessFactory', 'getRepository', 'getEntityManager'];

    private const FORM_FACTORY_METHODS = ['create', 'createNamed', 'createBuilder', 'createNamedBuilder', 'createForm'];

    private const FORM_METHODS = ['buildForm', 'configureOptions', 'buildView', 'finishView', 'getBlockPrefix', 'getName'];

    private const ARRAY_FUNCTIONS = ['array_merge', 'array_values', 'array_replace', 'array_filter', 'iterator_to_array', 'array_reverse'];

    /** @var array<string, array<string, true>> */
    public array $edges = [];

    /** @var array<string, array{0: string, 1: string, 2: string}> */
    public array $nodes = [];

    /** @var array<string, true> */
    public array $processed = [];

    /** @var array<string, true> method names called on a receiver whose type could not be inferred */
    public array $unresolvedNames = [];

    private array $queue = [];

    private array $byRuntime = [];

    private array $propTypes = [];

    private array $paramTypes = [];

    private array $ctorMaps = [];

    private array $declaredProps = [];

    private array $returnMemo = [];

    private array $inProgress = [];

    private array $dpMemo = [];

    private array $testClasses = [];

    private NodeFinder $finder;

    /** @var (Closure(string): bool)|null */
    private ?Closure $restrict = null;

    public function __construct(private Kernel $kernel, private Ast $ast)
    {
        $this->finder = new NodeFinder();
    }

    /**
     * Clears the graph but keeps learned type information.
     */
    public function resetGraph(?Closure $restrict): void
    {
        $this->edges = [];
        $this->processed = [];
        $this->queue = [];
        $this->byRuntime = [];
        $this->unresolvedNames = [];
        $this->restrict = $restrict;
    }

    /**
     * @param list<string> $dirs test directories, one per test namespace (tests/PyzTest, tests/<Org>Test, ...)
     *
     * @return list<string> seed node keys (the public methods of test classes; helpers are reached through them)
     */
    public function registerTests(array $dirs): array
    {
        $iterator = new AppendIterator();
        foreach ($dirs as $dir) {
            $iterator->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)));
        }
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || preg_match('#/_(support|generated|data|output)/#', $path)) {
                continue;
            }
            foreach ($this->finder->findInstanceOf($this->ast->file($path), Stmt\Class_::class) as $class) {
                if ($class->namespacedName === null) {
                    continue;
                }
                $fqcn = $class->namespacedName->toString();
                $parts = explode('\\', $fqcn);
                $methods = [];
                foreach ($class->getMethods() as $method) {
                    $methods[$method->name->toString()] = $this->withoutCodeAfterSkip($method);
                }
                $this->testClasses[$fqcn] = [
                    'module' => count($parts) > 3 ? [$parts[1], $parts[2]] : null,
                    'parent' => $class->extends?->toString(),
                    'methods' => $methods,
                    'props' => [],
                ];
            }
        }

        foreach (array_keys($this->testClasses) as $fqcn) {
            $this->collectTestProps($fqcn);
        }

        $seeds = [];
        foreach ($this->testClasses as $fqcn => $test) {
            foreach ($test['methods'] as $name => $method) {
                $key = $method->isPublic() ? $this->node(self::TEST_PREFIX . $fqcn, $name) : null;
                if ($key !== null) {
                    $seeds[] = $key;
                }
            }
        }

        return $seeds;
    }

    /**
     * A test method that calls markTestSkipped() or markTestIncomplete() as a top-level statement runs only up to that
     * call: the statements after it are removed.
     */
    private function withoutCodeAfterSkip(Stmt\ClassMethod $method): Stmt\ClassMethod
    {
        foreach ($method->stmts ?? [] as $index => $stmt) {
            if ($stmt instanceof Stmt\Expression && $this->isSkipCall($stmt->expr)) {
                $method = clone $method;
                $method->stmts = array_slice($method->stmts, 0, $index);

                return $method;
            }
        }

        return $method;
    }

    private function isSkipCall(Node $node): bool
    {
        return ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)
            && $node->name instanceof Identifier
            && in_array($node->name->toString(), self::SKIP_METHODS, true);
    }

    /**
     * What a test seed replaces or skips, read from its own body, from the test helpers it calls and from the
     * set-up methods of its class:
     *   - blockFactories: setFactory() was called; no factory method counts as tested;
     *   - blockFactoryMethods: factory methods replaced by mockFactoryMethod('name', ...);
     *   - blockClasses: project classes replaced by createMock(X::class) and similar calls;
     *   - notProven: reasons why the seed proves nothing (a conditional skip, setDependency() with a value that is
     *     not a `new` expression, mockFactoryMethod() with a name that is not a string literal).
     *
     * @return array{blockFactories: bool, blockFactoryMethods: list<string>, blockClasses: list<string>, notProven: list<string>}
     */
    public function testSeedMarkers(string $seed): array
    {
        $markers = ['blockFactories' => false, 'blockFactoryMethods' => [], 'blockClasses' => [], 'notProven' => []];
        [$runtime, , $name] = $this->nodes[$seed];
        $fqcn = substr($runtime, strlen(self::TEST_PREFIX));
        $methods = [];
        $queue = [$seed];
        foreach (self::SET_UP_METHODS as $setUp) {
            $key = $name === $setUp ? null : $this->node($runtime, $setUp);
            if ($key !== null) {
                $queue[] = $key;
            }
        }
        $visited = [];
        while ($queue) {
            $key = array_pop($queue);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            $method = $this->testMethod(substr($this->nodes[$key][0], strlen(self::TEST_PREFIX)), $this->nodes[$key][2]);
            if ($method !== null) {
                $methods[] = $method;
            }
            foreach (array_keys($this->edges[$key] ?? []) as $to) {
                if (str_starts_with($this->nodes[$to][0], self::TEST_PREFIX)) {
                    $queue[] = $to;
                }
            }
        }

        foreach ($methods as $method) {
            $calls = $this->finder->find($method->stmts ?? [], static fn (Node $n): bool => ($n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall)
                && $n->name instanceof Identifier);
            foreach ($calls as $call) {
                $callName = $call->name->toString();
                $args = array_values(array_filter($call->args, static fn ($arg): bool => $arg instanceof Node\Arg));
                if (in_array($callName, self::SKIP_METHODS, true)) {
                    $markers['notProven'][] = 'conditional ' . $callName . '()';
                } elseif ($callName === 'setFactory') {
                    $markers['blockFactories'] = true;
                } elseif ($callName === 'mockFactoryMethod') {
                    if (isset($args[0]) && $args[0]->value instanceof String_) {
                        $markers['blockFactoryMethods'][] = $args[0]->value->value;
                    } else {
                        $markers['notProven'][] = 'mockFactoryMethod() with a computed name';
                    }
                } elseif ($callName === 'setDependency') {
                    if (!isset($args[1]) || !$this->isRealObject($args[1]->value)) {
                        $markers['notProven'][] = 'setDependency()';
                    }
                } elseif (in_array($callName, self::MOCK_METHODS, true) && isset($args[0])) {
                    $mocked = $this->classConstant($args[0]->value);
                    if ($mocked !== null && $this->kernel->isProject($mocked)) {
                        $markers['blockClasses'][] = $mocked;
                        $implementation = $this->kernel->implementationOf($mocked);
                        if ($implementation !== null) {
                            $markers['blockClasses'][] = $implementation;
                        }
                    }
                }
            }
        }
        $markers['blockFactoryMethods'] = array_values(array_unique($markers['blockFactoryMethods']));
        if ($markers['blockFactories'] || $markers['blockFactoryMethods'] !== []) {
            $testModule = $this->testClasses[$fqcn]['module'] ?? null;
            array_push($markers['blockClasses'], ...$this->factoryProducts($markers['blockFactories'] ? null : $markers['blockFactoryMethods'], $testModule[1] ?? null));
        }
        $markers['blockClasses'] = array_values(array_unique($markers['blockClasses']));
        $markers['notProven'] = array_values(array_unique($markers['notProven']));

        return $markers;
    }

    /**
     * Classes that factory methods of the graph return: the objects a replaced factory or factory method no longer
     * creates. $methods null = every method of the factories of $module (every factory when the module is unknown).
     *
     * @param list<string>|null $methods
     *
     * @return list<string>
     */
    private function factoryProducts(?array $methods, ?string $module): array
    {
        $products = [];
        foreach ($this->nodes as [$runtime, , $method]) {
            $factory = Kernel::base($runtime);
            if (str_starts_with($runtime, self::TEST_PREFIX) || !str_ends_with($factory, 'Factory')) {
                continue;
            }
            $matches = $methods === null
                ? $module === null || ($this->kernel->moduleOf($factory)['module'] ?? null) === $module
                : in_array($method, $methods, true);
            if (!$matches) {
                continue;
            }
            foreach ($this->returnTypes($runtime, $method) as $type) {
                $products[Kernel::base($type)] = true;
            }
        }

        return array_keys($products);
    }

    /**
     * Node keys reachable from the seeds; a blocked node is neither reached nor followed.
     *
     * @param list<string> $seeds
     * @param (Closure(array{0: string, 1: string, 2: string}): bool)|null $isBlocked
     *
     * @return array<string, true>
     */
    public function reachable(array $seeds, ?Closure $isBlocked = null): array
    {
        $reached = [];
        $queue = $seeds;
        while ($queue) {
            $key = array_pop($queue);
            if (isset($reached[$key]) || ($isBlocked !== null && $isBlocked($this->nodes[$key]))) {
                continue;
            }
            $reached[$key] = true;
            foreach (array_keys($this->edges[$key] ?? []) as $to) {
                if (!isset($reached[$to])) {
                    $queue[] = $to;
                }
            }
        }

        return $reached;
    }

    /**
     * `new X(...)` or an array of those: a real object, not a mock.
     */
    private function isRealObject(Node $expr): bool
    {
        if ($expr instanceof Expr\New_) {
            return true;
        }
        if (!$expr instanceof Expr\Array_ || $expr->items === []) {
            return false;
        }
        foreach ($expr->items as $item) {
            if ($item === null || !$item->value instanceof Expr\New_) {
                return false;
            }
        }

        return true;
    }

    private function classConstant(Node $expr): ?string
    {
        return $expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && !$expr->class->isSpecialClassName()
            && $expr->name instanceof Identifier && $expr->name->toLowerString() === 'class'
            ? $expr->class->toString()
            : null;
    }

    public function testFileCount(): int
    {
        return count($this->testClasses);
    }

    /**
     * For a thin delegating method (no own logic) returns the [declaring class, method] pairs it calls; null otherwise.
     * Uses a throw-away graph: call it before building the next phase.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    public function delegateCallees(string $class, string $method): ?array
    {
        $key = $this->node($class, $method);
        $fn = $key === null ? null : $this->methodAst($class, $this->nodes[$key][1], $method);
        if ($fn === null || $fn->stmts === null || count($fn->stmts) > 3) {
            return null;
        }
        $logic = $this->finder->findFirst($fn->stmts, static fn (Node $n): bool => $n instanceof Stmt\If_
            || $n instanceof Stmt\Foreach_ || $n instanceof Stmt\For_ || $n instanceof Stmt\While_ || $n instanceof Stmt\Do_
            || $n instanceof Stmt\Switch_ || $n instanceof Stmt\TryCatch || $n instanceof Expr\Match_ || $n instanceof Expr\Ternary);
        if ($logic !== null) {
            return null;
        }
        $this->resetGraph(static fn (string $runtime): bool => $runtime === $class);
        $this->seed($key);
        $this->run();

        return array_values(array_map(fn (string $to): array => [$this->nodes[$to][1], $this->nodes[$to][2]], array_keys($this->edges[$key] ?? [])));
    }

    public function seed(string $key): void
    {
        $this->enqueue($key);
    }

    public function run(): void
    {
        while ($this->queue) {
            $this->processNode(array_pop($this->queue));
        }
    }

    /**
     * Returns a node key for calling $method on an object of class $runtime, or null when not analysable.
     */
    public function node(string $runtime, string $method, ?string $lookupClass = null): ?string
    {
        if (str_starts_with($runtime, self::TEST_PREFIX)) {
            if ($this->testMethod(substr($runtime, strlen(self::TEST_PREFIX)), $method) === null) {
                return null;
            }
            $key = $runtime . '::' . $method;
            $this->nodes[$key] ??= [$runtime, $runtime, $method];

            return $key;
        }
        if (!$this->kernel->isTracked($runtime)) {
            return null;
        }
        $rc = $this->kernel->refl($lookupClass ?? $runtime);
        if ($rc === null || !$rc->hasMethod($method)) {
            return null;
        }
        $rm = $rc->getMethod($method);
        $declaring = $rm->getDeclaringClass()->getName();
        if ($rm->isAbstract() || $this->kernel->isKernel($declaring)) {
            return null;
        }
        $key = $runtime . '::' . $rm->getName() . '@' . $declaring;
        $this->nodes[$key] ??= [$runtime, $declaring, $rm->getName()];

        return $key;
    }

    private function enqueue(string $key): void
    {
        if (isset($this->processed[$key])) {
            return;
        }
        $runtime = $this->nodes[$key][0];
        if ($this->restrict !== null && !str_starts_with($runtime, self::TEST_PREFIX) && !($this->restrict)($runtime)) {
            return;
        }
        $this->processed[$key] = true;
        $this->byRuntime[$runtime][$key] = true;
        $this->queue[] = $key;
    }

    private function addEdge(string $from, ?string $to): void
    {
        if ($to === null) {
            return;
        }
        $this->edges[$from][$to] = true;
        $this->enqueue($to);
    }

    private function processNode(string $key): void
    {
        [$runtime, $declaring, $method] = $this->nodes[$key];
        $fn = $this->methodAst($runtime, $declaring, $method);
        if ($fn === null || $fn->stmts === null) {
            return;
        }
        $scope = $this->scopeFor($runtime, $declaring, $declaring . '::' . $method);
        $scope->node = $key;
        $scope->vars = $this->computeVars($fn, $scope);

        $calls = $this->finder->find($fn->stmts, static fn (Node $n): bool => $n instanceof Expr\MethodCall
            || $n instanceof Expr\NullsafeMethodCall
            || $n instanceof Expr\StaticCall
            || $n instanceof Expr\New_
            || $n instanceof Expr\Assign);

        foreach ($calls as $call) {
            if ($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) {
                $this->processMethodCall($key, $call, $scope);

                continue;
            }
            if ($call instanceof Expr\StaticCall) {
                $this->processStaticCall($key, $call, $scope);

                continue;
            }
            if ($call instanceof Expr\New_) {
                $this->typeOf($call, $scope);
                if ($call->class instanceof Name && !$call->class->isSpecialClassName()) {
                    $this->addEdge($key, $this->node($call->class->toString(), '__construct'));
                }

                continue;
            }
            if ($this->isThisProperty($call->var) && !str_starts_with($runtime, self::TEST_PREFIX)) {
                $this->addPropTypes($runtime, $call->var->name->toString(), $this->typeOf($call->expr, $scope));
            }
        }
    }

    private function processMethodCall(string $from, Expr\MethodCall|Expr\NullsafeMethodCall $call, Scope $scope): void
    {
        if (!$call->name instanceof Identifier) {
            return;
        }
        $name = $call->name->toString();

        if ($name === 'call' && isset($call->args[0]) && $call->args[0] instanceof Node\Arg && $call->args[0]->value instanceof String_) {
            $this->addEdge($from, $this->gatewayNode($call->args[0]->value->value));
        }

        foreach ($this->formTypesIn($name, $call->args) as $formType) {
            foreach (self::FORM_METHODS as $formMethod) {
                $this->addEdge($from, $this->node($formType, $formMethod));
            }
        }

        $receivers = $this->typeOf($call->var, $scope);
        if ($receivers === []) {
            $this->unresolvedNames[$name] = true;
        }
        foreach ($receivers as $receiver) {
            if (str_starts_with($receiver, self::TEST_PREFIX)) {
                $this->addEdge($from, $this->node($receiver, $name));

                continue;
            }
            if ($this->kernel->isKernelCall($receiver, $name)) {
                continue;
            }
            if ($call->args !== [] && str_contains($receiver, '#')) {
                $this->recordArgs($receiver, $name, $call->args, $scope);
            }
            $to = $this->node($receiver, $name);
            $this->recordParamTypes($to, $call->args, $scope);
            $this->addEdge($from, $to);
        }
    }

    /**
     * Symfony form types passed as `X::class` to a form factory call are executed by Symfony.
     *
     * @return list<string>
     */
    private function formTypesIn(string $method, array $args): array
    {
        if (!in_array($method, self::FORM_FACTORY_METHODS, true)) {
            return [];
        }
        $types = [];
        foreach (array_slice($args, 0, 2) as $arg) {
            if ($arg instanceof Node\Arg && $arg->value instanceof Expr\ClassConstFetch && $arg->value->class instanceof Name
                && $arg->value->name instanceof Identifier && $arg->value->name->toLowerString() === 'class') {
                $class = $arg->value->class->toString();
                if ($this->kernel->isTracked($class) && interface_exists('Symfony\\Component\\Form\\FormTypeInterface') && $this->kernel->refl($class)?->implementsInterface('Symfony\\Component\\Form\\FormTypeInterface') === true) {
                    $types[] = $class;
                }
            }
        }

        return $types;
    }

    private function processStaticCall(string $from, Expr\StaticCall $call, Scope $scope): void
    {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
            return;
        }
        $name = $call->name->toString();
        $class = $call->class->toLowerString();

        if ($class === 'parent') {
            $parent = $this->kernel->refl($scope->declaring)?->getParentClass();
            if ($parent) {
                $this->addEdge($from, $this->node($scope->runtime, $name, $parent->getName()));
            }

            return;
        }
        if ($class === 'self' || $class === 'static') {
            $this->addEdge($from, $this->node($scope->runtime, $name));

            return;
        }
        $this->addEdge($from, $this->node($call->class->toString(), $name));
    }

    private function gatewayNode(string $url): ?string
    {
        if (!preg_match('#^/([a-z0-9-]+)/gateway/([a-z0-9-]+)$#', $url, $m)) {
            return null;
        }
        $module = str_replace(' ', '', ucwords(str_replace('-', ' ', $m[1])));
        $action = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $m[2])))) . 'Action';
        $controller = $this->kernel->resolve('Zed', $module, 'Communication\\Controller\\GatewayController');

        return $controller === null ? null : $this->node($controller, $action);
    }

    /**
     * @return list<string>
     */
    private function typeOf(?Node $expr, Scope $scope, int $depth = 0): array
    {
        if ($expr === null || $depth > 30) {
            return [];
        }

        return match (true) {
            $expr instanceof Expr\Variable => is_string($expr->name)
                ? ($expr->name === 'this' ? [$scope->runtime] : ($scope->vars[$expr->name] ?? []))
                : [],
            $expr instanceof Expr\PropertyFetch, $expr instanceof Expr\NullsafePropertyFetch => $this->isThisProperty($expr)
                ? $this->propTypesFor($scope, $expr->name->toString())
                : [],
            $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall => $this->methodCallType($expr, $scope, $depth),
            $expr instanceof Expr\StaticCall => $this->staticCallType($expr, $scope),
            $expr instanceof Expr\New_ => $this->newType($expr, $scope, $depth),
            $expr instanceof Expr\Array_ => $this->union(array_map(
                fn ($item) => $item === null ? [] : $this->typeOf($item->value, $scope, $depth + 1),
                $expr->items,
            )),
            $expr instanceof Expr\FuncCall => $expr->name instanceof Name && in_array($expr->name->toLowerString(), self::ARRAY_FUNCTIONS, true)
                ? $this->union(array_map(fn ($arg) => $arg instanceof Node\Arg ? $this->typeOf($arg->value, $scope, $depth + 1) : [], $expr->args))
                : [],
            $expr instanceof Expr\Ternary => $this->union([$this->typeOf($expr->if, $scope, $depth + 1), $this->typeOf($expr->else, $scope, $depth + 1)]),
            $expr instanceof Expr\BinaryOp\Coalesce => $this->union([$this->typeOf($expr->left, $scope, $depth + 1), $this->typeOf($expr->right, $scope, $depth + 1)]),
            $expr instanceof Expr\Assign => $this->typeOf($expr->expr, $scope, $depth + 1),
            $expr instanceof Expr\Match_ => $this->union(array_map(fn ($arm) => $this->typeOf($arm->body, $scope, $depth + 1), $expr->arms)),
            default => [],
        };
    }

    private function methodCallType(Expr\MethodCall|Expr\NullsafeMethodCall $expr, Scope $scope, int $depth): array
    {
        if (!$expr->name instanceof Identifier) {
            return [];
        }
        $name = $expr->name->toString();

        $locator = $this->locatorType($expr, $name);
        if ($locator !== null) {
            return $locator;
        }

        $types = [];
        foreach ($this->typeOf($expr->var, $scope, $depth + 1) as $receiver) {
            if ($expr->args !== [] && (str_starts_with($receiver, self::TEST_PREFIX) || !$this->kernel->isKernelCall($receiver, $name))) {
                $this->recordParamTypes($this->node($receiver, $name), $expr->args, $scope, $depth);
            }
            $types[] = $this->callReturnTypes($receiver, $name, $expr->args);
        }
        $types = $this->union($types);

        if ($types === [] && $scope->testModule !== null && in_array($name, self::TEST_GETTERS, true)) {
            return $this->testGetterType($name, $expr->args, $scope->testModule);
        }

        return $types;
    }

    private function locatorType(Expr\MethodCall|Expr\NullsafeMethodCall $expr, string $name): ?array
    {
        if (!in_array($name, ['facade', 'client', 'service', 'queryContainer'], true)) {
            return null;
        }
        $moduleCall = $expr->var;
        if (!$moduleCall instanceof Expr\MethodCall || !$moduleCall->name instanceof Identifier) {
            return null;
        }
        $locatorCall = $moduleCall->var;
        if (!$locatorCall instanceof Expr\MethodCall || !$locatorCall->name instanceof Identifier || $locatorCall->name->toString() !== 'getLocator') {
            return null;
        }
        $target = $this->kernel->locatorTarget(ucfirst($moduleCall->name->toString()), $name);

        return $target === null ? [] : [$target];
    }

    private function testGetterType(string $name, array $args, array $testModule): array
    {
        [$layer, $module] = $testModule;
        if (isset($args[0]) && $args[0] instanceof Node\Arg && $args[0]->value instanceof String_) {
            $module = $args[0]->value->value;
        }
        $part = in_array($name, ['getRepository', 'getEntityManager'], true) ? 'Persistence' : 'Business';
        $target = $this->kernel->getterTarget($layer, $module, $part, $name);

        return $target === null ? [] : [$target];
    }

    private function staticCallType(Expr\StaticCall $expr, Scope $scope): array
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            return [];
        }
        $name = $expr->name->toString();

        return match ($expr->class->toLowerString()) {
            'parent' => ($parent = $this->kernel->refl($scope->declaring)?->getParentClass())
                ? $this->returnTypes($scope->runtime, $name, $parent->getName())
                : [],
            'self', 'static' => $this->callReturnTypes($scope->runtime, $name, $expr->args),
            default => [],
        };
    }

    private function newType(Expr\New_ $expr, Scope $scope, int $depth): array
    {
        if (!$expr->class instanceof Name) {
            return [];
        }
        $class = $expr->class->isSpecialClassName() ? Kernel::base($scope->runtime) : $expr->class->toString();
        if (!$this->kernel->isTracked($class)) {
            return [];
        }
        $this->recordArgs($class, '__construct', $expr->args, $scope, $depth);

        return [$class];
    }

    private function callReturnTypes(string $receiver, string $method, array $args): array
    {
        if (!str_starts_with($receiver, self::TEST_PREFIX) && $this->kernel->isKernelCall($receiver, $method)) {
            return match ($method) {
                'getProvidedDependency' => $this->dependencyTypes($receiver, $args),
                'getLocator' => [],
                default => $this->kernel->kernelTypes($receiver, $method),
            };
        }

        return $this->returnTypes($receiver, $method);
    }

    private function returnTypes(string $runtime, string $method, ?string $lookupClass = null): array
    {
        $key = $this->node($runtime, $method, $lookupClass);
        if ($key === null) {
            return [];
        }
        if (isset($this->returnMemo[$runtime][$key])) {
            return $this->returnMemo[$runtime][$key];
        }
        if (isset($this->inProgress[$key])) {
            return [];
        }
        $this->inProgress[$key] = true;

        [, $declaring] = $this->nodes[$key];
        $fn = $this->methodAst($runtime, $declaring, $this->nodes[$key][2]);
        $types = [];
        if ($fn !== null && $fn->stmts !== null) {
            $scope = $this->scopeFor($runtime, $declaring, $declaring . '::' . $this->nodes[$key][2]);
            $scope->node = $key;
            $scope->vars = $this->computeVars($fn, $scope);
            $types = $this->union(array_map(fn (Expr $e) => $this->typeOf($e, $scope), $this->findReturns($fn->stmts)));
            if ($types === []) {
                $types = $this->hintTypes($fn->returnType);
            }
        }

        unset($this->inProgress[$key]);

        return $this->returnMemo[$runtime][$key] = $types;
    }

    /**
     * Resolves `$this->getProvidedDependency(XDependencyProvider::CONST)` through the module DependencyProvider closures.
     */
    private function dependencyTypes(string $class, array $args): array
    {
        $arg = $args[0] ?? null;
        if (!$arg instanceof Node\Arg || !$arg->value instanceof Expr\ClassConstFetch || !$arg->value->name instanceof Identifier) {
            return [];
        }
        $constant = $arg->value->name->toString();
        $module = $this->kernel->moduleOf($class);
        if ($module === null) {
            return [];
        }
        $provider = $this->kernel->resolve($module['layer'], $module['module'], $module['module'] . 'DependencyProvider');
        if ($provider === null) {
            return [];
        }
        $memoKey = $provider . '|' . $constant;
        if (isset($this->dpMemo[$memoKey])) {
            return $this->dpMemo[$memoKey];
        }
        $this->dpMemo[$memoKey] = [];

        $types = [];
        for ($rc = $this->kernel->refl($provider); $rc && !$this->kernel->isKernel($rc->getName()); $rc = $rc->getParentClass()) {
            $file = $rc->getFileName();
            if (!$file) {
                continue;
            }
            foreach ($this->providerClosures($this->ast->file($file), $constant) as $closure) {
                $scope = $this->scopeFor($provider, $rc->getName(), $provider . '::' . $constant);
                $scope->vars = $this->computeVars($closure, $scope);
                $returns = $closure instanceof Expr\ArrowFunction ? [$closure->expr] : $this->findReturns($closure->stmts);
                foreach ($returns as $return) {
                    $types[] = $this->typeOf($return, $scope);
                }
            }
        }

        return $this->dpMemo[$memoKey] = $this->union($types);
    }

    /**
     * @return list<Expr\Closure|Expr\ArrowFunction>
     */
    private function providerClosures(array $stmts, string $constant): array
    {
        $closures = [];
        $isConstant = static fn ($node): bool => $node instanceof Expr\ClassConstFetch
            && $node->name instanceof Identifier
            && $node->name->toString() === $constant;
        $unwrap = static function ($node) {
            if ($node instanceof Expr\MethodCall && isset($node->args[0]) && $node->args[0] instanceof Node\Arg) {
                $node = $node->args[0]->value;
            }

            return $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction ? $node : null;
        };

        foreach ($this->finder->find($stmts, static fn (Node $n): bool => $n instanceof Expr\MethodCall || $n instanceof Expr\Assign) as $node) {
            if ($node instanceof Expr\MethodCall) {
                if ($node->name instanceof Identifier && in_array($node->name->toString(), ['set', 'extend'], true)
                    && count($node->args) >= 2 && $node->args[0] instanceof Node\Arg && $isConstant($node->args[0]->value)) {
                    $closures[] = $unwrap($node->args[1]->value);
                }

                continue;
            }
            if ($node->var instanceof Expr\ArrayDimFetch && $isConstant($node->var->dim)) {
                $closures[] = $unwrap($node->expr);
            }
        }

        return array_values(array_filter($closures));
    }

    /**
     * Argument types flow into the callee's parameters (context-insensitive, merged over all call sites).
     */
    private function recordParamTypes(?string $key, array $args, Scope $scope, int $depth = 0): void
    {
        if ($key === null || $args === []) {
            return;
        }
        [$runtime, $declaring, $method] = $this->nodes[$key];
        $fn = $this->methodAst($runtime, $declaring, $method);
        if ($fn === null) {
            return;
        }
        $names = [];
        $variadic = null;
        foreach ($fn->params as $index => $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $names[$param->var->name] = $index;
            }
            if ($param->variadic) {
                $variadic = $index;
            }
        }
        $changed = false;
        foreach ($args as $position => $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $index = $arg->name !== null ? ($names[$arg->name->toString()] ?? null) : ($variadic !== null && $position >= $variadic ? $variadic : $position);
            if ($index === null || !isset($fn->params[$index])) {
                continue;
            }
            $types = $this->typeOf($arg->value, $scope, $depth + 1);
            $new = array_diff($types, $this->paramTypes[$key][$index] ?? []);
            if ($new !== []) {
                $this->paramTypes[$key][$index] = array_values(array_merge($this->paramTypes[$key][$index] ?? [], $new));
                $changed = true;
            }
        }
        if ($changed) {
            unset($this->returnMemo[$runtime]);
            if (isset($this->processed[$key])) {
                $this->queue[] = $key;
            }
        }
    }

    /**
     * Remembers which classes are passed into properties, via constructor or setter-like methods (`addStep()`, `setPlugins()`).
     */
    private function recordArgs(string $class, string $method, array $args, Scope $scope, int $depth = 0): void
    {
        $map = $this->paramPropMap($class, $method);
        if ($map['props'] === []) {
            return;
        }
        foreach ($args as $position => $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $index = $position;
            if ($arg->name !== null) {
                $index = $map['names'][$arg->name->toString()] ?? null;
            } elseif ($arg->unpack || ($map['variadic'] !== null && $position >= $map['variadic'])) {
                $index = $map['variadic'];
            }
            if ($index === null || !isset($map['props'][$index])) {
                continue;
            }
            $types = $this->typeOf($arg->value, $scope, $depth + 1);
            foreach ($map['props'][$index] as $prop) {
                $this->addPropTypes($class, $prop, $types);
            }
        }
    }

    /**
     * @return array{props: array<int, list<string>>, names: array<string, int>, variadic: int|null}
     */
    private function paramPropMap(string $class, string $method): array
    {
        $class = Kernel::base($class);
        $memoKey = $class . '::' . $method;
        if (isset($this->ctorMaps[$memoKey])) {
            return $this->ctorMaps[$memoKey];
        }
        $map = ['props' => [], 'names' => [], 'variadic' => null];
        $rc = $this->kernel->refl($class);
        $rm = $rc !== null && $rc->hasMethod($method) ? $rc->getMethod($method) : null;
        $fn = $rm && $rm->getFileName() ? $this->ast->method($rm->getFileName(), $method, (int)$rm->getStartLine()) : null;
        if ($fn !== null) {
            foreach ($fn->params as $index => $param) {
                if (!$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                    continue;
                }
                $map['names'][$param->var->name] = $index;
                if ($param->flags !== 0) {
                    $map['props'][$index][] = $param->var->name;
                }
                if ($param->variadic) {
                    $map['variadic'] = $index;
                }
            }
            foreach ($this->finder->findInstanceOf($fn->stmts ?? [], Expr\Assign::class) as $assign) {
                $value = $assign->expr instanceof Expr\BinaryOp\Coalesce ? $assign->expr->left : $assign->expr;
                $target = $assign->var instanceof Expr\ArrayDimFetch ? $assign->var->var : $assign->var;
                if ($this->isThisProperty($target) && $value instanceof Expr\Variable && is_string($value->name)
                    && isset($map['names'][$value->name])) {
                    $map['props'][$map['names'][$value->name]][] = $target->name->toString();
                }
            }
            $map = $this->forwardParentArgs($map, $fn, $rm, $method);
        }

        return $this->ctorMaps[$memoKey] = $map;
    }

    /**
     * Follows `parent::__construct($a, $b)` so arguments forwarded to the parent land in the parent's properties.
     */
    private function forwardParentArgs(array $map, Stmt\ClassMethod $fn, ReflectionMethod $rm, string $method): array
    {
        $parent = $rm->getDeclaringClass()->getParentClass();
        if (!$parent) {
            return $map;
        }
        foreach ($this->finder->findInstanceOf($fn->stmts ?? [], Expr\StaticCall::class) as $call) {
            if (!$call->class instanceof Name || $call->class->toLowerString() !== 'parent'
                || !$call->name instanceof Identifier || $call->name->toLowerString() !== strtolower($method)) {
                continue;
            }
            $parentMap = $this->paramPropMap($parent->getName(), $method);
            foreach ($call->args as $position => $arg) {
                if (!$arg instanceof Node\Arg || !$arg->value instanceof Expr\Variable || !is_string($arg->value->name)
                    || !isset($map['names'][$arg->value->name])) {
                    continue;
                }
                $parentIndex = $parentMap['variadic'] !== null && $position >= $parentMap['variadic'] ? $parentMap['variadic'] : $position;
                foreach ($parentMap['props'][$parentIndex] ?? [] as $prop) {
                    $map['props'][$map['names'][$arg->value->name]][] = $prop;
                }
            }
        }

        return $map;
    }

    private function addPropTypes(string $runtime, string $prop, array $types): void
    {
        $known = $this->propTypes[$runtime][$prop] ?? [];
        $new = array_diff($types, $known);
        if ($new === []) {
            return;
        }
        $this->propTypes[$runtime][$prop] = array_values(array_merge($known, $new));
        unset($this->returnMemo[$runtime]);
        foreach (array_keys($this->byRuntime[$runtime] ?? []) as $key) {
            $this->queue[] = $key;
        }
    }

    private function propTypesFor(Scope $scope, string $prop): array
    {
        if (str_starts_with($scope->runtime, self::TEST_PREFIX)) {
            return $this->testProp(substr($scope->runtime, strlen(self::TEST_PREFIX)), $prop);
        }
        $runtime = $scope->runtime;
        $known = $this->union([$this->propTypes[$runtime][$prop] ?? [], $this->propTypes[Kernel::base($runtime)][$prop] ?? []]);
        if ($known !== []) {
            return $known;
        }
        if (!isset($this->declaredProps[$runtime][$prop])) {
            $this->declaredProps[$runtime][$prop] = $this->declaredPropTypes($runtime, $prop);
        }

        return $this->declaredProps[$runtime][$prop];
    }

    /**
     * Fallback when no instantiation was seen: the declared (or @var) type, an interface resolved by Spryker naming.
     */
    private function declaredPropTypes(string $class, string $prop): array
    {
        $rc = $this->kernel->refl($class);
        if ($rc === null || !$rc->hasProperty($prop)) {
            return [];
        }
        $property = $rc->getProperty($prop);
        $type = $property->getType();
        $name = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
        if ($name === null && preg_match('/@var\s+\\\\?([A-Za-z_][\w\\\\]*)/', (string)$property->getDocComment(), $m)) {
            $name = $m[1];
        }
        $implementation = $name === null ? null : $this->kernel->implementationOf($name);

        return $implementation === null ? [] : [$implementation];
    }

    /**
     * Flow-insensitive variable types: parameters, assignments and foreach values.
     */
    private function computeVars(Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $fn, Scope $scope): array
    {
        $vars = [];
        foreach ($fn->params as $index => $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $types = $this->union([$this->hintTypes($param->type), $scope->node !== null ? ($this->paramTypes[$scope->node][$index] ?? []) : []]);
                if ($types !== []) {
                    $vars[$param->var->name] = $types;
                }
            }
        }
        $body = $fn instanceof Expr\ArrowFunction ? [$fn->expr] : ($fn->stmts ?? []);
        foreach ($this->finder->findInstanceOf($body, Stmt\Catch_::class) as $catch) {
            if ($catch->var !== null && is_string($catch->var->name)) {
                $vars[$catch->var->name] = $this->union([$vars[$catch->var->name] ?? [], ...array_map(fn (Name $t) => $this->hintTypes($t), $catch->types)]);
            }
        }
        $scope->vars = $vars;

        $nodes = $this->finder->find($body, static fn (Node $n): bool => $n instanceof Expr\Assign || $n instanceof Stmt\Foreach_);
        if ($nodes === []) {
            return $vars;
        }
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($nodes as $node) {
                $target = $node instanceof Expr\Assign ? $node->var : $node->valueVar;
                $value = $node->expr;
                if ($target instanceof Expr\ArrayDimFetch) {
                    $target = $target->var;
                }
                if (!$target instanceof Expr\Variable || !is_string($target->name)) {
                    continue;
                }
                $types = $this->typeOf($value, $scope);
                if ($types !== []) {
                    $vars[$target->name] = $this->union([$vars[$target->name] ?? [], $types]);
                    $scope->vars = $vars;
                }
            }
        }

        return $this->deriveConfiguredObjects($body, $vars, $scope);
    }

    /**
     * A local object configured via setter-like calls (`$broker->addStep(...)`) becomes a per-call-site token
     * ("Class#Declaring::method"), so steps/plugins added in one factory method do not leak into all other instances.
     */
    private function deriveConfiguredObjects(array $body, array $vars, Scope $scope): array
    {
        $calls = $this->finder->find($body, static fn (Node $n): bool => $n instanceof Expr\MethodCall
            && $n->var instanceof Expr\Variable && is_string($n->var->name) && $n->var->name !== 'this'
            && $n->name instanceof Identifier && $n->args !== []);
        foreach ($calls as $call) {
            $variable = $call->var->name;
            if (!isset($vars[$variable])) {
                continue;
            }
            $types = [];
            foreach ($vars[$variable] as $type) {
                if (str_starts_with($type, self::TEST_PREFIX) || $this->paramPropMap($type, $call->name->toString())['props'] === []) {
                    $types[] = $type;

                    continue;
                }
                $token = Kernel::base($type) . '#' . $scope->site;
                if ($token !== $type) {
                    foreach ($this->propTypes[$type] ?? [] as $prop => $propTypes) {
                        $this->addPropTypes($token, $prop, $propTypes);
                    }
                }
                $scope->vars[$variable] = $this->union([array_diff($scope->vars[$variable], [$type]), [$token]]);
                $this->recordArgs($token, $call->name->toString(), $call->args, $scope);
                $types[] = $token;
            }
            $vars[$variable] = array_values(array_unique($types));
            $scope->vars = $vars;
        }

        return $vars;
    }

    private function hintTypes(?Node $type): array
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }
        if ($type instanceof Node\UnionType) {
            return $this->union(array_map(fn ($t) => $this->hintTypes($t), $type->types));
        }
        if (!$type instanceof Name || $type->isSpecialClassName()) {
            return [];
        }
        $class = $type->toString();

        return $this->kernel->isTracked($class) && $this->kernel->isConcrete($class) ? [$class] : [];
    }

    /**
     * @return list<Expr>
     */
    private function findReturns(array $stmts): array
    {
        $returns = [];
        $stack = $stmts;
        while ($stack) {
            $node = array_pop($stack);
            if (is_array($node)) {
                array_push($stack, ...$node);

                continue;
            }
            if (!$node instanceof Node || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
                || $node instanceof Stmt\Class_ || $node instanceof Stmt\Function_) {
                continue;
            }
            if ($node instanceof Stmt\Return_ && $node->expr !== null) {
                $returns[] = $node->expr;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $stack[] = $node->$name;
            }
        }

        return $returns;
    }

    public function methodNode(string $class, string $method): ?Stmt\ClassMethod
    {
        $key = $this->node($class, $method);

        return $key === null ? null : $this->methodAst($class, $this->nodes[$key][1], $method);
    }

    private function methodAst(string $runtime, string $declaring, string $method): ?Stmt\ClassMethod
    {
        if (str_starts_with($runtime, self::TEST_PREFIX)) {
            return $this->testMethod(substr($runtime, strlen(self::TEST_PREFIX)), $method);
        }
        $rc = $this->kernel->refl($declaring);
        if ($rc === null || !$rc->hasMethod($method)) {
            return null;
        }
        $rm = $rc->getMethod($method);
        $file = $rm->getFileName();

        return $file ? $this->ast->method($file, $method, (int)$rm->getStartLine()) : null;
    }

    private function scopeFor(string $runtime, string $declaring, string $site = ''): Scope
    {
        $testModule = null;
        if (str_starts_with($runtime, self::TEST_PREFIX)) {
            $testModule = $this->testClasses[substr($runtime, strlen(self::TEST_PREFIX))]['module'] ?? null;
        }

        return new Scope($runtime, $declaring, [], $testModule, $site !== '' ? $site : $declaring);
    }

    private function testMethod(string $fqcn, string $method): ?Stmt\ClassMethod
    {
        for ($class = $fqcn; $class !== null && isset($this->testClasses[$class]); $class = $this->testClasses[$class]['parent']) {
            if (isset($this->testClasses[$class]['methods'][$method])) {
                return $this->testClasses[$class]['methods'][$method];
            }
        }

        return null;
    }

    private function testProp(string $fqcn, string $prop): array
    {
        $types = [];
        for ($class = $fqcn; $class !== null && isset($this->testClasses[$class]); $class = $this->testClasses[$class]['parent']) {
            $types[] = $this->testClasses[$class]['props'][$prop] ?? [];
        }

        return $this->union($types);
    }

    private function collectTestProps(string $fqcn): void
    {
        $runtime = self::TEST_PREFIX . $fqcn;
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($this->testClasses[$fqcn]['methods'] as $method) {
                $assigns = $this->finder->findInstanceOf($method->stmts ?? [], Expr\Assign::class);
                $assigns = array_filter($assigns, fn (Expr\Assign $a): bool => $this->isThisProperty($a->var));
                if ($assigns === []) {
                    continue;
                }
                $scope = $this->scopeFor($runtime, $runtime);
                $scope->vars = $this->computeVars($method, $scope);
                foreach ($assigns as $assign) {
                    $prop = $assign->var->name->toString();
                    $this->testClasses[$fqcn]['props'][$prop] = $this->union([
                        $this->testClasses[$fqcn]['props'][$prop] ?? [],
                        $this->typeOf($assign->expr, $scope),
                    ]);
                }
            }
        }
    }

    private function isThisProperty(?Node $node): bool
    {
        return ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch)
            && $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier;
    }

    /**
     * @param list<list<string>> $lists
     *
     * @return list<string>
     */
    private function union(array $lists): array
    {
        return $lists === [] ? [] : array_values(array_unique(array_merge(...$lists)));
    }
}

final class ProjectScanner
{
    private const EXCLUDED_PATH = '#/(Dependency|Controller|Persistence/Propel|Theme)/#';

    private const EXCLUDED_FILE = '/(Config|DependencyProvider|Factory|Interface|Constants|Controller|Stub)\.php$/';

    public function __construct(private Kernel $kernel)
    {
    }

    /**
     * @return list<array{class: string, file: string, core: string|null, methods: list<string>, allMethods: list<string>, layer: string, module: string}>
     */
    public function scan(string $root): array
    {
        $classes = [];
        foreach ($this->projectFiles($root) as $path => $class) {
            $relative = substr($path, strlen($root) + 1);
            if (preg_match(self::EXCLUDED_PATH, $relative) || preg_match(self::EXCLUDED_FILE, $relative)) {
                continue;
            }
            $rc = $this->kernel->refl($class);
            if ($rc === null || $rc->getFileName() !== $path || $rc->isInterface() || $rc->isTrait() || $rc->isEnum()) {
                continue;
            }
            $methods = [];
            $allMethods = [];
            foreach ($rc->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->getFileName() !== $path || $method->getName() === '__construct'
                    || $method->isAbstract()) {
                    continue;
                }
                $allMethods[] = $method->getName();
                if ($method->isPublic() || $this->isOverride($rc, $method->getName())) {
                    $methods[] = $method->getName();
                }
            }
            if ($allMethods === []) {
                continue;
            }
            $module = $this->kernel->moduleOf($class);
            $classes[] = [
                'class' => $class,
                'file' => $relative,
                'core' => $this->coreParent($rc),
                'methods' => $methods,
                'allMethods' => $allMethods,
                'layer' => $module['layer'] ?? '?',
                'module' => $module['module'] ?? '?',
            ];
        }
        usort($classes, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return $classes;
    }

    /**
     * Every PHP file of every project namespace dir, mapped to the class name derived from its path (PSR-4).
     *
     * @return array<string, string> path => class
     */
    public function projectFiles(string $root): array
    {
        $files = [];
        foreach ($this->kernel->projectOrgs as $org) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src/$org", FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $path = $file->getPathname();
                    $files[$path] = str_replace('/', '\\', substr($path, strlen("$root/src/"), -4));
                }
            }
        }

        return $files;
    }

    /**
     * A non-public method counts only when it overrides a parent or interface method (new behaviour of an override).
     * Pure protected/private helpers are reached through the public methods of the same class.
     */
    private function isOverride(ReflectionClass $rc, string $method): bool
    {
        $parent = $rc->getParentClass();
        if ($parent && $parent->hasMethod($method)) {
            return true;
        }
        foreach ($rc->getInterfaces() as $interface) {
            if ($interface->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nearest core ancestor that carries domain behaviour (Kernel classes and Abstract* bases do not count).
     */
    private function coreParent(ReflectionClass $rc): ?string
    {
        for ($parent = $rc->getParentClass(); $parent; $parent = $parent->getParentClass()) {
            $name = $parent->getName();
            $org = strstr($name, '\\', true);
            if (in_array($org, $this->kernel->projectOrgs, true)) {
                continue;
            }
            if (!in_array($org, Kernel::CORE_ORGS, true) || $this->kernel->isKernel($name) || str_starts_with($parent->getShortName(), 'Abstract')) {
                return null;
            }

            return $name;
        }

        return null;
    }
}

/**
 * Methods that are not worth a test: they are neither listed nor counted.
 */
final class SkipRules
{
    public const REASON_BOOTSTRAP = 'application bootstrap';

    public const REASON_ROUTING = 'routing/resource configuration plugin';

    public const REASON_STUB_ONLY = 'client method only calls a Zed stub';

    public const REASON_UI = 'form/table definition (--skip-ui-definitions)';

    public const REASON_DECLARATIVE = 'declarative: only returns constant data';

    /** Plugins that only declare routes/resources; they are verified by HTTP tests, not unit tests. */
    private const ROUTING_INTERFACES = [
        'Spryker\\Glue\\GlueApplicationExtension\\Dependency\\Plugin\\ResourceInterface',
        'Spryker\\Glue\\GlueJsonApiConventionExtension\\Dependency\\Plugin\\JsonApiResourceInterface',
        'Spryker\\Glue\\GlueApplicationExtension\\Dependency\\Plugin\\ResourceRoutePluginInterface',
        'Spryker\\Glue\\GlueBackendApiApplicationAuthorizationConnectorExtension\\Dependency\\Plugin\\ProtectedRouteAuthorizationConfigProviderPluginInterface',
        'Spryker\\Yves\\RouterExtension\\Dependency\\Plugin\\RouteProviderPluginInterface',
    ];

    private const FORM_TYPE = 'Symfony\\Component\\Form\\FormTypeInterface';

    private const GUI_TABLE = 'Spryker\\Zed\\Gui\\Communication\\Table\\AbstractTable';

    private const FORM_DEFINITION_METHODS = ['buildForm', 'configureOptions', 'getBlockPrefix', 'getName'];

    /** Plugins adding fields to a core form, e.g. ProductAbstractFormExpanderPluginInterface, MerchantFormExpanderPluginInterface. */
    private const FORM_EXPANDER_INTERFACE_PATTERN = '/FormExpanderPluginInterface$/';

    private NodeFinder $finder;

    public function __construct(private Kernel $kernel, private Analyzer $analyzer, private bool $skipUiDefinitions)
    {
        $this->finder = new NodeFinder();
    }

    /**
     * @param array{class: string, layer: string, methods: list<string>} $class
     *
     * @return array<string, string> method => reason
     */
    public function skippedMethodsOf(array $class): array
    {
        $name = $class['class'];
        $rc = $this->kernel->refl($name);
        if ($rc === null) {
            return [];
        }
        $classReason = $this->classReason($name, $rc);
        if ($classReason !== null) {
            return array_fill_keys($class['methods'], $classReason);
        }

        $isForm = $this->skipUiDefinitions && interface_exists(self::FORM_TYPE) && $rc->implementsInterface(self::FORM_TYPE);
        $isTable = $this->skipUiDefinitions && class_exists(self::GUI_TABLE) && $rc->isSubclassOf(self::GUI_TABLE);
        $isClient = $class['layer'] === 'Client' && str_ends_with($name, 'Client');
        $skipped = [];
        foreach ($class['methods'] as $method) {
            $fn = $this->analyzer->methodNode($name, $method);
            $reason = match (true) {
                $isClient && $this->isStubOnly($name, $method) => self::REASON_STUB_ONLY,
                $isForm && (in_array($method, self::FORM_DEFINITION_METHODS, true) || (str_starts_with($method, 'add') && $fn !== null && !$this->hasLogic($fn))) => self::REASON_UI,
                $isTable && $method === 'configure' => self::REASON_UI,
                $fn !== null && $this->isDeclarative($fn) => self::REASON_DECLARATIVE,
                default => null,
            };
            if ($reason !== null) {
                $skipped[$method] = $reason;
            }
        }

        return $skipped;
    }

    private function classReason(string $name, ReflectionClass $rc): ?string
    {
        if (str_ends_with($name, 'Bootstrap')) {
            return self::REASON_BOOTSTRAP;
        }
        foreach (self::ROUTING_INTERFACES as $interface) {
            if (interface_exists($interface) && $rc->implementsInterface($interface)) {
                return self::REASON_ROUTING;
            }
        }
        if ($this->skipUiDefinitions && preg_grep(self::FORM_EXPANDER_INTERFACE_PATTERN, $rc->getInterfaceNames())) {
            return self::REASON_UI;
        }

        return null;
    }

    /**
     * Thin client method whose only project callee is a Zed stub (factory calls aside).
     */
    private function isStubOnly(string $class, string $method): bool
    {
        $callees = $this->analyzer->delegateCallees($class, $method) ?? [];
        $stubCalls = array_filter($callees, static fn (array $c): bool => str_ends_with($c[0], 'Stub'));
        $otherCalls = array_filter($callees, static fn (array $c): bool => !str_ends_with($c[0], 'Stub') && !str_ends_with($c[0], 'Factory'));

        return $stubCalls !== [] && $otherCalls === [];
    }

    /**
     * The whole body is `return <constant data>;`.
     */
    private function isDeclarative(Stmt\ClassMethod $fn): bool
    {
        $stmts = $fn->stmts ?? [];

        return count($stmts) === 1 && $stmts[0] instanceof Stmt\Return_ && $this->isConstantData($stmts[0]->expr);
    }

    /**
     * Scalars, constants, `X::class`, arrays of those, and objects built from them:
     * `new X()` / `new X('a')` and fluent setters on them: `(new XTransfer())->setA('a')->setB(Y::B)`.
     */
    private function isConstantData(?Node $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar, $expr instanceof Expr\ClassConstFetch, $expr instanceof Expr\ConstFetch => true,
            $expr instanceof Expr\UnaryMinus => $this->isConstantData($expr->expr),
            $expr instanceof Expr\Array_ => !array_filter($expr->items, fn ($item): bool => $item !== null
                && (!$this->isConstantData($item->value) || ($item->key !== null && !$this->isConstantData($item->key)))),
            $expr instanceof Expr\New_ => $expr->class instanceof Name && $this->areConstantArgs($expr->args),
            $expr instanceof Expr\MethodCall => ($expr->var instanceof Expr\New_ || $expr->var instanceof Expr\MethodCall)
                && $this->isConstantData($expr->var) && $this->areConstantArgs($expr->args),
            default => false,
        };
    }

    private function areConstantArgs(array $args): bool
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack || !$this->isConstantData($arg->value)) {
                return false;
            }
        }

        return true;
    }

    private function hasLogic(Stmt\ClassMethod $fn): bool
    {
        return $this->finder->findFirst($fn->stmts ?? [], static fn (Node $n): bool => $n instanceof Stmt\If_
            || $n instanceof Stmt\Foreach_ || $n instanceof Stmt\For_ || $n instanceof Stmt\While_ || $n instanceof Stmt\Do_
            || $n instanceof Stmt\Switch_ || $n instanceof Stmt\TryCatch || $n instanceof Expr\Match_ || $n instanceof Expr\Ternary
            || $n instanceof Expr\Closure || $n instanceof Expr\ArrowFunction) !== null;
    }
}

final class Report
{
    private const MAX_ENTRIES = 4;

    private const MAX_PLUGINS = 3;

    private array $registeredIn = [];

    private array $pluginCalls = [];

    public function __construct(private Kernel $kernel, private Ast $ast, private Analyzer $analyzer)
    {
    }

    /**
     * Scans project DependencyProviders for `new X()` registrations.
     */
    public function collectRegistrations(array $projectFiles): void
    {
        $finder = new NodeFinder();
        foreach (array_keys($projectFiles) as $path) {
            if (!str_ends_with($path, 'DependencyProvider.php')) {
                continue;
            }
            $provider = basename($path, '.php');
            foreach ($finder->findInstanceOf($this->ast->file($path), Stmt\ClassMethod::class) as $method) {
                foreach ($finder->findInstanceOf($method->stmts ?? [], Expr\New_::class) as $new) {
                    if ($new->class instanceof Name && !$new->class->isSpecialClassName()) {
                        $this->registeredIn[$new->class->toString()][] = $provider . '::' . $method->name->toString() . '()';
                    }
                }
            }
        }
    }

    /**
     * "XFactory::createY()" — the method of the module factory (project or core) that instantiates the class.
     */
    public function factoryMethodCreating(string $class): ?string
    {
        $info = $this->kernel->moduleOf($class);
        $factory = $info === null ? null : $this->kernel->getterTarget($info['layer'], $info['module'], $info['part'], 'getFactory');
        $finder = new NodeFinder();
        for ($rc = $factory === null ? null : $this->kernel->refl($factory); $rc && !$this->kernel->isKernel($rc->getName()); $rc = $rc->getParentClass()) {
            foreach ($finder->findInstanceOf($this->ast->file((string)$rc->getFileName()), Stmt\ClassMethod::class) as $method) {
                $creates = $finder->findFirst($method->stmts ?? [], static fn (Node $n): bool => $n instanceof Expr\New_
                    && $n->class instanceof Name && $n->class->toString() === $class);
                if ($creates !== null) {
                    return $rc->getShortName() . '::' . $method->name->toString() . '()';
                }
            }
        }

        return null;
    }

    /**
     * @return list<string> "XDependencyProvider::method()" where the class is instantiated
     */
    public function registrationsOf(string $class): array
    {
        return array_values(array_unique($this->registeredIn[$class] ?? []));
    }

    /**
     * @return list<string>
     */
    public function registeredPluginsOf(string $layer, string $module): array
    {
        $plugins = [];
        foreach (array_keys($this->registeredIn) as $class) {
            $info = $this->kernel->moduleOf($class);
            if ($info !== null && $info['module'] === $module && preg_match('/(Plugin|Subscriber)$/', $class) && $this->kernel->isConcrete($class)) {
                $plugins[] = $class;
            }
        }

        return $plugins;
    }

    /**
     * @return list<string> "Plugin (registered in X::y())" lines for plugins calling $facade::$method
     */
    public function pluginsCalling(string $facade, string $method): array
    {
        $info = $this->kernel->moduleOf($facade);
        if ($info === null) {
            return [];
        }
        $lines = [];
        foreach ($this->registeredPluginsOf($info['layer'], $info['module']) as $plugin) {
            if (in_array($method, $this->facadeCallsOf($plugin), true)) {
                $lines[] = $this->short($plugin) . '  (registered in ' . implode(', ', array_unique($this->registeredIn[$plugin])) . ')';
            }
        }

        return $lines;
    }

    private function facadeCallsOf(string $plugin): array
    {
        if (isset($this->pluginCalls[$plugin])) {
            return $this->pluginCalls[$plugin];
        }
        $calls = [];
        $finder = new NodeFinder();
        for ($rc = $this->kernel->refl($plugin); $rc && !$this->kernel->isKernel($rc->getName()); $rc = $rc->getParentClass()) {
            if (!$rc->getFileName()) {
                continue;
            }
            foreach ($finder->findInstanceOf($this->ast->file($rc->getFileName()), Expr\MethodCall::class) as $call) {
                if ($call->name instanceof Identifier && $call->var instanceof Expr\MethodCall && $call->var->name instanceof Identifier
                    && in_array($call->var->name->toString(), ['getFacade', 'getClient', 'getService'], true)) {
                    $calls[] = $call->name->toString();
                }
            }
        }

        return $this->pluginCalls[$plugin] = array_values(array_unique($calls));
    }

    /**
     * Methods the framework calls: magic methods, overrides/implementations of non-project classes and interfaces
     * (plugin interfaces, core overrides, Symfony/Codeception contracts) and controller actions.
     */
    public function isFrameworkCalled(string $class, string $method): bool
    {
        if (str_starts_with($method, '__') || (str_ends_with($method, 'Action') && str_ends_with($class, 'Controller'))) {
            return true;
        }
        $rc = $this->kernel->refl($class);
        if ($rc === null) {
            return true;
        }
        for ($parent = $rc->getParentClass(); $parent; $parent = $parent->getParentClass()) {
            if (!$this->kernel->isProject($parent->getName()) && $parent->hasMethod($method)) {
                return true;
            }
        }
        foreach ($rc->getInterfaces() as $interface) {
            if (!$this->kernel->isProject($interface->getName()) && $interface->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classes referenced in production code (`new X`, `X::m()`, `X::class`, `instanceof X`, `catch (X)`, `extends X`) outside their own file.
     * Scans project dirs plus config/ and public/ (bootstraps, application config).
     *
     * @param array<string, string> $projectFiles
     *
     * @return array<string, true>
     */
    public function referencedClasses(string $root, array $projectFiles): array
    {
        $paths = array_keys($projectFiles);
        foreach (['config', 'public'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }
        $references = [];
        $finder = new NodeFinder();
        foreach ($paths as $path) {
            $own = $projectFiles[$path] ?? null;
            $nodes = $finder->find($this->ast->file($path), static fn (Node $n): bool => $n instanceof Expr\New_
                || $n instanceof Expr\StaticCall || $n instanceof Expr\ClassConstFetch || $n instanceof Expr\Instanceof_ || $n instanceof Stmt\Catch_
                || ($n instanceof Stmt\Class_ && $n->extends !== null));
            foreach ($nodes as $node) {
                $names = match (true) {
                    $node instanceof Stmt\Catch_ => $node->types,
                    $node instanceof Stmt\Class_ => [$node->extends],
                    default => [$node->class],
                };
                foreach ($names as $name) {
                    if ($name instanceof Name && !$name->isSpecialClassName() && $name->toString() !== $own) {
                        $references[$name->toString()] = true;
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Classes the Spryker kernel resolves by naming convention (never instantiated explicitly).
     */
    public function isKernelResolved(string $class): bool
    {
        $info = $this->kernel->moduleOf($class);
        if ($info === null) {
            return false;
        }
        $module = $info['module'];
        $short = substr($class, strrpos($class, '\\') + 1);

        return in_array($short, ["{$module}Facade", "{$module}Client", "{$module}Service", "{$module}Repository", "{$module}EntityManager", "{$module}QueryContainer", "{$module}Config"], true)
            || str_ends_with($short, 'Controller');
    }

    /**
     * Method names referenced dynamically: string literals in project PHP (callables, `[$this, 'x']`)
     * and Twig attribute/method access (`item.name` -> name/getName/isName/hasName).
     *
     * @param array<string, string> $projectFiles
     *
     * @return array<string, true>
     */
    public function namesReferencedAsText(string $root, array $projectFiles, array $skipLayers = []): array
    {
        $names = [];
        $finder = new NodeFinder();
        foreach (array_keys($projectFiles) as $path) {
            foreach ($finder->findInstanceOf($this->ast->file($path), String_::class) as $string) {
                if (preg_match('/^[a-zA-Z_]\w*$/', $string->value)) {
                    $names[$string->value] = true;
                }
            }
        }
        foreach ($this->kernel->projectOrgs as $org) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src/$org", FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $layer = explode('/', substr($file->getPathname(), strlen("$root/src/$org/")))[0];
                if (in_array($layer, $skipLayers, true)) {
                    continue;
                }
                if ($file->getExtension() !== 'twig' || !preg_match_all('/\.([a-zA-Z_]\w*)/', (string)file_get_contents($file->getPathname()), $m)) {
                    continue;
                }
                foreach ($m[1] as $name) {
                    foreach ([$name, 'get' . ucfirst($name), 'is' . ucfirst($name), 'has' . ucfirst($name)] as $candidate) {
                        $names[$candidate] = true;
                    }
                }
            }
        }

        return $names;
    }

    public function short(string $class): string
    {
        $info = $this->kernel->moduleOf($class);

        return $info === null
            ? $class
            : "{$info['org']}\\{$info['layer']}\\{$info['module']}\\…\\" . substr($class, strrpos($class, '\\') + 1);
    }

    public function nodeLabel(string $key): string
    {
        [$runtime, , $method] = $this->analyzer->nodes[$key];
        $runtime = Kernel::base($runtime);

        return $this->short($runtime) . '::' . $method . '()';
    }
}

// ---------------------------------------------------------------------------------------------------------------------

const COVERAGE_GAPS_LAYERS = ['Zed', 'Client', 'Yves', 'Glue', 'Service', 'Shared'];

/**
 * Strict option parser: an unknown option, a missing value or a positional argument is a usage error.
 *
 * @param list<string> $arguments
 *
 * @return array<string, string|true|list<string>>|string the options, or the error message
 */
function coverageGapsParseOptions(array $arguments): array|string
{
    $flags = ['only-untested', 'no-entries', 'no-unused', 'hide-unused', 'skip-ui-definitions', 'show-skipped', 'json', 'help'];
    $values = ['layer', 'module', 'skip-layer', 'why', 'root', 'limit'];
    $options = [];
    for ($i = 0; $i < count($arguments); $i++) {
        $argument = $arguments[$i];
        if (!str_starts_with($argument, '--')) {
            return "unexpected argument '$argument'";
        }
        [$name, $value] = explode('=', substr($argument, 2), 2) + [1 => null];
        if (in_array($name, $flags, true)) {
            if ($value !== null) {
                return "--$name takes no value";
            }
            $options[$name] = true;

            continue;
        }
        if (!in_array($name, $values, true)) {
            return "unknown option '--$name'";
        }
        if ($value === null) {
            $value = $arguments[++$i] ?? null;
            if ($value === null || str_starts_with($value, '--')) {
                return "--$name needs a value";
            }
        }
        if ($name === 'skip-layer') {
            $options[$name][] = $value;
        } else {
            $options[$name] = $value;
        }
    }

    return $options;
}

function coverageGapsUsageError(string $message): never
{
    fwrite(STDERR, "coverage-gaps.php: $message (see --help)\n");
    exit(COVERAGE_GAPS_EXIT_USAGE);
}

function coverageGapsPlural(int $count, string $singular, string $plural): string
{
    return $count . ' ' . ($count === 1 ? $singular : $plural);
}

$options = coverageGapsParseOptions(array_slice($argv, 1));
if (is_string($options)) {
    coverageGapsUsageError($options);
}
if (isset($options['help'])) {
    echo "Usage: php coverage-gaps.php [--layer=Zed|Client|Yves|Glue|Service|Shared] [--module=Cart] [--only-untested]\n"
        . "       [--no-entries] [--no-unused] [--hide-unused] [--skip-ui-definitions] [--show-skipped] [--skip-layer=Yves,Glue]\n"
        . "       [--why=FQCN] [--root=DIR] [--limit=N] [--json]\n"
        . "  --layer=L        report only one layer\n"
        . "  --module=M       report only one module\n"
        . "  --skip-layer=L   ignore layers the project does not use (comma-separated or repeated): not reported, not counted,\n"
        . "                   not production callers for unused code, not entry points\n"
        . "  --only-untested  hide partially tested classes\n"
        . "  --no-entries     skip the 'test via' lookup and the entry points\n"
        . "  --no-unused      skip unused-code detection\n"
        . "  --hide-unused    detect unused code and drop it from the lists and counts (wins over --no-unused)\n"
        . "  --skip-ui-definitions  also skip form field definitions (buildForm, add*Field without logic), form expander plugins and Table::configure()\n"
        . "  --show-skipped   list the skipped methods (by default only the counts are printed)\n"
        . "  --why=FQCN       print the call paths from entry points to the untested methods of FQCN to stderr (use / instead of \\ when running via docker/sdk)\n"
        . "  --root=DIR       analyse another project dir (its src/<Org> dirs are autoloaded, vendor/ stays this project's)\n"
        . "  --limit=N        findings per section in the summary (default 10, 0 prints the counts only)\n"
        . "  --json           print the full report as JSON instead of the summary\n"
        . "Exit codes: 0 OK, 1 core-extended classes without any test, 2 usage or requirements error, 3 classes or files could not be analysed.\n";
    exit(COVERAGE_GAPS_EXIT_OK);
}
if (isset($options['limit']) && !ctype_digit((string)$options['limit'])) {
    coverageGapsUsageError('--limit takes a number (0 prints the counts only)');
}
$limit = (int)($options['limit'] ?? 10);
if (isset($options['hide-unused'])) {
    unset($options['no-unused']);
}
if (isset($options['root'])) {
    $root = realpath((string)$options['root']) ?: (string)$options['root'];
    if (!is_dir($root . '/src')) {
        coverageGapsUsageError("--root: no src/ directory in $root");
    }
}
if (isset($options['layer'])) {
    $options['layer'] = ucfirst(strtolower((string)$options['layer']));
    if (!in_array($options['layer'], COVERAGE_GAPS_LAYERS, true)) {
        coverageGapsUsageError("--layer: unknown layer '{$options['layer']}', use Zed, Client, Yves, Glue, Service or Shared");
    }
}
if (isset($options['why'])) {
    if (isset($options['no-entries'])) {
        coverageGapsUsageError('--why needs the entry-point lookup: remove --no-entries');
    }
    $options['why'] = ltrim(str_replace('/', '\\', (string)$options['why']), '\\');
}
$skipLayers = [];
foreach ((array)($options['skip-layer'] ?? []) as $value) {
    foreach (array_filter(array_map('trim', explode(',', (string)$value))) as $layer) {
        $layer = ucfirst(strtolower($layer));
        if (!in_array($layer, COVERAGE_GAPS_LAYERS, true)) {
            coverageGapsUsageError("--skip-layer: unknown layer '$layer', use Zed, Client, Yves, Glue, Service or Shared");
        }
        $skipLayers[$layer] = $layer;
    }
}
$skipLayers = array_values($skipLayers);
$log = static fn (string $message) => fwrite(STDERR, sprintf("[%5.1fs] %s\n", microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], $message));

$kernel = new Kernel(Kernel::detectProjectOrgs($root));
coverageGapsRegisterSrcAutoloader($root, $kernel->projectOrgs);
$ast = new Ast();
$analyzer = new Analyzer($kernel, $ast);
$report = new Report($kernel, $ast, $analyzer);
$scanner = new ProjectScanner($kernel);
$isIgnoredLayer = static fn (string $class): bool => in_array($kernel->moduleOf($class)['layer'] ?? '', $skipLayers, true);
$isInScope = static function (string $class) use ($kernel, $isIgnoredLayer, $options): bool {
    $module = $kernel->moduleOf($class);

    return !$isIgnoredLayer($class)
        && (!isset($options['layer']) || ($module['layer'] ?? null) === $options['layer'])
        && (!isset($options['module']) || ($module['module'] ?? null) === $options['module']);
};
$projectFiles = $scanner->projectFiles($root);
// --skip-layer: code of ignored layers is neither reported nor a production caller nor an entry point.
$projectFilesInUse = array_filter($projectFiles, static fn (string $class): bool => !$isIgnoredLayer($class));
if ($skipLayers !== []) {
    $log('Ignoring layers: ' . implode(', ', $skipLayers));
}
$projectFilesInScope = array_filter($projectFiles, $isInScope);
if ($projectFilesInScope === [] && (isset($options['layer']) || isset($options['module']))) {
    coverageGapsUsageError(sprintf(
        'no project class matches%s%s',
        isset($options['layer']) ? ' --layer=' . $options['layer'] : '',
        isset($options['module']) ? ' --module=' . $options['module'] : '',
    ));
}

// Load every project class in a child process first; a class that fails there is never loaded here.
$log(sprintf('Loading %d project classes in a child process ...', count($projectFiles)));
$loadCheck = coverageGapsLoadCheck($projectRoot . '/vendor/autoload.php', $root, $kernel->projectOrgs, array_values($projectFiles));
$kernel->setLoadCheck(array_values(array_diff($projectFiles, array_keys($loadCheck['failed']))), $loadCheck['failed']);

$log('Scanning src/{' . implode(',', $kernel->projectOrgs) . '} ...');
$classes = $scanner->scan($root);
$classes = array_values(array_filter($classes, static fn (array $c): bool => !in_array($c['layer'], $skipLayers, true)
    && (!isset($options['layer']) || $c['layer'] === $options['layer'])
    && (!isset($options['module']) || $c['module'] === $options['module'])));
$log(sprintf('%d classes with own methods in scope', count($classes)));

// Every tests/<Org>Test directory is a test namespace: PyzTest by default, another name in a project with its own namespace.
$testDirs = glob($root . '/tests/*Test', GLOB_ONLYDIR) ?: [];
$log($testDirs === []
    ? 'No tests/<Org>Test directory found: every method counts as untested'
    : 'Following calls from tests/{' . implode(',', array_map('basename', $testDirs)) . '} ...');
$seeds = $analyzer->registerTests($testDirs);
foreach ($seeds as $seed) {
    $analyzer->seed($seed);
}
$analyzer->run();

// A seed with mocks reaches nothing through the replaced code; a seed that proves nothing gives "reachable, not proven".
$cleanSeeds = [];
$testedKeys = [];
$notProvenKeys = [];
foreach ($seeds as $seed) {
    $markers = $analyzer->testSeedMarkers($seed);
    if (!$markers['blockFactories'] && $markers['blockFactoryMethods'] === [] && $markers['blockClasses'] === [] && $markers['notProven'] === []) {
        $cleanSeeds[] = $seed;

        continue;
    }
    $blockedClasses = array_flip($markers['blockClasses']);
    $blockedFactoryMethods = array_flip($markers['blockFactoryMethods']);
    $reached = $analyzer->reachable([$seed], static function (array $node) use ($markers, $blockedClasses, $blockedFactoryMethods): bool {
        [$runtime, $declaring, $method] = $node;
        $runtime = Kernel::base($runtime);
        $isFactory = str_ends_with($runtime, 'Factory');

        return ($isFactory && ($markers['blockFactories'] || isset($blockedFactoryMethods[$method])))
            || isset($blockedClasses[$runtime]) || isset($blockedClasses[$declaring]);
    });
    if ($markers['notProven'] === []) {
        $testedKeys += $reached;

        continue;
    }
    foreach (array_keys($reached) as $key) {
        foreach ($markers['notProven'] as $reason) {
            $notProvenKeys[$key][$reason] = true;
        }
    }
}
$testedKeys += $analyzer->reachable($cleanSeeds);
$tested = [];
foreach (array_keys($testedKeys) as $key) {
    [, $declaring, $method] = $analyzer->nodes[$key];
    $tested[$declaring][$method] = true;
}
$notProven = [];
foreach ($notProvenKeys as $key => $reasons) {
    [, $declaring, $method] = $analyzer->nodes[$key];
    if (!isset($tested[$declaring][$method])) {
        $notProven[$declaring][$method] = array_values(array_unique(array_merge($notProven[$declaring][$method] ?? [], array_keys($reasons))));
    }
}
$log(sprintf('%d test classes, %d reached methods, %d reachable but not proven', $analyzer->testFileCount(), count($testedKeys), count($notProvenKeys)));

// Thin plugins: a plugin method that only delegates to tested project code counts as covered.
$inScope = array_flip(array_column($classes, 'class'));
$coveredByDelegates = [];
foreach ($classes as $class) {
    if (!str_ends_with($class['class'], 'Plugin')) {
        continue;
    }
    foreach ($class['methods'] as $method) {
        if (isset($tested[$class['class']][$method])) {
            continue;
        }
        $callees = array_filter($analyzer->delegateCallees($class['class'], $method) ?? [], static fn (array $c): bool => isset($inScope[$c[0]]));
        if ($callees !== [] && !array_filter($callees, static fn (array $c): bool => !isset($tested[$c[0]][$c[1]]))) {
            $coveredByDelegates[$class['class']][$method] = true;
        }
    }
}

// Skip rules: bootstraps, routing plugins, stub-only client methods, declarative methods, (optionally) UI definitions.
// Skipped methods are not listed and not counted.
$skipRules = new SkipRules($kernel, $analyzer, isset($options['skip-ui-definitions']));
$skipped = [];
foreach ($classes as $class) {
    $reasons = $skipRules->skippedMethodsOf($class);
    if ($reasons !== []) {
        $skipped[$class['class']] = $reasons;
    }
}
$skippedByReason = static function (string $reason) use ($skipped): array {
    $result = [];
    foreach ($skipped as $class => $reasons) {
        $methods = array_keys(array_filter($reasons, static fn (string $r): bool => $r === $reason));
        if ($methods !== []) {
            $result[$class] = $methods;
        }
    }

    return $result;
};
$withoutSkipped = static fn (array $class): array => array_values(array_filter(
    $class['methods'],
    static fn (string $m): bool => !isset($skipped[$class['class']][$m]),
));

$gaps = [];
foreach ($classes as $class) {
    $class['methods'] = $withoutSkipped($class);
    $missing = array_values(array_filter($class['methods'], static fn (string $m): bool => !isset($tested[$class['class']][$m])
        && !isset($coveredByDelegates[$class['class']][$m])));
    if ($missing === [] || (isset($options['only-untested']) && count($missing) !== count($class['methods']))) {
        continue;
    }
    $class['missing'] = $missing;
    $class['untested'] = count($missing) === count($class['methods']);
    $gaps[] = $class;
}

// Unused code: production call graph over all project code (tests excluded), liveness from framework roots.
$unused = [];
if (!isset($options['no-unused'])) {
    $log('Building production call graph for unused-code detection ...');
    $analyzer->resetGraph(static fn (string $runtime): bool => $kernel->isProject($runtime) && !$isIgnoredLayer($runtime));
    foreach ($projectFilesInUse as $path => $class) {
        $rc = $kernel->refl($class);
        if ($rc === null || $rc->getFileName() !== $path || $rc->isInterface()) {
            continue;
        }
        // Concrete classes also get their inherited project methods, so template methods (`$this->getX()` in an
        // abstract parent) resolve to the subclass implementation.
        foreach ($rc->getMethods() as $method) {
            $declaring = $method->getDeclaringClass()->getName();
            if ($declaring === $class || (!$rc->isAbstract() && $kernel->isProject($declaring))) {
                $key = $analyzer->node($class, $method->getName());
                if ($key !== null) {
                    $analyzer->seed($key);
                }
            }
        }
    }
    $analyzer->run();

    $referencedNames = $analyzer->unresolvedNames + $report->namesReferencedAsText($root, $projectFilesInUse, $skipLayers);
    $queue = [];
    foreach (array_keys($analyzer->processed) as $key) {
        [, $declaring, $method] = $analyzer->nodes[$key];
        if (isset($referencedNames[$method]) || $report->isFrameworkCalled($declaring, $method)) {
            $queue[] = $key;
        }
    }
    $live = array_fill_keys($queue, true);
    while ($queue) {
        foreach (array_keys($analyzer->edges[array_pop($queue)] ?? []) as $to) {
            if (!isset($live[$to])) {
                $live[$to] = true;
                $queue[] = $to;
            }
        }
    }
    $liveMethods = [];
    foreach (array_keys($live) as $key) {
        $liveMethods[$analyzer->nodes[$key][1]][$analyzer->nodes[$key][2]] = true;
    }
    $referencedClasses = $report->referencedClasses($root, $projectFilesInUse);
    foreach ($classes as $class) {
        $isReferenced = isset($referencedClasses[$class['class']]) || $report->isKernelResolved($class['class']);
        $dead = !$isReferenced
            ? $class['allMethods']
            : array_values(array_filter($class['allMethods'], static fn (string $m): bool => !isset($liveMethods[$class['class']][$m])));
        if ($dead !== []) {
            $unused[$class['class']] = $dead;
        }
    }
    $log(sprintf('%d live methods, %d unused methods in %d classes', count($live), array_sum(array_map('count', $unused)), count($unused)));
}

// --hide-unused: unused methods are neither listed nor counted; classes left without methods disappear.
$hiddenUnused = 0;
if (isset($options['hide-unused']) && $unused !== []) {
    $hiddenUnused = array_sum(array_map('count', $unused));
    $withoutUnused = static function (array $item, string $key) use ($unused): array {
        $item[$key] = array_values(array_diff($item[$key], $unused[$item['class']] ?? []));

        return $item;
    };
    $classes = array_values(array_filter(
        array_map(static fn (array $c): array => $withoutUnused($withoutUnused($c, 'methods'), 'allMethods'), $classes),
        static fn (array $c): bool => $c['allMethods'] !== [],
    ));
    $gaps = array_values(array_filter(
        array_map(static fn (array $g): array => $withoutUnused($withoutUnused($g, 'methods'), 'missing'), $gaps),
        static fn (array $g): bool => $g['missing'] !== [],
    ));
    foreach ($gaps as $index => $gap) {
        $gaps[$index]['untested'] = count($gap['missing']) === count($gap['methods']);
    }
    $unused = [];
}

// Classes and files that could not be analysed: load failures in the child process, parse errors.
foreach (array_keys($projectFilesInScope) as $path) {
    $ast->check($path);
}
$notAnalysed = [];
$relative = static fn (string $path): string => str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
$testDirPrefixes = array_map(static fn (string $dir): string => $dir . '/', $testDirs);
foreach ($ast->errors as $path => $error) {
    $isTest = array_filter($testDirPrefixes, static fn (string $prefix): bool => str_starts_with($path, $prefix)) !== [];
    $class = $projectFiles[$path] ?? null;
    if (!$isTest && ($class === null || !isset($projectFilesInScope[$path]))) {
        continue;
    }
    $notAnalysed[] = ['kind' => $isTest ? 'test file' : 'class', 'name' => $isTest ? $relative($path) : $class, 'file' => $relative($path), 'reason' => 'parse error: ' . $error];
}
$classFiles = array_flip($projectFilesInScope);
foreach ($kernel->unloadable as $class => $reason) {
    $path = $classFiles[$class] ?? null;
    if ($path === null || isset($ast->errors[$path])) {
        continue;
    }
    $notAnalysed[] = ['kind' => 'class', 'name' => $class, 'file' => $relative($path), 'reason' => str_replace($root . '/', '', $reason)];
}
foreach ($loadCheck['absent'] as $class) {
    $path = $classFiles[$class] ?? null;
    if ($path === null || isset($ast->errors[$path])) {
        continue;
    }
    $declared = array_map(
        static fn (Stmt\ClassLike $node): string => (string)$node->namespacedName?->toString(),
        (new NodeFinder())->findInstanceOf($ast->file($path), Stmt\ClassLike::class),
    );
    if ($declared !== [] && !in_array($class, $declared, true)) {
        $notAnalysed[] = ['kind' => 'class', 'name' => $class, 'file' => $relative($path), 'reason' => 'the file declares ' . implode(', ', $declared) . ', not the class its path names'];
    }
}
usort($notAnalysed, static fn (array $a, array $b): int => [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']]);
$notAnalysedClasses = count(array_filter($notAnalysed, static fn (array $n): bool => $n['kind'] === 'class'));
$notAnalysedTestFiles = count($notAnalysed) - $notAnalysedClasses;
if ($notAnalysed !== []) {
    $log(sprintf('Could not analyse %d classes and %d test files', $notAnalysedClasses, $notAnalysedTestFiles));
}

if (isset($options['why']) && !isset(array_column($classes, null, 'class')[$options['why']])) {
    $why = array_values(array_filter($notAnalysed, static fn (array $n): bool => $n['name'] === $options['why']));
    coverageGapsUsageError($why !== []
        ? "--why: {$options['why']} could not be analysed: {$why[0]['reason']}"
        : "--why: {$options['why']} is not a project class with own methods in the scope of this run");
}

// Entry points: build a module-restricted graph from Facade/Client/Service/Plugin/Controller/Console entries.
$entriesByClass = [];
$unitTestSuggestions = [];
$entryKeys = [];
if (!isset($options['no-entries']) && $gaps !== []) {
    $report->collectRegistrations($projectFilesInUse);
    $modules = [];
    foreach ($gaps as $gap) {
        $modules[$gap['layer'] . '|' . $gap['module']] = [$gap['layer'], $gap['module']];
    }
    $moduleNames = array_flip(array_column($modules, 1));
    $analyzer->resetGraph(static fn (string $runtime): bool => isset($moduleNames[$kernel->moduleOf($runtime)['module'] ?? '']) && !$isIgnoredLayer($runtime));

    $log(sprintf('Building entry-point graph for %d modules ...', count($modules)));
    $entryClasses = [];
    foreach ($modules as [$layer, $module]) {
        foreach (['getFacade' => 'Business', 'getClient' => '', 'getService' => ''] as $getter => $part) {
            $class = $kernel->getterTarget($layer, $module, $part, $getter);
            if ($class !== null) {
                $entryClasses[$class] = true;
            }
        }
        foreach ($report->registeredPluginsOf($layer, $module) as $plugin) {
            $entryClasses[$plugin] = true;
        }
    }
    foreach ($classes as $class) {
        if (isset($modules[$class['layer'] . '|' . $class['module']])
            && preg_match('/(Plugin|Console|Widget|Facade|Client|Service)$/', $class['class'])) {
            $entryClasses[$class['class']] = true;
        }
    }
    foreach ($modules as [$layer, $module]) {
        foreach ($kernel->controllersOf($layer, $module) as $controller) {
            $entryClasses[$controller] = true;
        }
    }

    foreach (array_keys($entryClasses) as $class) {
        if ($isIgnoredLayer($class)) {
            continue;
        }
        $rc = $kernel->refl($class);
        if ($rc === null || !$kernel->isConcrete($class)) {
            continue;
        }
        foreach ($rc->getMethods() as $method) {
            $name = $method->getName();
            $isEntry = $method->isPublic() ? !str_starts_with($name, '__') : $name === 'execute';
            if (str_ends_with($class, 'Widget') && $name === '__construct') {
                $isEntry = true;
            }
            $key = $isEntry ? $analyzer->node($class, $name) : null;
            if ($key !== null) {
                $entryKeys[$key] = true;
                $analyzer->seed($key);
            }
        }
    }
    $analyzer->run();
    $log(sprintf('%d entry methods, %d reachable methods', count($entryKeys), count($analyzer->processed)));

    $reverse = [];
    foreach ($analyzer->edges as $from => $targets) {
        foreach (array_keys($targets) as $to) {
            $reverse[$to][$from] = true;
        }
    }
    $nodesByDeclaring = [];
    foreach (array_keys($analyzer->processed) as $key) {
        $nodesByDeclaring[$analyzer->nodes[$key][1]][$analyzer->nodes[$key][2]][] = $key;
    }

    foreach ($gaps as $gap) {
        $queue = [];
        foreach ($gap['missing'] as $method) {
            array_push($queue, ...($nodesByDeclaring[$gap['class']][$method] ?? []));
        }
        $visited = array_fill_keys($queue, null);
        $found = [];
        while ($queue) {
            $key = array_shift($queue);
            if (isset($entryKeys[$key])) {
                $found[$key] = true;

                continue;
            }
            foreach (array_keys($reverse[$key] ?? []) as $caller) {
                if (!array_key_exists($caller, $visited)) {
                    $visited[$caller] = $key;
                    $queue[] = $caller;
                }
            }
        }
        $entriesByClass[$gap['class']] = array_keys($found);

        // Glue/Yves: when only controller actions (HTTP) reach the class, suggest a unit test of the class the
        // controller calls (found on the path), created through the module factory.
        $isHttpOnly = $found !== [] && in_array($gap['layer'], ['Glue', 'Yves'], true)
            && !array_filter(array_keys($found), static fn (string $entry): bool => !str_ends_with(Kernel::base($analyzer->nodes[$entry][0]), 'Controller'));
        if ($isHttpOnly) {
            foreach (array_keys($found) as $entry) {
                $hop = $visited[$entry];
                while ($hop !== null && preg_match('/(Factory|Controller)$/', Kernel::base($analyzer->nodes[$hop][0]))) {
                    $hop = $visited[$hop];
                }
                if ($hop === null) {
                    continue;
                }
                $testClass = Kernel::base($analyzer->nodes[$hop][0]);
                $test = $testClass . '::' . $analyzer->nodes[$hop][2];
                $unitTestSuggestions[$gap['class']][$test] ??= [
                    'test' => $test,
                    'class' => $testClass,
                    'createWith' => $report->factoryMethodCreating($testClass),
                    'reachedFrom' => [],
                ];
                $unitTestSuggestions[$gap['class']][$test]['reachedFrom'][] = Kernel::base($analyzer->nodes[$entry][0]) . '::' . $analyzer->nodes[$entry][2];
            }
        }

        if (($options['why'] ?? null) === $gap['class']) {
            fwrite(STDERR, sprintf("\n--why %s: %d graph nodes of this class, %d entry points\n", $gap['class'], count(array_filter(array_keys($visited), static fn ($k) => $visited[$k] === null)), count($found)));
            foreach (array_keys($found) as $entry) {
                $path = [];
                for ($key = $entry; $key !== null; $key = $visited[$key]) {
                    $path[] = $report->nodeLabel($key);
                }
                fwrite(STDERR, '  ' . implode("\n    → ", $path) . "\n");
            }
        }
    }
}
if (isset($options['why']) && !in_array($options['why'], array_column($gaps, 'class'), true)) {
    fwrite(STDERR, "\n--why {$options['why']}: every counted method of this class is reached by a test or skipped\n");
}

// ---------------------------------------------------------------------------------------------------------------------

$label = static fn (string $key): string => Kernel::base($analyzer->nodes[$key][0]) . '::' . $analyzer->nodes[$key][2];
$projectGaps = array_values(array_filter($gaps, static fn (array $g): bool => $g['core'] === null));
$extendedGaps = array_values(array_filter($gaps, static fn (array $g): bool => $g['core'] !== null));
$sortGaps = static function (array $items): array {
    usort($items, static fn (array $a, array $b): int => [!$a['untested'], $a['layer'], $a['module'], $a['class']] <=> [!$b['untested'], $b['layer'], $b['module'], $b['class']]);

    return $items;
};
$projectGaps = $sortGaps($projectGaps);
$extendedGaps = $sortGaps($extendedGaps);
$allMethods = array_sum(array_map(static fn (array $c): int => count($withoutSkipped($c)), $classes));
$missingMethods = array_sum(array_map(static fn (array $g): int => count($g['missing']), $gaps));
$notProvenMethods = array_sum(array_map(static fn (array $g): int => count(array_intersect($g['missing'], array_keys($notProven[$g['class']] ?? []))), $gaps));
$extendedUntested = count(array_filter($extendedGaps, static fn (array $g): bool => $g['untested']));
$projectUntested = count(array_filter($projectGaps, static fn (array $g): bool => $g['untested']));
$unusedMethods = array_sum(array_map('count', $unused));
$counts = [
    'core-extended without test' => $extendedUntested,
    'core-extended partial' => count($extendedGaps) - $extendedUntested,
    'project without test' => $projectUntested,
    'project partial' => count($projectGaps) - $projectUntested,
    'unused methods' => $unusedMethods,
    'not analysed' => count($notAnalysed),
];
$exitCode = match (true) {
    $notAnalysed !== [] => COVERAGE_GAPS_EXIT_NOT_ANALYSED,
    $extendedUntested > 0 => COVERAGE_GAPS_EXIT_CORE_EXTENDED_UNTESTED,
    default => COVERAGE_GAPS_EXIT_OK,
};

$result = [
    'verdict' => $exitCode === COVERAGE_GAPS_EXIT_OK ? 'OK' : 'FOUND',
    'exitCode' => $exitCode,
    'counts' => $counts,
    'projectOrgs' => $kernel->projectOrgs,
    'ignoredLayers' => $skipLayers,
    'summary' => [
        'classes' => count($classes),
        'methods' => $allMethods,
        'testedMethods' => $allMethods - $missingMethods,
        'notProvenMethods' => $notProvenMethods,
        'hiddenUnusedMethods' => $hiddenUnused,
    ],
    'notAnalysed' => $notAnalysed,
    'gaps' => [],
    'entryPoints' => [],
    'unused' => [],
    'coveredByDelegates' => array_map('array_keys', $coveredByDelegates),
    'skippedStubOnly' => $skippedByReason(SkipRules::REASON_STUB_ONLY),
    'skipped' => $skipped,
];
foreach ($gaps as $gap) {
    $entries = $entriesByClass[$gap['class']] ?? [];
    $result['gaps'][$gap['class']] = [
        'section' => $gap['core'] === null ? 'project' : 'core-extended',
        'core' => $gap['core'],
        'file' => $gap['file'],
        'untested' => $gap['untested'],
        'missing' => $gap['missing'],
        'notProven' => array_intersect_key($notProven[$gap['class']] ?? [], array_flip($gap['missing'])),
        'unusedMissing' => array_values(array_intersect($gap['missing'], $unused[$gap['class']] ?? [])),
        'testVia' => array_values(array_unique(array_map($label, $entries))),
        'suggestedUnitTests' => array_map(static fn (array $s): array => [
            'test' => $s['test'],
            'createWith' => $s['createWith'],
            'reachedFrom' => array_values(array_unique($s['reachedFrom'])),
        ], array_values($unitTestSuggestions[$gap['class']] ?? [])),
    ];
    if ($gap['core'] !== null) {
        foreach ($entries as $entry) {
            $result['entryPoints'][$label($entry)][] = $gap['class'];
        }
    }
}
uksort($result['entryPoints'], static fn (string $a, string $b): int => count($result['entryPoints'][$b]) <=> count($result['entryPoints'][$a]) ?: strcmp($a, $b));
$classInfo = array_column($classes, null, 'class');
foreach ($unused as $class => $dead) {
    $result['unused'][$class] = [
        'methods' => $dead,
        'testsOnly' => array_values(array_filter($dead, static fn (string $m): bool => isset($tested[$class][$m]))),
        'wholeClass' => count($dead) === count($classInfo[$class]['allMethods']),
    ];
}

$reportFile = spryker_upgrade_state_dir($projectRoot) . '/coverage-gaps-report.json';
file_put_contents($reportFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    exit($exitCode);
}

/**
 * One section of the summary: a title line, at most $limit lines, and the count of the lines left out.
 *
 * @param list<string> $lines
 */
$printSection = static function (string $title, array $lines) use ($limit): void {
    echo "\n$title\n";
    foreach (array_slice($lines, 0, $limit) as $line) {
        echo "  $line\n";
    }
    if (count($lines) > $limit) {
        printf("  (%d more in the report)\n", count($lines) - $limit);
    }
};
$methodList = static fn (array $gap): string => implode(', ', array_map(static fn (string $m): string => "$m()"
    . (in_array($m, $unused[$gap['class']] ?? [], true) ? ' [unused]' : '')
    . (isset($notProven[$gap['class']][$m]) ? ' [not proven: ' . implode(', ', $notProven[$gap['class']][$m]) . ']' : ''), $gap['missing']));
$testVia = static function (array $gap) use ($report, $analyzer, $entriesByClass, $unitTestSuggestions, $options, $unused): string {
    if (!array_diff($gap['missing'], $unused[$gap['class']] ?? [])) {
        return 'unused in production code: delete instead of testing';
    }
    if (isset($options['no-entries'])) {
        return '';
    }
    $suggestion = array_values($unitTestSuggestions[$gap['class']] ?? [])[0] ?? null;
    if ($suggestion !== null) {
        $method = substr($suggestion['test'], strrpos($suggestion['test'], ':') + 1);

        return ($suggestion['class'] === $gap['class'] ? 'unit test of this class: ' : 'unit test of ')
            . $report->short($suggestion['class']) . "::$method()"
            . ($suggestion['createWith'] !== null ? ", create it with {$suggestion['createWith']}" : '');
    }
    $entries = $entriesByClass[$gap['class']] ?? [];
    if ($entries === []) {
        return 'no entry point found (dead code or wired dynamically)';
    }
    $labels = [];
    foreach ($entries as $entry) {
        $registrations = $report->registrationsOf($gap['class']);
        $labels[] = Kernel::base($analyzer->nodes[$entry][0]) === $gap['class']
            ? 'directly' . ($registrations !== [] ? ' (registered in ' . implode(', ', $registrations) . ')' : '')
            : $report->nodeLabel($entry);
    }
    $labels = array_values(array_unique($labels));

    return 'test via ' . $labels[0] . (count($labels) > 1 ? sprintf(' (+%d more)', count($labels) - 1) : '');
};
$gapLine = static function (array $gap) use ($methodList, $testVia): string {
    $total = count($gap['methods']);
    $via = $testVia($gap);

    return ($gap['untested'] ? '✗ ' : '◐ ') . $gap['class']
        . ($gap['untested'] ? '' : sprintf(' (%d/%d methods tested)', $total - count($gap['missing']), $total))
        . ($gap['core'] !== null ? ' extends ' . $gap['core'] : '')
        . ': ' . $methodList($gap) . ($via !== '' ? "; $via" : '');
};

echo sprintf(
    "coverage-gaps: %s %s\n",
    $result['verdict'],
    implode(', ', array_map(static fn (string $name, int $count): string => "$name $count", array_keys($counts), $counts)),
);
printf(
    "Scope: %s, %s, %d reached by tests (%.1f%%), %d reachable but not proven, %d skipped, %d covered by thin delegates%s.\n",
    coverageGapsPlural(count($classes), 'class', 'classes'),
    coverageGapsPlural($allMethods, 'method', 'methods'),
    $allMethods - $missingMethods,
    $allMethods ? ($allMethods - $missingMethods) / $allMethods * 100 : 0,
    $notProvenMethods,
    array_sum(array_map('count', $skipped)),
    array_sum(array_map('count', $coveredByDelegates)),
    isset($options['hide-unused']) ? sprintf(', %d unused hidden', $hiddenUnused) : '',
);
if ($skipLayers !== []) {
    echo 'Ignored layers: ' . implode(', ', $skipLayers) . ".\n";
}
if ($notAnalysed !== []) {
    $printSection(
        'Could not analyse: ' . coverageGapsPlural($notAnalysedClasses, 'class', 'classes')
        . ($notAnalysedTestFiles > 0 ? ', ' . coverageGapsPlural($notAnalysedTestFiles, 'test file', 'test files') : '')
        . ' (the counts above leave them out)',
        array_map(static fn (array $n): string => $n['name'] . ($n['kind'] === 'class' ? " ({$n['file']})" : '') . ': ' . $n['reason'], $notAnalysed),
    );
}
$printSection(
    sprintf('Core-extended classes with untested methods: %d (%d without any test)', count($extendedGaps), $extendedUntested),
    array_map($gapLine, $extendedGaps),
);
$printSection(
    sprintf('Project classes with untested methods: %d (%d without any test)', count($projectGaps), $projectUntested),
    array_map($gapLine, $projectGaps),
);
if (!isset($options['no-entries']) && $result['entryPoints'] !== []) {
    $printSection(
        sprintf('Entry points that run the untested core-extended classes: %d', count($result['entryPoints'])),
        array_map(static fn (string $entry, array $covered): string => "$entry() runs " . implode(', ', $covered), array_keys($result['entryPoints']), $result['entryPoints']),
    );
}
if (!isset($options['no-unused']) && !isset($options['hide-unused']) && $unused !== []) {
    $printSection(
        'Unused code: ' . coverageGapsPlural($unusedMethods, 'method', 'methods') . ' in ' . coverageGapsPlural(count($unused), 'class', 'classes'),
        array_map(static fn (string $class, array $u): string => $class . ': ' . implode(', ', array_map(static fn (string $m): string => "$m()"
            . (in_array($m, $u['testsOnly'], true) ? ' [tests only]' : ''), $u['methods'])) . ($u['wholeClass'] ? ' (whole class)' : ''), array_keys($result['unused']), $result['unused']),
    );
}
if (isset($options['show-skipped']) && $skipped !== []) {
    $lines = [];
    foreach ([SkipRules::REASON_BOOTSTRAP, SkipRules::REASON_ROUTING, SkipRules::REASON_STUB_ONLY, SkipRules::REASON_UI, SkipRules::REASON_DECLARATIVE] as $reason) {
        foreach ($skippedByReason($reason) as $class => $methods) {
            $lines[] = "$class::" . implode('(), ', $methods) . "() — $reason";
        }
    }
    $printSection(sprintf('Skipped methods: %d', array_sum(array_map('count', $skipped))), $lines);
}
echo "\nFull report: " . spryker_upgrade_rel($reportFile, $projectRoot) . "\n";
exit($exitCode);
