<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use RuntimeException;
use SprykerSdk\Zed\AiDev\Business\DataImport\OdsConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\OdsReader;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group OdsReader
 */
class OdsReaderTest extends Unit
{
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

    public function testExtractContentThrowsExceptionWhenFileNotFound(): void
    {
        // Arrange
        $odsReader = new OdsReader();
        $filePath = $this->tempDir . '/nonexistent.ods';

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ODS file not found');

        // Act
        $odsReader->extractContent($filePath);
    }

    public function testExtractContentThrowsExceptionWhenFileIsInvalid(): void
    {
        // Arrange
        $odsReader = new OdsReader();
        $filePath = $this->tempDir . '/invalid.ods';
        file_put_contents($filePath, 'not a valid ods file');

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid ODS file format');

        // Act
        $odsReader->extractContent($filePath);
    }

    /**
     * @dataProvider extractRowsDataProvider
     */
    public function testExtractRows(string $xmlContent, int $expectedRowCount): void
    {
        // Arrange
        $odsReader = new OdsReader();
        $sheet = simplexml_load_string($xmlContent);

        // Act
        $rows = $odsReader->extractRows($sheet);

        // Assert
        $this->assertCount($expectedRowCount, $rows);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function extractRowsDataProvider(): array
    {
        return [
            'empty sheet' => [
                'xmlContent' => $this->buildSheetXml([]),
                'expectedRowCount' => 0,
            ],
            'single row' => [
                'xmlContent' => $this->buildSheetXml([['Cell1', 'Cell2']]),
                'expectedRowCount' => 1,
            ],
            'multiple rows' => [
                'xmlContent' => $this->buildSheetXml([
                    ['Header1', 'Header2'],
                    ['Value1', 'Value2'],
                    ['Value3', 'Value4'],
                ]),
                'expectedRowCount' => 3,
            ],
            'trailing empty rows trimmed' => [
                'xmlContent' => $this->buildSheetXml([
                    ['Header1', 'Header2'],
                    ['Value1', 'Value2'],
                    ['', ''],
                    ['', ''],
                ]),
                'expectedRowCount' => 2,
            ],
        ];
    }

    /**
     * @dataProvider getCellValueDataProvider
     */
    public function testGetCellValue(string $cellXml, string $expectedValue): void
    {
        // Arrange
        $odsReader = new OdsReader();
        $cell = simplexml_load_string($cellXml);

        // Act
        $value = $odsReader->getCellValue($cell);

        // Assert
        $this->assertSame($expectedValue, $value);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getCellValueDataProvider(): array
    {
        $ns = [
            'office' => OdsConstants::NAMESPACE_OFFICE,
            'text' => OdsConstants::NAMESPACE_TEXT,
        ];

        return [
            'string value' => [
                'cellXml' => sprintf(
                    '<table:table-cell xmlns:table="%s" xmlns:office="%s" xmlns:text="%s" office:value-type="string" office:string-value="Test"><text:p>Test</text:p></table:table-cell>',
                    OdsConstants::NAMESPACE_TABLE,
                    $ns['office'],
                    $ns['text'],
                ),
                'expectedValue' => 'Test',
            ],
            'float value' => [
                'cellXml' => sprintf(
                    '<table:table-cell xmlns:table="%s" xmlns:office="%s" office:value-type="float" office:value="123.45"></table:table-cell>',
                    OdsConstants::NAMESPACE_TABLE,
                    $ns['office'],
                ),
                'expectedValue' => '123.45',
            ],
            'percentage value' => [
                'cellXml' => sprintf(
                    '<table:table-cell xmlns:table="%s" xmlns:office="%s" office:value-type="percentage" office:value="0.75"></table:table-cell>',
                    OdsConstants::NAMESPACE_TABLE,
                    $ns['office'],
                ),
                'expectedValue' => '0.75',
            ],
            'currency value' => [
                'cellXml' => sprintf(
                    '<table:table-cell xmlns:table="%s" xmlns:office="%s" office:value-type="currency" office:value="99.99"></table:table-cell>',
                    OdsConstants::NAMESPACE_TABLE,
                    $ns['office'],
                ),
                'expectedValue' => '99.99',
            ],
            'boolean true value' => [
                'cellXml' => sprintf(
                    '<table:table-cell xmlns:table="%s" xmlns:office="%s" office:value-type="boolean" office:boolean-value="true"></table:table-cell>',
                    OdsConstants::NAMESPACE_TABLE,
                    $ns['office'],
                ),
                'expectedValue' => '1',
            ],
            'boolean false value' => [
                'cellXml' => sprintf(
                    '<table:table-cell xmlns:table="%s" xmlns:office="%s" office:value-type="boolean" office:boolean-value="false"></table:table-cell>',
                    OdsConstants::NAMESPACE_TABLE,
                    $ns['office'],
                ),
                'expectedValue' => '0',
            ],
            'date value' => [
                'cellXml' => sprintf(
                    '<table:table-cell xmlns:table="%s" xmlns:office="%s" office:value-type="date" office:date-value="2024-01-15"></table:table-cell>',
                    OdsConstants::NAMESPACE_TABLE,
                    $ns['office'],
                ),
                'expectedValue' => '2024-01-15',
            ],
            'empty cell' => [
                'cellXml' => sprintf(
                    '<table:table-cell xmlns:table="%s" xmlns:office="%s"></table:table-cell>',
                    OdsConstants::NAMESPACE_TABLE,
                    $ns['office'],
                ),
                'expectedValue' => '',
            ],
        ];
    }

    /**
     * @param array<array<string>> $rows
     */
    protected function buildSheetXml(array $rows): string
    {
        $ns = [
            'table' => OdsConstants::NAMESPACE_TABLE,
            'office' => OdsConstants::NAMESPACE_OFFICE,
            'text' => OdsConstants::NAMESPACE_TEXT,
        ];

        $rowsXml = '';
        foreach ($rows as $row) {
            $cellsXml = '';
            foreach ($row as $cellValue) {
                if ($cellValue === '') {
                    $cellsXml .= sprintf('<table:table-cell xmlns:table="%s" xmlns:office="%s"/>', $ns['table'], $ns['office']);
                } else {
                    $cellsXml .= sprintf(
                        '<table:table-cell xmlns:table="%s" xmlns:office="%s" xmlns:text="%s" office:value-type="string" office:string-value="%s"><text:p>%s</text:p></table:table-cell>',
                        $ns['table'],
                        $ns['office'],
                        $ns['text'],
                        htmlspecialchars($cellValue),
                        htmlspecialchars($cellValue),
                    );
                }
            }
            $rowsXml .= sprintf('<table:table-row xmlns:table="%s">%s</table:table-row>', $ns['table'], $cellsXml);
        }

        return sprintf(
            '<table:table xmlns:table="%s" xmlns:office="%s" xmlns:text="%s" table:name="Sheet1">%s</table:table>',
            $ns['table'],
            $ns['office'],
            $ns['text'],
            $rowsXml,
        );
    }
}
