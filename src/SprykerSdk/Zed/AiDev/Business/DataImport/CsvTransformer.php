<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use Exception;

class CsvTransformer implements CsvTransformerInterface
{
    use JsonResponseTrait;

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface $csvReader
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriterInterface $csvWriter
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluatorInterface $filterEvaluator
     */
    public function __construct(
        protected CsvReaderInterface $csvReader,
        protected CsvWriterInterface $csvWriter,
        protected FilterEvaluatorInterface $filterEvaluator,
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param string $sourcePath
     * @param string $targetPath
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<string, mixed> $defaultValues
     * @param string $mode
     * @param bool $createBackup
     *
     * @return string
     */
    public function transform(
        string $sourcePath,
        string $targetPath,
        array $columnMappings,
        array $rowFilters = [],
        array $valueTransformations = [],
        array $defaultValues = [],
        string $mode = CsvConstants::MODE_APPEND,
        bool $createBackup = true
    ): string {
        $validationError = $this->validateFiles($sourcePath, $targetPath);
        if ($validationError !== null) {
            return $validationError;
        }

        if (!in_array($mode, CsvConstants::SUPPORTED_MODES, true)) {
            return $this->errorResponse(
                CsvConstants::OPERATION_FAILED,
                sprintf('Invalid mode "%s"', $mode),
                ['mode' => $mode, 'supported_modes' => CsvConstants::SUPPORTED_MODES],
            );
        }

        try {
            $sourceHeaders = $this->csvReader->getHeaders($sourcePath);
            $targetHeaders = $this->csvReader->getHeaders($targetPath);
            $finalHeaders = $this->mergeHeadersWithMappingsAndDefaults($targetHeaders, $columnMappings, $defaultValues);

            $validationError = $this->validateTransformationParameters(
                $columnMappings,
                $rowFilters,
                $valueTransformations,
                $sourceHeaders,
                $targetHeaders,
            );
            if ($validationError !== null) {
                return $validationError;
            }

            $sourceRows = $this->csvReader->getRows($sourcePath);
            $result = $this->processRows($sourceRows, $columnMappings, $rowFilters, $valueTransformations, $defaultValues, $finalHeaders);

            if ($mode === CsvConstants::MODE_REPLACE) {
                $backupPath = $this->csvWriter->write($targetPath, $finalHeaders, $result['transformed_rows'], $createBackup);

                return $this->buildSuccessResponse($result, $columnMappings, $sourceHeaders, $finalHeaders, $backupPath);
            }

            $hasNewColumns = count($finalHeaders) > count($targetHeaders);

            if ($hasNewColumns) {
                $this->csvWriter->ensureFileEndsWithNewline($targetPath);

                $existingRows = $this->csvReader->getRows($targetPath);
                $existingRowsWithNewColumns = $this->addMissingColumns($existingRows, $finalHeaders);
                $allRows = array_merge($existingRowsWithNewColumns, $result['transformed_rows']);
                $backupPath = $this->csvWriter->write($targetPath, $finalHeaders, $allRows, $createBackup);

                return $this->buildSuccessResponse($result, $columnMappings, $sourceHeaders, $finalHeaders, $backupPath);
            }

            $backupPath = $this->csvWriter->append($targetPath, $result['transformed_rows'], $createBackup);

            return $this->buildSuccessResponse($result, $columnMappings, $sourceHeaders, $finalHeaders, $backupPath);
        } catch (Exception $e) {
            return $this->errorResponse(
                CsvConstants::OPERATION_FAILED,
                $e->getMessage(),
                ['source_path' => $sourcePath, 'target_path' => $targetPath],
            );
        }
    }

    /**
     * @param string $sourcePath
     * @param string $targetPath
     *
     * @return string|null
     */
    protected function validateFiles(string $sourcePath, string $targetPath): ?string
    {
        if (!file_exists($sourcePath)) {
            return $this->errorResponse(CsvConstants::FILE_NOT_FOUND, 'Source file does not exist', ['file_path' => $sourcePath]);
        }

        if (!file_exists($targetPath)) {
            return $this->errorResponse(CsvConstants::FILE_NOT_FOUND, 'Target file does not exist', ['file_path' => $targetPath]);
        }

        if (!is_writable($targetPath)) {
            return $this->errorResponse(CsvConstants::FILE_NOT_WRITABLE, 'Target file is not writable', ['file_path' => $targetPath]);
        }

        return null;
    }

    /**
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<string> $sourceHeaders
     * @param array<string> $targetHeaders
     *
     * @return string|null
     */
    protected function validateTransformationParameters(
        array $columnMappings,
        array $rowFilters,
        array $valueTransformations,
        array $sourceHeaders,
        array $targetHeaders,
    ): ?string {
        $mappingsForValidation = array_flip($columnMappings);
        $mappingErrors = $this->validateColumnMappings($mappingsForValidation, $sourceHeaders, $targetHeaders);
        if ($mappingErrors) {
            return $this->errorResponse(CsvConstants::INVALID_MAPPINGS, 'Column mappings validation failed', ['errors' => $mappingErrors]);
        }

        $filterErrors = $this->filterEvaluator->validateCriteria($rowFilters, $sourceHeaders);
        if ($filterErrors) {
            return $this->errorResponse(CsvConstants::INVALID_FILTERS, 'Row filters validation failed', ['errors' => $filterErrors]);
        }

        $transformationErrors = $this->validateTransformations($valueTransformations, array_values($columnMappings));
        if ($transformationErrors) {
            return $this->errorResponse(CsvConstants::INVALID_TRANSFORMATIONS, 'Value transformations validation failed', ['errors' => $transformationErrors]);
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceRows
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<string, mixed> $defaultValues
     * @param array<string> $targetHeaders
     *
     * @return array<string, mixed>
     */
    protected function processRows(
        array $sourceRows,
        array $columnMappings,
        array $rowFilters,
        array $valueTransformations,
        array $defaultValues,
        array $targetHeaders,
    ): array {
        $transformedRows = [];
        $filteredOutCount = 0;
        $transformationsApplied = 0;

        foreach ($sourceRows as $sourceRow) {
            if (!$this->filterEvaluator->evaluate($sourceRow, $rowFilters)) {
                $filteredOutCount++;

                continue;
            }

            $targetRow = $this->mapRow($sourceRow, $columnMappings, $targetHeaders);
            $targetRow = $this->applyDefaultValues($targetRow, $defaultValues);

            if ($valueTransformations) {
                $targetRow = $this->transformRow($targetRow, $valueTransformations);
                $transformationsApplied++;
            }

            $transformedRows[] = $targetRow;
        }

        return [
            'transformed_rows' => $transformedRows,
            'filtered_out_count' => $filteredOutCount,
            'transformations_applied' => $transformationsApplied,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, string> $columnMappings
     * @param array<string> $sourceHeaders
     * @param array<string> $targetHeaders
     * @param string|null $backupPath
     *
     * @return string
     */
    protected function buildSuccessResponse(
        array $result,
        array $columnMappings,
        array $sourceHeaders,
        array $targetHeaders,
        ?string $backupPath,
    ): string {
        $unmappedSourceColumns = array_diff($sourceHeaders, array_keys($columnMappings));
        $unmappedTargetColumns = array_diff($targetHeaders, array_values($columnMappings));

        return $this->successResponse([
            'rows_appended' => count($result['transformed_rows']),
            'rows_filtered_out' => $result['filtered_out_count'],
            'transformations_applied' => $result['transformations_applied'],
            'unmapped_source_columns' => array_values($unmappedSourceColumns),
            'unmapped_target_columns' => array_values($unmappedTargetColumns),
            'backup_path' => $backupPath,
        ]);
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @param array<string, string> $columnMappings
     * @param array<string> $targetHeaders
     *
     * @return array<string, mixed>
     */
    protected function mapRow(array $sourceRow, array $columnMappings, array $targetHeaders): array
    {
        $targetRow = array_fill_keys($targetHeaders, '');

        foreach ($columnMappings as $sourceColumn => $targetColumn) {
            $targetRow[$targetColumn] = $sourceRow[$sourceColumn] ?? '';
        }

        return $targetRow;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $transformations
     *
     * @return array<string, mixed>
     */
    protected function transformRow(array $row, array $transformations): array
    {
        foreach ($transformations as $transformation) {
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
            CsvConstants::OPERATION_DIVIDE => $value != 0 ? $sourceValue / $value : $sourceValue,
            default => $row[$column] ?? '',
        };

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $transformations
     * @param array<string> $mappedColumns
     *
     * @return array<string>
     */
    protected function validateTransformations(array $transformations, array $mappedColumns): array
    {
        $errors = [];

        foreach ($transformations as $index => $transformation) {
            if (!isset($transformation['column'])) {
                $errors[] = sprintf('Transformation at index %d is missing "column" field', $index);

                continue;
            }

            $isMathOperation = isset($transformation['operation']);
            $isStringReplacement = isset($transformation['find']) || isset($transformation['replace']);

            if (!$isMathOperation && !$isStringReplacement) {
                $errors[] = sprintf('Transformation at index %d must have either {find, replace} or {operation, value}', $index);

                continue;
            }

            if ($isStringReplacement) {
                $errors = array_merge($errors, $this->validateStringReplacement($transformation, $index, $mappedColumns));
            }

            if ($isMathOperation) {
                $errors = array_merge($errors, $this->validateMathOperation($transformation, $index));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $transformation
     * @param int $index
     * @param array<string> $mappedColumns
     *
     * @return array<string>
     */
    protected function validateStringReplacement(array $transformation, int $index, array $mappedColumns): array
    {
        $errors = [];

        if (!isset($transformation['find'])) {
            $errors[] = sprintf('Transformation at index %d is missing "find" field', $index);
        }

        if (!isset($transformation['replace'])) {
            $errors[] = sprintf('Transformation at index %d is missing "replace" field', $index);
        }

        if (!in_array($transformation['column'], $mappedColumns, true)) {
            $errors[] = sprintf('String replacement column "%s" not found in column mappings', $transformation['column']);
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $transformation
     * @param int $index
     *
     * @return array<string>
     */
    protected function validateMathOperation(array $transformation, int $index): array
    {
        $errors = [];

        if (!isset($transformation['value'])) {
            $errors[] = sprintf('Math operation at index %d is missing "value" field', $index);
        }

        if (!in_array($transformation['operation'], CsvConstants::SUPPORTED_OPERATIONS, true)) {
            $errors[] = sprintf('Invalid operation "%s" at index %d', $transformation['operation'] ?? 'null', $index);
        }

        return $errors;
    }

    /**
     * @param array<string, string> $mappings
     * @param array<string> $sourceColumns
     * @param array<string> $targetColumns
     *
     * @return array<string>
     */
    protected function validateColumnMappings(array $mappings, array $sourceColumns, array $targetColumns): array
    {
        $errors = [];

        foreach ($mappings as $targetColumn => $sourceColumn) {
            if (!in_array($sourceColumn, $sourceColumns, true)) {
                $errors[] = sprintf('Invalid source column "%s" in mapping for target "%s"', $sourceColumn, $targetColumn);
            }
        }

        return $errors;
    }

    /**
     * @param array<string> $targetHeaders
     * @param array<string, string> $columnMappings
     * @param array<string, mixed> $defaultValues
     *
     * @return array<string>
     */
    protected function mergeHeadersWithMappingsAndDefaults(
        array $targetHeaders,
        array $columnMappings,
        array $defaultValues
    ): array {
        $newColumnsFromMappings = array_diff(array_values($columnMappings), $targetHeaders);
        $newColumnsFromDefaults = array_diff(array_keys($defaultValues), $targetHeaders);
        $allNewColumns = array_unique(array_merge($newColumnsFromMappings, $newColumnsFromDefaults));

        return array_merge($targetHeaders, $allNewColumns);
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

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $defaultValues
     *
     * @return array<string, mixed>
     */
    protected function applyDefaultValues(array $row, array $defaultValues): array
    {
        foreach ($defaultValues as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }
}
