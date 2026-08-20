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
use SprykerSdk\Zed\AiDev\Business\DataImport\Trait\FileValidationTrait;
use SprykerSdk\Zed\AiDev\Business\DataImport\Trait\JsonResponseTrait;

class OdsSplitter implements OdsSplitterInterface
{
    use JsonResponseTrait;
    use FileValidationTrait;

    protected const string ERROR_CODE_RUNTIME = 'runtime_error';

    protected const string ERROR_CODE_UNEXPECTED = 'unexpected_error';

    protected const int TOTAL_SHEETS_EMPTY = 0;

    public function __construct(
        protected OdsReaderInterface $odsReader,
        protected CsvWriterInterface $csvWriter,
    ) {
    }

    public function split(string $odsFilePath, string $outputDirectory): string
    {
        $validationError = $this->validateFilePath($odsFilePath, OdsConstants::INVALID_PATH, ['file_path' => $odsFilePath]);
        if ($validationError !== null) {
            return $this->errorResponse($validationError['code'], $validationError['message'], $validationError['details']);
        }

        $validationError = $this->validateFileExists($odsFilePath, OdsConstants::FILE_NOT_FOUND, 'ODS file not found', ['file_path' => $odsFilePath]);
        if ($validationError !== null) {
            return $this->errorResponse($validationError['code'], $validationError['message'], $validationError['details']);
        }

        $validationError = $this->validateFileReadable($odsFilePath, OdsConstants::FILE_NOT_READABLE, 'ODS file is not readable', ['file_path' => $odsFilePath]);
        if ($validationError !== null) {
            return $this->errorResponse($validationError['code'], $validationError['message'], $validationError['details']);
        }

        $validationError = $this->validateDirectoryPath($outputDirectory, OdsConstants::INVALID_PATH, ['directory' => $outputDirectory]);
        if ($validationError !== null) {
            return $this->errorResponse($validationError['code'], $validationError['message'], $validationError['details']);
        }

        $validationError = $this->validateAndPrepareOutputDirectory($outputDirectory);
        if ($validationError !== null) {
            return $this->errorResponse($validationError['code'], $validationError['message'], $validationError['details']);
        }

        try {
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
     * @return array<string, mixed>|null
     */
    protected function validateAndPrepareOutputDirectory(string $outputDirectory): ?array
    {
        $error = $this->ensureDirectoryExists($outputDirectory);
        if ($error !== null) {
            return $error;
        }

        return $this->ensureDirectoryIsWritable($outputDirectory);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function ensureDirectoryExists(string $outputDirectory): ?array
    {
        $pathError = $this->validateFilePath($outputDirectory, OdsConstants::INVALID_PATH, ['directory' => $outputDirectory]);
        if ($pathError !== null) {
            return $pathError;
        }

        if (is_dir($outputDirectory)) {
            return null;
        }

        if (mkdir($outputDirectory, OdsConstants::DIRECTORY_PERMISSIONS, true)) {
            return null;
        }

        if (is_dir($outputDirectory)) {
            return null;
        }

        return [
            'code' => OdsConstants::DIRECTORY_CREATE_FAILED,
            'message' => sprintf('Cannot create output directory: %s', $outputDirectory),
            'details' => ['directory' => $outputDirectory],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function ensureDirectoryIsWritable(string $outputDirectory): ?array
    {
        if (is_writable($outputDirectory)) {
            return null;
        }

        return [
            'code' => OdsConstants::DIRECTORY_NOT_WRITABLE,
            'message' => sprintf('Output directory is not writable: %s', $outputDirectory),
            'details' => ['directory' => $outputDirectory],
        ];
    }

    /**
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

                continue;
            }
            $sheetsSkipped[] = $result['sheetName'];
        }

        return [
            'filesCreated' => $filesCreated,
            'sheetsSkipped' => $sheetsSkipped,
        ];
    }

    /**
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

    protected function generateCsvFilePath(string $baseFileName, string $sheetName, string $outputDirectory): string
    {
        $sanitizedSheetName = $this->sanitizeSheetName($sheetName);
        $csvFileName = sprintf('%s_%s.csv', $baseFileName, $sanitizedSheetName);

        return $outputDirectory . DIRECTORY_SEPARATOR . $csvFileName;
    }

    /**
     * @param array<array<string>> $rows
     */
    protected function writeSheetToCsv(array $rows, string $csvFilePath): bool
    {
        $headers = array_shift($rows);

        if (!is_array($headers)) {
            return false;
        }

        $headers = $this->trimTrailingEmptyColumns($headers);
        $headers = $this->ensureUniqueHeaders($headers);
        $rows = $this->trimRowsToHeaderLength($rows, count($headers));

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

    protected function getSheetName(SimpleXMLElement $sheet): string
    {
        $attributes = $sheet->attributes(OdsConstants::NAMESPACE_TABLE);

        if (!isset($attributes['name'])) {
            return OdsConstants::DEFAULT_SHEET_NAME;
        }

        return (string)$attributes['name'];
    }

    protected function sanitizeSheetName(string $sheetName): string
    {
        $sanitized = preg_replace('/[\/\\\:*?"<>|]/', '_', $sheetName);

        if ($sanitized === null) {
            return $sheetName;
        }

        return $sanitized;
    }

    /**
     * @param array<string> $headers
     *
     * @return array<string>
     */
    protected function trimTrailingEmptyColumns(array $headers): array
    {
        while (end($headers) === '') {
            array_pop($headers);
        }

        return $headers;
    }

    /**
     * @param array<string, bool> $seen
     */
    protected function generateUniqueHeader(string $originalHeader, array $seen): string
    {
        $header = $originalHeader;
        $counter = 1;

        while (isset($seen[$header])) {
            $header = $originalHeader === ''
                ? sprintf('column_%d', $counter)
                : sprintf('%s_%d', $originalHeader, $counter);
            $counter++;
        }

        return $header;
    }

    /**
     * @param array<string> $headers
     *
     * @return array<string>
     */
    protected function ensureUniqueHeaders(array $headers): array
    {
        $seen = [];
        $result = [];

        foreach ($headers as $originalHeader) {
            $uniqueHeader = $this->generateUniqueHeader($originalHeader, $seen);
            $seen[$uniqueHeader] = true;
            $result[] = $uniqueHeader;
        }

        return $result;
    }

    /**
     * @param array<array<string>> $rows
     *
     * @return array<array<string>>
     */
    protected function trimRowsToHeaderLength(array $rows, int $headerLength): array
    {
        return array_map(
            fn (array $row): array => array_slice($row, 0, $headerLength),
            $rows,
        );
    }
}
