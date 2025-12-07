<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\GoogleSpreadsheet\Processor;

interface GoogleSpreadsheetProcessorInterface
{
    /**
     * @return array<string, mixed>
     */
    public function process(string $spreadsheetUrl, string $outputDirectory): array;
}
