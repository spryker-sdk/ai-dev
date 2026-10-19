<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Strategy;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;

class ReplaceStrategy extends AbstractTransformStrategy
{
    public function isApplicable(string $mode): bool
    {
        return $mode === CsvConstants::MODE_REPLACE;
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(TransformContext $context): array
    {
        $rowsToProcess = $context->sourceRows ?? $context->targetRows;
        $result = $this->processRows($rowsToProcess, $context->config);

        $this->csvWriter->write(
            $context->targetPath,
            $context->config->finalHeaders,
            $result['rows'],
        );

        $unmappedSource = $context->sourceHeaders !== null
            ? array_diff($context->sourceHeaders, array_keys($context->config->columnMappings))
            : [];
        $unmappedTarget = array_diff($context->targetHeaders, array_values($context->config->columnMappings));

        return [
            'rows_written' => count($result['rows']),
            'rows_filtered_out' => $result['filtered_count'],
            'transformations_applied' => $result['processed_count'],
            'unmapped_source_columns' => array_values($unmappedSource),
            'unmapped_target_columns' => array_values($unmappedTarget),
        ];
    }
}
