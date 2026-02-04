<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;

class TransformationOperation implements RowOperationInterface
{
    /**
     * @var float
     */
    protected const float DIVISION_EPSILON = 0.0;

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig $config
     *
     * @return bool
     */
    public function isApplicable(RowProcessingConfig $config): bool
    {
        return !empty($config->valueTransformations);
    }

    /**
     * @param array<string, mixed> $row
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig $config
     *
     * @return array<string, mixed>
     */
    public function execute(array $row, RowProcessingConfig $config): array
    {
        foreach ($config->valueTransformations as $transformation) {
            $column = $transformation['column'];

            if (isset($transformation['operation'])) {
                $row = $this->applyMathOperation($row, $transformation);

                continue;
            }

            if (isset($transformation['find']) && isset($transformation['replace'])) {
                $row = $this->applyStringReplacement($row, $column, $transformation['find'], $transformation['replace']);
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $column
     * @param string $find
     * @param string $replace
     *
     * @return array<string, mixed>
     */
    protected function applyStringReplacement(array $row, string $column, string $find, string $replace): array
    {
        if (isset($row[$column])) {
            $row[$column] = str_replace($find, $replace, (string)$row[$column]);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $transformation
     *
     * @return array<string, mixed>
     */
    protected function applyMathOperation(array $row, array $transformation): array
    {
        $column = $transformation['column'];
        $operation = $transformation['operation'];
        $value = $transformation['value'];
        $sourceColumn = $transformation['sourceColumn'] ?? $column;

        if (!isset($row[$sourceColumn])) {
            return $row;
        }

        $sourceValue = is_numeric($row[$sourceColumn]) ? (float)$row[$sourceColumn] : 0;

        $row[$column] = match ($operation) {
            CsvConstants::OPERATION_ADD => $sourceValue + $value,
            CsvConstants::OPERATION_SUBTRACT => $sourceValue - $value,
            CsvConstants::OPERATION_MULTIPLY => $sourceValue * $value,
            CsvConstants::OPERATION_DIVIDE => $value != static::DIVISION_EPSILON ? $sourceValue / $value : $sourceValue,
            default => $row[$column] ?? '',
        };

        return $row;
    }
}
