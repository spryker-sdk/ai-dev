<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;

class ModeValidator implements ValidatorInterface
{
    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidationContext $context
     *
     * @return bool
     */
    public function isApplicable(ValidationContext $context): bool
    {
        return true;
    }

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidationContext $context
     *
     * @return array<string, mixed>|null
     */
    public function validate(ValidationContext $context): ?array
    {
        if (!in_array($context->mode, CsvConstants::SUPPORTED_MODES, true)) {
            return [
                'code' => CsvConstants::OPERATION_FAILED,
                'message' => sprintf('Invalid mode "%s"', $context->mode),
                'details' => ['mode' => $context->mode, 'supported_modes' => CsvConstants::SUPPORTED_MODES],
            ];
        }

        return null;
    }
}
