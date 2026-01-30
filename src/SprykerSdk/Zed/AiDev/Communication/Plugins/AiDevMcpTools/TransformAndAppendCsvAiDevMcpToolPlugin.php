<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class TransformAndAppendCsvAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'transformAndAppendCsv';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Transform source CSV data and append to target CSV file. Maps columns from source to target, filters rows based on criteria, applies value transformations (find/replace), and appends results. Only mapped columns are copied; unmapped target columns get empty values. Row filters use AND logic. Creates backup by default. Parameters: sourcePath (required), targetPath (required), columnMappings (object: sourceCol => targetCol), rowFilters (optional array), valueTransformations (optional array of {column, find, replace}), createBackup (default true).';
    }

    /**
     * @param string $sourcePath
     * @param string $targetPath
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param bool $createBackup
     *
     * @return string
     */
    public function transformAndAppendCsv(
        string $sourcePath,
        string $targetPath,
        array $columnMappings,
        array $rowFilters = [],
        array $valueTransformations = [],
        bool $createBackup = true
    ): string {
        return $this->getBusinessFactory()
            ->createCsvTransformer()
            ->transformAndAppend($sourcePath, $targetPath, $columnMappings, $rowFilters, $valueTransformations, $createBackup);
    }
}
