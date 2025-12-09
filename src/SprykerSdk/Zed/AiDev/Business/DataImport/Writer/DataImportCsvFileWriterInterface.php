<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Writer;

use Generated\Shared\Transfer\DataImportCsvWriteResponseTransfer;

interface DataImportCsvFileWriterInterface
{
    /**
     * @param string $filePath
     * @param array<array<string, mixed>> $data
     * @param array<string, string> $columnMapping
     *
     * @return \Generated\Shared\Transfer\DataImportCsvWriteResponseTransfer
     */
    public function writeDataImportCsvFile(string $filePath, array $data, array $columnMapping = []): DataImportCsvWriteResponseTransfer;
}
