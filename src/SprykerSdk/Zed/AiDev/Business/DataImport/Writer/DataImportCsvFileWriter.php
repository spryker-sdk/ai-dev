<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Writer;

use Exception;
use Generated\Shared\Transfer\DataImportCsvWriteResponseTransfer;
use Spryker\Service\UtilDataReader\Model\Reader\Csv\CsvReaderInterface;
use SprykerSdk\Service\AiDev\AiDevServiceInterface;
use SprykerSdk\Zed\AiDev\AiDevConfig;
use SprykerSdk\Zed\AiDev\Business\DataImport\Mapper\ColumnMapperInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnMappingValidatorInterface;

class DataImportCsvFileWriter implements DataImportCsvFileWriterInterface
{
    public function __construct(
        protected CsvReaderInterface $csvReader,
        protected AiDevServiceInterface $aiDevService,
        protected ColumnMappingValidatorInterface $columnMappingValidator,
        protected ColumnMapperInterface $columnMapper
    ) {
    }

    /**
     * @param array<array<string, mixed>>|string $data
     * @param array<string, string> $columnMapping
     */
    public function writeDataImportCsvFile(string $filePath, array $data, array $columnMapping = []): DataImportCsvWriteResponseTransfer
    {
        $resolvedPath = $this->aiDevService->resolvePath($filePath);

        $fileValidation = $this->validateFile($resolvedPath, $filePath);
        if ($fileValidation !== null) {
            return $fileValidation;
        }

        $headers = $this->loadHeaders($resolvedPath);
        if ($headers instanceof DataImportCsvWriteResponseTransfer) {
            return $headers;
        }

        $validationErrors = $this->columnMappingValidator->validateDataAgainstHeaders($headers, $data, $columnMapping);
        if ($validationErrors !== []) {
            return $this->createErrorResponse('Column mapping validation failed', 0, $validationErrors);
        }

        $totalRowsBeforeAppend = $this->csvReader->getTotal();
        $mappedData = $this->columnMapper->mapDataToHeaders($data, $headers, $columnMapping);

        $result = $this->aiDevService->writeCsvFile(
            $resolvedPath,
            $headers,
            $mappedData,
            AiDevConfig::CSV_MODE_APPEND,
        );

        return (new DataImportCsvWriteResponseTransfer())
            ->setIsSuccess($result->getIsSuccess())
            ->setError($result->getError())
            ->setFilePath($resolvedPath)
            ->setRowsWritten($result->getRowsAffected())
            ->setTotalRowsBeforeAppend($totalRowsBeforeAppend)
            ->setTotalRowsAfterAppend($totalRowsBeforeAppend + $result->getRowsAffected());
    }

    protected function validateFile(string $resolvedPath, string $originalPath): ?DataImportCsvWriteResponseTransfer
    {
        if (!file_exists($resolvedPath)) {
            return $this->createErrorResponse(
                sprintf('CSV file does not exist: %s (resolved to: %s)', $originalPath, $resolvedPath),
            );
        }

        if (!is_writable($resolvedPath)) {
            return $this->createErrorResponse(
                sprintf('CSV file is not writable: %s', $resolvedPath),
            );
        }

        return null;
    }

    /**
     * @return \Generated\Shared\Transfer\DataImportCsvWriteResponseTransfer|array<string>
     */
    protected function loadHeaders(string $resolvedPath): array|DataImportCsvWriteResponseTransfer
    {
        try {
            $this->csvReader->load($resolvedPath);
        } catch (Exception $exception) {
            return $this->createErrorResponse(
                sprintf('Failed to load CSV file: %s. Error: %s', $resolvedPath, $exception->getMessage()),
            );
        }

        $headers = $this->csvReader->getColumns();

        if ($headers === []) {
            return $this->createErrorResponse('CSV file has no headers');
        }

        return $headers;
    }

    /**
     * @param array<string>|string $validationErrors
     */
    protected function createErrorResponse(string $error, int $rowsWritten = 0, array $validationErrors = []): DataImportCsvWriteResponseTransfer
    {
        $response = (new DataImportCsvWriteResponseTransfer())
            ->setIsSuccess(false)
            ->setError($error)
            ->setRowsWritten($rowsWritten);

        if ($validationErrors !== []) {
            $response->setValidationErrors($validationErrors);
        }

        return $response;
    }
}
