<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
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
