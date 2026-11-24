<?php

/**
 * Tests for ../coverage-gaps.php, run against the example project in ./fixture.
 *
 * Run from a directory whose vendor/ has the script's requirements ($UP is the scripts directory of the
 * spryker-upgrade skill):
 *   vendor/bin/phpunit $UP/coverage-gaps/coverage-gaps/CoverageGapsTest.php
 *
 * The script takes vendor/ from the project root. The tests pass it as SPRYKER_PROJECT_ROOT: the value of that
 * variable when it is set, otherwise the nearest directory above this file that has composer.json and vendor/autoload.php.
 *
 * Requires phpunit/phpunit >= 10 (attributes) in addition to the script's requirements (see ../coverage-gaps.md).
 */

declare(strict_types=1);

namespace PyzTest\Tools\CoverageGaps;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverageGapsTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../coverage-gaps.php';

    private const FIXTURE = __DIR__ . '/fixture';

    private const FACADE = 'Example\Zed\Gizmo\Business\GizmoFacade';

    private const MODEL = 'Example\Zed\Gizmo\Business\Model\\';

    private const PLUGIN = 'Example\Zed\Gizmo\Communication\Plugin\\';

    private const FORM = 'Example\Zed\Gizmo\Communication\Form\GizmoForm';

    private const TABLE = 'Example\Zed\Gizmo\Communication\Table\GizmoTable';

    private const REASON_DECLARATIVE = 'declarative: only returns constant data';

    private const REASON_UI = 'form/table definition (--skip-ui-definitions)';

    /**
     * @var array<string, array{stdout: string, stderr: string, exitCode: int}>
     */
    private static array $runs = [];

    public function testGivenSrcDirsWhenScanningThenEveryProjectDirIsAnalysedAndGeneratedOrmConfigAreSkipped(): void
    {
        $result = $this->runJson();

        $this->assertSame(['Example', 'ExampleExtra'], $result['projectOrgs']);
        $this->assertArrayHasKey('ExampleExtra\Zed\Extra\Business\ExtraFacade', $result['gaps']);
        $this->assertSame([], array_filter(array_keys($result['gaps']), static fn (string $class): bool => str_starts_with($class, 'Generated\\')));
    }

    public function testGivenInfrastructureFilesWhenScanningThenFactoriesProvidersStubsControllersInterfacesAndAbstractsAreNotReported(): void
    {
        $result = $this->runJson();

        $reported = array_merge(array_keys($result['gaps']), array_keys($result['unused']));
        $this->assertSame([], preg_grep('/(Factory|DependencyProvider|Stub|Controller|Interface|AbstractGreeter)$/', $reported));
    }

    public function testGivenFacadeTestWhenAnalysingThenOnlyFacadeMethodsWithoutTestAreMissing(): void
    {
        $gap = $this->runJson()['gaps'][self::FACADE];

        $this->assertFalse($gap['untested']);
        $this->assertEqualsCanonicalizing(['format', 'runOtherSteps', 'addItems'], $gap['missing']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideIndirectlyTestedClasses(): array
    {
        return [
            'constructor dependency (promoted property)' => [self::MODEL . 'Rounder'],
            'object configured by a setter in the tested factory method' => [self::MODEL . 'DefaultStep'],
            'plugin stack from the DependencyProvider' => [self::PLUGIN . 'GizmoExpanderPlugin'],
            'template method called from the abstract parent' => [self::MODEL . 'HelloGreeter'],
            'thin plugin delegating to tested code' => [self::PLUGIN . 'CalculatorItemExpanderPlugin'],
        ];
    }

    #[DataProvider('provideIndirectlyTestedClasses')]
    public function testGivenClassReachedFromATestWhenAnalysingThenItIsNotReported(string $class): void
    {
        $this->assertArrayNotHasKey($class, $this->runJson()['gaps']);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function provideCountedMethods(): array
    {
        return [
            'pure protected helper is not counted' => [self::MODEL . 'Formatter', ['format']],
            'protected override of a core method is counted' => ['Example\Zed\Gizmo\Communication\Console\GizmoConsole', ['execute']],
            'protected implementations of abstract parent methods are counted' => [self::TABLE, ['configure', 'prepareData']],
        ];
    }

    /**
     * @param list<string> $missing
     */
    #[DataProvider('provideCountedMethods')]
    public function testGivenNonPublicMethodsWhenCountingThenOnlyOverridesAreCounted(string $class, array $missing): void
    {
        $this->assertEqualsCanonicalizing($missing, $this->runJson()['gaps'][$class]['missing']);
    }

    public function testGivenModelWithOneUnreachedMethodWhenAnalysingThenOnlyThatMethodIsMissing(): void
    {
        $gap = $this->runJson()['gaps'][self::MODEL . 'Calculator'];

        $this->assertFalse($gap['untested']);
        $this->assertSame(['unusedHelper'], $gap['missing']);
        $this->assertSame(['unusedHelper'], $gap['unusedMissing']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideUntestedClassesWithEntryPoint(): array
    {
        return [
            'only a mock is used in the test' => [self::MODEL . 'Formatter', self::FACADE . '::format'],
            'same importer class, but set up by an untested factory method' => [self::MODEL . 'OtherStep', self::FACADE . '::runOtherSteps'],
        ];
    }

    #[DataProvider('provideUntestedClassesWithEntryPoint')]
    public function testGivenUntestedClassWhenAnalysingThenItIsReportedWithTheFacadeMethodToTestItThrough(string $class, string $entryPoint): void
    {
        $gap = $this->runJson()['gaps'][$class];

        $this->assertSame('project', $gap['section']);
        $this->assertTrue($gap['untested']);
        $this->assertSame([$entryPoint], $gap['testVia']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideGlueClassesReachedOnlyOverHttp(): array
    {
        return [
            'class called by the controller' => ['Example\Glue\GizmosRestApi\Processor\GizmoProcessor'],
            'class behind it' => ['Example\Glue\GizmosRestApi\Mapper\GizmoMapper'],
        ];
    }

    #[DataProvider('provideGlueClassesReachedOnlyOverHttp')]
    public function testGivenGlueClassReachedOnlyFromControllerWhenAnalysingThenUnitTestOfTheControllerCalleeIsSuggested(string $class): void
    {
        $this->assertSame(
            [[
                'test' => 'Example\Glue\GizmosRestApi\Processor\GizmoProcessor::process',
                'createWith' => 'GizmosRestApiFactory::createGizmoProcessor()',
                'reachedFrom' => ['Example\Glue\GizmosRestApi\Controller\GizmosResourceController::getAction'],
            ]],
            $this->runJson()['gaps'][$class]['suggestedUnitTests'],
        );
    }

    public function testGivenZedClassWithFacadeEntryPointWhenAnalysingThenNoUnitTestSuggestionIsMade(): void
    {
        $this->assertSame([], $this->runJson()['gaps'][self::MODEL . 'Formatter']['suggestedUnitTests']);
    }

    public function testGivenUntestedClassExtendingCoreWhenAnalysingThenItIsInCoreSectionWithCoreParentAndEntryPoint(): void
    {
        $result = $this->runJson();
        $class = self::MODEL . 'ExtendedStorageProvider';

        $this->assertSame('core-extended', $result['gaps'][$class]['section']);
        $this->assertSame('Spryker\Zed\Cart\Business\StorageProvider\NonPersistentProvider', $result['gaps'][$class]['core']);
        $this->assertSame([self::FACADE . '::addItems'], $result['gaps'][$class]['testVia']);
        $this->assertSame([$class], $result['entryPoints'][self::FACADE . '::addItems']);
    }

    public function testGivenThinPluginDelegatingToTestedCodeWhenAnalysingThenItIsCountedAsCoveredByDelegates(): void
    {
        $this->assertSame(
            [self::PLUGIN . 'CalculatorItemExpanderPlugin' => ['expandItems']],
            $this->runJson()['coveredByDelegates'],
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNotDelegateCoveredPlugins(): array
    {
        return [
            'thin plugin, but the delegate is untested' => [self::PLUGIN . 'FormatterItemExpanderPlugin'],
            'delegate is tested, but the plugin has own logic' => [self::PLUGIN . 'ConditionalItemExpanderPlugin'],
        ];
    }

    #[DataProvider('provideNotDelegateCoveredPlugins')]
    public function testGivenPluginNotQualifyingAsThinDelegateWhenAnalysingThenItStaysReported(string $plugin): void
    {
        $this->assertSame(['expandItems'], $this->runJson()['gaps'][$plugin]['missing']);
    }

    public function testGivenClientMethodOnlyCallingZedStubWhenAnalysingThenItIsSkippedAndOtherClientMethodsStay(): void
    {
        $result = $this->runJson();

        $this->assertSame(['Example\Client\Gizmo\GizmoClient' => ['getRemoteGizmo']], $result['skippedStubOnly']);
        $this->assertSame(['getRemoteGizmo' => 'client method only calls a Zed stub'], $result['skipped']['Example\Client\Gizmo\GizmoClient']);
        $this->assertSame(['readLocal'], $result['gaps']['Example\Client\Gizmo\GizmoClient']['missing']);
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function provideSkippedClasses(): array
    {
        return [
            'Glue resource route plugin' => [
                'Example\Glue\GizmosRestApi\Plugin\GizmosResourceRoutePlugin',
                'routing/resource configuration plugin',
                ['configure', 'getResourceType', 'getController', 'getResourceAttributesClassName'],
            ],
            'Yves route provider plugin' => ['Example\Yves\GizmoPage\Plugin\Router\GizmoPageRouteProviderPlugin', 'routing/resource configuration plugin', ['addRoutes']],
            'application bootstrap' => ['Example\Glue\GizmoApplication\Bootstrap\GizmoBootstrap', 'application bootstrap', ['boot']],
        ];
    }

    /**
     * @param list<string> $methods
     */
    #[DataProvider('provideSkippedClasses')]
    public function testGivenConfigurationOnlyClassWhenAnalysingThenAllItsMethodsAreSkipped(string $class, string $reason, array $methods): void
    {
        $result = $this->runJson();

        $this->assertArrayNotHasKey($class, $result['gaps']);
        $this->assertSame(array_fill_keys($methods, $reason), $result['skipped'][$class]);
    }

    public function testGivenDeclarativeMethodsWhenAnalysingThenOnlyThoseAreSkippedAndLogicStaysReported(): void
    {
        $result = $this->runJson();
        $class = self::MODEL . 'MandatoryColumns';

        $this->assertSame(['getMandatoryColumns' => self::REASON_DECLARATIVE, 'getDefaultQuote' => self::REASON_DECLARATIVE], $result['skipped'][$class]);
        $this->assertSame(['isSatisfiedBy'], $result['gaps'][$class]['missing']);
    }

    /**
     * @return array<string, array{string, list<string>, array<string, string>}>
     */
    public static function provideUiDefinitions(): array
    {
        return [
            'form, default' => ['', ['buildForm', 'addNameField', 'addTypeField', 'normalizeName'], ['getBlockPrefix' => self::REASON_DECLARATIVE]],
            'form, --skip-ui-definitions' => [
                '--skip-ui-definitions',
                ['addTypeField', 'normalizeName'],
                ['buildForm' => self::REASON_UI, 'getBlockPrefix' => self::REASON_UI, 'addNameField' => self::REASON_UI],
            ],
        ];
    }

    /**
     * @param list<string> $missing
     * @param array<string, string> $skipped
     */
    #[DataProvider('provideUiDefinitions')]
    public function testGivenFormWhenAnalysingThenFieldDefinitionsAreSkippedOnlyWithTheFlag(string $flag, array $missing, array $skipped): void
    {
        $result = $flag === '' ? $this->runJson() : $this->runJson($flag);

        $this->assertEqualsCanonicalizing($missing, $result['gaps'][self::FORM]['missing']);
        $this->assertEqualsCanonicalizing($skipped, $result['skipped'][self::FORM]);
    }

    public function testGivenFormExpanderPluginWhenAnalysingThenWholeClassIsSkippedOnlyWithTheFlag(): void
    {
        $class = self::PLUGIN . 'GizmoProductAbstractFormExpanderPlugin';

        $this->assertSame(['expand'], $this->runJson()['gaps'][$class]['missing']);
        $this->assertArrayNotHasKey($class, $this->runJson('--skip-ui-definitions')['gaps']);
        $this->assertSame(['expand' => self::REASON_UI], $this->runJson('--skip-ui-definitions')['skipped'][$class]);
    }

    public function testGivenFormDataProviderExpanderPluginWhenSkippingUiDefinitionsThenItStaysReported(): void
    {
        $class = self::PLUGIN . 'GizmoProductAbstractFormDataProviderExpanderPlugin';

        $this->assertSame(['expand'], $this->runJson('--skip-ui-definitions')['gaps'][$class]['missing']);
        $this->assertArrayNotHasKey($class, $this->runJson('--skip-ui-definitions')['skipped']);
    }

    public function testGivenGuiTableWhenAnalysingThenConfigureIsSkippedOnlyWithTheFlag(): void
    {
        $default = $this->runJson();
        $withFlag = $this->runJson('--skip-ui-definitions');

        $this->assertEqualsCanonicalizing(['configure', 'prepareData'], $default['gaps'][self::TABLE]['missing']);
        $this->assertSame(['prepareData'], $withFlag['gaps'][self::TABLE]['missing']);
        $this->assertSame(['configure' => self::REASON_UI], $withFlag['skipped'][self::TABLE]);
    }

    /**
     * @return array<string, array{string, list<string>, list<string>, bool}>
     */
    public static function provideUnusedCode(): array
    {
        return [
            'class referenced nowhere' => [self::MODEL . 'Orphan', ['doNothing'], [], true],
            'methods called nowhere, protected included' => [self::MODEL . 'Calculator', ['unusedHelper', 'unusedProtected'], [], false],
            'facade method called only by tests' => [self::FACADE, ['legacyOnlyInTests'], ['legacyOnlyInTests'], false],
            'class reachable only from a tests-only method' => [self::MODEL . 'Legacy', ['run'], ['run'], true],
        ];
    }

    /**
     * @param list<string> $methods
     * @param list<string> $testsOnly
     */
    #[DataProvider('provideUnusedCode')]
    public function testGivenCodeWithoutProductionCallerWhenAnalysingThenItIsReportedAsUnused(string $class, array $methods, array $testsOnly, bool $wholeClass): void
    {
        $this->assertSame(
            ['methods' => $methods, 'testsOnly' => $testsOnly, 'wholeClass' => $wholeClass],
            $this->runJson()['unused'][$class],
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideLiveClasses(): array
    {
        return [
            'plugin implementing a core interface' => [self::PLUGIN . 'FormatterItemExpanderPlugin'],
            'console command (framework calls execute)' => ['Example\Zed\Gizmo\Communication\Console\GizmoConsole'],
            'plugin from a project plugin stack' => [self::PLUGIN . 'GizmoExpanderPlugin'],
            'step added by a factory method' => [self::MODEL . 'OtherStep'],
            'template method implementation' => [self::MODEL . 'HelloGreeter'],
            'core override used by a controller' => [self::MODEL . 'ExtendedStorageProvider'],
        ];
    }

    #[DataProvider('provideLiveClasses')]
    public function testGivenCodeCalledInProductionWhenAnalysingThenItIsNotReportedAsUnused(string $class): void
    {
        $this->assertArrayNotHasKey($class, $this->runJson()['unused']);
    }

    public function testGivenHideUnusedFlagWhenAnalysingThenUnusedCodeIsNeitherListedNorCounted(): void
    {
        $default = $this->runJson();
        $hidden = $this->runJson('--hide-unused');
        $unusedMethodCount = array_sum(array_map(static fn (array $u): int => count($u['methods']), $default['unused']));

        $this->assertSame([], $hidden['unused']);
        $this->assertArrayNotHasKey(self::MODEL . 'Orphan', $hidden['gaps']);
        $this->assertArrayNotHasKey(self::MODEL . 'Calculator', $hidden['gaps']);
        $this->assertArrayHasKey(self::MODEL . 'Formatter', $hidden['gaps']);
        $this->assertSame($unusedMethodCount, $hidden['summary']['hiddenUnusedMethods']);
        $this->assertLessThan($default['summary']['methods'], $hidden['summary']['methods']);
    }

    public function testGivenNoUnusedFlagWhenAnalysingThenUnusedDetectionIsSkipped(): void
    {
        $result = $this->runJson('--no-unused');

        $this->assertSame([], $result['unused']);
        $this->assertSame([], $result['gaps'][self::MODEL . 'Calculator']['unusedMissing']);
    }

    public function testGivenOnlyUntestedFlagWhenAnalysingThenPartiallyTestedClassesAreHidden(): void
    {
        $gaps = $this->runJson('--only-untested')['gaps'];

        $this->assertArrayNotHasKey(self::FACADE, $gaps);
        $this->assertArrayNotHasKey(self::MODEL . 'Calculator', $gaps);
        $this->assertArrayHasKey(self::MODEL . 'Formatter', $gaps);
    }

    public function testGivenNoEntriesFlagWhenAnalysingThenNoEntryPointsAreResolved(): void
    {
        $result = $this->runJson('--no-entries');

        $this->assertSame([], $result['entryPoints']);
        $this->assertSame([], array_merge(...array_column(array_values($result['gaps']), 'testVia')));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function provideFilters(): array
    {
        return [
            'module' => ['--module=Extra', ['ExampleExtra\Zed\Extra\Business\ExtraFacade']],
            'layer' => ['--layer=Client', ['Example\Client\Gizmo\GizmoClient', 'Example\Client\Gizmo\Reader\LocalReader']],
        ];
    }

    /**
     * @param list<string> $expectedClasses
     */
    #[DataProvider('provideFilters')]
    public function testGivenFilterFlagWhenAnalysingThenOnlyMatchingClassesAreReported(string $flag, array $expectedClasses): void
    {
        $this->assertEqualsCanonicalizing($expectedClasses, array_keys($this->runJson($flag)['gaps']));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function provideSkipLayerFlags(): array
    {
        return [
            'comma-separated' => [['--skip-layer=Yves,Glue']],
            'repeated, any case' => [['--skip-layer=yves', '--skip-layer=GLUE']],
        ];
    }

    /**
     * @param list<string> $flags
     */
    #[DataProvider('provideSkipLayerFlags')]
    public function testGivenSkippedLayersWhenAnalysingThenTheirClassesAreNotReported(array $flags): void
    {
        $result = $this->runJson(...$flags);

        $this->assertSame(['Yves', 'Glue'], $result['ignoredLayers']);
        $reported = array_merge(array_keys($result['gaps']), array_keys($result['unused']), array_keys($result['skipped']));
        $this->assertSame([], preg_grep('/^Example\\\\(Yves|Glue)\\\\/', $reported));
        $this->assertArrayHasKey(self::MODEL . 'Formatter', $result['gaps']);
    }

    public function testGivenCodeCalledOnlyFromASkippedLayerWhenAnalysingThenItBecomesUnused(): void
    {
        $default = $this->runJson();
        $withoutYves = $this->runJson('--skip-layer=Yves,Glue');

        $this->assertArrayNotHasKey('Example\Client\Gizmo\Reader\LocalReader', $default['unused']);
        $this->assertSame(['read'], $withoutYves['unused']['Example\Client\Gizmo\Reader\LocalReader']['methods']);
        $this->assertContains('readLocal', $withoutYves['unused']['Example\Client\Gizmo\GizmoClient']['methods']);
    }

    public function testGivenUnknownLayerWhenRunningThenItFails(): void
    {
        $result = $this->runScript(['--root=' . self::FIXTURE, '--skip-layer=Frontend']);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString("unknown layer 'Frontend'", $result['stderr']);
    }

    public function testGivenDefaultFlagsWhenPrintingTextReportThenAllSectionsAndRuleListsArePrinted(): void
    {
        $output = $this->runReport()['stdout'];

        $expectedLines = [
            '1. Project classes', '2. Classes extending core', '3. Core entry points', '4. Unused code', 'delete instead of testing',
            'Covered via tested delegates (thin plugins)', 'Skipped — application bootstrap', 'Skipped — routing/resource configuration plugin',
            'Skipped — client method only calls a Zed stub', 'Skipped — declarative: only returns constant data',
            'unit test of this class: Example\Glue\GizmosRestApi\…\GizmoProcessor::process()',
            'unit test of Example\Glue\GizmosRestApi\…\GizmoProcessor::process() — it runs this class',
            'create it with GizmosRestApiFactory::createGizmoProcessor()',
            'reached over HTTP from Example\Glue\GizmosRestApi\…\GizmosResourceController::getAction()',
            '(an HTTP test - Glue API Cest, Cypress - also covers it, but HTTP tests are not counted by this script)',
        ];
        foreach ($expectedLines as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    public function testGivenDefaultFlagsWhenPrintingTextReportThenSkippedMethodsAreOnlyCounted(): void
    {
        $output = $this->runReport()['stdout'];

        $this->assertStringContainsString('Skipped — application bootstrap: 1 methods in 1 classes', $output);
        $this->assertStringContainsString('Use --show-skipped to list the skipped methods.', $output);
        $this->assertStringNotContainsString('GizmoBootstrap::boot()', $output);
    }

    public function testGivenShowSkippedFlagWhenPrintingTextReportThenSkippedMethodsAreListed(): void
    {
        $output = $this->runReport('--show-skipped')['stdout'];

        $this->assertStringContainsString('- Example\Glue\GizmoApplication\Bootstrap\GizmoBootstrap::boot()', $output);
        $this->assertStringContainsString('- Example\Client\Gizmo\GizmoClient::getRemoteGizmo()', $output);
        $this->assertStringNotContainsString('Use --show-skipped', $output);
    }

    public function testGivenWhyFlagWhenAnalysingThenCallPathFromEntryPointIsPrinted(): void
    {
        $stderr = $this->runReport('--why=Example/Zed/Gizmo/Business/Model/OtherStep')['stderr'];

        $this->assertMatchesRegularExpression('/GizmoFacade::runOtherSteps\(\)\s+→ .*StepImporter::import\(\)\s+→ .*OtherStep::execute\(\)/s', $stderr);
    }

    /**
     * @return array<string, array{string|null, list<string>}>
     */
    public static function provideMissingRequirements(): array
    {
        return [
            'composer dependencies not installed' => [null, ['Composer dependencies are not installed', 'Run: composer install']],
            'php-parser not installed' => ['<?php', ['nikic/php-parser is not installed. Run: composer require --dev nikic/php-parser:^5.0', 'spryker/kernel is not installed']],
            'php-parser 4' => [
                '<?php namespace PhpParser { class ParserFactory { public function create(int $kind) {} } }',
                ['nikic/php-parser ^5.0 is required, installed: an older version. Run: composer require --dev nikic/php-parser:^5.0'],
            ],
        ];
    }

    /**
     * @param list<string> $expectedMessages
     */
    #[DataProvider('provideMissingRequirements')]
    public function testGivenMissingRequirementWhenRunningThenItExitsWithInstallInstructions(?string $autoloadContent, array $expectedMessages): void
    {
        $projectDir = sys_get_temp_dir() . '/coverage-gaps-requirements-' . bin2hex(random_bytes(4));
        mkdir($projectDir, 0777, true);
        file_put_contents($projectDir . '/composer.json', '{}');
        if ($autoloadContent !== null) {
            mkdir($projectDir . '/vendor');
            file_put_contents($projectDir . '/vendor/autoload.php', $autoloadContent);
        }

        $result = $this->runScript([], $projectDir);
        array_map('unlink', array_filter([$projectDir . '/composer.json', $projectDir . '/vendor/autoload.php'], 'is_file'));
        array_map('rmdir', array_filter([$projectDir . '/vendor', $projectDir], 'is_dir'));

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('requirements not met', $result['stderr']);
        foreach ($expectedMessages as $expectedMessage) {
            $this->assertStringContainsString($expectedMessage, $result['stderr']);
        }
    }

    public function testGivenWorkingDirectoryOutsideAProjectWhenRunningThenItExitsWithoutUsingItsOwnLocation(): void
    {
        $process = proc_open(
            [PHP_BINARY, self::SCRIPT],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),
            array_diff_key(getenv(), ['SPRYKER_PROJECT_ROOT' => true]),
        );
        fclose($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $this->assertSame(2, proc_close($process));
        $this->assertStringContainsString('Could not find a Spryker project root', $stderr);
    }

    public function testGivenSeveralTestNamespacesWhenAnalysingThenEveryTestsOrgTestDirectoryIsRead(): void
    {
        $stderr = $this->runReport('--json')['stderr'];

        $this->assertStringContainsString('Following calls from tests/{ExampleTest,PyzTest}', $stderr);
        $this->assertStringContainsString('2 test classes', $stderr);
    }

    public function testGivenRootWithoutSrcWhenRunningThenItFails(): void
    {
        $result = $this->runScript(['--root=' . __DIR__]);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('no src/ directory', $result['stderr']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runJson(string ...$flags): array
    {
        $result = $this->runReport('--json', ...$flags);

        return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runReport(string ...$flags): array
    {
        $key = implode(' ', $flags);
        if (!isset(self::$runs[$key])) {
            self::$runs[$key] = $this->runScript(['--root=' . self::FIXTURE, ...$flags]);
            $this->assertSame(0, self::$runs[$key]['exitCode'], self::$runs[$key]['stderr']);
        }

        return self::$runs[$key];
    }

    /**
     * The directory whose vendor/ the script loads: SPRYKER_PROJECT_ROOT when set, otherwise the nearest ancestor of
     * this file with composer.json and vendor/autoload.php.
     */
    private static function projectRoot(): string
    {
        $explicit = getenv('SPRYKER_PROJECT_ROOT');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        $dir = __DIR__;
        while (!is_file($dir . '/composer.json') || !is_file($dir . '/vendor/autoload.php')) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                self::fail('No directory with composer.json and vendor/autoload.php above ' . __DIR__ . '; set SPRYKER_PROJECT_ROOT.');
            }
            $dir = $parent;
        }

        return $dir;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runScript(array $arguments, ?string $projectRoot = null): array
    {
        $environment = ['SPRYKER_PROJECT_ROOT' => $projectRoot ?? self::projectRoot()] + getenv();
        $process = proc_open([PHP_BINARY, self::SCRIPT, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => proc_close($process)];
    }
}
