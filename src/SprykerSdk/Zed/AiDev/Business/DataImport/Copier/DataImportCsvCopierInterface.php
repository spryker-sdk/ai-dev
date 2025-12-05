<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Copier;

use Generated\Shared\Transfer\DataImportCsvCopyRequestTransfer;
use Generated\Shared\Transfer\DataImportCsvCopyResponseTransfer;

interface DataImportCsvCopierInterface
{
    public function copyWithMapping(DataImportCsvCopyRequestTransfer $dataImportCsvCopyRequestTransfer): DataImportCsvCopyResponseTransfer;
}
