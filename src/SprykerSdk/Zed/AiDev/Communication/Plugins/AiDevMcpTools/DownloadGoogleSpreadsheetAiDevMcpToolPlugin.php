<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class DownloadGoogleSpreadsheetAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getDescription(): string
    {
        return 'Downloads a publicly accessible Google Spreadsheet and splits it into separate CSV files by sheets. Parameters: spreadsheetUrl (string), outputDirectory (string - MUST be relative path like "data/imports" or "b2b-import", NOT absolute paths). The directory will be created relative to the project root if it does not exist. Returns metadata about downloaded sheets including file paths, row counts, and sheet names.';
    }

    public function getName(): string
    {
        return 'downloadGoogleSpreadsheet';
    }

    public function downloadGoogleSpreadsheet(string $spreadsheetUrl, string $outputDirectory): string
    {
        $result = $this->getBusinessFactory()
            ->createGoogleSpreadsheetProcessor()
            ->process($spreadsheetUrl, $outputDirectory);

        return json_encode($result, JSON_PRETTY_PRINT);
    }
}
