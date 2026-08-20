<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;

class ColumnMappingValidator implements ValidatorInterface
{
    public function isApplicable(ValidationContext $context): bool
    {
        return $context->hasSourceFile() && !empty($context->columnMappings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validate(ValidationContext $context): ?array
    {
        $sourceHeaders = $context->getSourceHeaders();
        $mappingsForValidation = array_flip($context->columnMappings);

        $errors = [];
        foreach ($mappingsForValidation as $targetColumn => $sourceColumn) {
            if (!in_array($sourceColumn, $sourceHeaders, true)) {
                $errors[] = sprintf('Invalid source column "%s" in mapping for target "%s"', $sourceColumn, $targetColumn);
            }
        }

        if ($errors) {
            return [
            'code' => CsvConstants::INVALID_MAPPINGS,
            'message' => 'Column mappings validation failed',
            'details' => ['errors' => $errors],
            ];
        }

        return null;
    }
}
