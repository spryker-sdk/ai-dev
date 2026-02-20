<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Strategy;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriterInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluatorInterface;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig;

abstract class AbstractTransformStrategy
{
    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface $csvReader
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriterInterface $csvWriter
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluatorInterface $filterEvaluator
     * @param array<\SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowOperationInterface> $rowOperations
     */
    public function __construct(
        protected CsvReaderInterface $csvReader,
        protected CsvWriterInterface $csvWriter,
        protected FilterEvaluatorInterface $filterEvaluator,
        protected array $rowOperations,
    ) {
    }

    /**
     * @param string $mode
     *
     * @return bool
     */
    abstract public function isApplicable(string $mode): bool;

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\TransformContext $context
     *
     * @return array<string, mixed>
     */
    abstract public function execute(TransformContext $context): array;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig $config
     *
     * @return array<string, mixed>
     */
    protected function processRows(array $rows, RowProcessingConfig $config): array
    {
        $processedRows = [];
        $filteredCount = 0;
        $processedCount = 0;

        foreach ($rows as $row) {
            $matches = $this->filterEvaluator->evaluate($row, $config->rowFilters);

            if (!$matches) {
                $filteredCount++;

                continue;
            }

            $row = $this->applyOperations($row, $config);
            $processedRows[] = $row;
            $processedCount++;
        }

        return [
            'rows' => $processedRows,
            'processed_count' => $processedCount,
            'filtered_count' => $filteredCount,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig $config
     *
     * @return array<string, mixed>
     */
    protected function applyOperations(array $row, RowProcessingConfig $config): array
    {
        foreach ($this->rowOperations as $operation) {
            if ($operation->isApplicable($config)) {
                $row = $operation->execute($row, $config);
            }
        }

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string> $finalHeaders
     *
     * @return array<int, array<string, mixed>>
     */
    protected function addMissingColumns(array $rows, array $finalHeaders): array
    {
        $result = [];

        foreach ($rows as $row) {
            $newRow = [];
            foreach ($finalHeaders as $header) {
                $newRow[$header] = $row[$header] ?? '';
            }
            $result[] = $newRow;
        }

        return $result;
    }
}
