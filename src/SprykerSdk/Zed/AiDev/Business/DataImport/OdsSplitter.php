<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use Exception;
use RuntimeException;
use SimpleXMLElement;

class OdsSplitter implements OdsSplitterInterface
{
    use JsonResponseTrait;

    protected const string NAMESPACE_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    protected const string ERROR_CODE_RUNTIME = 'runtime_error';

    protected const string ERROR_CODE_UNEXPECTED = 'unexpected_error';

    protected const string DEFAULT_SHEET_NAME = 'Sheet';

    protected const int DIRECTORY_PERMISSIONS = 0755;

    protected const int TOTAL_SHEETS_EMPTY = 0;

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\OdsReaderInterface $odsReader
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriterInterface $csvWriter
     */
    public function __construct(
        protected OdsReaderInterface $odsReader,
        protected CsvWriterInterface $csvWriter,
    ) {
    }

    /**
     * @param string $odsFilePath
     * @param string $outputDirectory
     *
     * @return string
     */
    public function split(string $odsFilePath, string $outputDirectory): string
    {
        try {
            $this->validateAndPrepareOutputDirectory($outputDirectory);

            $sheets = $this->extractSheets($odsFilePath);

            if ($sheets === []) {
                return $this->createEmptyResponse();
            }

            $baseFileName = pathinfo($odsFilePath, PATHINFO_FILENAME);
            $result = $this->processSheets($sheets, $baseFileName, $outputDirectory);

            return $this->successResponse([
                'filesCreated' => $result['filesCreated'],
                'sheetsSkipped' => $result['sheetsSkipped'],
                'totalSheets' => count($sheets),
            ]);
        } catch (RuntimeException $e) {
            return $this->errorResponse(static::ERROR_CODE_RUNTIME, $e->getMessage());
        } catch (Exception $e) {
            return $this->errorResponse(
                static::ERROR_CODE_UNEXPECTED,
                sprintf('Unexpected error: %s', $e->getMessage()),
            );
        }
    }

    /**
     * @param string $outputDirectory
     *
     * @return void
     */
    protected function validateAndPrepareOutputDirectory(string $outputDirectory): void
    {
        $this->ensureDirectoryExists($outputDirectory);
        $this->ensureDirectoryIsWritable($outputDirectory);
    }

    /**
     * @param string $outputDirectory
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function ensureDirectoryExists(string $outputDirectory): void
    {
        if (is_dir($outputDirectory)) {
            return;
        }

        if (mkdir($outputDirectory, static::DIRECTORY_PERMISSIONS, true)) {
            return;
        }

        if (is_dir($outputDirectory)) {
            return;
        }

        throw new RuntimeException(
            sprintf('Cannot create output directory: %s', $outputDirectory),
        );
    }

    /**
     * @param string $outputDirectory
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function ensureDirectoryIsWritable(string $outputDirectory): void
    {
        if (is_writable($outputDirectory)) {
            return;
        }

        throw new RuntimeException(
            sprintf('Output directory is not writable: %s', $outputDirectory),
        );
    }

    /**
     * @param string $odsFilePath
     *
     * @return array<\SimpleXMLElement>
     */
    protected function extractSheets(string $odsFilePath): array
    {
        $xml = $this->odsReader->extractContent($odsFilePath);
        $sheets = $xml->xpath('//table:table');

        if ($sheets === false) {
            return [];
        }

        return $sheets;
    }

    /**
     * @return string
     */
    protected function createEmptyResponse(): string
    {
        return $this->successResponse([
            'filesCreated' => [],
            'sheetsSkipped' => [],
            'totalSheets' => static::TOTAL_SHEETS_EMPTY,
        ]);
    }

    /**
     * @param array<\SimpleXMLElement> $sheets
     * @param string $baseFileName
     * @param string $outputDirectory
     *
     * @return array<string, array<string>>
     */
    protected function processSheets(array $sheets, string $baseFileName, string $outputDirectory): array
    {
        $filesCreated = [];
        $sheetsSkipped = [];

        foreach ($sheets as $sheet) {
            $result = $this->processSingleSheet($sheet, $baseFileName, $outputDirectory);

            if ($result['success']) {
                $filesCreated[] = $result['filePath'];
            } else {
                $sheetsSkipped[] = $result['sheetName'];
            }
        }

        return [
            'filesCreated' => $filesCreated,
            'sheetsSkipped' => $sheetsSkipped,
        ];
    }

    /**
     * @param \SimpleXMLElement $sheet
     * @param string $baseFileName
     * @param string $outputDirectory
     *
     * @return array<string, mixed>
     */
    protected function processSingleSheet(SimpleXMLElement $sheet, string $baseFileName, string $outputDirectory): array
    {
        $sheetName = $this->getSheetName($sheet);
        $rows = $this->odsReader->extractRows($sheet);

        if (!$this->isValidSheetData($rows)) {
            return ['success' => false, 'sheetName' => $sheetName];
        }

        $csvFilePath = $this->generateCsvFilePath($baseFileName, $sheetName, $outputDirectory);
        $writingResult = $this->writeSheetToCsv($rows, $csvFilePath);

        if (!$writingResult) {
            return ['success' => false, 'sheetName' => $sheetName];
        }

        return ['success' => true, 'filePath' => $csvFilePath];
    }

    /**
     * @param array<array<string>> $rows
     *
     * @return bool
     */
    protected function isValidSheetData(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        if (!isset($rows[0])) {
            return false;
        }

        return is_array($rows[0]);
    }

    /**
     * @param string $baseFileName
     * @param string $sheetName
     * @param string $outputDirectory
     *
     * @return string
     */
    protected function generateCsvFilePath(string $baseFileName, string $sheetName, string $outputDirectory): string
    {
        $sanitizedSheetName = $this->sanitizeSheetName($sheetName);
        $csvFileName = sprintf('%s_%s.csv', $baseFileName, $sanitizedSheetName);

        return $outputDirectory . DIRECTORY_SEPARATOR . $csvFileName;
    }

    /**
     * @param array<array<string>> $rows
     * @param string $csvFilePath
     *
     * @return bool
     */
    protected function writeSheetToCsv(array $rows, string $csvFilePath): bool
    {
        $headers = array_shift($rows);

        if (!is_array($headers)) {
            return false;
        }

        $associativeRows = $this->convertToAssociativeRows($rows, $headers);

        $this->csvWriter->write($csvFilePath, $headers, $associativeRows, false);

        return true;
    }

    /**
     * @param array<array<string>> $rows
     * @param array<string> $headers
     *
     * @return array<array<string, string>>
     */
    protected function convertToAssociativeRows(array $rows, array $headers): array
    {
        $associativeRows = [];

        foreach ($rows as $row) {
            $associativeRow = $this->convertRowToAssociative($row, $headers);

            if ($associativeRow === null) {
                continue;
            }

            $associativeRows[] = $associativeRow;
        }

        return $associativeRows;
    }

    /**
     * @param mixed $row
     * @param array<string> $headers
     *
     * @return array<string, string>|null
     */
    protected function convertRowToAssociative(mixed $row, array $headers): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        $associativeRow = [];
        foreach ($headers as $index => $header) {
            $associativeRow[$header] = $row[$index] ?? '';
        }

        return $associativeRow;
    }

    /**
     * @param \SimpleXMLElement $sheet
     *
     * @return string
     */
    protected function getSheetName(SimpleXMLElement $sheet): string
    {
        $attributes = $sheet->attributes(static::NAMESPACE_TABLE);

        if (!isset($attributes['name'])) {
            return static::DEFAULT_SHEET_NAME;
        }

        return (string)$attributes['name'];
    }

    /**
     * @param string $sheetName
     *
     * @return string
     */
    protected function sanitizeSheetName(string $sheetName): string
    {
        $sanitized = preg_replace('/[\/\\\:*?"<>|]/', '_', $sheetName);

        if ($sanitized === null) {
            return $sheetName;
        }

        return $sanitized;
    }
}
