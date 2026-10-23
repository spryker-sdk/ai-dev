<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvRowDeleter;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriter;
use SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluator;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group CsvRowDeleter
 */
class CsvRowDeleterTest extends Unit
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
     * @dataProvider deleteRowsDataProvider
     *
     * @param array<int, array<string, mixed>> $criteria
     * @param array<int, array<string, mixed>>|null $customRows
     */
    public function testDeleteRows(
        array $criteria,
        int $expectedRowsDeleted,
        bool $createBackup,
        bool $shouldSucceed,
        ?string $expectedErrorCode = null,
        ?array $customRows = null
    ): void {
        // Arrange
        $filePath = $this->tempDir . '/test.csv';
        $rows = $customRows ?? static::PRODUCT_ROWS;
        $content = $this->buildCsvContentFromRows($rows);
        file_put_contents($filePath, $content);

        $csvReader = new CsvReader();
        $csvWriter = new CsvWriter($csvReader);
        $filterEvaluator = new FilterEvaluator();
        $csvRowDeleter = new CsvRowDeleter($csvReader, $csvWriter, $filterEvaluator);

        $initialRowCount = count($rows);

        // Act
        $result = $csvRowDeleter->deleteRows($filePath, $criteria, $createBackup);
        $resultData = json_decode($result, true);

        // Assert
        if ($shouldSucceed) {
            $this->assertTrue($resultData['success'] ?? false);
            $this->assertSame($initialRowCount, $resultData['rows_before']);
            $this->assertSame($expectedRowsDeleted, $resultData['rows_deleted']);
            $this->assertSame($initialRowCount - $expectedRowsDeleted, $resultData['rows_after']);

            if ($createBackup && $expectedRowsDeleted > 0) {
                $this->assertNotNull($resultData['backup_path'] ?? null);
                $this->assertFileExists($resultData['backup_path']);
            } else {
                $this->assertNull($resultData['backup_path'] ?? null);
            }

            $actualRows = $csvReader->getRows($filePath);
            $this->assertCount($initialRowCount - $expectedRowsDeleted, $actualRows);
        } else {
            $this->assertFalse($resultData['success'] ?? true);
            $this->assertSame($expectedErrorCode, $resultData['error_code']);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function deleteRowsDataProvider(): array
    {
        $allElectronicsRows = [
            [
                'abstract_sku' => '001',
                'name.en_US' => 'Canon',
                'name.de_DE' => 'Canon',
                'brand' => 'Canon',
                'color' => 'Red',
                'color_code' => '#DC2E09',
                'price' => '99.99',
                'tax_set_name' => 'Electronics',
                'description.en_US' => 'Camera',
            ],
            [
                'abstract_sku' => '002',
                'name.en_US' => 'Sony',
                'name.de_DE' => 'Sony',
                'brand' => 'Sony',
                'color' => 'Black',
                'color_code' => '#000000',
                'price' => '149.99',
                'tax_set_name' => 'Electronics',
                'description.en_US' => 'Camera',
            ],
        ];

        return [
            'delete matching rows' => [
                'criteria' => [['column' => 'brand', 'operator' => 'equals', 'value' => 'Canon']],
                'expectedRowsDeleted' => 1,
                'createBackup' => true,
                'shouldSucceed' => true,
                'expectedErrorCode' => null,
                'customRows' => null,
            ],
            'no matches' => [
                'criteria' => [['column' => 'brand', 'operator' => 'equals', 'value' => 'NonExistent']],
                'expectedRowsDeleted' => 0,
                'createBackup' => true,
                'shouldSucceed' => true,
                'expectedErrorCode' => null,
                'customRows' => null,
            ],
            'partial matches' => [
                'criteria' => [['column' => 'tax_set_name', 'operator' => 'equals', 'value' => 'Electronics']],
                'expectedRowsDeleted' => 2,
                'createBackup' => true,
                'shouldSucceed' => true,
                'expectedErrorCode' => null,
                'customRows' => null,
            ],
            'safety check prevents deleting all rows' => [
                'criteria' => [['column' => 'tax_set_name', 'operator' => 'equals', 'value' => 'Electronics']],
                'expectedRowsDeleted' => 0,
                'createBackup' => true,
                'shouldSucceed' => false,
                'expectedErrorCode' => CsvConstants::WOULD_DELETE_ALL_ROWS,
                'customRows' => $allElectronicsRows,
            ],
            'skip backup flag' => [
                'criteria' => [['column' => 'brand', 'operator' => 'equals', 'value' => 'Canon']],
                'expectedRowsDeleted' => 1,
                'createBackup' => false,
                'shouldSucceed' => true,
                'expectedErrorCode' => null,
                'customRows' => null,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function buildCsvContentFromRows(array $rows): string
    {
        if (!$rows) {
            return '';
        }

        $headers = array_keys($rows[0]);
        $lines = [];
        $lines[] = implode(',', $headers);

        foreach ($rows as $row) {
            $values = array_map(fn ($header) => $row[$header] ?? '', $headers);
            $lines[] = implode(',', $values);
        }

        return implode("\n", $lines);
    }
}
