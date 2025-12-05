<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Filter;

use Generated\Shared\Transfer\FilterValidationResponseTransfer;

class RowFilter implements RowFilterInterface
{
    protected const string LOGIC_AND = 'AND';

    protected const string LOGIC_OR = 'OR';

    /**
     * @param array<string, mixed> $row
     * @param array<array<string, mixed>> $filters
     * @param string $logic
     *
     * @return bool
     */
    public function matchesFilters(array $row, array $filters, string $logic = self::LOGIC_AND): bool
    {
        if ($filters === []) {
            return true;
        }

        $logic = strtoupper($logic);

        if ($logic === static::LOGIC_OR) {
            return $this->matchesFiltersWithOrLogic($row, $filters);
        }

        return $this->matchesFiltersWithAndLogic($row, $filters);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<array<string, mixed>> $filters
     *
     * @return bool
     */
    protected function matchesFiltersWithAndLogic(array $row, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (!$this->matchesSingleFilter($row, $filter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<array<string, mixed>> $filters
     *
     * @return bool
     */
    protected function matchesFiltersWithOrLogic(array $row, array $filters): bool
    {
        foreach ($filters as $filter) {
            if ($this->matchesSingleFilter($row, $filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filter
     *
     * @return bool
     */
    protected function matchesSingleFilter(array $row, array $filter): bool
    {
        $column = $filter['column'] ?? null;
        $value = $filter['value'] ?? null;
        $values = $filter['values'] ?? null;
        $exclude = $filter['exclude'] ?? false;

        if ($column === null) {
            return true;
        }

        if ($value === null && $values === null) {
            return true;
        }

        if (!isset($row[$column])) {
            return $exclude;
        }

        $rowValue = (string)$row[$column];

        if ($values !== null) {
            $matches = $this->matchesAnyValue($rowValue, $values);
        } else {
            $filterValue = (string)$value;
            $matches = $rowValue === $filterValue;
        }

        return $exclude ? !$matches : $matches;
    }

    /**
     * @param string $rowValue
     * @param array<mixed> $values
     *
     * @return bool
     */
    protected function matchesAnyValue(string $rowValue, array $values): bool
    {
        foreach ($values as $value) {
            if ($rowValue === (string)$value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $headers
     * @param array<array<string, mixed>> $filters
     *
     * @return \Generated\Shared\Transfer\FilterValidationResponseTransfer
     */
    public function validateFilters(array $headers, array $filters): FilterValidationResponseTransfer
    {
        $errors = [];

        foreach ($filters as $index => $filter) {
            $column = $filter['column'] ?? null;
            $value = $filter['value'] ?? null;
            $values = $filter['values'] ?? null;

            if ($column === null) {
                $errors[] = sprintf('Filter at index %d is missing "column" field', $index);

                continue;
            }

            if (!in_array($column, $headers, true)) {
                $errors[] = sprintf('Column "%s" does not exist in CSV file. Available columns: %s', $column, implode(', ', $headers));
            }

            if ($value === null && $values === null) {
                $errors[] = sprintf('Filter at index %d must have either "value" or "values" field', $index);
            }

            if ($values !== null && !is_array($values)) {
                $errors[] = sprintf('Filter at index %d has "values" field that is not an array', $index);
            }
        }

        if ($errors !== []) {
            return (new FilterValidationResponseTransfer())
                ->setIsValid(false)
                ->setErrors($errors);
        }

        return (new FilterValidationResponseTransfer())
            ->setIsValid(true);
    }
}
