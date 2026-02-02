<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolInputSchemaPluginInterface;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class TransformCsvAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface, AiDevMcpToolInputSchemaPluginInterface
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'transformCsv';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Transform and modify CSV files with three modes: 1) APPEND mode (default): Transfer data from source CSV to target CSV, mapping columns and adding new rows. Requires sourcePath and columnMappings. 2) REPLACE mode: Same as append but replaces entire target file instead of adding rows. Requires sourcePath and columnMappings. 3) UPDATE mode: Update existing rows IN PLACE in the target file based on filter criteria - NO source file needed, NO columnMappings needed. Use rowFilters to match rows (e.g., SKU contains pattern), then apply valueTransformations or defaultValues to update specific columns. UPDATE mode modifies only matched rows, leaves others unchanged. Can also REMOVE columns entirely using columnsToRemove parameter (works in UPDATE mode). IMPORTANT: All file paths must be relative to project root. Row filters use AND logic. Creates backup by default. Parameters: sourcePath (required for append/replace, NOT used in update mode), targetPath (always required), columnMappings (required for append/replace, NOT used in update mode), rowFilters (optional, but essential for update mode to match rows), valueTransformations (optional array: {column, find, replace} for string replacement OR {column, operation, value, sourceColumn} for math operations: add/subtract/multiply/divide), defaultValues (optional object: column => value to set), columnsToRemove (optional array of column names to delete from file, works in UPDATE mode), mode ("append"/"replace"/"update"), createBackup (default true).';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sourcePath' => [
                    'type' => 'string',
                    'description' => 'Relative path to source CSV file (required for append/replace modes, ignored in update mode)',
                ],
                'targetPath' => [
                    'type' => 'string',
                    'description' => 'Relative path to target CSV file',
                ],
                'columnMappings' => [
                    'type' => 'object',
                    'description' => 'Object mapping source column names to target column names (required for append/replace modes, ignored in update mode)',
                    'additionalProperties' => [
                        'type' => 'string',
                    ],
                ],
                'rowFilters' => [
                    'type' => 'array',
                    'description' => 'Filters to match rows. In append/replace modes: excludes matching rows. In UPDATE mode: selects rows to update (essential for update mode)',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'column' => ['type' => 'string', 'description' => 'Column name to filter on'],
                            'operator' => ['type' => 'string', 'description' => 'Comparison operator (equals, contains, startsWith, endsWith, greaterThan, lessThan, etc.)'],
                            'value' => ['type' => ['string', 'number', 'boolean'], 'description' => 'Value to compare against'],
                        ],
                    ],
                ],
                'valueTransformations' => [
                    'type' => 'array',
                    'description' => 'Optional value transformations: string replacement {column, find, replace} or math operations {column, operation, value, sourceColumn}',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'column' => ['type' => 'string', 'description' => 'Target column name'],
                            'find' => ['type' => 'string', 'description' => 'String to find (for replacement)'],
                            'replace' => ['type' => 'string', 'description' => 'String to replace with (for replacement)'],
                            'operation' => ['type' => 'string', 'enum' => ['add', 'subtract', 'multiply', 'divide'], 'description' => 'Math operation'],
                            'value' => ['type' => 'number', 'description' => 'Value for math operation'],
                            'sourceColumn' => ['type' => 'string', 'description' => 'Source column for math operation (defaults to column)'],
                        ],
                    ],
                ],
                'defaultValues' => [
                    'type' => 'object',
                    'description' => 'Optional default values for target columns (targetCol => value)',
                    'additionalProperties' => [
                        'type' => ['string', 'number', 'boolean'],
                    ],
                ],
                'columnsToRemove' => [
                    'type' => 'array',
                    'description' => 'Optional array of column names to remove from the file (works in UPDATE mode)',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'mode' => [
                    'type' => 'string',
                    'description' => 'Operation mode: "append" (add rows from source), "replace" (overwrite target with source), or "update" (modify existing rows in target based on filters)',
                    'enum' => ['append', 'replace', 'update'],
                    'default' => 'append',
                ],
                'createBackup' => [
                    'type' => 'boolean',
                    'description' => 'Whether to create a backup of the target file',
                    'default' => true,
                ],
            ],
            'required' => ['targetPath'],
        ];
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param string $targetPath
     * @param string|null $sourcePath
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<string, mixed> $defaultValues
     * @param array<string> $columnsToRemove
     * @param string $mode
     * @param bool $createBackup
     *
     * @return string
     */
    public function transformCsv(
        string $targetPath,
        ?string $sourcePath = null,
        array $columnMappings = [],
        array $rowFilters = [],
        array $valueTransformations = [],
        array $defaultValues = [],
        array $columnsToRemove = [],
        string $mode = CsvConstants::MODE_APPEND,
        bool $createBackup = true
    ): string {
        return $this->getBusinessFactory()
            ->createCsvTransformer()
            ->transform($sourcePath ?? '', $targetPath, $columnMappings, $rowFilters, $valueTransformations, $defaultValues, $columnsToRemove, $mode, $createBackup);
    }
}
