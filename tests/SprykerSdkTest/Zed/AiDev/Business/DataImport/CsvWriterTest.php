<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriter;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group CsvWriter
 */
class CsvWriterTest extends Unit
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
     * @dataProvider writeDataProvider
     *
     * @param array<string> $headers
     * @param array<int, array<string, mixed>> $rows
     */
    public function testWrite(
        array $headers,
        array $rows,
        ?string $existingContent,
        string $expectedDelimiter
    ): void {
        // Arrange
        $filePath = $this->tempDir . '/test.csv';
        if ($existingContent !== null) {
            file_put_contents($filePath, $existingContent);
        }

        $csvReader = new CsvReader();
        $csvWriter = new CsvWriter($csvReader);

        // Act
        $csvWriter->write($filePath, $headers, $rows);

        // Assert
        $this->assertFileExists($filePath);

        $actualHeaders = $csvReader->getHeaders($filePath);
        $actualRows = $csvReader->getRows($filePath);
        $actualDelimiter = $csvReader->detectDelimiter($filePath);

        $this->assertSame($headers, $actualHeaders);
        $this->assertCount(count($rows), $actualRows);
        $this->assertSame($expectedDelimiter, $actualDelimiter);

        foreach ($rows as $index => $expectedRow) {
            $this->assertSame($expectedRow, $actualRows[$index]);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function writeDataProvider(): array
    {
        return [
            'new file with default delimiter' => [
                'headers' => static::PRODUCT_HEADERS,
                'rows' => static::PRODUCT_ROWS,
                'existingContent' => null,
                'expectedDelimiter' => ',',
            ],
            'existing file preserves semicolon delimiter' => [
                'headers' => static::PRODUCT_HEADERS,
                'rows' => static::PRODUCT_ROWS,
                'existingContent' => implode(';', static::PRODUCT_HEADERS) . "\n",
                'expectedDelimiter' => ';',
            ],
            'existing file preserves tab delimiter' => [
                'headers' => static::PRODUCT_HEADERS,
                'rows' => static::PRODUCT_ROWS,
                'existingContent' => implode("\t", static::PRODUCT_HEADERS) . "\n",
                'expectedDelimiter' => "\t",
            ],
            'empty rows' => [
                'headers' => static::PRODUCT_HEADERS,
                'rows' => [],
                'existingContent' => null,
                'expectedDelimiter' => ',',
            ],
            'single row' => [
                'headers' => static::PRODUCT_HEADERS,
                'rows' => [static::PRODUCT_ROWS[0]],
                'existingContent' => null,
                'expectedDelimiter' => ',',
            ],
            'row with missing column values' => [
                'headers' => static::PRODUCT_HEADERS,
                'rows' => [[
                    'abstract_sku' => '003',
                    'name.en_US' => '',
                    'name.de_DE' => '',
                    'brand' => 'Nikon',
                    'color' => '',
                    'color_code' => '',
                    'price' => '',
                    'tax_set_name' => '',
                    'description.en_US' => '',
                ]],
                'existingContent' => null,
                'expectedDelimiter' => ',',
            ],
        ];
    }
}
