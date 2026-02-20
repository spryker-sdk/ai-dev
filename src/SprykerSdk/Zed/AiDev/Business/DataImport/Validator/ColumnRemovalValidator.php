<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;

class ColumnRemovalValidator implements ValidatorInterface
{
    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidationContext $context
     *
     * @return bool
     */
    public function isApplicable(ValidationContext $context): bool
    {
        return !$context->hasSourceFile() && !empty($context->columnsToRemove);
    }

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidationContext $context
     *
     * @return array<string, mixed>|null
     */
    public function validate(ValidationContext $context): ?array
    {
        $targetHeaders = $context->getTargetHeaders();
        $invalidColumns = array_diff($context->columnsToRemove, $targetHeaders);

        if ($invalidColumns) {
            return [
                'code' => CsvConstants::COLUMN_NOT_FOUND,
                'message' => 'Cannot remove columns that do not exist',
                'details' => ['invalid_columns' => array_values($invalidColumns)],
            ];
        }

        return null;
    }
}
