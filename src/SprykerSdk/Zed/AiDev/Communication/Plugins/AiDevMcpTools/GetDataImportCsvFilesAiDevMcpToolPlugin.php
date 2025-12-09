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
class GetDataImportCsvFilesAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getDescription(): string
    {
        return 'Tool to discover and list all CSV files in a data import directory. Supports only relative paths. Returns a list of CSV file paths, optionally filtered by a search string in the filename. Useful for exploring available data import files.';
    }

    public function getName(): string
    {
        return 'getDataImportCsvFiles';
    }

    public function getDataImportCsvFiles(string $path, string $searchString = ''): string
    {
        $result = $this->getBusinessFactory()
            ->createDataImportCsvFilesFinder()
            ->findDataImportCsvFiles($path, $searchString);

        return json_encode($result, JSON_PRETTY_PRINT);
    }
}
