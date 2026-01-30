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
class SplitOdsToCsvAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'splitOdsToCsv';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Split an ODS (OpenDocument Spreadsheet) file into separate CSV files, one per sheet. Skips empty sheets and returns details about created files. Parameters: odsFilePath (required, absolute path to .ods file), outputDirectory (required, directory where CSV files will be written).';
    }

    /**
     * @param string $odsFilePath
     * @param string $outputDirectory
     *
     * @return string
     */
    public function splitOdsToCsv(string $odsFilePath, string $outputDirectory): string
    {
        return $this->getBusinessFactory()
            ->createOdsSplitter()
            ->split($odsFilePath, $outputDirectory);
    }
}
