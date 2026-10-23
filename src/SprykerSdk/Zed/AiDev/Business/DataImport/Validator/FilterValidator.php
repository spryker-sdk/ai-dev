<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluatorInterface;

class FilterValidator implements ValidatorInterface
{
    public function __construct(
        protected FilterEvaluatorInterface $filterEvaluator,
    ) {
    }

    public function isApplicable(ValidationContext $context): bool
    {
        return !empty($context->rowFilters);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validate(ValidationContext $context): ?array
    {
        $headers = $context->hasSourceFile()
            ? $context->getSourceHeaders()
            : $context->getTargetHeaders();

        $filterErrors = $this->filterEvaluator->validateCriteria($context->rowFilters, $headers);

        if ($filterErrors) {
            return [
                'code' => CsvConstants::INVALID_FILTERS,
                'message' => 'Row filters validation failed',
                'details' => ['errors' => $filterErrors],
            ];
        }

        return null;
    }
}
