<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use Exception;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\AbstractTransformStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\TransformContext;
use SprykerSdk\Zed\AiDev\Business\DataImport\Trait\JsonResponseTrait;
use SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidationContext;

class CsvTransformer implements CsvTransformerInterface
{
    use JsonResponseTrait;

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface $csvReader
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriterInterface $csvWriter
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluatorInterface $filterEvaluator
     * @param array<\SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowOperationInterface> $rowOperations
     * @param array<\SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\AbstractTransformStrategy> $transformStrategies
     * @param array<\SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidatorInterface> $validators
     */
    public function __construct(
        protected CsvReaderInterface $csvReader,
        protected CsvWriterInterface $csvWriter,
        protected FilterEvaluatorInterface $filterEvaluator,
        protected array $rowOperations,
        protected array $transformStrategies,
        protected array $validators,
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
     * @param array<string> $columnsToRemove
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
        array $columnsToRemove = [],
        string $mode = CsvConstants::MODE_APPEND,
        bool $createBackup = true,
    ): string {
        try {
            $validationError = $this->validateRequest(
                $mode,
                $sourcePath,
                $targetPath,
                $columnMappings,
                $rowFilters,
                $valueTransformations,
                $columnsToRemove,
            );
            if ($validationError !== null) {
                return $validationError;
            }

            $backupPath = null;
            if ($createBackup) {
                $backupPath = $this->createBackup($targetPath);
            }

            $context = $this->prepareContext(
                $sourcePath,
                $targetPath,
                $columnMappings,
                $rowFilters,
                $valueTransformations,
                $defaultValues,
                $columnsToRemove,
            );

            $strategy = $this->getTransformStrategy($mode);
            $result = $strategy->execute($context);
            $result['backup_path'] = $backupPath;

            return $this->buildResponse($result);
        } catch (Exception $e) {
            return $this->errorResponse(
                CsvConstants::OPERATION_FAILED,
                $e->getMessage(),
                ['source_path' => $sourcePath, 'target_path' => $targetPath],
            );
        }
    }

    /**
     * @param string $mode
     * @param string $sourcePath
     * @param string $targetPath
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<string> $columnsToRemove
     *
     * @return string|null
     */
    protected function validateRequest(
        string $mode,
        string $sourcePath,
        string $targetPath,
        array $columnMappings,
        array $rowFilters,
        array $valueTransformations,
        array $columnsToRemove,
    ): ?string {
        $context = new ValidationContext(
            mode: $mode,
            sourcePath: $sourcePath,
            targetPath: $targetPath,
            columnMappings: $columnMappings,
            rowFilters: $rowFilters,
            valueTransformations: $valueTransformations,
            defaultValues: [],
            columnsToRemove: $columnsToRemove,
            csvReader: $this->csvReader,
        );

        foreach ($this->validators as $validator) {
            if (!$validator->isApplicable($context)) {
                continue;
            }

            $error = $validator->validate($context);
            if ($error !== null) {
                return $this->errorResponse($error['code'], $error['message'], $error['details']);
            }
        }

        return null;
    }

    /**
     * @param string $sourcePath
     * @param string $targetPath
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<string, mixed> $defaultValues
     * @param array<string> $columnsToRemove
     *
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\TransformContext
     */
    protected function prepareContext(
        string $sourcePath,
        string $targetPath,
        array $columnMappings,
        array $rowFilters,
        array $valueTransformations,
        array $defaultValues,
        array $columnsToRemove,
    ): TransformContext {
        $targetHeaders = $this->csvReader->getHeaders($targetPath);
        $sourceHeaders = null;
        $sourceRows = null;
        $targetRows = null;
        $finalHeaders = $targetHeaders;

        if ($sourcePath !== '') {
            $sourceHeaders = $this->csvReader->getHeaders($sourcePath);
            $sourceRows = $this->csvReader->getRows($sourcePath);
            $finalHeaders = $this->mergeHeadersWithMappingsAndDefaults($targetHeaders, $columnMappings, $defaultValues);
        }

        if ($sourcePath === '') {
            $finalHeaders = $this->removeColumns($columnsToRemove, $targetHeaders);
            $targetRows = $this->csvReader->getRows($targetPath);
        }

        $config = new RowProcessingConfig(
            columnMappings: $columnMappings,
            finalHeaders: $finalHeaders,
            columnsToRemove: $columnsToRemove,
            defaultValues: $defaultValues,
            valueTransformations: $valueTransformations,
            rowFilters: $rowFilters,
        );

        return new TransformContext(
            targetPath: $targetPath,
            config: $config,
            sourceRows: $sourceRows,
            sourceHeaders: $sourceHeaders,
            targetHeaders: $targetHeaders,
            targetRows: $targetRows,
        );
    }

    /**
     * @param string $filePath
     *
     * @throws \Exception
     *
     * @return string|null
     */
    protected function createBackup(string $filePath): ?string
    {
        $backupPath = $filePath . CsvConstants::BACKUP_EXTENSION;

        if (!copy($filePath, $backupPath)) {
            throw new Exception(sprintf('Failed to create backup at %s', $backupPath));
        }

        return $backupPath;
    }

    /**
     * @param string $mode
     *
     * @throws \Exception
     *
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\AbstractTransformStrategy
     */
    protected function getTransformStrategy(string $mode): AbstractTransformStrategy
    {
        foreach ($this->transformStrategies as $strategy) {
            if ($strategy->isApplicable($mode)) {
                return $strategy;
            }
        }

        throw new Exception(sprintf('No strategy found for mode "%s"', $mode));
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return string
     */
    protected function buildResponse(array $result): string
    {
        return $this->successResponse($result);
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
        array $defaultValues,
    ): array {
        $newColumnsFromMappings = array_diff(array_values($columnMappings), $targetHeaders);
        $newColumnsFromDefaults = array_diff(array_keys($defaultValues), $targetHeaders);
        $allNewColumns = array_unique(array_merge($newColumnsFromMappings, $newColumnsFromDefaults));

        return array_merge($targetHeaders, $allNewColumns);
    }

    /**
     * @param array<string> $columnsToRemove
     * @param array<string> $targetHeaders
     *
     * @return array<string>
     */
    protected function removeColumns(array $columnsToRemove, array $targetHeaders): array
    {
        if (!$columnsToRemove) {
            return $targetHeaders;
        }

        return array_values(array_diff($targetHeaders, $columnsToRemove));
    }
}
