<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Deleter;

use Generated\Shared\Transfer\DataImportCsvDeleteRequestTransfer;
use Generated\Shared\Transfer\DataImportCsvDeleteResponseTransfer;
use SprykerSdk\Service\AiDev\AiDevServiceInterface;
use SprykerSdk\Zed\AiDev\AiDevConfig;
use SprykerSdk\Zed\AiDev\Business\DataImport\Filter\RowFilterInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\Reader\DataImportCsvFileReaderInterface;

class DataImportCsvRowsDeleter implements DataImportCsvRowsDeleterInterface
{
    public function __construct(
        protected DataImportCsvFileReaderInterface $dataImportCsvFileReader,
        protected RowFilterInterface $rowFilter,
        protected AiDevServiceInterface $aiDevService
    ) {
    }

    public function deleteRows(DataImportCsvDeleteRequestTransfer $dataImportCsvDeleteRequestTransfer): DataImportCsvDeleteResponseTransfer
    {
        $filters = $dataImportCsvDeleteRequestTransfer->getFilters();
        $filterLogic = $dataImportCsvDeleteRequestTransfer->getFilterLogic() ?? AiDevConfig::FILTER_LOGIC_OR;
        $filePath = $dataImportCsvDeleteRequestTransfer->getFilePathOrFail();

        if ($filters === []) {
            return (new DataImportCsvDeleteResponseTransfer())
                ->setIsSuccess(false)
                ->setError('No filters provided. At least one filter is required to delete rows.')
                ->setRowsDeleted(0)
                ->setTotalRowsRemaining(0);
        }

        $readResult = $this->dataImportCsvFileReader->readDataImportCsvFile(
            $filePath,
            0,
            PHP_INT_MAX,
            [],
        );

        if (isset($readResult['error'])) {
            return (new DataImportCsvDeleteResponseTransfer())
                ->setIsSuccess(false)
                ->setError($readResult['error'])
                ->setRowsDeleted(0)
                ->setTotalRowsRemaining(0);
        }

        $headers = $readResult['columns'];
        $allRows = $readResult['rows'];
        $totalRowsBefore = count($allRows);

        $validationResponseTransfer = $this->rowFilter->validateFilters($headers, $filters);
        if (!$validationResponseTransfer->getIsValid()) {
            return (new DataImportCsvDeleteResponseTransfer())
                ->setIsSuccess(false)
                ->setError('Filter validation failed')
                ->setValidationErrors($validationResponseTransfer->getErrors())
                ->setRowsDeleted(0)
                ->setTotalRowsRemaining($totalRowsBefore);
        }

        $rowsToKeep = $this->filterRowsToKeep($allRows, $filters, $filterLogic);
        $rowsDeleted = $totalRowsBefore - count($rowsToKeep);

        $writeResult = $this->aiDevService->writeCsvFile(
            $readResult['filePath'],
            $headers,
            $rowsToKeep,
            AiDevConfig::CSV_MODE_OVERWRITE,
        );

        if (!$writeResult->getIsSuccess()) {
            return (new DataImportCsvDeleteResponseTransfer())
                ->setIsSuccess(false)
                ->setError($writeResult->getError() ?? 'Failed to write CSV file')
                ->setRowsDeleted(0)
                ->setTotalRowsRemaining($totalRowsBefore);
        }

        return (new DataImportCsvDeleteResponseTransfer())
            ->setIsSuccess(true)
            ->setFilePath($readResult['filePath'])
            ->setRowsDeleted($rowsDeleted)
            ->setTotalRowsBefore($totalRowsBefore)
            ->setTotalRowsRemaining(count($rowsToKeep))
            ->setFilterLogic($filterLogic);
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param array<array<string, mixed>> $filters
     * @param string $filterLogic
     *
     * @return array<array<string, mixed>>
     */
    protected function filterRowsToKeep(array $rows, array $filters, string $filterLogic): array
    {
        $rowsToKeep = [];

        foreach ($rows as $row) {
            if (!$this->rowFilter->matchesFilters($row, $filters, $filterLogic)) {
                $rowsToKeep[] = $row;
            }
        }

        return $rowsToKeep;
    }
}
