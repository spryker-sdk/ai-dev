<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Scripts\CoverageGaps;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CoverageGapsTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../plugins/spryker-ai-dev-sdk/skills/spryker-upgrade/scripts/coverage-gaps/coverage-gaps.php';

    private const FIXTURE = __DIR__ . '/fixture';

    private const FIXTURE_UNLOADABLE = __DIR__ . '/fixture-unloadable';

    private const FIXTURE_MOCKS = __DIR__ . '/fixture-mocks';

    /**
     * @var string
     */
    private const FACADE = 'Example\Zed\Gizmo\Business\GizmoFacade';

    /**
     * @var string
     */
    private const MODEL = 'Example\Zed\Gizmo\Business\Model\\';

    /**
     * @var string
     */
    private const PLUGIN = 'Example\Zed\Gizmo\Communication\Plugin\\';

    /**
     * @var string
     */
    private const FORM = 'Example\Zed\Gizmo\Communication\Form\GizmoForm';

    /**
     * @var string
     */
    private const TABLE = 'Example\Zed\Gizmo\Communication\Table\GizmoTable';

    /**
     * @var string
     */
    private const REASON_DECLARATIVE = 'declarative: only returns constant data';

    /**
     * @var string
     */
    private const REASON_UI = 'form/table definition (--skip-ui-definitions)';

    /**
     * @var array<string, array{stdout: string, stderr: string, exitCode: int}>
     */
    private static array $runs = [];

    private static ?string $workDir = null;

    public static function tearDownAfterClass(): void
    {
        if (static::$workDir !== null) {
            static::removeDirectory(static::$workDir);
            static::$workDir = null;
        }
    }

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
        $this->assertSame([], preg_grep('/(Factory|DependencyProvider|Stub|Controller|Interface)$/', $reported));
    }

    public function testGivenAbstractClassWhenAnalysingThenItsUntestedMethodIsReportedInTheDeclaringClass(): void
    {
        $gaps = $this->runJson()['gaps'];

        $this->assertSame(['shout'], $gaps[static::MODEL . 'AbstractGreeter']['missing']);
        $this->assertArrayNotHasKey(static::MODEL . 'HelloGreeter', $gaps);
    }

    public function testGivenFacadeTestWhenAnalysingThenOnlyFacadeMethodsWithoutTestAreMissing(): void
    {
        $gap = $this->runJson()['gaps'][static::FACADE];

        $this->assertFalse($gap['untested']);
        $this->assertEqualsCanonicalizing(['format', 'runOtherSteps', 'addItems'], $gap['missing']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideIndirectlyTestedClasses(): array
    {
        return [
            'constructor dependency (promoted property)' => [static::MODEL . 'Rounder'],
            'object configured by a setter in the tested factory method' => [static::MODEL . 'DefaultStep'],
            'plugin stack from the DependencyProvider' => [static::PLUGIN . 'GizmoExpanderPlugin'],
            'template method called from the abstract parent' => [static::MODEL . 'HelloGreeter'],
            'thin plugin delegating to tested code' => [static::PLUGIN . 'CalculatorItemExpanderPlugin'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideIndirectlyTestedClasses')]
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
            'pure protected helper is not counted' => [static::MODEL . 'Formatter', ['format']],
            'protected override of a core method is counted' => ['Example\Zed\Gizmo\Communication\Console\GizmoConsole', ['execute']],
            'protected implementations of abstract parent methods are counted' => [static::TABLE, ['configure', 'prepareData']],
        ];
    }

    /**
     * @param list<string> $missing
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideCountedMethods')]
    public function testGivenNonPublicMethodsWhenCountingThenOnlyOverridesAreCounted(string $class, array $missing): void
    {
        $this->assertEqualsCanonicalizing($missing, $this->runJson()['gaps'][$class]['missing']);
    }

    public function testGivenModelWithOneUnreachedMethodWhenAnalysingThenOnlyThatMethodIsMissing(): void
    {
        $gap = $this->runJson()['gaps'][static::MODEL . 'Calculator'];

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
            'only a mock is used in the test' => [static::MODEL . 'Formatter', static::FACADE . '::format'],
            'same importer class, but set up by an untested factory method' => [static::MODEL . 'OtherStep', static::FACADE . '::runOtherSteps'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideUntestedClassesWithEntryPoint')]
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

    #[\PHPUnit\Framework\Attributes\DataProvider('provideGlueClassesReachedOnlyOverHttp')]
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
        $this->assertSame([], $this->runJson()['gaps'][static::MODEL . 'Formatter']['suggestedUnitTests']);
    }

    public function testGivenUntestedClassExtendingCoreWhenAnalysingThenItIsInCoreSectionWithCoreParentAndEntryPoint(): void
    {
        $result = $this->runJson();
        $class = static::MODEL . 'ExtendedStorageProvider';

        $this->assertSame('core-extended', $result['gaps'][$class]['section']);
        $this->assertSame('Spryker\Zed\Cart\Business\StorageProvider\NonPersistentProvider', $result['gaps'][$class]['core']);
        $this->assertSame([static::FACADE . '::addItems'], $result['gaps'][$class]['testVia']);
        $this->assertSame([$class], $result['entryPoints'][static::FACADE . '::addItems']);
    }

    public function testGivenThinPluginDelegatingToTestedCodeWhenAnalysingThenItIsCountedAsCoveredByDelegates(): void
    {
        $this->assertSame(
            [static::PLUGIN . 'CalculatorItemExpanderPlugin' => ['expandItems']],
            $this->runJson()['coveredByDelegates'],
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNotDelegateCoveredPlugins(): array
    {
        return [
            'thin plugin, but the delegate is untested' => [static::PLUGIN . 'FormatterItemExpanderPlugin'],
            'delegate is tested, but the plugin has own logic' => [static::PLUGIN . 'ConditionalItemExpanderPlugin'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideNotDelegateCoveredPlugins')]
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
    #[\PHPUnit\Framework\Attributes\DataProvider('provideSkippedClasses')]
    public function testGivenConfigurationOnlyClassWhenAnalysingThenAllItsMethodsAreSkipped(string $class, string $reason, array $methods): void
    {
        $result = $this->runJson();

        $this->assertArrayNotHasKey($class, $result['gaps']);
        $this->assertSame(array_fill_keys($methods, $reason), $result['skipped'][$class]);
    }

    public function testGivenDeclarativeMethodsWhenAnalysingThenOnlyThoseAreSkippedAndLogicStaysReported(): void
    {
        $result = $this->runJson();
        $class = static::MODEL . 'MandatoryColumns';

        $this->assertSame(['getMandatoryColumns' => static::REASON_DECLARATIVE, 'getDefaultQuote' => static::REASON_DECLARATIVE], $result['skipped'][$class]);
        $this->assertSame(['isSatisfiedBy'], $result['gaps'][$class]['missing']);
    }

    /**
     * @return array<string, array{string, list<string>, array<string, string>}>
     */
    public static function provideUiDefinitions(): array
    {
        return [
            'form, default' => ['', ['buildForm', 'addNameField', 'addTypeField', 'normalizeName'], ['getBlockPrefix' => static::REASON_DECLARATIVE]],
            'form, --skip-ui-definitions' => [
                '--skip-ui-definitions',
                ['addTypeField', 'normalizeName'],
                ['buildForm' => static::REASON_UI, 'getBlockPrefix' => static::REASON_UI, 'addNameField' => static::REASON_UI],
            ],
        ];
    }

    /**
     * @param list<string> $missing
     * @param array<string, string> $skipped
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideUiDefinitions')]
    public function testGivenFormWhenAnalysingThenFieldDefinitionsAreSkippedOnlyWithTheFlag(string $flag, array $missing, array $skipped): void
    {
        $result = $flag === '' ? $this->runJson() : $this->runJson($flag);

        $this->assertEqualsCanonicalizing($missing, $result['gaps'][static::FORM]['missing']);
        $this->assertEqualsCanonicalizing($skipped, $result['skipped'][static::FORM]);
    }

    public function testGivenFormExpanderPluginWhenAnalysingThenWholeClassIsSkippedOnlyWithTheFlag(): void
    {
        $class = static::PLUGIN . 'GizmoProductAbstractFormExpanderPlugin';

        $this->assertSame(['expand'], $this->runJson()['gaps'][$class]['missing']);
        $this->assertArrayNotHasKey($class, $this->runJson('--skip-ui-definitions')['gaps']);
        $this->assertSame(['expand' => static::REASON_UI], $this->runJson('--skip-ui-definitions')['skipped'][$class]);
    }

    public function testGivenFormDataProviderExpanderPluginWhenSkippingUiDefinitionsThenItStaysReported(): void
    {
        $class = static::PLUGIN . 'GizmoProductAbstractFormDataProviderExpanderPlugin';

        $this->assertSame(['expand'], $this->runJson('--skip-ui-definitions')['gaps'][$class]['missing']);
        $this->assertArrayNotHasKey($class, $this->runJson('--skip-ui-definitions')['skipped']);
    }

    public function testGivenGuiTableWhenAnalysingThenConfigureIsSkippedOnlyWithTheFlag(): void
    {
        $default = $this->runJson();
        $withFlag = $this->runJson('--skip-ui-definitions');

        $this->assertEqualsCanonicalizing(['configure', 'prepareData'], $default['gaps'][static::TABLE]['missing']);
        $this->assertSame(['prepareData'], $withFlag['gaps'][static::TABLE]['missing']);
        $this->assertSame(['configure' => static::REASON_UI], $withFlag['skipped'][static::TABLE]);
    }

    /**
     * @return array<string, array{string, list<string>, list<string>, bool}>
     */
    public static function provideUnusedCode(): array
    {
        return [
            'class referenced nowhere' => [static::MODEL . 'Orphan', ['doNothing'], [], true],
            'methods called nowhere, protected included' => [static::MODEL . 'Calculator', ['unusedHelper', 'unusedProtected'], [], false],
            'facade method called only by tests' => [static::FACADE, ['legacyOnlyInTests'], ['legacyOnlyInTests'], false],
            'class reachable only from a tests-only method' => [static::MODEL . 'Legacy', ['run'], ['run'], true],
        ];
    }

    /**
     * @param list<string> $methods
     * @param list<string> $testsOnly
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideUnusedCode')]
    public function testGivenCodeWithoutProductionCallerWhenAnalysingThenItIsReportedAsUnused(
        string $class,
        array $methods,
        array $testsOnly,
        bool $wholeClass
    ): void {
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
            'plugin implementing a core interface' => [static::PLUGIN . 'FormatterItemExpanderPlugin'],
            'console command (framework calls execute)' => ['Example\Zed\Gizmo\Communication\Console\GizmoConsole'],
            'plugin from a project plugin stack' => [static::PLUGIN . 'GizmoExpanderPlugin'],
            'step added by a factory method' => [static::MODEL . 'OtherStep'],
            'template method implementation' => [static::MODEL . 'HelloGreeter'],
            'core override used by a controller' => [static::MODEL . 'ExtendedStorageProvider'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideLiveClasses')]
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
        $this->assertArrayNotHasKey(static::MODEL . 'Orphan', $hidden['gaps']);
        $this->assertArrayNotHasKey(static::MODEL . 'Calculator', $hidden['gaps']);
        $this->assertArrayHasKey(static::MODEL . 'Formatter', $hidden['gaps']);
        $this->assertSame($unusedMethodCount, $hidden['summary']['hiddenUnusedMethods']);
        $this->assertLessThan($default['summary']['methods'], $hidden['summary']['methods']);
    }

    public function testGivenNoUnusedFlagWhenAnalysingThenUnusedDetectionIsSkipped(): void
    {
        $result = $this->runJson('--no-unused');

        $this->assertSame([], $result['unused']);
        $this->assertSame([], $result['gaps'][static::MODEL . 'Calculator']['unusedMissing']);
    }

    public function testGivenOnlyUntestedFlagWhenAnalysingThenPartiallyTestedClassesAreHidden(): void
    {
        $gaps = $this->runJson('--only-untested')['gaps'];

        $this->assertArrayNotHasKey(static::FACADE, $gaps);
        $this->assertArrayNotHasKey(static::MODEL . 'Calculator', $gaps);
        $this->assertArrayHasKey(static::MODEL . 'Formatter', $gaps);
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
    #[\PHPUnit\Framework\Attributes\DataProvider('provideFilters')]
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
    #[\PHPUnit\Framework\Attributes\DataProvider('provideSkipLayerFlags')]
    public function testGivenSkippedLayersWhenAnalysingThenTheirClassesAreNotReported(array $flags): void
    {
        $result = $this->runJson(...$flags);

        $this->assertSame(['Yves', 'Glue'], $result['ignoredLayers']);
        $reported = array_merge(array_keys($result['gaps']), array_keys($result['unused']), array_keys($result['skipped']));
        $this->assertSame([], preg_grep('/^Example\\\\(Yves|Glue)\\\\/', $reported));
        $this->assertArrayHasKey(static::MODEL . 'Formatter', $result['gaps']);
    }

    public function testGivenCodeCalledOnlyFromASkippedLayerWhenAnalysingThenItBecomesUnused(): void
    {
        $default = $this->runJson();
        $withoutYves = $this->runJson('--skip-layer=Yves,Glue');

        $this->assertArrayNotHasKey('Example\Client\Gizmo\Reader\LocalReader', $default['unused']);
        $this->assertSame(['read'], $withoutYves['unused']['Example\Client\Gizmo\Reader\LocalReader']['methods']);
        $this->assertContains('readLocal', $withoutYves['unused']['Example\Client\Gizmo\GizmoClient']['methods']);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function provideUsageErrors(): array
    {
        return [
            'unknown option' => [['--modul=Gizmo'], "unknown option '--modul'"],
            'value for a flag' => [['--json=yes'], '--json takes no value'],
            'positional argument' => [['Gizmo'], "unexpected argument 'Gizmo'"],
            'unknown layer' => [['--layer=Frontend'], "--layer: unknown layer 'Frontend'"],
            'unknown layer to skip' => [['--skip-layer=Frontend'], "--skip-layer: unknown layer 'Frontend'"],
            'module without classes' => [['--module=DoesNotExist'], 'no project class matches --module=DoesNotExist'],
            'layer without classes' => [['--layer=Service'], 'no project class matches --layer=Service'],
            'limit is not a number' => [['--limit=all'], '--limit takes a number'],
            'unknown class for --why' => [['--why=Example/Zed/Gizmo/Business/Model/Missing'], '--why: Example\Zed\Gizmo\Business\Model\Missing is not a project class'],
            '--why without entry points' => [['--why=Example/Zed/Gizmo/Business/Model/Formatter', '--no-entries'], '--why needs the entry-point lookup'],
        ];
    }

    /**
     * @param list<string> $flags
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideUsageErrors')]
    public function testGivenInvalidArgumentsWhenRunningThenItExitsWithUsageError(array $flags, string $message): void
    {
        $result = $this->runScript(['--root=' . static::FIXTURE, ...$flags]);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString($message, $result['stderr']);
        $this->assertSame('', $result['stdout']);
    }

    public function testGivenDefaultFlagsWhenPrintingSummaryThenVerdictComesFirstAndReportPathLast(): void
    {
        $result = $this->runReport();
        $lines = explode("\n", trim($result['stdout']));

        $this->assertSame(1, $result['exitCode']);
        $this->assertMatchesRegularExpression('/^coverage-gaps: FOUND core-extended without test 1, core-extended partial 0, project without test \d+, project partial \d+, unused methods \d+, not analysed 0$/', $lines[0]);
        $this->assertSame('Full report: .spryker-upgrade/state/coverage-gaps-report.json', end($lines));
        $this->assertFileExists(static::workDir() . '/project/.spryker-upgrade/state/coverage-gaps-report.json');
        $expectedLines = [
            'Core-extended classes with untested methods: 1 (1 without any test)',
            'Example\Zed\Gizmo\Business\Model\ExtendedStorageProvider extends Spryker\Zed\Cart\Business\StorageProvider\NonPersistentProvider: addItems(); test via Example\Zed\Gizmo\…\GizmoFacade::addItems()',
            'Project classes with untested methods:',
            'Entry points that run the untested core-extended classes: 1',
            'Unused code:',
        ];
        foreach ($expectedLines as $expected) {
            $this->assertStringContainsString($expected, $result['stdout']);
        }
    }

    public function testGivenDefaultFlagsWhenPrintingSummaryThenEachSectionHasAtMostTenFindings(): void
    {
        $output = $this->runReport()['stdout'];

        $this->assertMatchesRegularExpression('/Project classes with untested methods: (\d+).*\n(  [✗◐] .*\n){10}  \(\d+ more in the report\)\n/u', $output);
    }

    public function testGivenLimitWhenPrintingSummaryThenOnlyThatManyFindingsArePrinted(): void
    {
        $output = $this->runReport('--limit=1')['stdout'];

        $this->assertMatchesRegularExpression('/Project classes with untested methods: (\d+).*\n  [✗◐] .*\n  \(\d+ more in the report\)\n/u', $output);
        $this->assertStringNotContainsString('(0 more', $output);
    }

    public function testGivenGlueClassReachedOnlyOverHttpWhenPrintingSummaryThenUnitTestIsSuggested(): void
    {
        $output = $this->runReport('--limit=100')['stdout'];

        $this->assertStringContainsString('GizmoProcessor: process(); unit test of this class: Example\Glue\GizmosRestApi\…\GizmoProcessor::process(), create it with GizmosRestApiFactory::createGizmoProcessor()', $output);
        $this->assertStringContainsString('GizmoMapper: map(); unit test of Example\Glue\GizmosRestApi\…\GizmoProcessor::process(), create it with', $output);
    }

    public function testGivenJsonFlagWhenRunningThenStdoutIsTheFullReportAndTheReportFileIsWritten(): void
    {
        $result = $this->runJson();

        $this->assertSame('FOUND', $result['verdict']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertSame(1, $result['counts']['core-extended without test']);
        $this->assertSame([], $result['notAnalysed']);
        $this->assertSame($result, json_decode((string)file_get_contents(static::workDir() . '/project/.spryker-upgrade/state/coverage-gaps-report.json'), true));
    }

    public function testGivenDefaultFlagsWhenPrintingSummaryThenSkippedMethodsAreOnlyCounted(): void
    {
        $output = $this->runReport()['stdout'];

        $this->assertMatchesRegularExpression('/\d+ skipped/', $output);
        $this->assertStringNotContainsString('GizmoBootstrap::boot()', $output);
    }

    public function testGivenShowSkippedFlagWhenPrintingSummaryThenSkippedMethodsAreListed(): void
    {
        $output = $this->runReport('--show-skipped')['stdout'];

        $this->assertStringContainsString('Example\Glue\GizmoApplication\Bootstrap\GizmoBootstrap::boot() — application bootstrap', $output);
        $this->assertStringContainsString('Example\Client\Gizmo\GizmoClient::getRemoteGizmo() — client method only calls a Zed stub', $output);
    }

    public function testGivenWhyFlagWhenAnalysingThenCallPathFromEntryPointIsPrinted(): void
    {
        $stderr = $this->runReport('--why=Example/Zed/Gizmo/Business/Model/OtherStep')['stderr'];

        $this->assertMatchesRegularExpression('/GizmoFacade::runOtherSteps\(\)\s+→ .*StepImporter::import\(\)\s+→ .*OtherStep::execute\(\)/s', $stderr);
    }

    public function testGivenWhyFlagForATestedClassWhenAnalysingThenItSaysSo(): void
    {
        $stderr = $this->runReport('--why=Example/Zed/Gizmo/Business/Model/Rounder')['stderr'];

        $this->assertStringContainsString('--why Example\Zed\Gizmo\Business\Model\Rounder: every counted method of this class is reached by a test or skipped', $stderr);
    }

    public function testGivenUnloadableClassesAndParseErrorsWhenAnalysingThenTheyAreReportedAndTheExitCodeIs3(): void
    {
        $result = $this->runScript(['--root=' . static::FIXTURE_UNLOADABLE, '--json']);
        $report = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $reasons = array_column($report['notAnalysed'], 'reason', 'name');

        $this->assertSame(3, $result['exitCode'], $result['stderr']);
        $this->assertSame(4, $report['counts']['not analysed']);
        $this->assertStringContainsString('must be compatible with', $reasons['Broken\Zed\Broken\Business\Model\IncompatibleStep']);
        $this->assertStringContainsString('Class "Spryker\Zed\Missing\Business\Model\RemovedStep" not found', $reasons['Broken\Zed\Broken\Business\Model\MissingParentStep']);
        $this->assertStringStartsWith('parse error:', $reasons['Broken\Zed\Broken\Business\Model\SyntaxErrorStep']);
        $this->assertStringStartsWith('parse error:', $reasons['tests/BrokenTest/Zed/Broken/Business/SyntaxErrorTest.php']);
        $this->assertArrayHasKey('Broken\Zed\Broken\Business\Model\WorkingStep', $report['gaps']);
    }

    public function testGivenUnloadableClassesWhenPrintingSummaryThenTheyAreListedFirst(): void
    {
        $result = $this->runScript(['--root=' . static::FIXTURE_UNLOADABLE]);

        $this->assertSame(3, $result['exitCode']);
        $this->assertStringStartsWith('coverage-gaps: FOUND ', $result['stdout']);
        $this->assertStringContainsString('not analysed 4', $result['stdout']);
        $this->assertStringContainsString("\nCould not analyse: 3 classes, 1 test file", $result['stdout']);
        $this->assertStringNotContainsString('Fatal error', $result['stderr']);
    }

    /**
     * @return array<string, array{string, bool, bool, list<string>}>
     */
    public static function provideMockedTests(): array
    {
        return [
            'no mock' => ['PlainTest.php', true, true, []],
            'setFactory()' => ['SetFactoryTest.php', true, false, []],
            'mockFactoryMethod()' => ['MockFactoryMethodTest.php', true, false, []],
            'createMock() of the project class' => ['CreateMockTest.php', true, false, []],
            'markTestSkipped() before the call' => ['SkippedBeforeTest.php', false, false, []],
            'conditional markTestSkipped()' => ['ConditionalSkipTest.php', false, false, ['conditional markTestSkipped()']],
            'setDependency() with a mock' => ['SetDependencyMockTest.php', false, false, ['setDependency()']],
            'setDependency() with real objects' => ['SetDependencyRealTest.php', true, true, []],
        ];
    }

    /**
     * @param list<string> $notProven
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideMockedTests')]
    public function testGivenTestWithMocksOrSkipsWhenAnalysingThenOnlyTheRealPathCounts(
        string $testFile,
        bool $facadeTested,
        bool $formatterTested,
        array $notProven
    ): void {
        $root = static::workDir() . '/mocks-' . basename($testFile, '.php');
        if (!is_dir($root)) {
            static::copyDirectory(static::FIXTURE . '/src', $root . '/src');
            mkdir($root . '/tests/PyzTest/Zed/Gizmo/Business', 0777, true);
            copy(static::FIXTURE_MOCKS . '/' . $testFile, $root . '/tests/PyzTest/Zed/Gizmo/Business/' . $testFile);
        }
        $result = $this->runScript(['--root=' . $root, '--json', '--no-unused', '--no-entries']);
        $gaps = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['gaps'];

        $this->assertSame(!$facadeTested, in_array('format', $gaps[static::FACADE]['missing'] ?? [], true));
        $this->assertSame(!$formatterTested, isset($gaps[static::MODEL . 'Formatter']));
        if ($notProven !== []) {
            $this->assertSame(['format' => $notProven], $gaps[static::FACADE]['notProven']);
            $this->assertSame(['format' => $notProven], $gaps[static::MODEL . 'Formatter']['notProven']);
        }
    }

    /**
     * @return array<string, array{string|null, list<string>}>
     */
    public static function provideMissingRequirements(): array
    {
        return [
            'composer dependencies not installed' => [null, ['Composer dependencies are not installed', 'Run: composer install']],
            'php-parser not installed' => ['<?php', ['nikic/php-parser is not installed. Use the version the project has', 'COVERAGE_GAPS_PARSER_AUTOLOAD', 'spryker/kernel is not installed']],
            'php-parser 4' => [
                '<?php namespace PhpParser { class ParserFactory { public function create(int $kind) {} } }',
                ['nikic/php-parser ^5.0 is required, installed: an older version. Use the version the project has'],
            ],
        ];
    }

    /**
     * @param list<string> $expectedMessages
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideMissingRequirements')]
    public function testGivenMissingRequirementWhenRunningThenItExitsWithInstallInstructions(?string $autoloadContent, array $expectedMessages): void
    {
        $projectDir = static::workDir() . '/requirements-' . bin2hex(random_bytes(4));
        mkdir($projectDir, 0777, true);
        file_put_contents($projectDir . '/composer.json', '{}');
        if ($autoloadContent !== null) {
            mkdir($projectDir . '/vendor');
            file_put_contents($projectDir . '/vendor/autoload.php', $autoloadContent);
        }

        $result = $this->runScript([], $projectDir);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('requirements not met', $result['stderr']);
        foreach ($expectedMessages as $expectedMessage) {
            $this->assertStringContainsString($expectedMessage, $result['stderr']);
        }
    }

    public function testGivenWorkingDirectoryOutsideAProjectWhenRunningThenItExitsWithoutUsingItsOwnLocation(): void
    {
        $process = proc_open(
            [PHP_BINARY, static::SCRIPT],
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

        $this->assertSame(2, $result['exitCode']);
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
     * Runs the script on ./fixture: exit code 1 while the untested core-extended class of the fixture is in scope, else 0.
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runReport(string ...$flags): array
    {
        $key = implode(' ', $flags);
        if (!isset(static::$runs[$key])) {
            static::$runs[$key] = $this->runScript(['--root=' . static::FIXTURE, ...$flags]);
            $this->assertContains(static::$runs[$key]['exitCode'], [0, 1], static::$runs[$key]['stderr']);
        }

        return static::$runs[$key];
    }

    /**
     * A temporary directory for this test run; its project/ is the project root of the script.
     */
    private static function workDir(): string
    {
        if (static::$workDir !== null) {
            return static::$workDir;
        }
        static::$workDir = sys_get_temp_dir() . '/coverage-gaps-test-' . bin2hex(random_bytes(4));
        mkdir(static::$workDir . '/project/vendor', 0777, true);
        file_put_contents(static::$workDir . '/project/composer.json', '{}');
        file_put_contents(static::$workDir . '/project/vendor/autoload.php', sprintf(
            "<?php\n\$loader = require %s;\nrequire %s;\n\nreturn \$loader;\n",
            var_export(static::vendorRoot() . '/vendor/autoload.php', true),
            var_export(static::FIXTURE . '/stubs/autoload.php', true),
        ));

        return static::$workDir;
    }

    /**
     * The directory whose vendor/ the script loads: SPRYKER_PROJECT_ROOT when set, otherwise the nearest ancestor of
     * this file with composer.json and vendor/autoload.php.
     */
    private static function vendorRoot(): string
    {
        $explicit = getenv('SPRYKER_PROJECT_ROOT');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        $dir = __DIR__;
        while (!is_file($dir . '/composer.json') || !is_file($dir . '/vendor/autoload.php')) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                static::fail('No directory with composer.json and vendor/autoload.php above ' . __DIR__ . '; set SPRYKER_PROJECT_ROOT.');
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
        $environment = ['SPRYKER_PROJECT_ROOT' => $projectRoot ?? static::workDir() . '/project'] + getenv();
        unset($environment['SPRYKER_UPGRADE_STATE_DIR'], $environment['COVERAGE_GAPS_PARSER_AUTOLOAD']);
        $process = proc_open([PHP_BINARY, static::SCRIPT, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => proc_close($process)];
    }

    private static function copyDirectory(string $source, string $target): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir($target, 0777, true);
        foreach ($files as $file) {
            $destination = $target . '/' . substr($file->getPathname(), strlen($source) + 1);
            $file->isDir() ? mkdir($destination) : copy($file->getPathname(), $destination);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
