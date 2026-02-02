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
        return 'Transform source CSV data and write to target CSV file. IMPORTANT: All file paths must be relative to the project root. Maps columns from source to target, filters rows based on criteria, applies value transformations (find/replace), sets default values for target columns. Mode can be "append" (add to existing) or "replace" (overwrite file). Only mapped columns are copied; unmapped target columns get empty values unless defaultValues specified. Row filters use AND logic. Creates backup by default. Parameters: sourcePath (required, relative path), targetPath (required, relative path), columnMappings (object: sourceCol => targetCol), rowFilters (optional array), valueTransformations (optional array of {column, find, replace}), defaultValues (optional object: targetCol => value), mode (default "append"), createBackup (default true).';
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
                    'description' => 'Relative path to source CSV file',
                ],
                'targetPath' => [
                    'type' => 'string',
                    'description' => 'Relative path to target CSV file to append to',
                ],
                'columnMappings' => [
                    'type' => 'object',
                    'description' => 'Object mapping source column names to target column names',
                    'additionalProperties' => [
                        'type' => 'string',
                    ],
                ],
                'rowFilters' => [
                    'type' => 'array',
                    'description' => 'Optional filters to apply to rows',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'column' => ['type' => 'string'],
                            'operator' => ['type' => 'string'],
                            'value' => ['type' => ['string', 'number', 'boolean']],
                        ],
                    ],
                ],
                'valueTransformations' => [
                    'type' => 'array',
                    'description' => 'Optional value transformations (find/replace)',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'column' => ['type' => 'string'],
                            'find' => ['type' => 'string'],
                            'replace' => ['type' => 'string'],
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
                'mode' => [
                    'type' => 'string',
                    'description' => 'Operation mode: "append" (add to existing rows) or "replace" (overwrite file)',
                    'enum' => ['append', 'replace'],
                    'default' => 'append',
                ],
                'createBackup' => [
                    'type' => 'boolean',
                    'description' => 'Whether to create a backup of the target file',
                    'default' => true,
                ],
            ],
            'required' => ['sourcePath', 'targetPath', 'columnMappings'],
        ];
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
    public function transformCsv(
        string $sourcePath,
        string $targetPath,
        array $columnMappings,
        array $rowFilters = [],
        array $valueTransformations = [],
        array $defaultValues = [],
        string $mode = CsvConstants::MODE_APPEND,
        bool $createBackup = true
    ): string {
        return $this->getBusinessFactory()
            ->createCsvTransformer()
            ->transform($sourcePath, $targetPath, $columnMappings, $rowFilters, $valueTransformations, $defaultValues, $mode, $createBackup);
    }
}
