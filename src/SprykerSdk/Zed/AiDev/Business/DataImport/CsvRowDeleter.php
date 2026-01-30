<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use Exception;

class CsvRowDeleter implements CsvRowDeleterInterface
{
    use JsonResponseTrait;

    public function __construct(
        protected CsvReaderInterface $csvReader,
        protected CsvWriterInterface $csvWriter,
        protected FilterEvaluatorInterface $filterEvaluator,
    ) {
    }

    /**
     * @param string $filePath
     * @param array<int, array<string, mixed>> $criteria
     * @param bool $createBackup
     *
     * @return string
     */
    public function deleteRows(string $filePath, array $criteria, bool $createBackup = true): string
    {
        if (!file_exists($filePath)) {
            return $this->errorResponse(CsvConstants::FILE_NOT_FOUND, 'File does not exist', ['file_path' => $filePath]);
        }

        if (!is_writable($filePath)) {
            return $this->errorResponse(CsvConstants::FILE_NOT_WRITABLE, 'File is not writable', ['file_path' => $filePath]);
        }

        try {
            $headers = $this->csvReader->getHeaders($filePath);
            $validationErrors = $this->filterEvaluator->validateCriteria($criteria, $headers);

            if ($validationErrors) {
                return $this->errorResponse(CsvConstants::INVALID_CRITERIA, 'Criteria validation failed', ['errors' => $validationErrors]);
            }

            $rows = $this->csvReader->getRows($filePath);
            $filterResult = $this->filterRows($rows, $criteria);

            if ($filterResult['deleted_count'] === count($rows)) {
                return $this->errorResponse(
                    CsvConstants::WOULD_DELETE_ALL_ROWS,
                    'Criteria would delete all rows (safety check)',
                    ['rows_count' => count($rows)],
                );
            }

            if ($filterResult['deleted_count'] === 0) {
                return $this->successResponse([
                    'rows_before' => count($rows),
                    'rows_after' => count($rows),
                    'rows_deleted' => 0,
                    'backup_path' => null,
                ]);
            }

            $backupPath = $this->csvWriter->write($filePath, $headers, $filterResult['remaining_rows'], $createBackup);

            return $this->successResponse([
                'rows_before' => count($rows),
                'rows_after' => count($filterResult['remaining_rows']),
                'rows_deleted' => $filterResult['deleted_count'],
                'backup_path' => $backupPath,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse(CsvConstants::OPERATION_FAILED, $e->getMessage(), ['file_path' => $filePath]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $criteria
     *
     * @return array<string, mixed>
     */
    protected function filterRows(array $rows, array $criteria): array
    {
        $remainingRows = [];
        $deletedCount = 0;

        foreach ($rows as $row) {
            if ($this->filterEvaluator->evaluate($row, $criteria)) {
                $deletedCount++;

                continue;
            }

            $remainingRows[] = $row;
        }

        return [
            'remaining_rows' => $remainingRows,
            'deleted_count' => $deletedCount,
        ];
    }
}
