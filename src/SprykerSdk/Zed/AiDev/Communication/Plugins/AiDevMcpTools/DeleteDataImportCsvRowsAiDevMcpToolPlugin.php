<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Generated\Shared\Transfer\DataImportCsvDeleteRequestTransfer;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class DeleteDataImportCsvRowsAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getDescription(): string
    {
        return 'Delete rows from CSV data import files based on filter criteria. Removes matching rows and overwrites the file. Parameters: filePath (string), filters (JSON array, required), filterLogic (string: "AND"|"OR", default "OR"). Filter examples: Single value: [{"column":"status","value":"inactive"}]. Multiple values: [{"column":"concrete_sku","values":["SKU001","SKU002","SKU003"]}]. Exclude: [{"column":"store","value":"US","exclude":true}] (deletes all non-US). OR logic (default): Deletes row if ANY filter matches. AND logic: Deletes row only if ALL filters match. Use case 1: Delete multiple SKUs with OR: [{"column":"sku","values":["SKU1","SKU2","SKU3"]}]. Use case 2: Delete US inactive products with AND: [{"column":"store","value":"US"},{"column":"status","value":"inactive"}], filterLogic="AND". Returns: rowsDeleted, totalRowsBefore, totalRowsRemaining.';
    }

    public function getName(): string
    {
        return 'deleteDataImportCsvRows';
    }

    /**
     * @param string $filePath
     * @param string $filters
     * @param string $filterLogic
     *
     * @return string
     */
    public function deleteDataImportCsvRows(string $filePath, string $filters, string $filterLogic): string
    {
        $filtersArray = json_decode($filters, true);
        if (!is_array($filtersArray)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid filters parameter: must be a JSON string containing an array of filter objects',
                'rowsDeleted' => 0,
                'totalRowsRemaining' => 0,
            ], JSON_PRETTY_PRINT);
        }

        $requestTransfer = (new DataImportCsvDeleteRequestTransfer())
            ->setFilePath($filePath)
            ->setFilters($filtersArray)
            ->setFilterLogic($filterLogic);

        $responseTransfer = $this->getBusinessFactory()
            ->createDataImportCsvRowsDeleter()
            ->deleteRows($requestTransfer);

        $result = [
            'success' => $responseTransfer->getIsSuccess(),
            'rowsDeleted' => $responseTransfer->getRowsDeleted(),
            'totalRowsBefore' => $responseTransfer->getTotalRowsBefore(),
            'totalRowsRemaining' => $responseTransfer->getTotalRowsRemaining(),
            'filePath' => $responseTransfer->getFilePath(),
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
