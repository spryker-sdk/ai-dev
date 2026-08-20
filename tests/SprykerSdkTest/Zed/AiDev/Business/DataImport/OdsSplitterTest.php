<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use RuntimeException;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriter;
use SprykerSdk\Zed\AiDev\Business\DataImport\OdsConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\OdsReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\OdsSplitter;
use ZipArchive;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group OdsSplitter
 */
class OdsSplitterTest extends Unit
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
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * Recursively remove directory and all its contents
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = glob($dir . '/*');
        if ($items) {
            foreach ($items as $item) {
                if (is_dir($item)) {
                    $this->removeDirectory($item);
                } else {
                    unlink($item);
                }
            }
        }
        rmdir($dir);
    }

    public function testSplitCreatesMultipleCsvFiles(): void
    {
        // Arrange
        $odsFilePath = $this->createTestOdsFile([
            'Sheet1' => [['Header1', 'Header2'], ['Value1', 'Value2']],
            'Sheet2' => [['HeaderA', 'HeaderB'], ['ValueA', 'ValueB']],
        ]);

        $outputDir = $this->tempDir . '/output';
        $odsSplitter = $this->createOdsSplitter();

        // Act
        $result = $odsSplitter->split($odsFilePath, $outputDir);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertTrue($resultData['success'] ?? false);
        $this->assertCount(2, $resultData['filesCreated']);
        $this->assertCount(0, $resultData['sheetsSkipped']);
        $this->assertSame(2, $resultData['totalSheets']);

        foreach ($resultData['filesCreated'] as $filePath) {
            $this->assertFileExists($filePath);
        }
    }

    public function testSplitSkipsEmptySheets(): void
    {
        // Arrange
        $odsFilePath = $this->createTestOdsFile([
            'Sheet1' => [['Header1', 'Header2'], ['Value1', 'Value2']],
            'EmptySheet' => [],
        ]);

        $outputDir = $this->tempDir . '/output';
        $odsSplitter = $this->createOdsSplitter();

        // Act
        $result = $odsSplitter->split($odsFilePath, $outputDir);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertTrue($resultData['success'] ?? false);
        $this->assertCount(1, $resultData['filesCreated']);
        $this->assertCount(1, $resultData['sheetsSkipped']);
    }

    public function testSplitSanitizesSheetNames(): void
    {
        // Arrange
        $odsFilePath = $this->createTestOdsFile([
            'Sheet:With*Invalid?Chars' => [['Header1'], ['Value1']],
        ]);

        $outputDir = $this->tempDir . '/output';
        $odsSplitter = $this->createOdsSplitter();

        // Act
        $result = $odsSplitter->split($odsFilePath, $outputDir);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertTrue($resultData['success'] ?? false);
        $this->assertCount(1, $resultData['filesCreated']);

        $fileName = basename($resultData['filesCreated'][0]);
        $this->assertStringNotContainsString(':', $fileName);
        $this->assertStringNotContainsString('*', $fileName);
        $this->assertStringNotContainsString('?', $fileName);
    }

    public function testSplitEnsuresUniqueHeaders(): void
    {
        // Arrange
        $odsFilePath = $this->createTestOdsFile([
            'Sheet1' => [['Header', 'Header', 'Header'], ['Value1', 'Value2', 'Value3']],
        ]);

        $outputDir = $this->tempDir . '/output';
        $odsSplitter = $this->createOdsSplitter();

        // Act
        $result = $odsSplitter->split($odsFilePath, $outputDir);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertTrue($resultData['success'] ?? false);

        $csvReader = new CsvReader();
        $headers = $csvReader->getHeaders($resultData['filesCreated'][0]);
        $this->assertCount(3, $headers);
        $this->assertSame('Header', $headers[0]);
        $this->assertSame('Header_1', $headers[1]);
        $this->assertSame('Header_2', $headers[2]);
    }

    public function testSplitTrimsTrailingEmptyColumns(): void
    {
        // Arrange
        $odsFilePath = $this->createTestOdsFile([
            'Sheet1' => [['Header1', 'Header2', '', ''], ['Value1', 'Value2', '', '']],
        ]);

        $outputDir = $this->tempDir . '/output';
        $odsSplitter = $this->createOdsSplitter();

        // Act
        $result = $odsSplitter->split($odsFilePath, $outputDir);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertTrue($resultData['success'] ?? false);

        $csvReader = new CsvReader();
        $headers = $csvReader->getHeaders($resultData['filesCreated'][0]);
        $this->assertCount(2, $headers);
    }

    public function testSplitReturnsErrorWhenFileNotFound(): void
    {
        // Arrange
        $odsFilePath = $this->tempDir . '/nonexistent.ods';
        $outputDir = $this->tempDir . '/output';
        $odsSplitter = $this->createOdsSplitter();

        // Act
        $result = $odsSplitter->split($odsFilePath, $outputDir);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertFalse($resultData['success'] ?? true);
        $this->assertSame(OdsConstants::FILE_NOT_FOUND, $resultData['error_code']);
    }

    public function testSplitReturnsErrorWhenInvalidPath(): void
    {
        // Arrange
        $odsFilePath = '';
        $outputDir = $this->tempDir . '/output';
        $odsSplitter = $this->createOdsSplitter();

        // Act
        $result = $odsSplitter->split($odsFilePath, $outputDir);
        $resultData = json_decode($result, true);

        // Assert
        $this->assertFalse($resultData['success'] ?? true);
        $this->assertSame(OdsConstants::INVALID_PATH, $resultData['error_code']);
    }

    /**
     * @param array<string, array<array<string>>> $sheets
     *
     * @throws \RuntimeException
     */
    protected function createTestOdsFile(array $sheets): string
    {
        $odsPath = $this->tempDir . '/test_' . uniqid() . '.ods';
        $contentXml = $this->buildOdsContentXml($sheets);

        $zip = new ZipArchive();
        if ($zip->open($odsPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Failed to create ODS file');
        }

        $zip->addFromString('content.xml', $contentXml);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
        $zip->close();

        return $odsPath;
    }

    /**
     * @param array<string, array<array<string>>> $sheets
     */
    protected function buildOdsContentXml(array $sheets): string
    {
        $ns = [
            'table' => OdsConstants::NAMESPACE_TABLE,
            'office' => OdsConstants::NAMESPACE_OFFICE,
            'text' => OdsConstants::NAMESPACE_TEXT,
        ];

        $tablesXml = '';
        foreach ($sheets as $sheetName => $rows) {
            $rowsXml = '';
            foreach ($rows as $row) {
                $cellsXml = '';
                foreach ($row as $cellValue) {
                    if ($cellValue === '') {
                        $cellsXml .= '<table:table-cell/>';
                    } else {
                        $cellsXml .= sprintf(
                            '<table:table-cell office:value-type="string" office:string-value="%s"><text:p>%s</text:p></table:table-cell>',
                            htmlspecialchars($cellValue),
                            htmlspecialchars($cellValue),
                        );
                    }
                }
                $rowsXml .= sprintf('<table:table-row>%s</table:table-row>', $cellsXml);
            }
            $tablesXml .= sprintf('<table:table table:name="%s">%s</table:table>', htmlspecialchars($sheetName), $rowsXml);
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>
            <office:document-content xmlns:office="%s" xmlns:table="%s" xmlns:text="%s">
                <office:body>
                    <office:spreadsheet>%s</office:spreadsheet>
                </office:body>
            </office:document-content>',
            $ns['office'],
            $ns['table'],
            $ns['text'],
            $tablesXml,
        );
    }

    protected function createOdsSplitter(): OdsSplitter
    {
        $odsReader = new OdsReader();
        $csvReader = new CsvReader();
        $csvWriter = new CsvWriter($csvReader);

        return new OdsSplitter($odsReader, $csvWriter);
    }
}
