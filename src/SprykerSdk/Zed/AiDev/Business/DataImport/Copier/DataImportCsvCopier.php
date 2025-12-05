<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Copier;

use Generated\Shared\Transfer\DataImportCsvCopyRequestTransfer;
use Generated\Shared\Transfer\DataImportCsvCopyResponseTransfer;
use SprykerSdk\Service\AiDev\AiDevServiceInterface;
use SprykerSdk\Zed\AiDev\AiDevConfig;
use SprykerSdk\Zed\AiDev\Business\DataImport\Mapper\ColumnMapperInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Reader\DataImportCsvFileReaderInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ColumnMappingValidatorInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Writer\DataImportCsvFileWriterInterface;

class DataImportCsvCopier implements DataImportCsvCopierInterface
{
    public function __construct(
        protected DataImportCsvFileReaderInterface $dataImportCsvFileReader,
        protected DataImportCsvFileWriterInterface $dataImportCsvFileWriter,
        protected AiDevServiceInterface $aiDevService,
        protected ColumnMappingValidatorInterface $columnMappingValidator,
        protected ColumnMapperInterface $columnMapper
    ) {
    }

    public function copyWithMapping(DataImportCsvCopyRequestTransfer $dataImportCsvCopyRequestTransfer): DataImportCsvCopyResponseTransfer
    {
        $sourceData = $this->dataImportCsvFileReader->readDataImportCsvFile(
            $dataImportCsvCopyRequestTransfer->getSourceFilePathOrFail(),
            0,
            PHP_INT_MAX,
            $dataImportCsvCopyRequestTransfer->getFilters(),
            $dataImportCsvCopyRequestTransfer->getFilterLogic() ?? AiDevConfig::FILTER_LOGIC_AND,
        );

        if (isset($sourceData['error'])) {
            return (new DataImportCsvCopyResponseTransfer())
                ->setIsSuccess(false)
                ->setError($sourceData['error'])
                ->setRowsCopied(0);
        }

        $targetData = $this->dataImportCsvFileReader->readDataImportCsvFile(
            $dataImportCsvCopyRequestTransfer->getTargetFilePathOrFail(),
            0,
            1,
        );

        if (isset($targetData['error'])) {
            return (new DataImportCsvCopyResponseTransfer())
                ->setIsSuccess(false)
                ->setError(sprintf('Failed to read target file: %s', $targetData['error']))
                ->setRowsCopied(0);
        }

        $sourceHeaders = $sourceData['columns'];
        $targetHeaders = $targetData['columns'];

        $validationErrors = $this->columnMappingValidator->validate(
            $sourceHeaders,
            $targetHeaders,
            $dataImportCsvCopyRequestTransfer->getColumnMapping(),
        );
        if ($validationErrors !== []) {
            return (new DataImportCsvCopyResponseTransfer())
                ->setIsSuccess(false)
                ->setError('Column mapping validation failed')
                ->setRowsCopied(0)
                ->setValidationErrors($validationErrors);
        }

        $transformedRows = $this->columnMapper->transformRows(
            $sourceData['rows'],
            $dataImportCsvCopyRequestTransfer->getColumnMapping(),
            $targetHeaders,
            $dataImportCsvCopyRequestTransfer->getValueReplacements(),
        );

        $resolvedTargetPath = $this->aiDevService->resolvePath(
            $dataImportCsvCopyRequestTransfer->getTargetFilePathOrFail(),
        );

        $writeResult = $this->aiDevService->writeCsvFile(
            $resolvedTargetPath,
            $targetHeaders,
            $transformedRows,
            $dataImportCsvCopyRequestTransfer->getMode() ?? AiDevConfig::CSV_MODE_APPEND,
        );

        if (!$writeResult->getIsSuccess()) {
            return (new DataImportCsvCopyResponseTransfer())
                ->setIsSuccess(false)
                ->setError($writeResult->getError() ?? 'Failed to write CSV file')
                ->setRowsCopied(0);
        }

        return (new DataImportCsvCopyResponseTransfer())
            ->setIsSuccess($writeResult->getIsSuccess())
            ->setError($writeResult->getError())
            ->setSourceFilePath($sourceData['filePath'])
            ->setTargetFilePath($resolvedTargetPath)
            ->setRowsCopied($writeResult->getRowsAffected())
            ->setMode($dataImportCsvCopyRequestTransfer->getMode() ?? AiDevConfig::CSV_MODE_APPEND)
            ->setFilterLogic($dataImportCsvCopyRequestTransfer->getFilterLogic() ?? AiDevConfig::FILTER_LOGIC_AND);
    }
}
