<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvAnalyzer;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReader;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group CsvAnalyzer
 */
class CsvAnalyzerTest extends Unit
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

    public function testAnalyzeReturnsBasicFileStatistics(): void
    {
        // Arrange
        $filePath = $this->tempDir . '/test.csv';
        $content = $this->buildCsvContent();
        file_put_contents($filePath, $content);

        $csvAnalyzer = new CsvAnalyzer(new CsvReader());

        // Act
        $result = $csvAnalyzer->analyze($filePath, 5, []);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertTrue($resultData['success'] ?? false);
        $this->assertSame($filePath, $resultData['file_path']);
        $this->assertSame(3, $resultData['row_count']);
        $this->assertSame(static::PRODUCT_HEADERS, $resultData['headers']);
        $this->assertCount(3, $resultData['sample_rows']);
    }

    public function testAnalyzeWithColumnAnalysis(): void
    {
        // Arrange
        $filePath = $this->tempDir . '/test.csv';
        $content = $this->buildCsvContent();
        file_put_contents($filePath, $content);

        $csvAnalyzer = new CsvAnalyzer(new CsvReader());

        // Act
        $result = $csvAnalyzer->analyze($filePath, 5, ['brand', 'tax_set_name']);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertTrue($resultData['success'] ?? false);
        $this->assertArrayHasKey('column_analysis', $resultData);
        $this->assertArrayHasKey('brand', $resultData['column_analysis']);
        $this->assertArrayHasKey('tax_set_name', $resultData['column_analysis']);

        $brandAnalysis = $resultData['column_analysis']['brand'];
        $this->assertSame(3, $brandAnalysis['unique_count']);
        $this->assertSame(0, $brandAnalysis['null_count']);
        $this->assertContains('Canon', $brandAnalysis['unique_values']);
        $this->assertContains('Sony', $brandAnalysis['unique_values']);
        $this->assertContains('Nikon', $brandAnalysis['unique_values']);
    }

    public function testAnalyzeLargeFileHandling(): void
    {
        // Arrange
        $filePath = $this->tempDir . '/large_test.csv';
        $largeContent = $this->buildLargeCsvContent(1500);
        file_put_contents($filePath, $largeContent);

        $csvAnalyzer = new CsvAnalyzer(new CsvReader());

        // Act
        $result = $csvAnalyzer->analyze($filePath, 9, []);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertTrue($resultData['success'] ?? false);
        $this->assertSame(1500, $resultData['row_count']);
        $this->assertCount(9, $resultData['sample_rows']);
    }

    public function testAnalyzeReturnsErrorWhenFileNotFound(): void
    {
        // Arrange
        $filePath = $this->tempDir . '/nonexistent.csv';
        $csvAnalyzer = new CsvAnalyzer(new CsvReader());

        // Act
        $result = $csvAnalyzer->analyze($filePath);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertFalse($resultData['success'] ?? true);
        $this->assertSame(CsvConstants::FILE_NOT_FOUND, $resultData['error_code']);
    }

    public function testAnalyzeReturnsErrorWhenNoHeaders(): void
    {
        // Arrange
        $filePath = $this->tempDir . '/test.csv';
        file_put_contents($filePath, "\n");

        $csvAnalyzer = new CsvAnalyzer(new CsvReader());

        // Act
        $result = $csvAnalyzer->analyze($filePath);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertFalse($resultData['success'] ?? true);
        $this->assertSame(CsvConstants::INVALID_CSV_FORMAT, $resultData['error_code']);
    }

    public function testAnalyzeReturnsErrorWhenEmptyFile(): void
    {
        // Arrange
        $filePath = $this->tempDir . '/test.csv';
        $content = implode(',', static::PRODUCT_HEADERS) . "\n";
        file_put_contents($filePath, $content);

        $csvAnalyzer = new CsvAnalyzer(new CsvReader());

        // Act
        $result = $csvAnalyzer->analyze($filePath);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertFalse($resultData['success'] ?? true);
        $this->assertSame(CsvConstants::EMPTY_FILE, $resultData['error_code']);
    }

    public function testAnalyzeReturnsErrorWhenInvalidSampleRows(): void
    {
        // Arrange
        $filePath = $this->tempDir . '/test.csv';
        $content = $this->buildCsvContent();
        file_put_contents($filePath, $content);

        $csvAnalyzer = new CsvAnalyzer(new CsvReader());

        // Act
        $result = $csvAnalyzer->analyze($filePath, 0, []);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertFalse($resultData['success'] ?? true);
        $this->assertSame(CsvConstants::INVALID_CSV_FORMAT, $resultData['error_code']);
    }

    public function testAnalyzeReturnsErrorWhenColumnNotFound(): void
    {
        // Arrange
        $filePath = $this->tempDir . '/test.csv';
        $content = $this->buildCsvContent();
        file_put_contents($filePath, $content);

        $csvAnalyzer = new CsvAnalyzer(new CsvReader());

        // Act
        $result = $csvAnalyzer->analyze($filePath, 5, ['nonexistent_column']);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertFalse($resultData['success'] ?? true);
        $this->assertSame(CsvConstants::COLUMN_NOT_FOUND, $resultData['error_code']);
    }

    protected function buildLargeCsvContent(int $rowCount): string
    {
        $lines = [];
        $lines[] = implode(',', static::PRODUCT_HEADERS);

        for ($i = 1; $i <= $rowCount; $i++) {
            $row = [
                'abstract_sku' => sprintf('%03d', $i),
                'name.en_US' => 'Product ' . $i,
                'name.de_DE' => 'Produkt ' . $i,
                'brand' => 'Brand' . ($i % 10),
                'color' => 'Color' . ($i % 5),
                'color_code' => '#' . str_pad(dechex($i % 16777215), 6, '0', STR_PAD_LEFT),
                'price' => sprintf('%.2f', 10 + ($i % 100)),
                'tax_set_name' => 'Tax' . ($i % 3),
                'description.en_US' => 'Description ' . $i,
            ];

            $values = array_map(fn ($header) => $row[$header] ?? '', static::PRODUCT_HEADERS);
            $lines[] = implode(',', $values);
        }

        return implode("\n", $lines);
    }
}
