<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
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
