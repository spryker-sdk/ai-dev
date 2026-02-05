<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReader;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group CsvReader
 */
class CsvReaderTest extends Unit
{
    use CsvTestDataTrait;

    /**
     * @var string
     */
    protected string $tempDir;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/csv_test_' . uniqid();
        mkdir($this->tempDir);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*'));
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * @dataProvider readDataProvider
     *
     * @param string $delimiter
     * @param int $offset
     * @param int|null $limit
     * @param int $expectedRowCount
     *
     * @return void
     */
    public function testRead(string $delimiter, int $offset, ?int $limit, int $expectedRowCount): void
    {
        // Arrange
        $content = $this->buildCsvContent($delimiter);
        $filePath = $this->createTempCsv($content);
        $csvReader = new CsvReader();

        // Act
        $headers = $csvReader->getHeaders($filePath);
        $rows = $csvReader->getRows($filePath, $offset, $limit);
        $rowCount = $csvReader->getRowCount($filePath);

        // Assert
        $this->assertSame(static::PRODUCT_HEADERS, $headers);
        $this->assertCount($expectedRowCount, $rows);
        $this->assertSame(count(static::PRODUCT_ROWS), $rowCount);

        foreach ($rows as $index => $row) {
            $expectedIndex = $offset + $index;
            if (isset(static::PRODUCT_ROWS[$expectedIndex])) {
                $this->assertSame(static::PRODUCT_ROWS[$expectedIndex], $row);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function readDataProvider(): array
    {
        return [
            'comma delimiter no pagination' => [
                'delimiter' => ',',
                'offset' => 0,
                'limit' => null,
                'expectedRowCount' => 3,
            ],
            'semicolon delimiter no pagination' => [
                'delimiter' => ';',
                'offset' => 0,
                'limit' => null,
                'expectedRowCount' => 3,
            ],
            'tab delimiter no pagination' => [
                'delimiter' => "\t",
                'offset' => 0,
                'limit' => null,
                'expectedRowCount' => 3,
            ],
            'comma delimiter with offset' => [
                'delimiter' => ',',
                'offset' => 1,
                'limit' => null,
                'expectedRowCount' => 2,
            ],
            'comma delimiter with limit' => [
                'delimiter' => ',',
                'offset' => 0,
                'limit' => 1,
                'expectedRowCount' => 1,
            ],
            'comma delimiter with offset and limit' => [
                'delimiter' => ',',
                'offset' => 1,
                'limit' => 1,
                'expectedRowCount' => 1,
            ],
        ];
    }
}
