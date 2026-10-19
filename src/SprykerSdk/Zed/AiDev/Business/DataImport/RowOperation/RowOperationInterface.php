<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation;

interface RowOperationInterface
{
    public function isApplicable(RowProcessingConfig $config): bool;

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function execute(array $row, RowProcessingConfig $config): array;
}
