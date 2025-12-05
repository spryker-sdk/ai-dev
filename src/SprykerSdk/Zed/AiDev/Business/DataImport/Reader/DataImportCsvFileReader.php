<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Reader;

use Exception;
use Spryker\Service\UtilDataReader\Model\Reader\Csv\CsvReaderInterface;
use SprykerSdk\Service\AiDev\AiDevServiceInterface;
use SprykerSdk\Zed\AiDev\AiDevConfig;
use SprykerSdk\Zed\AiDev\Business\DataImport\Filter\RowFilterInterface;

class DataImportCsvFileReader implements DataImportCsvFileReaderInterface
{
    protected const int DEFAULT_LIMIT = 100;

    protected const int DEFAULT_OFFSET = 0;

    public function __construct(
        protected CsvReaderInterface $csvReader,
        protected RowFilterInterface $rowFilter,
        protected AiDevServiceInterface $aiDevService
    ) {
    }

    /**
     * @param array<array<string, mixed>>|string $filters
     *
     * @return array<string, mixed>
     */
    public function readDataImportCsvFile(
        string $filePath,
        int $offset = self::DEFAULT_OFFSET,
        int $limit = self::DEFAULT_LIMIT,
        array $filters = [],
        string $filterLogic = AiDevConfig::FILTER_LOGIC_AND
    ): array {
        $resolvedPath = $this->resolvePath($filePath);

        try {
            $this->csvReader->load($resolvedPath);
        } catch (Exception $exception) {
            return [
                'error' => sprintf('Failed to load CSV file: %s (resolved to: %s). Error: %s', $filePath, $resolvedPath, $exception->getMessage()),
                'columns' => [],
                'rows' => [],
                'totalRows' => 0,
            ];
        }

        $columns = $this->csvReader->getColumns();
        $totalRowsInFile = $this->csvReader->getTotal();
        $result = $this->readPaginatedRows($offset, $limit, $filters, $filterLogic);

        return [
            'filePath' => $resolvedPath,
            'columns' => $columns,
            'rows' => $result['rows'],
            'totalRows' => $result['totalFilteredRows'],
            'totalRowsInFile' => $totalRowsInFile,
            'offset' => $offset,
            'limit' => $limit,
            'filters' => $filters,
            'filterLogic' => $filterLogic,
            'errors' => $result['errors'],
            'skippedRows' => $result['skippedRows'],
        ];
    }

    protected function resolvePath(string $path): string
    {
        return $this->aiDevService->resolvePath($path);
    }

    /**
     * @param array<array<string, mixed>>|int $filters
     *
     * @return array<string, mixed>
     */
    protected function readPaginatedRows(int $offset, int $limit, array $filters = [], string $filterLogic = self::DEFAULT_FILTER_LOGIC): array
    {
        $this->csvReader->rewind();

        $errors = [];
        $rows = [];
        $skippedRows = 0;
        $totalFilteredRows = 0;
        $currentRowIndex = 0;
        $collectedEnoughRows = false;

        while ($this->csvReader->valid()) {
            try {
                $row = $this->csvReader->read();

                if (!$this->rowFilter->matchesFilters($row, $filters, $filterLogic)) {
                    continue;
                }

                $totalFilteredRows++;

                if ($collectedEnoughRows) {
                    continue;
                }

                if ($totalFilteredRows <= $offset) {
                    continue;
                }

                if (count($rows) >= $limit) {
                    $collectedEnoughRows = true;

                    continue;
                }

                $rows[] = $row;
            } catch (Exception $exception) {
                $errors[] = sprintf('Row %d: %s', $currentRowIndex + 1, $exception->getMessage());
                $skippedRows++;
            }

            $currentRowIndex++;
        }

        return [
            'rows' => $rows,
            'totalFilteredRows' => $totalFilteredRows,
            'errors' => $errors,
            'skippedRows' => $skippedRows,
        ];
    }
}
