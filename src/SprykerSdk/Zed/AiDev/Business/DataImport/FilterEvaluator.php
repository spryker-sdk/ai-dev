<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

class FilterEvaluator implements FilterEvaluatorInterface
{
    /**
     * @var array<string>
     */
    protected const array SUPPORTED_OPERATORS = [
        'equals',
        'not_equals',
        'in',
        'not_in',
        'contains',
        'not_contains',
        'starts_with',
        'ends_with',
        'empty',
        'not_empty',
    ];

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $criteria
     *
     * @return bool
     */
    public function evaluate(array $row, array $criteria): bool
    {
        if (!$criteria) {
            return true;
        }

        foreach ($criteria as $criterion) {
            if (!$this->evaluateSingleCriterion($row, $criterion)) {
                return false;
            }
        }

        return true;
    }

    public function isValidOperator(string $operator): bool
    {
        return in_array($operator, static::SUPPORTED_OPERATORS, true);
    }

    /**
     * @param array<int, array<string, mixed>> $criteria
     * @param array<string> $availableColumns
     *
     * @return array<string>
     */
    public function validateCriteria(array $criteria, array $availableColumns): array
    {
        $errors = [];

        foreach ($criteria as $index => $criterion) {
            if (!isset($criterion['column'])) {
                $errors[] = sprintf('Criterion at index %d is missing "column" field', $index);

                continue;
            }

            if (!isset($criterion['operator'])) {
                $errors[] = sprintf('Criterion at index %d is missing "operator" field', $index);

                continue;
            }

            if (!in_array($criterion['column'], $availableColumns, true)) {
                $errors[] = sprintf('Column "%s" not found in available columns', $criterion['column']);
            }

            if (!$this->isValidOperator($criterion['operator'])) {
                $errors[] = sprintf('Operator "%s" is not supported', $criterion['operator']);
            }

            if (in_array($criterion['operator'], ['in', 'not_in'], true) && !is_array($criterion['value'] ?? null)) {
                $errors[] = sprintf('Operator "%s" requires array value', $criterion['operator']);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $criterion
     *
     * @return bool
     */
    protected function evaluateSingleCriterion(array $row, array $criterion): bool
    {
        $column = $criterion['column'];
        $operator = $criterion['operator'];
        $value = $criterion['value'] ?? null;
        $cellValue = $row[$column] ?? '';

        return match ($operator) {
            'equals' => $cellValue === $value,
            'not_equals' => $cellValue !== $value,
            'in' => in_array($cellValue, (array)$value, true),
            'not_in' => !in_array($cellValue, (array)$value, true),
            'contains' => str_contains((string)$cellValue, (string)$value),
            'not_contains' => !str_contains((string)$cellValue, (string)$value),
            'starts_with' => str_starts_with((string)$cellValue, (string)$value),
            'ends_with' => str_ends_with((string)$cellValue, (string)$value),
            'empty' => !$cellValue,
            'not_empty' => (bool)$cellValue,
            default => false,
        };
    }
}
