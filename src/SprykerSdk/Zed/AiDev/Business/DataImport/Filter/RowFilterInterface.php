<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Filter;

use Generated\Shared\Transfer\FilterValidationResponseTransfer;

interface RowFilterInterface
{
    /**
     * @param array<string, mixed> $row
     * @param array<array<string, mixed>> $filters
     * @param string $logic
     *
     * @return bool
     */
    public function matchesFilters(array $row, array $filters, string $logic = 'AND'): bool;

    /**
     * @param array<string> $headers
     * @param array<array<string, mixed>> $filters
     *
     * @return \Generated\Shared\Transfer\FilterValidationResponseTransfer
     */
    public function validateFilters(array $headers, array $filters): FilterValidationResponseTransfer;
}
