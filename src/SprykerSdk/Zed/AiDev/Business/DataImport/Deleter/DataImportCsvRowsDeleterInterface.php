<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Deleter;

use Generated\Shared\Transfer\DataImportCsvDeleteRequestTransfer;
use Generated\Shared\Transfer\DataImportCsvDeleteResponseTransfer;

interface DataImportCsvRowsDeleterInterface
{
    public function deleteRows(DataImportCsvDeleteRequestTransfer $dataImportCsvDeleteRequestTransfer): DataImportCsvDeleteResponseTransfer;
}
