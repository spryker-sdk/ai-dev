<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Strategy;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig;

class UpdateStrategy extends AbstractTransformStrategy
{
    /**
     * @param string $mode
     *
     * @return bool
     */
    public function isApplicable(string $mode): bool
    {
        return $mode === CsvConstants::MODE_UPDATE;
    }

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\TransformContext $context
     *
     * @return array<string, mixed>
     */
    public function execute(TransformContext $context): array
    {
        $result = $this->processRows($context->targetRows, $context->config);

        $this->csvWriter->write(
            $context->targetPath,
            $context->config->finalHeaders,
            $result['rows'],
        );

        $columnsRemovedCount = count($context->config->columnsToRemove);

        return [
            'rows_updated' => $result['processed_count'],
            'columns_removed' => $columnsRemovedCount,
            'total_rows' => count($context->targetRows),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig $config
     *
     * @return array<string, mixed>
     */
    protected function processRows(array $rows, RowProcessingConfig $config): array
    {
        $processedRows = [];
        $processedCount = 0;

        foreach ($rows as $row) {
            $matches = $this->filterEvaluator->evaluate($row, $config->rowFilters);

            if ($matches) {
                $row = $this->applyOperations($row, $config);
                $processedCount++;
            }

            $processedRows[] = $row;
        }

        return [
            'rows' => $processedRows,
            'processed_count' => $processedCount,
            'filtered_count' => 0,
        ];
    }
}
