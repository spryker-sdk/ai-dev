<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

class ColumnMappingValidator implements ColumnMappingValidatorInterface
{
    /**
     * @param array<string> $sourceHeaders
     * @param array<string> $targetHeaders
     * @param array<string, mixed> $columnMapping
     *
     * @return array<string>
     */
    public function validate(array $sourceHeaders, array $targetHeaders, array $columnMapping): array
    {
        $errors = [];

        foreach ($columnMapping as $sourceColumn => $targetColumn) {
            if (!in_array($sourceColumn, $sourceHeaders, true)) {
                $errors[] = sprintf('Source column "%s" does not exist in source file', $sourceColumn);
            }

            if (is_array($targetColumn)) {
                foreach ($targetColumn as $targetCol) {
                    if (!in_array($targetCol, $targetHeaders, true)) {
                        $errors[] = sprintf('Target column "%s" does not exist in target file', $targetCol);
                    }
                }
            } elseif (!in_array($targetColumn, $targetHeaders, true)) {
                $errors[] = sprintf('Target column "%s" does not exist in target file', $targetColumn);
            }
        }

        return $errors;
    }

    /**
     * @param array<string> $headers
     * @param array<array<string, mixed>> $data
     * @param array<string, string> $columnMapping
     *
     * @return array<string>
     */
    public function validateDataAgainstHeaders(array $headers, array $data, array $columnMapping): array
    {
        if ($data === []) {
            return ['Data array is empty'];
        }

        $dataKeys = array_keys($data[0]);
        $mappedKeys = array_keys($columnMapping);

        return array_merge(
            $this->validateDataKeys($dataKeys, $mappedKeys, $headers),
            $this->validateMappingKeys($columnMapping, $dataKeys, $headers),
        );
    }

    /**
     * @param array<string> $dataKeys
     * @param array<string> $mappedKeys
     * @param array<string> $headers
     *
     * @return array<string>
     */
    protected function validateDataKeys(array $dataKeys, array $mappedKeys, array $headers): array
    {
        $errors = [];

        foreach ($dataKeys as $dataKey) {
            if (in_array($dataKey, $mappedKeys, true) || in_array($dataKey, $headers, true)) {
                continue;
            }

            $errors[] = sprintf(
                'Data key "%s" is not mapped and does not match any CSV header. Available headers: %s',
                $dataKey,
                implode(', ', $headers),
            );
        }

        return $errors;
    }

    /**
     * @param array<string, string> $columnMapping
     * @param array<string> $dataKeys
     * @param array<string> $headers
     *
     * @return array<string>
     */
    protected function validateMappingKeys(array $columnMapping, array $dataKeys, array $headers): array
    {
        $errors = [];

        foreach ($columnMapping as $dataKey => $csvHeader) {
            if (!in_array($dataKey, $dataKeys, true)) {
                $errors[] = sprintf('Mapping key "%s" does not exist in data', $dataKey);
            }

            if (!in_array($csvHeader, $headers, true)) {
                $errors[] = sprintf(
                    'Mapped CSV header "%s" does not exist in file. Available headers: %s',
                    $csvHeader,
                    implode(', ', $headers),
                );
            }
        }

        return $errors;
    }
}
