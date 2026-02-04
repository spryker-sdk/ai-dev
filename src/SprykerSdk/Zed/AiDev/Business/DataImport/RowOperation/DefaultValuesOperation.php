<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation;

class DefaultValuesOperation implements RowOperationInterface
{
    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig $config
     *
     * @return bool
     */
    public function isApplicable(RowProcessingConfig $config): bool
    {
        return !empty($config->defaultValues);
    }

    /**
     * @param array<string, mixed> $row
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig $config
     *
     * @return array<string, mixed>
     */
    public function execute(array $row, RowProcessingConfig $config): array
    {
        foreach ($config->defaultValues as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }
}
