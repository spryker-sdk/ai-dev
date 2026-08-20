<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;

class TransformationValidator implements ValidatorInterface
{
    public function isApplicable(ValidationContext $context): bool
    {
        return !empty($context->valueTransformations);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validate(ValidationContext $context): ?array
    {
        $mappedColumns = $context->hasSourceFile()
            ? array_values($context->columnMappings)
            : $context->getTargetHeaders();

        $errors = $this->validateTransformations($context->valueTransformations, $mappedColumns);

        if ($errors) {
            return [
                'code' => CsvConstants::INVALID_TRANSFORMATIONS,
                'message' => 'Value transformations validation failed',
                'details' => ['errors' => $errors],
            ];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $transformations
     * @param array<string> $mappedColumns
     *
     * @return array<string>
     */
    protected function validateTransformations(array $transformations, array $mappedColumns): array
    {
        $errors = [];

        foreach ($transformations as $index => $transformation) {
            if (!isset($transformation['column'])) {
                $errors[] = sprintf('Transformation at index %d is missing "column" field', $index);

                continue;
            }

            $isMathOperation = isset($transformation['operation']);
            $isStringReplacement = isset($transformation['find']) || isset($transformation['replace']);

            if (!$isMathOperation && !$isStringReplacement) {
                $errors[] = sprintf('Transformation at index %d must have either {find, replace} or {operation, value}', $index);

                continue;
            }

            if ($isStringReplacement) {
                $errors = array_merge($errors, $this->validateStringReplacement($transformation, $index, $mappedColumns));
            }

            if ($isMathOperation) {
                $errors = array_merge($errors, $this->validateMathOperation($transformation, $index));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $transformation
     * @param array<string> $mappedColumns
     *
     * @return array<string>
     */
    protected function validateStringReplacement(array $transformation, int $index, array $mappedColumns): array
    {
        $errors = [];

        if (!isset($transformation['find'])) {
            $errors[] = sprintf('Transformation at index %d is missing "find" field', $index);
        }

        if (!isset($transformation['replace'])) {
            $errors[] = sprintf('Transformation at index %d is missing "replace" field', $index);
        }

        if (!in_array($transformation['column'], $mappedColumns, true)) {
            $errors[] = sprintf('String replacement column "%s" not found in column mappings', $transformation['column']);
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $transformation
     *
     * @return array<string>
     */
    protected function validateMathOperation(array $transformation, int $index): array
    {
        $errors = [];

        if (!isset($transformation['value'])) {
            $errors[] = sprintf('Math operation at index %d is missing "value" field', $index);
        }

        if (!in_array($transformation['operation'], CsvConstants::SUPPORTED_OPERATIONS, true)) {
            $errors[] = sprintf('Invalid operation "%s" at index %d', $transformation['operation'] ?? 'null', $index);
        }

        return $errors;
    }
}
