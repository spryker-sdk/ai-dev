<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Copier;

use Generated\Shared\Transfer\DataImportCsvCopyRequestTransfer;
use Generated\Shared\Transfer\DataImportCsvCopyResponseTransfer;

interface DataImportCsvCopierInterface
{
    public function copyWithMapping(DataImportCsvCopyRequestTransfer $dataImportCsvCopyRequestTransfer): DataImportCsvCopyResponseTransfer;
}
