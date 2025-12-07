<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class GetDataImportCsvContentAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getDescription(): string
    {
        return 'Read and inspect CSV data import files with filtering and pagination. Returns header columns, paginated data rows as JSON objects keyed by column name, and total row count. Parameters: filePath (string), offset (int, default 0), limit (int, default 100), filters (JSON array, default []), filterLogic (string: "AND"|"OR", default "AND"). Filter examples: Single value: [{"column":"store","value":"US"}]. Multiple values: [{"column":"sku","values":["SKU1","SKU2","SKU3"]}]. Exclude: [{"column":"status","value":"inactive","exclude":true}]. AND logic (default): All filters must match. OR logic: Any filter can match. Use case: Read US store active products with AND logic: [{"column":"store","value":"US"},{"column":"status","value":"active"}].';
    }

    public function getName(): string
    {
        return 'getDataImportCsvContent';
    }

    /**
     * @param string $filePath
     * @param int $offset
     * @param int $limit
     * @param string $filters
     * @param string $filterLogic
     *
     * @return string
     */
    public function getDataImportCsvContent(
        string $filePath,
        int $offset = 0,
        int $limit = 100,
        string $filters = '[]',
        string $filterLogic = 'AND'
    ): string {
        $filtersArray = json_decode($filters, true);
        if (!is_array($filtersArray)) {
            $filtersArray = [];
        }

        $result = $this->getBusinessFactory()
            ->createDataImportCsvFileReader()
            ->readDataImportCsvFile($filePath, $offset, $limit, $filtersArray, $filterLogic);

        return json_encode($result, JSON_PRETTY_PRINT);
    }
}
