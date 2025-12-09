<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\GoogleSpreadsheet\Downloader;

interface GoogleSpreadsheetDownloaderInterface
{
    public function downloadSpreadsheet(string $spreadsheetUrl, string $outputPath): bool;
}
