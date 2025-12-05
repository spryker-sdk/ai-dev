<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
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
