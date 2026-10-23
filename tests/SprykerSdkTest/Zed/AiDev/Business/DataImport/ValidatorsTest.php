<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use RuntimeException;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnMappingValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnRemovalValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\FilterValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ModeValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\SourceFileValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\TargetFileValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\TransformationValidator;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidationContext;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group Validators
 */
class ValidatorsTest extends Unit
{
    use CsvTestDataTrait;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = 'csv_output/test_' . uniqid();
        if (!is_dir('csv_output')) {
            mkdir('csv_output', 0777, true);
        }
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files) {
                array_map('unlink', $files);
            }
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * @dataProvider validateDataProvider
     */
    public function testValidate(
        string $validatorClass,
        callable $contextFactory,
        bool $shouldPass,
        ?string $expectedErrorCode = null
    ): void {
        // Arrange
        $sourcePath = $this->createTestFile('source.csv');
        $targetPath = $this->createTestFile('target.csv');
        $csvReader = new CsvReader();

        $context = $contextFactory($sourcePath, $targetPath, $csvReader);
        $validator = $this->createValidator($validatorClass);

        // Act
        $error = $validator->validate($context);

        // Assert
        if ($shouldPass) {
            $this->assertNull($error);
        } else {
            $this->assertNotNull($error);
            $this->assertSame($expectedErrorCode, $error['code']);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function validateDataProvider(): array
    {
        return [
            'ModeValidator: valid append mode' => [
                'validatorClass' => ModeValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => true,
            ],
            'ModeValidator: valid replace mode' => [
                'validatorClass' => ModeValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_REPLACE,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => true,
            ],
            'ModeValidator: invalid mode' => [
                'validatorClass' => ModeValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: 'invalid_mode',
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => false,
                'expectedErrorCode' => CsvConstants::OPERATION_FAILED,
            ],
            'ColumnMappingValidator: valid mappings' => [
                'validatorClass' => ColumnMappingValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: ['brand' => 'brand', 'price' => 'price'],
                    rowFilters: [],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => true,
            ],
            'ColumnMappingValidator: source column not found' => [
                'validatorClass' => ColumnMappingValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: ['nonexistent_column' => 'brand'],
                    rowFilters: [],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => false,
                'expectedErrorCode' => CsvConstants::INVALID_MAPPINGS,
            ],
            'FilterValidator: valid criteria' => [
                'validatorClass' => FilterValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [['column' => 'brand', 'operator' => 'equals', 'value' => 'Canon']],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => true,
            ],
            'FilterValidator: invalid operator' => [
                'validatorClass' => FilterValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [['column' => 'brand', 'operator' => 'invalid_op', 'value' => 'Canon']],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => false,
                'expectedErrorCode' => CsvConstants::INVALID_FILTERS,
            ],
            'FilterValidator: missing column' => [
                'validatorClass' => FilterValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [['column' => 'nonexistent', 'operator' => 'equals', 'value' => 'test']],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => false,
                'expectedErrorCode' => CsvConstants::INVALID_FILTERS,
            ],
            'TransformationValidator: valid transformation' => [
                'validatorClass' => TransformationValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: ['price' => 'price'],
                    rowFilters: [],
                    valueTransformations: [['column' => 'price', 'operation' => 'add', 'value' => 10]],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => true,
            ],
            'TransformationValidator: missing column field' => [
                'validatorClass' => TransformationValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: ['price' => 'price'],
                    rowFilters: [],
                    valueTransformations: [['operation' => 'add', 'value' => 10]],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => false,
                'expectedErrorCode' => CsvConstants::INVALID_TRANSFORMATIONS,
            ],
            'ColumnRemovalValidator: valid columns' => [
                'validatorClass' => ColumnRemovalValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_REPLACE,
                    sourcePath: '',
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: ['brand'],
                    csvReader: $csvReader,
                ),
                'shouldPass' => true,
            ],
            'ColumnRemovalValidator: column not found' => [
                'validatorClass' => ColumnRemovalValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_REPLACE,
                    sourcePath: '',
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: ['nonexistent_column'],
                    csvReader: $csvReader,
                ),
                'shouldPass' => false,
                'expectedErrorCode' => CsvConstants::COLUMN_NOT_FOUND,
            ],
            'SourceFileValidator: valid source' => [
                'validatorClass' => SourceFileValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => true,
            ],
            'TargetFileValidator: valid target' => [
                'validatorClass' => TargetFileValidator::class,
                'contextFactory' => fn ($sourcePath, $targetPath, $csvReader) => new ValidationContext(
                    mode: CsvConstants::MODE_APPEND,
                    sourcePath: $sourcePath,
                    targetPath: $targetPath,
                    columnMappings: [],
                    rowFilters: [],
                    valueTransformations: [],
                    defaultValues: [],
                    columnsToRemove: [],
                    csvReader: $csvReader,
                ),
                'shouldPass' => true,
            ],
        ];
    }

    /**
     * @throws \RuntimeException
     */
    protected function createValidator(string $validatorClass): object
    {
        return match ($validatorClass) {
            ModeValidator::class => new ModeValidator(),
            ColumnMappingValidator::class => new ColumnMappingValidator(),
            FilterValidator::class => new FilterValidator(new FilterEvaluator()),
            TransformationValidator::class => new TransformationValidator(),
            ColumnRemovalValidator::class => new ColumnRemovalValidator(),
            SourceFileValidator::class => new SourceFileValidator(),
            TargetFileValidator::class => new TargetFileValidator(),
            default => throw new RuntimeException('Unknown validator class: ' . $validatorClass),
        };
    }

    protected function createTestFile(string $fileName): string
    {
        $filePath = $this->tempDir . '/' . $fileName;
        $content = $this->buildCsvContent();
        file_put_contents($filePath, $content);

        return $filePath;
    }
}
