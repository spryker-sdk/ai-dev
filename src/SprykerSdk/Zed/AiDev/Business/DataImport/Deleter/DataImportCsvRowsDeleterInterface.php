<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Deleter;

use Generated\Shared\Transfer\DataImportCsvDeleteRequestTransfer;
use Generated\Shared\Transfer\DataImportCsvDeleteResponseTransfer;

interface DataImportCsvRowsDeleterInterface
{
    public function deleteRows(DataImportCsvDeleteRequestTransfer $dataImportCsvDeleteRequestTransfer): DataImportCsvDeleteResponseTransfer;
}
