<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Strategy;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;

class AppendStrategy extends AbstractTransformStrategy
{
    public function isApplicable(string $mode): bool
    {
        return $mode === CsvConstants::MODE_APPEND;
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(TransformContext $context): array
    {
        $result = $this->processRows($context->sourceRows, $context->config);
        $this->writeResults($context, $result);

        return $this->buildResultData($context, $result);
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function writeResults(TransformContext $context, array $result): void
    {
        $headersChanged = $context->config->finalHeaders !== $context->targetHeaders;

        if (!$headersChanged) {
            $this->csvWriter->append($context->targetPath, $result['rows']);

            return;
        }

        $this->writeWithChangedHeaders($context, $result);
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function writeWithChangedHeaders(TransformContext $context, array $result): void
    {
        $this->csvWriter->ensureFileEndsWithNewline($context->targetPath);
        $existingRows = $this->csvReader->getRows($context->targetPath);
        $existingRows = $this->addMissingColumns($existingRows, $context->config->finalHeaders);
        $allRows = array_merge($existingRows, $result['rows']);

        $this->csvWriter->write($context->targetPath, $context->config->finalHeaders, $allRows);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    protected function buildResultData(TransformContext $context, array $result): array
    {
        $unmappedSource = array_diff($context->sourceHeaders, array_keys($context->config->columnMappings));
        $unmappedTarget = array_diff($context->targetHeaders, array_values($context->config->columnMappings));

        return [
            'rows_appended' => count($result['rows']),
            'rows_filtered_out' => $result['filtered_count'],
            'transformations_applied' => $result['processed_count'],
            'unmapped_source_columns' => array_values($unmappedSource),
            'unmapped_target_columns' => array_values($unmappedTarget),
        ];
    }
}
