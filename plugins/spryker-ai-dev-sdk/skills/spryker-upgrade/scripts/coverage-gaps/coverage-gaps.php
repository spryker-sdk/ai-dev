<?php

/**
 * Coverage gaps finder for the project code in src/ (every dir except Generated, Orm, SprykerConfig).
 * Static analysis, no test run needed.
 * Full documentation of the flags and the logic: coverage-gaps.md, next to this file.
 * Requirements: PHP >= 8.1, Composer vendor/, nikic/php-parser ^5.0, a Spryker project (checked at startup).
 *
 * Reports:
 *   1. Project classes (no core parent) whose methods are not reached by any test.
 *   2. Classes extending a core class whose own/overridden methods are not reached by any test.
 *   3. Entry points (Facade / Client / Service / Plugin / Controller) through which the untested
 *      core-extended classes are executed - i.e. where to write a test for the new behaviour.
 *   Rules: thin plugins that only delegate to tested project code count as covered.
 *   Skipped (not listed, not counted): application bootstraps (*Bootstrap), routing/resource plugins (Glue resource and
 *   route plugins, Yves route providers), Client methods that only forward to a Zed stub, declarative methods whose
 *   whole body returns constant data (constants, scalars, X::class, arrays/transfers built from them) and, with
 *   --skip-ui-definitions, form field definitions, form expander plugins and Table::configure().
 *   4. Unused code: methods not reachable from any framework entry in production code (tests excluded) -
 *      delete them instead of writing tests.
 *
 * "Reached by a test" = a method is called from a test class in tests/<Org>Test (mocks excluded) directly, or transitively:
 * Facade -> Factory -> new Model(...) -> constructor dependencies -> plugin stacks from DependencyProviders
 * -> Zed stubs -> Gateway controllers. HTTP-based tests (Glue Cest, Cypress) are not followed.
 *
 * Usage (from the project root; $UP is the scripts directory of the spryker-upgrade skill). The project root is the
 * working directory or SPRYKER_PROJECT_ROOT, never the location of this file. The script loads project classes, so
 * run it with a PHP that can load them: host PHP, or `docker/sdk cli php <path>` when this file is inside the project.
 *   php $UP/coverage-gaps/coverage-gaps.php
 *   php $UP/coverage-gaps/coverage-gaps.php --layer=Zed --module=Cart --only-untested --no-entries --no-unused --hide-unused --skip-ui-definitions --show-skipped --skip-layer=Yves,Glue --why=Pyz/Zed/Cart/Business/Model/Foo --root=. --json > report.json
 *
 * Flags (all optional, combinable):
 *   --layer=Zed        only one application layer: Zed|Client|Yves|Glue|Service|Shared
 *   --module=Cart      only one module
 *   --only-untested    hide partially tested classes, keep classes without any test
 *   --no-entries       skip the "test via" lookup and section 3 (faster)
 *   --no-unused        skip the unused-code detection and section 4
 *   --hide-unused      detect unused code, then drop it from the lists and counts (no section 4); wins over --no-unused
 *   --skip-layer=Yves,Glue  ignore layers the project does not use (comma-separated or repeated): their classes are
 *                      not reported or counted, are no production callers for the unused-code check (code only they
 *                      call becomes unused) and are no entry points for "test via"
 *   --show-skipped     list the skipped methods per reason in the summary (by default only the counts)
 *   --skip-ui-definitions  also skip form definitions (buildForm, configureOptions, getBlockPrefix, add* methods
 *                      without logic), whole form expander plugins (*FormExpanderPluginInterface) and Table::configure()
 *   --why=FQCN         print to stderr the call paths from entry points to the untested methods of FQCN;
 *                      write "/" instead of "\" - docker/sdk strips backslashes
 *   --root=DIR         analyse another project dir (its src/<Org> dirs are autoloaded; vendor/ stays this project's)
 *   --json             print the result as JSON instead of the text report
 *   --help             print the flags
 *
 * Tests (fixture project with one example per rule: coverage-gaps/fixture/README.md):
 *   vendor/bin/phpunit $UP/coverage-gaps/coverage-gaps/CoverageGapsTest.php
 * Progress goes to stderr, the report to stdout.
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

ini_set('memory_limit', '-1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../bootstrap.php';

$root = spryker_upgrade_project_root();
defined('APPLICATION_ROOT_DIR') || define('APPLICATION_ROOT_DIR', $root);

/**
 * Requirements (see coverage-gaps.md):
 *   - PHP >= 8.1
 *   - Composer dependencies installed (vendor/autoload.php in the project root)
 *   - nikic/php-parser ^5.0      composer require --dev nikic/php-parser:^5.0
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

    $problems = [];
    $installed = static fn (string $package): ?string => class_exists(Composer\InstalledVersions::class) && Composer\InstalledVersions::isInstalled($package)
        ? Composer\InstalledVersions::getPrettyVersion($package)
        : null;
    if (!class_exists(PhpParser\ParserFactory::class)) {
        $problems[] = 'nikic/php-parser is not installed. Run: composer require --dev nikic/php-parser:^5.0';
    } elseif (!method_exists(PhpParser\ParserFactory::class, 'createForNewestSupportedVersion')) {
        $problems[] = sprintf(
            'nikic/php-parser ^5.0 is required, installed: %s. Run: composer require --dev nikic/php-parser:^5.0',
            $installed('nikic/php-parser') ?? 'an older version',
        );
    }
    if (!class_exists('Spryker\\Zed\\Kernel\\Business\\AbstractFacade')) {
        $problems[] = 'spryker/kernel is not installed: this tool analyses Spryker projects (Spryker\\Zed\\Kernel\\Business\\AbstractFacade not found).';
    }

    return $problems;
}

$requirementProblems = coverageGapsRequirementProblems($root);
if ($requirementProblems !== []) {
    fwrite(STDERR, "coverage-gaps.php: requirements not met\n  - " . implode("\n  - ", $requirementProblems) . "\n");
    exit(2);
}

final class Ast
{
    private Parser $parser;

    private NodeFinder $finder;

    /** @var array<string, array{stmts: array, methods: array<string, list<Stmt\ClassMethod>>}> */
    private array $cache = [];

    public function __construct(private int $limit = 600)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    public function file(string $path): array
    {
        return $this->load($path)['stmts'];
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
        } catch (Throwable) {
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

    public function exists(string $class): bool
    {
        $class = self::base($class);
        if (!isset($this->exists[$class])) {
            try {
                $this->exists[$class] = class_exists($class) || interface_exists($class) || trait_exists($class);
            } catch (Throwable) {
                $this->exists[$class] = false;
            }
        }

        return $this->exists[$class];
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
     * @return list<string> seed node keys (all methods of test classes)
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
                    $methods[$method->name->toString()] = $method;
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
            foreach (array_keys($test['methods']) as $method) {
                $key = $this->node(self::TEST_PREFIX . $fqcn, $method);
                if ($key !== null) {
                    $seeds[] = $key;
                }
            }
        }

        return $seeds;
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
                if ($this->kernel->isTracked($class) && is_subclass_of($class, 'Symfony\\Component\\Form\\FormTypeInterface')) {
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
            if ($rc === null || $rc->getFileName() !== $path || $rc->isInterface() || $rc->isTrait() || $rc->isAbstract() || $rc->isEnum()) {
                continue;
            }
            $methods = [];
            $allMethods = [];
            foreach ($rc->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->getFileName() !== $path || $method->getName() === '__construct') {
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

$options = getopt('', ['layer:', 'module:', 'only-untested', 'no-entries', 'no-unused', 'hide-unused', 'skip-ui-definitions', 'show-skipped', 'skip-layer:', 'why:', 'root:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Usage: php coverage-gaps.php [--layer=Zed|Client|Yves|Glue|Service|Shared] [--module=Cart] [--only-untested]\n"
        . "       [--no-entries] [--no-unused] [--hide-unused] [--skip-ui-definitions] [--show-skipped] [--skip-layer=Yves,Glue]\n"
        . "       [--why=FQCN] [--root=DIR] [--json]\n"
        . "  --skip-layer=L   ignore layers the project does not use (comma-separated or repeated): not reported, not counted,\n"
        . "                   not production callers for unused code, not entry points\n"
        . "  --only-untested  hide partially tested classes\n"
        . "  --no-entries     skip the 'test via' lookup and section 3\n"
        . "  --no-unused      skip unused-code detection and section 4\n"
        . "  --hide-unused    detect unused code and drop it from the lists and counts (wins over --no-unused)\n"
        . "  --skip-ui-definitions  also skip form field definitions (buildForm, add*Field without logic), form expander plugins and Table::configure()\n"
        . "  --show-skipped   list the skipped methods per reason (by default only the counts are printed)\n"
        . "  --why=FQCN       print the call paths from entry points to the untested methods of FQCN (use / instead of \\ when running via docker/sdk)\n"
        . "  --root=DIR       analyse another project dir (its src/<Org> dirs are autoloaded, vendor/ stays this project's)\n"
        . "  --json           print the result as JSON instead of the text report\n";
    exit(0);
}
if (isset($options['hide-unused'])) {
    unset($options['no-unused']);
}
if (isset($options['root'])) {
    $root = realpath($options['root']) ?: $options['root'];
    if (!is_dir($root . '/src')) {
        fwrite(STDERR, "--root: no src/ directory in $root\n");
        exit(1);
    }
    $rootOrgs = Kernel::detectProjectOrgs($root);
    spl_autoload_register(static function (string $class) use ($root, $rootOrgs): void {
        $org = strstr($class, '\\', true);
        $file = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
        if (in_array($org, $rootOrgs, true) && is_file($file)) {
            require_once $file;
        }
    }, true, true);
}
if (isset($options['why'])) {
    $options['why'] = ltrim(str_replace('/', '\\', $options['why']), '\\');
}
$skipLayers = [];
foreach ((array)($options['skip-layer'] ?? []) as $value) {
    foreach (array_filter(array_map('trim', explode(',', (string)$value))) as $layer) {
        $layer = ucfirst(strtolower($layer));
        if (!in_array($layer, ['Zed', 'Client', 'Yves', 'Glue', 'Service', 'Shared'], true)) {
            fwrite(STDERR, "--skip-layer: unknown layer '$layer', use Zed, Client, Yves, Glue, Service or Shared\n");
            exit(1);
        }
        $skipLayers[$layer] = $layer;
    }
}
$skipLayers = array_values($skipLayers);
$log = static fn (string $message) => fwrite(STDERR, sprintf("[%5.1fs] %s\n", microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], $message));

$kernel = new Kernel(Kernel::detectProjectOrgs($root));
$ast = new Ast();
$analyzer = new Analyzer($kernel, $ast);
$report = new Report($kernel, $ast, $analyzer);
$scanner = new ProjectScanner($kernel);
$isIgnoredLayer = static fn (string $class): bool => in_array($kernel->moduleOf($class)['layer'] ?? '', $skipLayers, true);
$projectFiles = $scanner->projectFiles($root);
// --skip-layer: code of ignored layers is neither reported nor a production caller nor an entry point.
$projectFilesInUse = array_filter($projectFiles, static fn (string $class): bool => !$isIgnoredLayer($class));
if ($skipLayers !== []) {
    $log('Ignoring layers: ' . implode(', ', $skipLayers));
}

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
$tested = [];
foreach (array_keys($analyzer->processed) as $key) {
    [, $declaring, $method] = $analyzer->nodes[$key];
    $tested[$declaring][$method] = true;
}
$log(sprintf('%d test classes, %d reached methods', $analyzer->testFileCount(), count($analyzer->processed)));

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

// ---------------------------------------------------------------------------------------------------------------------

$printClass = static function (array $gap) use ($report, $analyzer, $entriesByClass, $unitTestSuggestions, $options, $unused): void {
    $deadMethods = array_flip($unused[$gap['class']] ?? []);
    $total = count($gap['methods']);
    $marker = $gap['untested'] ? '✗' : '◐';
    echo "  $marker {$gap['class']}" . ($gap['untested'] ? '' : sprintf('  (%d/%d methods tested)', $total - count($gap['missing']), $total)) . "\n";
    echo "      file:      {$gap['file']}\n";
    if ($gap['core'] !== null) {
        echo "      extends:   {$gap['core']}\n";
    }
    echo '      untested:  ' . implode(', ', array_map(static fn (string $m): string => "$m()" . (isset($deadMethods[$m]) ? ' [unused]' : ''), $gap['missing'])) . "\n";
    if (!array_diff($gap['missing'], array_keys($deadMethods))) {
        echo "      test via:  — unused in production code: delete instead of testing (see section 4)\n";

        return;
    }
    if (isset($options['no-entries'])) {
        return;
    }
    $entries = $entriesByClass[$gap['class']] ?? [];
    if ($entries === []) {
        echo "      test via:  — no entry point found (not created by a factory/DP reachable from the module API: dead code or wired dynamically)\n";

        return;
    }
    if (isset($unitTestSuggestions[$gap['class']])) {
        foreach (array_values($unitTestSuggestions[$gap['class']]) as $index => $suggestion) {
            echo $index === 0 ? '      test via:  ' : '                 ';
            echo $suggestion['class'] === $gap['class']
                ? 'unit test of this class: ' . $report->short($suggestion['class']) . '::' . substr($suggestion['test'], strrpos($suggestion['test'], ':') + 1) . "()\n"
                : 'unit test of ' . $report->short($suggestion['class']) . '::' . substr($suggestion['test'], strrpos($suggestion['test'], ':') + 1) . "() — it runs this class\n";
            if ($suggestion['createWith'] !== null) {
                echo "                   create it with {$suggestion['createWith']}\n";
            }
            $controllers = array_map(static fn (string $action): string => $report->short(substr($action, 0, strpos($action, '::'))) . substr($action, strpos($action, '::')) . '()', array_unique($suggestion['reachedFrom']));
            echo '                   reached over HTTP from ' . implode(', ', $controllers) . "\n";
        }
        printf("                   (an HTTP test - %s - also covers it, but HTTP tests are not counted by this script)\n", $gap['layer'] === 'Glue' ? 'Glue API Cest, Cypress' : 'Yves acceptance Cest, Cypress');

        return;
    }
    $lines = [];
    $direct = false;
    foreach ($entries as $entry) {
        if (Kernel::base($analyzer->nodes[$entry][0]) === $gap['class']) {
            $direct = true;

            continue;
        }
        $lines[] = $report->nodeLabel($entry);
    }
    if ($direct) {
        $registrations = $report->registrationsOf($gap['class']);
        array_unshift($lines, 'directly (public API of this class)' . ($registrations ? '; registered in ' . implode(', ', $registrations) : ''));
    }
    foreach (array_slice($lines, 0, 4) as $index => $line) {
        echo ($index === 0 ? '      test via:  ' : '                 ') . $line . "\n";
    }
    if (count($lines) > 4) {
        echo '                 … +' . (count($lines) - 4) . " more\n";
    }
};

$printSection = static function (string $title, array $items) use ($printClass): void {
    $untested = count(array_filter($items, static fn (array $g): bool => $g['untested']));
    echo "\n" . str_repeat('=', 100) . "\n";
    printf("%s — %d without any test, %d partially tested\n", $title, $untested, count($items) - $untested);
    echo str_repeat('=', 100) . "\n";
    $currentModule = null;
    usort($items, static fn (array $a, array $b): int => [$a['layer'], $a['module'], !$a['untested'], $a['class']] <=> [$b['layer'], $b['module'], !$b['untested'], $b['class']]);
    foreach ($items as $item) {
        if ($currentModule !== $item['layer'] . '/' . $item['module']) {
            $currentModule = $item['layer'] . '/' . $item['module'];
            echo "\n$currentModule\n";
        }
        $printClass($item);
    }
};

$projectGaps = array_values(array_filter($gaps, static fn (array $g): bool => $g['core'] === null));
$extendedGaps = array_values(array_filter($gaps, static fn (array $g): bool => $g['core'] !== null));

if (isset($options['json'])) {
    $label = static fn (string $key): string => Kernel::base($analyzer->nodes[$key][0]) . '::' . $analyzer->nodes[$key][2];
    $result = [
        'projectOrgs' => $kernel->projectOrgs,
        'ignoredLayers' => $skipLayers,
        'summary' => [
            'classes' => count($classes),
            'methods' => $allMethods = array_sum(array_map(static fn (array $c): int => count($withoutSkipped($c)), $classes)),
            'testedMethods' => $allMethods - array_sum(array_map(static fn (array $g): int => count($g['missing']), $gaps)),
            'hiddenUnusedMethods' => $hiddenUnused,
        ],
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
    $classInfo = array_column($classes, null, 'class');
    foreach ($unused as $class => $dead) {
        $result['unused'][$class] = [
            'methods' => $dead,
            'testsOnly' => array_values(array_filter($dead, static fn (string $m): bool => isset($tested[$class][$m]))),
            'wholeClass' => count($dead) === count($classInfo[$class]['allMethods']),
        ];
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

$printSection('1. Project classes', $projectGaps);
$printSection('2. Classes extending core', $extendedGaps);

if (!isset($options['no-entries'])) {
    $byEntry = [];
    foreach ($extendedGaps as $gap) {
        foreach ($entriesByClass[$gap['class']] ?? [] as $entry) {
            $byEntry[$entry][] = $gap['class'];
        }
    }
    uksort($byEntry, static fn (string $a, string $b): int => count($byEntry[$b]) <=> count($byEntry[$a]) ?: strcmp($a, $b));

    echo "\n" . str_repeat('=', 100) . "\n";
    printf("3. Core entry points that execute the untested overrides — %d entry points\n", count($byEntry));
    echo str_repeat('=', 100) . "\n";
    foreach ($byEntry as $entry => $covered) {
        [$runtime, , $method] = $analyzer->nodes[$entry];
        $runtime = Kernel::base($runtime);
        echo "\n  → " . $runtime . '::' . $method . "()\n";
        foreach ($covered as $class) {
            echo "      covers:  $class\n";
        }
        if (preg_match('/(Facade|Client|Service)$/', $runtime)) {
            $plugins = $report->pluginsCalling($runtime, $method);
            foreach (array_slice($plugins, 0, 3) as $plugin) {
                echo "      plugin:  $plugin\n";
            }
            if (count($plugins) > 3) {
                echo '               … +' . (count($plugins) - 3) . " more plugins\n";
            }
        }
    }
}

if (!isset($options['no-unused']) && !isset($options['hide-unused'])) {
    $classInfo = array_column($classes, null, 'class');
    $wholeClasses = count(array_filter($unused, static fn (array $dead, string $class): bool => count($dead) === count($classInfo[$class]['allMethods']), ARRAY_FILTER_USE_BOTH));
    echo "\n" . str_repeat('=', 100) . "\n";
    printf("4. Unused code — %d methods in %d classes have no caller in production code (%d whole classes)\n", array_sum(array_map('count', $unused)), count($unused), $wholeClasses);
    echo "   [tests only] = called only from tests; the test keeps dead code alive\n";
    echo str_repeat('=', 100) . "\n";
    $currentModule = null;
    foreach ($unused as $class => $dead) {
        $info = $classInfo[$class];
        if ($currentModule !== $info['layer'] . '/' . $info['module']) {
            $currentModule = $info['layer'] . '/' . $info['module'];
            echo "\n$currentModule\n";
        }
        $whole = count($dead) === count($info['allMethods']);
        echo "  ✗ $class" . ($whole ? '  (whole class unused)' : '') . "\n";
        echo "      file:      {$info['file']}\n";
        echo '      unused:    ' . implode(', ', array_map(static fn (string $m): string => "$m()" . (isset($tested[$class][$m]) ? ' [tests only]' : ''), $dead)) . "\n";
    }
}

$allMethods = array_sum(array_map(static fn (array $c): int => count($withoutSkipped($c)), $classes));
$missingMethods = array_sum(array_map(static fn (array $g): int => count($g['missing']), $gaps));
echo "\n" . str_repeat('-', 100) . "\n";
printf(
    "Summary: %d classes / %d methods in scope; %d methods reached by tests (%.1f%%).\n"
    . "         Project: %d untested, %d partial. Core-extended: %d untested, %d partial.\n"
    . (isset($options['hide-unused'])
        ? sprintf("         Unused in production code: %d methods hidden and not counted (--hide-unused).\n", $hiddenUnused)
        : sprintf("         Unused in production code: %d methods in %d classes.\n", array_sum(array_map('count', $unused)), count($unused)))
    . "         Thin plugins counted as covered via their tested delegates: %d methods in %d plugins.\n"
    . "         Skipped (not listed, not counted): %d methods in %d classes.\n",
    count($classes),
    $allMethods,
    $allMethods - $missingMethods,
    $allMethods ? ($allMethods - $missingMethods) / $allMethods * 100 : 0,
    count(array_filter($projectGaps, static fn (array $g): bool => $g['untested'])),
    count(array_filter($projectGaps, static fn (array $g): bool => !$g['untested'])),
    count(array_filter($extendedGaps, static fn (array $g): bool => $g['untested'])),
    count(array_filter($extendedGaps, static fn (array $g): bool => !$g['untested'])),
    array_sum(array_map('count', $coveredByDelegates)),
    count($coveredByDelegates),
    array_sum(array_map('count', $skipped)),
    count($skipped),
);
if ($coveredByDelegates !== []) {
    echo "         Covered via tested delegates (thin plugins):\n";
    foreach ($coveredByDelegates as $plugin => $methods) {
        echo "           - $plugin::" . implode('(), ', array_keys($methods)) . "()\n";
    }
}
foreach ([SkipRules::REASON_BOOTSTRAP, SkipRules::REASON_ROUTING, SkipRules::REASON_STUB_ONLY, SkipRules::REASON_UI, SkipRules::REASON_DECLARATIVE] as $reason) {
    $classesByReason = $skippedByReason($reason);
    if ($classesByReason === []) {
        continue;
    }
    printf("         Skipped — %s: %d methods in %d classes\n", $reason, array_sum(array_map('count', $classesByReason)), count($classesByReason));
    if (!isset($options['show-skipped'])) {
        continue;
    }
    foreach ($classesByReason as $class => $methods) {
        echo "           - $class::" . implode('(), ', $methods) . "()\n";
    }
}
if ($skipped !== [] && !isset($options['show-skipped'])) {
    echo "         Use --show-skipped to list the skipped methods.\n";
}
if ($skipLayers !== []) {
    echo '         Ignored layers: ' . implode(', ', $skipLayers) . " (not reported, not production callers, not entry points).\n";
}
echo "Note: static analysis — HTTP tests (Glue Cest, Cypress) and dynamically resolved calls are not followed.\n";
