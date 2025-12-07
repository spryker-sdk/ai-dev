<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\Csv;

use Generated\Shared\Transfer\CsvOperationResultTransfer;

interface CsvWriterInterface
{
    /**
     * @param array<string>|string $headers
     * @param array<array<string, mixed>> $rows
     */
    public function writeCsvFile(
        string $filePath,
        array $headers,
        array $rows,
        string $mode
    ): CsvOperationResultTransfer;
}
