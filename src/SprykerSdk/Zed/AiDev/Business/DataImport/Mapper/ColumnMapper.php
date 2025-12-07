<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Mapper;

class ColumnMapper implements ColumnMapperInterface
{
    /**
     * @param array<array<string, mixed>> $data
     * @param array<string> $headers
     * @param array<string, string> $columnMapping
     *
     * @return array<array<string, mixed>>
     */
    public function mapDataToHeaders(array $data, array $headers, array $columnMapping): array
    {
        $reversedMapping = array_flip($columnMapping);

        return array_map(
            fn (array $row) => $this->mapRowToHeaders($row, $headers, $reversedMapping),
            $data,
        );
    }

    /**
     * @param array<array<string, mixed>> $sourceRows
     * @param array<string, mixed> $columnMapping
     * @param array<string> $targetHeaders
     * @param array<string, array<string, string>> $valueReplacements
     *
     * @return array<array<string, mixed>>
     */
    public function transformRows(
        array $sourceRows,
        array $columnMapping,
        array $targetHeaders,
        array $valueReplacements = []
    ): array {
        return array_map(
            fn (array $row) => $this->transformRow($row, $columnMapping, $targetHeaders, $valueReplacements),
            $sourceRows,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string> $headers
     * @param array<string, string> $reversedMapping
     *
     * @return array<string, mixed>
     */
    protected function mapRowToHeaders(array $row, array $headers, array $reversedMapping): array
    {
        $mappedRow = [];

        foreach ($headers as $header) {
            $mappedRow[$header] = $this->getValueForHeader($row, $header, $reversedMapping);
        }

        return $mappedRow;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $header
     * @param array<string, string> $reversedMapping
     *
     * @return string
     */
    protected function getValueForHeader(array $row, string $header, array $reversedMapping): string
    {
        $dataKey = $reversedMapping[$header] ?? $header;

        return $row[$dataKey] ?? '';
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @param array<string, mixed> $columnMapping
     * @param array<string> $targetHeaders
     * @param array<string, array<string, string>> $valueReplacements
     *
     * @return array<string, mixed>
     */
    protected function transformRow(
        array $sourceRow,
        array $columnMapping,
        array $targetHeaders,
        array $valueReplacements
    ): array {
        $targetRow = array_fill_keys($targetHeaders, '');

        foreach ($columnMapping as $sourceColumn => $targetColumns) {
            if (!isset($sourceRow[$sourceColumn])) {
                continue;
            }

            $value = $sourceRow[$sourceColumn];

            if (isset($valueReplacements[$sourceColumn])) {
                $value = $this->applyValueReplacement($value, $valueReplacements[$sourceColumn]);
            }

            $this->assignValueToTargetColumns($targetRow, $targetColumns, $value, $targetHeaders);
        }

        return $targetRow;
    }

    /**
     * @param array<string, mixed> $targetRow
     * @param array<string>|string $targetColumns
     * @param array<string> $targetHeaders
     */
    protected function assignValueToTargetColumns(array &$targetRow, array|string $targetColumns, mixed $value, array $targetHeaders): void
    {
        $columns = is_array($targetColumns) ? $targetColumns : [$targetColumns];

        foreach ($columns as $targetColumn) {
            if (in_array($targetColumn, $targetHeaders, true)) {
                $targetRow[$targetColumn] = $value;
            }
        }
    }

    /**
     * @param array<string, string> $replacements
     */
    protected function applyValueReplacement(mixed $value, array $replacements): mixed
    {
        $stringValue = (string)$value;

        return $replacements[$stringValue] ?? $value;
    }
}
