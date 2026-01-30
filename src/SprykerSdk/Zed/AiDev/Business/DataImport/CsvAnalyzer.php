<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use Exception;

class CsvAnalyzer implements CsvAnalyzerInterface
{
    use JsonResponseTrait;

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface $csvReader
     */
    public function __construct(protected CsvReaderInterface $csvReader)
    {
    }

    /**
     * @param string $filePath
     * @param int $sampleRows
     * @param array<string> $analyzeColumns
     *
     * @return string
     */
    public function analyze(string $filePath, int $sampleRows = 5, array $analyzeColumns = []): string
    {
        if (!file_exists($filePath)) {
            return $this->errorResponse(
                CsvConstants::FILE_NOT_FOUND,
                sprintf('File not found: %s', $filePath),
            );
        }

        if (!is_readable($filePath)) {
            return $this->errorResponse(
                CsvConstants::FILE_NOT_READABLE,
                sprintf('File is not readable: %s', $filePath),
            );
        }

        try {
            $headers = $this->csvReader->getHeaders($filePath);
            if (!$headers) {
                return $this->errorResponse(CsvConstants::NO_HEADERS, 'CSV file has no headers');
            }

            $rowCount = $this->csvReader->getRowCount($filePath);
            if ($rowCount === 0) {
                return $this->errorResponse(CsvConstants::EMPTY_FILE, 'CSV file has no data rows');
            }

            $samples = $this->getSampleRows($filePath, $rowCount, $sampleRows);

            $data = [
                'file_path' => $filePath,
                'row_count' => $rowCount,
                'headers' => $headers,
                'sample_rows' => $samples,
            ];

            if ($analyzeColumns) {
                $columnAnalysis = $this->analyzeColumns($filePath, $headers, $analyzeColumns);

                if (isset($columnAnalysis['error'])) {
                    return $this->errorResponse(CsvConstants::COLUMN_NOT_FOUND, $columnAnalysis['error']);
                }

                $data['column_analysis'] = $columnAnalysis;
            }

            return $this->successResponse($data);
        } catch (Exception $e) {
            return $this->errorResponse(
                CsvConstants::INVALID_CSV_FORMAT,
                sprintf('Error reading CSV file: %s', $e->getMessage()),
            );
        }
    }

    /**
     * @param string $filePath
     * @param int $rowCount
     * @param int $sampleRows
     *
     * @return array<array<string, string>>
     */
    protected function getSampleRows(string $filePath, int $rowCount, int $sampleRows): array
    {
        if ($rowCount <= CsvConstants::LARGE_FILE_THRESHOLD) {
            return $this->csvReader->getRows($filePath, 0, $sampleRows);
        }

        return $this->getSamplesFromLargeFile($filePath, $rowCount, $sampleRows);
    }

    /**
     * @param string $filePath
     * @param int $rowCount
     * @param int $sampleRows
     *
     * @return array<array<string, string>>
     */
    protected function getSamplesFromLargeFile(string $filePath, int $rowCount, int $sampleRows): array
    {
        $samplesPerSection = (int)ceil($sampleRows / 3);

        $beginning = $this->csvReader->getRows($filePath, 0, $samplesPerSection);

        $middleOffset = (int)floor($rowCount / 2) - (int)floor($samplesPerSection / 2);
        $middle = $this->csvReader->getRows($filePath, $middleOffset, $samplesPerSection);

        $endOffset = $rowCount - $samplesPerSection;
        $end = $this->csvReader->getRows($filePath, $endOffset, $samplesPerSection);

        $samples = array_merge($beginning, $middle, $end);

        return array_slice($samples, 0, $sampleRows);
    }

    /**
     * @param string $filePath
     * @param array<string> $headers
     * @param array<string> $analyzeColumns
     *
     * @return array<string, array<string, mixed>>
     */
    protected function analyzeColumns(string $filePath, array $headers, array $analyzeColumns): array
    {
        $missingColumns = array_diff($analyzeColumns, $headers);
        if ($missingColumns) {
            return [
                'error' => sprintf(
                    'Columns not found: %s. Available columns: %s',
                    implode(', ', $missingColumns),
                    implode(', ', $headers),
                ),
            ];
        }

        $analysis = [];
        $rows = $this->csvReader->getRows($filePath);

        foreach ($analyzeColumns as $column) {
            $analysis[$column] = $this->analyzeColumn($rows, $column);
        }

        return $analysis;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param string $column
     *
     * @return array<string, mixed>
     */
    protected function analyzeColumn(array $rows, string $column): array
    {
        $uniqueValues = [];
        $nullCount = 0;

        foreach ($rows as $row) {
            $value = $row[$column] ?? null;

            if ($value === null || $value === '') {
                $nullCount++;
            } else {
                $uniqueValues[$value] = true;
            }
        }

        return [
            'unique_count' => count($uniqueValues),
            'null_count' => $nullCount,
            'unique_values' => array_keys($uniqueValues),
        ];
    }
}
