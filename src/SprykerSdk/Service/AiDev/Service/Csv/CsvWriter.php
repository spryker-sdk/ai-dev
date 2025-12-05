<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\Csv;

use Exception;
use Generated\Shared\Transfer\CsvOperationResultTransfer;

class CsvWriter implements CsvWriterInterface
{
    protected const string CSV_DELIMITER = ',';

    protected const string CSV_ENCLOSURE = '"';

    protected const string CSV_ESCAPE = '\\';

    public function writeCsvFile(
        string $filePath,
        array $headers,
        array $rows,
        string $mode
    ): CsvOperationResultTransfer {
        try {
            $handle = fopen($filePath, $mode);

            if ($handle === false) {
                return $this->createErrorResult(sprintf('Failed to open file for %s', $mode === 'w' ? 'writing' : 'appending'));
            }

            if ($mode === 'w' && !$this->writeHeaders($handle, $headers)) {
                fclose($handle);

                return $this->createErrorResult('Failed to write headers');
            }

            $rowsWritten = $this->writeRows($handle, $headers, $rows);

            if ($rowsWritten === false) {
                fclose($handle);

                return $this->createErrorResult('Failed to write rows');
            }

            fclose($handle);

            return (new CsvOperationResultTransfer())
                ->setIsSuccess(true)
                ->setRowsAffected($rowsWritten);
        } catch (Exception $exception) {
            return $this->createErrorResult(
                sprintf('Failed to write to CSV file: %s', $exception->getMessage()),
            );
        }
    }

    protected function writeHeaders($handle, array $headers): bool
    {
        return fputcsv($handle, $headers, static::CSV_DELIMITER, static::CSV_ENCLOSURE, static::CSV_ESCAPE) !== false;
    }

    /**
     * @param array<string> $headers
     * @param array<array<string, mixed>> $rows
     *
     * @return int|false
     */
    protected function writeRows($handle, array $headers, array $rows): int|false
    {
        $rowsWritten = 0;

        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $header) {
                $values[] = $row[$header] ?? '';
            }

            if (fputcsv($handle, $values, static::CSV_DELIMITER, static::CSV_ENCLOSURE, static::CSV_ESCAPE) === false) {
                return false;
            }

            $rowsWritten++;
        }

        return $rowsWritten;
    }

    protected function createErrorResult(string $error): CsvOperationResultTransfer
    {
        return (new CsvOperationResultTransfer())
            ->setIsSuccess(false)
            ->setError($error)
            ->setRowsAffected(0);
    }
}
