<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Writer;

use Generated\Shared\Transfer\DataImportCsvWriteResponseTransfer;

interface DataImportCsvFileWriterInterface
{
    /**
     * @param array<array<string, mixed>>|string $data
     * @param array<string, string> $columnMapping
     */
    public function writeDataImportCsvFile(string $filePath, array $data, array $columnMapping = []): DataImportCsvWriteResponseTransfer;
}
