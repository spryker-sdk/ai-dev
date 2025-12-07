<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Generated\Shared\Transfer\DataImportCsvCopyRequestTransfer;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class CopyDataImportCsvWithMappingAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getDescription(): string
    {
        return 'Copy and transform CSV data from source to target file with advanced mapping, filtering, and value replacement. Supports: 1:1 column mapping, 1:many expansion (duplicate value to multiple columns), filtering rows with AND/OR logic, value replacements, append/overwrite modes. Parameters: sourceFilePath (string), targetFilePath (string), filters (JSON array, default []), columnMapping (JSON object, default {}), valueReplacements (JSON object, default {}), mode (string: "append"|"overwrite", default "append"), filterLogic (string: "AND"|"OR", default "AND"). Filter example: [{"column":"store","value":"US"}]. Mapping examples: 1:1: {"source_sku":"concrete_sku"}. 1:many: {"name":["name.de_DE","name.en_US"]} (copies name to both columns). Value replacement: {"store":{"EU":"AT","US":"US-EAST"}} (replaces EU with AT, US with US-EAST in store column). Use case: Copy US products, map columns, replace store codes: filters=[{"column":"store","value":"US"}], columnMapping={"sku":"concrete_sku"}, valueReplacements={"store":{"US":"US-EAST"}}.';
    }

    public function getName(): string
    {
        return 'copyDataImportCsvWithMapping';
    }

    /**
     * @param string $sourceFilePath
     * @param string $targetFilePath
     * @param string $filters
     * @param string $columnMapping
     * @param string $valueReplacements
     * @param string $mode
     * @param string $filterLogic
     *
     * @return string
     */
    public function copyDataImportCsvWithMapping(
        string $sourceFilePath,
        string $targetFilePath,
        string $filters = '[]',
        string $columnMapping = '{}',
        string $valueReplacements = '{}',
        string $mode = 'append',
        string $filterLogic = 'AND'
    ): string {
        $filtersArray = json_decode($filters, true);
        if (!is_array($filtersArray)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid filters parameter: must be a JSON array string',
                'rowsCopied' => 0,
            ], JSON_PRETTY_PRINT);
        }

        $columnMappingArray = json_decode($columnMapping, true);
        if (!is_array($columnMappingArray)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid columnMapping parameter: must be a JSON object string',
                'rowsCopied' => 0,
            ], JSON_PRETTY_PRINT);
        }

        $valueReplacementsArray = json_decode($valueReplacements, true);
        if (!is_array($valueReplacementsArray)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid valueReplacements parameter: must be a JSON object string',
                'rowsCopied' => 0,
            ], JSON_PRETTY_PRINT);
        }

        $requestTransfer = (new DataImportCsvCopyRequestTransfer())
            ->setSourceFilePath($sourceFilePath)
            ->setTargetFilePath($targetFilePath)
            ->setFilters($filtersArray)
            ->setColumnMapping($columnMappingArray)
            ->setValueReplacements($valueReplacementsArray)
            ->setMode($mode)
            ->setFilterLogic($filterLogic);

        $responseTransfer = $this->getBusinessFactory()
            ->createDataImportCsvCopier()
            ->copyWithMapping($requestTransfer);

        $result = [
            'success' => $responseTransfer->getIsSuccess(),
            'rowsCopied' => $responseTransfer->getRowsCopied(),
            'sourceFilePath' => $responseTransfer->getSourceFilePath(),
            'targetFilePath' => $responseTransfer->getTargetFilePath(),
            'mode' => $responseTransfer->getMode(),
            'filterLogic' => $responseTransfer->getFilterLogic(),
        ];

        if (!$responseTransfer->getIsSuccess()) {
            $result['error'] = $responseTransfer->getError();
            if ($responseTransfer->getValidationErrors()) {
                $result['validationErrors'] = $responseTransfer->getValidationErrors();
            }
        }

        return json_encode($result, JSON_PRETTY_PRINT);
    }
}
