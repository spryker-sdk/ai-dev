<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev;

use Codeception\Actor;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvTransformer;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriter;
use SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluator;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\ColumnMappingOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\ColumnRemovalOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\DefaultValuesOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\TransformationOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\AppendStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\ReplaceStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\UpdateStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnMappingValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnRemovalValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\FilterValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ModeValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\SourceFileValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\TargetFileValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\TransformationValidator;

/**
 * Inherited Methods
 *
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause()
 *
 * @SuppressWarnings(PHPMD)
 */
class BusinessTester extends Actor
{
    use _generated\BusinessTesterActions;

    /**
     * @param array<string> $headers
     * @param array<int, array<string, mixed>> $rows
     */
    public function createCsvFile(string $filePath, array $headers, array $rows): void
    {
        $csvReader = new CsvReader();
        $csvWriter = new CsvWriter($csvReader);
        $csvWriter->write($filePath, $headers, $rows);
    }

    public function createCsvTransformer(): CsvTransformer
    {
        $csvReader = new CsvReader();
        $csvWriter = new CsvWriter($csvReader);
        $filterEvaluator = new FilterEvaluator();

        $rowOperations = [
            new ColumnMappingOperation(),
            new DefaultValuesOperation(),
            new TransformationOperation(),
            new ColumnRemovalOperation(),
        ];

        $strategies = [
            new AppendStrategy($csvReader, $csvWriter, $filterEvaluator, $rowOperations),
            new ReplaceStrategy($csvReader, $csvWriter, $filterEvaluator, $rowOperations),
            new UpdateStrategy($csvReader, $csvWriter, $filterEvaluator, $rowOperations),
        ];

        $validators = [
            new ModeValidator(),
            new SourceFileValidator(),
            new TargetFileValidator(),
            new ColumnMappingValidator(),
            new FilterValidator($filterEvaluator),
            new TransformationValidator(),
            new ColumnRemovalValidator(),
        ];

        return new CsvTransformer(
            $csvReader,
            $csvWriter,
            $filterEvaluator,
            $rowOperations,
            $strategies,
            $validators,
        );
    }

    /**
     * @param array<string, mixed> $resultData
     * @param array<string, mixed> $expectedAssertions
     */
    public function assertCsvTransformSuccess(
        array $resultData,
        string $targetPath,
        string $testName,
        array $expectedAssertions
    ): void {
        $this->assertTrue($resultData['success'] ?? false, "Test '{$testName}' should succeed");

        if (isset($expectedAssertions['hasBackup']) && $expectedAssertions['hasBackup']) {
            $this->assertArrayHasKey('backup_path', $resultData);
            $this->assertFileExists($resultData['backup_path']);
        }

        if (isset($expectedAssertions['rowCount'])) {
            $this->assertCsvFileHasRowCount($targetPath, $expectedAssertions['rowCount'], $testName);
        }

        if (isset($expectedAssertions['headers'])) {
            $this->assertCsvFileHasHeaders($targetPath, $expectedAssertions['headers'], $testName);
        }

        if (isset($expectedAssertions['rows'])) {
            $this->assertCsvFileHasRows($targetPath, $expectedAssertions['rows'], $testName);
        }

        if (isset($expectedAssertions['resultData'])) {
            foreach ($expectedAssertions['resultData'] as $key => $expectedValue) {
                $this->assertSame($expectedValue, $resultData[$key], "Test '{$testName}' result data '{$key}'");
            }
        }
    }

    /**
     * @param array<string, mixed> $resultData
     */
    public function assertCsvTransformFailure(
        array $resultData,
        string $testName,
        string $expectedErrorCode
    ): void {
        $this->assertFalse($resultData['success'] ?? true, "Test '{$testName}' should fail");
        $this->assertSame($expectedErrorCode, $resultData['error_code'], "Test '{$testName}' error code");
    }

    public function assertCsvFileHasRowCount(string $filePath, int $expectedCount, string $testName): void
    {
        $csvReader = new CsvReader();
        $rows = $csvReader->getRows($filePath);
        $this->assertCount($expectedCount, $rows, "Test '{$testName}' row count");
    }

    /**
     * @param array<string, array<string>> $headerAssertions
     */
    public function assertCsvFileHasHeaders(string $filePath, array $headerAssertions, string $testName): void
    {
        $csvReader = new CsvReader();
        $headers = $csvReader->getHeaders($filePath);

        foreach ($headerAssertions['contains'] ?? [] as $header) {
            $this->assertContains($header, $headers, "Test '{$testName}' should contain header '{$header}'");
        }

        foreach ($headerAssertions['notContains'] ?? [] as $header) {
            $this->assertNotContains($header, $headers, "Test '{$testName}' should not contain header '{$header}'");
        }
    }

    /**
     * @param array<int, array<string, mixed>> $expectedRows
     */
    public function assertCsvFileHasRows(string $filePath, array $expectedRows, string $testName): void
    {
        $csvReader = new CsvReader();
        $rows = $csvReader->getRows($filePath);

        foreach ($expectedRows as $rowIndex => $expectedRow) {
            foreach ($expectedRow as $column => $expectedValue) {
                $this->assertSame(
                    $expectedValue,
                    $rows[$rowIndex][$column],
                    "Test '{$testName}' row {$rowIndex} column '{$column}'",
                );
            }
        }
    }
}
