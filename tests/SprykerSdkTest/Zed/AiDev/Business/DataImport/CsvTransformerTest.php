<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group CsvTransformer
 */
class CsvTransformerTest extends Unit
{
    use CsvTestDataTrait;

    protected string $tempDir;

    /**
     * @var \SprykerSdkTest\Zed\AiDev\BusinessTester
     */
    protected $tester;

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
     * @dataProvider positiveTransformDataProvider
     *
     * @param array<string>|null $sourceHeaders
     * @param array<int, array<string, mixed>>|null $sourceRows
     * @param array<string> $targetHeaders
     * @param array<int, array<string, mixed>> $targetRows
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<string, mixed> $defaultValues
     * @param array<string> $columnsToRemove
     * @param array<string, mixed> $expectedAssertions
     */
    public function testTransformPositiveCases(
        string $testName,
        ?array $sourceHeaders,
        ?array $sourceRows,
        array $targetHeaders,
        array $targetRows,
        array $columnMappings,
        array $rowFilters,
        array $valueTransformations,
        array $defaultValues,
        array $columnsToRemove,
        string $mode,
        bool $createBackup,
        array $expectedAssertions
    ): void {
        // Arrange
        $sourcePath = $sourceHeaders !== null ? $this->tempDir . '/source.csv' : '';
        $targetPath = $this->tempDir . '/target.csv';

        if ($sourceHeaders !== null) {
            $this->tester->createCsvFile($sourcePath, $sourceHeaders, $sourceRows ?? []);
        }

        $this->tester->createCsvFile($targetPath, $targetHeaders, $targetRows);

        $csvTransformer = $this->tester->createCsvTransformer();

        // Act
        $result = $csvTransformer->transform(
            $sourcePath,
            $targetPath,
            $columnMappings,
            $rowFilters,
            $valueTransformations,
            $defaultValues,
            $columnsToRemove,
            $mode,
            $createBackup,
        );

        $resultData = json_decode($result, true);
        $this->assertIsArray($resultData, 'Expected JSON result, got: ' . var_export($result, true));

        // Assert
        $this->tester->assertCsvTransformSuccess($resultData, $targetPath, $testName, $expectedAssertions);
    }

    /**
     * @dataProvider negativeTransformDataProvider
     *
     * @param array<string> $sourceHeaders
     * @param array<int, array<string, mixed>> $sourceRows
     * @param array<string> $targetHeaders
     * @param array<int, array<string, mixed>> $targetRows
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<string, mixed> $defaultValues
     * @param array<string> $columnsToRemove
     */
    public function testTransformNegativeCases(
        string $testName,
        array $sourceHeaders,
        array $sourceRows,
        array $targetHeaders,
        array $targetRows,
        array $columnMappings,
        array $rowFilters,
        array $valueTransformations,
        array $defaultValues,
        array $columnsToRemove,
        string $mode,
        string $expectedErrorCode
    ): void {
        // Arrange
        $sourcePath = $this->tempDir . '/source.csv';
        $targetPath = $this->tempDir . '/target.csv';

        $this->tester->createCsvFile($sourcePath, $sourceHeaders, $sourceRows);
        $this->tester->createCsvFile($targetPath, $targetHeaders, $targetRows);

        $csvTransformer = $this->tester->createCsvTransformer();

        // Act
        $result = $csvTransformer->transform(
            $sourcePath,
            $targetPath,
            $columnMappings,
            $rowFilters,
            $valueTransformations,
            $defaultValues,
            $columnsToRemove,
            $mode,
            false,
        );

        $resultData = json_decode($result, true);
        $this->assertIsArray($resultData, 'Expected JSON result, got: ' . var_export($result, true));

        // Assert
        $this->tester->assertCsvTransformFailure($resultData, $testName, $expectedErrorCode);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function positiveTransformDataProvider(): array
    {
        return [
            'actual column mapping from source to target' => [
                'testName' => 'actual column mapping',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => static::PRODUCT_SOURCE_ROWS,
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => [],
                'columnMappings' => $this->getStandardColumnMappings(),
                'rowFilters' => [],
                'valueTransformations' => [],
                'defaultValues' => [],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_APPEND,
                'createBackup' => false,
                'expectedAssertions' => [
                    'rowCount' => 10,
                    'rows' => $this->mapSourceToTargetRows(static::PRODUCT_SOURCE_ROWS),
                ],
            ],
            'multiple operations combined: mapping, filtering, defaults' => [
                'testName' => 'multiple operations combined',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => static::PRODUCT_SOURCE_ROWS,
                'targetHeaders' => array_merge(static::PRODUCT_TARGET_HEADERS, ['category', 'availability']),
                'targetRows' => [],
                'columnMappings' => $this->getStandardColumnMappings(),
                'rowFilters' => [['column' => 'status', 'operator' => 'equals', 'value' => 'active']],
                'valueTransformations' => [],
                'defaultValues' => ['category' => 'Cameras', 'availability' => 'In Stock'],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_APPEND,
                'createBackup' => false,
                'expectedAssertions' => [
                    'rowCount' => 8,
                    'rows' => array_map(
                        fn ($row) => array_merge($row, ['category' => 'Cameras', 'availability' => 'In Stock']),
                        $this->mapSourceToTargetRows([
                            static::PRODUCT_SOURCE_ROWS[0],
                            static::PRODUCT_SOURCE_ROWS[1],
                            static::PRODUCT_SOURCE_ROWS[2],
                            static::PRODUCT_SOURCE_ROWS[3],
                            static::PRODUCT_SOURCE_ROWS[4],
                            static::PRODUCT_SOURCE_ROWS[5],
                            static::PRODUCT_SOURCE_ROWS[7],
                            static::PRODUCT_SOURCE_ROWS[8],
                        ]),
                    ),
                ],
            ],
            'value transformations: math operations on price' => [
                'testName' => 'value transformations',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => static::PRODUCT_SOURCE_ROWS,
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => [],
                'columnMappings' => $this->getStandardColumnMappings(),
                'rowFilters' => [],
                'valueTransformations' => [
                    ['column' => 'price', 'operation' => 'add', 'value' => '100'],
                ],
                'defaultValues' => [],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_APPEND,
                'createBackup' => false,
                'expectedAssertions' => [
                    'rowCount' => 10,
                    'rows' => array_map(
                        fn ($row) => array_merge($row, ['price' => (string)((float)$row['price'] + 100)]),
                        $this->mapSourceToTargetRows(static::PRODUCT_SOURCE_ROWS),
                    ),
                ],
            ],
            'replace mode overwrites existing data' => [
                'testName' => 'replace mode',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => array_slice(static::PRODUCT_SOURCE_ROWS, 0, 3),
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => $this->mapSourceToTargetRows(array_slice(static::PRODUCT_SOURCE_ROWS, 3, 3)),
                'columnMappings' => $this->getStandardColumnMappings(),
                'rowFilters' => [],
                'valueTransformations' => [],
                'defaultValues' => [],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_REPLACE,
                'createBackup' => false,
                'expectedAssertions' => [
                    'rowCount' => 3,
                    'rows' => $this->mapSourceToTargetRows(array_slice(static::PRODUCT_SOURCE_ROWS, 0, 3)),
                ],
            ],
            'update mode processes existing rows with filters' => [
                'testName' => 'update mode',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => static::PRODUCT_SOURCE_ROWS,
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => $this->mapSourceToTargetRows(array_slice(static::PRODUCT_SOURCE_ROWS, 0, 5)),
                'columnMappings' => $this->getStandardColumnMappings(),
                'rowFilters' => [['column' => 'status', 'operator' => 'equals', 'value' => 'active']],
                'valueTransformations' => [],
                'defaultValues' => ['category' => 'Updated'],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_UPDATE,
                'createBackup' => false,
                'expectedAssertions' => [
                    'rowCount' => 5,
                    'rows' => $this->mapSourceToTargetRows(array_slice(static::PRODUCT_SOURCE_ROWS, 0, 5)),
                ],
            ],
            'column removal without source file' => [
                'testName' => 'column removal',
                'sourceHeaders' => null,
                'sourceRows' => null,
                'targetHeaders' => array_merge(static::PRODUCT_TARGET_HEADERS, ['internal_code', 'supplier_id']),
                'targetRows' => array_map(
                    fn ($row) => array_merge($row, ['internal_code' => 'INT_' . $row['sku'], 'supplier_id' => 'SUP_001']),
                    $this->mapSourceToTargetRows(static::PRODUCT_SOURCE_ROWS),
                ),
                'columnMappings' => [],
                'rowFilters' => [],
                'valueTransformations' => [],
                'defaultValues' => [],
                'columnsToRemove' => ['internal_code', 'supplier_id'],
                'mode' => CsvConstants::MODE_REPLACE,
                'createBackup' => false,
                'expectedAssertions' => [
                    'rowCount' => 10,
                    'headers' => [
                        'contains' => ['brand', 'price', 'sku'],
                        'notContains' => ['internal_code', 'supplier_id'],
                    ],
                ],
            ],
            'backup file is created when requested' => [
                'testName' => 'backup creation',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => array_slice(static::PRODUCT_SOURCE_ROWS, 0, 5),
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => $this->mapSourceToTargetRows(array_slice(static::PRODUCT_SOURCE_ROWS, 5, 5)),
                'columnMappings' => $this->getStandardColumnMappings(),
                'rowFilters' => [],
                'valueTransformations' => [],
                'defaultValues' => [],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_REPLACE,
                'createBackup' => true,
                'expectedAssertions' => [
                    'rowCount' => 5,
                    'hasBackup' => true,
                ],
            ],
            'partial column mapping leaves unmapped columns empty' => [
                'testName' => 'partial column mapping',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => static::PRODUCT_SOURCE_ROWS,
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => [],
                'columnMappings' => [
                    'manufacturer' => 'brand',
                    'product_id' => 'sku',
                ],
                'rowFilters' => [],
                'valueTransformations' => [],
                'defaultValues' => [],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_APPEND,
                'createBackup' => false,
                'expectedAssertions' => [
                    'rowCount' => 10,
                    'rows' => [
                        0 => ['brand' => 'Canon', 'sku' => 'SKU001', 'price' => ''],
                        1 => ['brand' => 'Sony', 'sku' => 'SKU002', 'price' => ''],
                        2 => ['brand' => 'Nikon', 'sku' => 'SKU003', 'price' => ''],
                        3 => ['brand' => 'Fuji', 'sku' => 'SKU004', 'price' => ''],
                        4 => ['brand' => 'Canon', 'sku' => 'SKU005', 'price' => ''],
                        5 => ['brand' => 'Sony', 'sku' => 'SKU006', 'price' => ''],
                        6 => ['brand' => 'Nikon', 'sku' => 'SKU007', 'price' => ''],
                        7 => ['brand' => 'Olympus', 'sku' => 'SKU008', 'price' => ''],
                        8 => ['brand' => 'Panasonic', 'sku' => 'SKU009', 'price' => ''],
                        9 => ['brand' => 'Leica', 'sku' => 'SKU010', 'price' => ''],
                    ],
                ],
            ],
            'complex filtering with in operator' => [
                'testName' => 'complex filtering',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => static::PRODUCT_SOURCE_ROWS,
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => [],
                'columnMappings' => $this->getStandardColumnMappings(),
                'rowFilters' => [['column' => 'manufacturer', 'operator' => 'in', 'value' => ['Sony', 'Nikon', 'Canon']]],
                'valueTransformations' => [],
                'defaultValues' => [],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_APPEND,
                'createBackup' => false,
                'expectedAssertions' => [
                    'rowCount' => 6,
                    'rows' => $this->mapSourceToTargetRows([
                        static::PRODUCT_SOURCE_ROWS[0],
                        static::PRODUCT_SOURCE_ROWS[1],
                        static::PRODUCT_SOURCE_ROWS[2],
                        static::PRODUCT_SOURCE_ROWS[4],
                        static::PRODUCT_SOURCE_ROWS[5],
                        static::PRODUCT_SOURCE_ROWS[6],
                    ]),
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function negativeTransformDataProvider(): array
    {
        return [
            'validation fails with invalid mode' => [
                'testName' => 'invalid mode validation',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => static::PRODUCT_SOURCE_ROWS,
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => [],
                'columnMappings' => $this->getStandardColumnMappings(),
                'rowFilters' => [],
                'valueTransformations' => [],
                'defaultValues' => [],
                'columnsToRemove' => [],
                'mode' => 'invalid_mode',
                'expectedErrorCode' => CsvConstants::OPERATION_FAILED,
            ],
            'validation fails with invalid source column' => [
                'testName' => 'invalid source column',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => static::PRODUCT_SOURCE_ROWS,
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => [],
                'columnMappings' => array_merge(
                    $this->getStandardColumnMappings(),
                    ['nonexistent_column' => 'brand'],
                ),
                'rowFilters' => [],
                'valueTransformations' => [],
                'defaultValues' => [],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_APPEND,
                'expectedErrorCode' => CsvConstants::INVALID_MAPPINGS,
            ],
            'validation fails with invalid filter column' => [
                'testName' => 'invalid filter column',
                'sourceHeaders' => static::PRODUCT_SOURCE_HEADERS,
                'sourceRows' => static::PRODUCT_SOURCE_ROWS,
                'targetHeaders' => static::PRODUCT_TARGET_HEADERS,
                'targetRows' => [],
                'columnMappings' => $this->getStandardColumnMappings(),
                'rowFilters' => [['column' => 'nonexistent_column', 'operator' => 'equals', 'value' => 'test']],
                'valueTransformations' => [],
                'defaultValues' => [],
                'columnsToRemove' => [],
                'mode' => CsvConstants::MODE_APPEND,
                'expectedErrorCode' => CsvConstants::INVALID_FILTERS,
            ],
        ];
    }
}
