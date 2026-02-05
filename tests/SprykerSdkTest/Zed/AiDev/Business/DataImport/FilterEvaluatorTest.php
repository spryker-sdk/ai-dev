<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluator;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group FilterEvaluator
 */
class FilterEvaluatorTest extends Unit
{
    /**
     * @dataProvider evaluateDataProvider
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $criteria
     * @param bool $expected
     *
     * @return void
     */
    public function testEvaluate(array $row, array $criteria, bool $expected): void
    {
        // Arrange
        $filterEvaluator = new FilterEvaluator();

        // Act
        $result = $filterEvaluator->evaluate($row, $criteria);

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * @dataProvider validateCriteriaDataProvider
     *
     * @param array<int, array<string, mixed>> $criteria
     * @param array<string> $availableColumns
     * @param array<string> $expectedErrors
     *
     * @return void
     */
    public function testValidateCriteria(array $criteria, array $availableColumns, array $expectedErrors): void
    {
        // Arrange
        $filterEvaluator = new FilterEvaluator();

        // Act
        $errors = $filterEvaluator->validateCriteria($criteria, $availableColumns);

        // Assert
        $this->assertSame($expectedErrors, $errors);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function evaluateDataProvider(): array
    {
        return [
            'equals operator matches' => [
                'row' => ['brand' => 'Canon'],
                'criteria' => [['column' => 'brand', 'operator' => 'equals', 'value' => 'Canon']],
                'expected' => true,
            ],
            'equals operator does not match' => [
                'row' => ['brand' => 'Sony'],
                'criteria' => [['column' => 'brand', 'operator' => 'equals', 'value' => 'Canon']],
                'expected' => false,
            ],
            'not_equals operator matches' => [
                'row' => ['brand' => 'Sony'],
                'criteria' => [['column' => 'brand', 'operator' => 'not_equals', 'value' => 'Canon']],
                'expected' => true,
            ],
            'not_equals operator does not match' => [
                'row' => ['brand' => 'Canon'],
                'criteria' => [['column' => 'brand', 'operator' => 'not_equals', 'value' => 'Canon']],
                'expected' => false,
            ],
            'in operator matches' => [
                'row' => ['brand' => 'Canon'],
                'criteria' => [['column' => 'brand', 'operator' => 'in', 'value' => ['Canon', 'Sony']]],
                'expected' => true,
            ],
            'in operator does not match' => [
                'row' => ['brand' => 'Nikon'],
                'criteria' => [['column' => 'brand', 'operator' => 'in', 'value' => ['Canon', 'Sony']]],
                'expected' => false,
            ],
            'not_in operator matches' => [
                'row' => ['brand' => 'Nikon'],
                'criteria' => [['column' => 'brand', 'operator' => 'not_in', 'value' => ['Canon', 'Sony']]],
                'expected' => true,
            ],
            'not_in operator does not match' => [
                'row' => ['brand' => 'Canon'],
                'criteria' => [['column' => 'brand', 'operator' => 'not_in', 'value' => ['Canon', 'Sony']]],
                'expected' => false,
            ],
            'contains operator matches' => [
                'row' => ['description' => 'Canon IXUS camera'],
                'criteria' => [['column' => 'description', 'operator' => 'contains', 'value' => 'IXUS']],
                'expected' => true,
            ],
            'contains operator does not match' => [
                'row' => ['description' => 'Sony camera'],
                'criteria' => [['column' => 'description', 'operator' => 'contains', 'value' => 'IXUS']],
                'expected' => false,
            ],
            'not_contains operator matches' => [
                'row' => ['description' => 'Sony camera'],
                'criteria' => [['column' => 'description', 'operator' => 'not_contains', 'value' => 'IXUS']],
                'expected' => true,
            ],
            'not_contains operator does not match' => [
                'row' => ['description' => 'Canon IXUS camera'],
                'criteria' => [['column' => 'description', 'operator' => 'not_contains', 'value' => 'IXUS']],
                'expected' => false,
            ],
            'starts_with operator matches' => [
                'row' => ['brand' => 'Canon'],
                'criteria' => [['column' => 'brand', 'operator' => 'starts_with', 'value' => 'Can']],
                'expected' => true,
            ],
            'starts_with operator does not match' => [
                'row' => ['brand' => 'Sony'],
                'criteria' => [['column' => 'brand', 'operator' => 'starts_with', 'value' => 'Can']],
                'expected' => false,
            ],
            'ends_with operator matches' => [
                'row' => ['brand' => 'Canon'],
                'criteria' => [['column' => 'brand', 'operator' => 'ends_with', 'value' => 'non']],
                'expected' => true,
            ],
            'ends_with operator does not match' => [
                'row' => ['brand' => 'Sony'],
                'criteria' => [['column' => 'brand', 'operator' => 'ends_with', 'value' => 'non']],
                'expected' => false,
            ],
            'empty operator matches' => [
                'row' => ['notes' => ''],
                'criteria' => [['column' => 'notes', 'operator' => 'empty']],
                'expected' => true,
            ],
            'empty operator does not match' => [
                'row' => ['notes' => 'Some notes'],
                'criteria' => [['column' => 'notes', 'operator' => 'empty']],
                'expected' => false,
            ],
            'not_empty operator matches' => [
                'row' => ['notes' => 'Some notes'],
                'criteria' => [['column' => 'notes', 'operator' => 'not_empty']],
                'expected' => true,
            ],
            'not_empty operator does not match' => [
                'row' => ['notes' => ''],
                'criteria' => [['column' => 'notes', 'operator' => 'not_empty']],
                'expected' => false,
            ],
            'empty criteria always matches' => [
                'row' => ['brand' => 'Canon'],
                'criteria' => [],
                'expected' => true,
            ],
            'multiple criteria all match' => [
                'row' => ['brand' => 'Canon', 'color' => 'Red'],
                'criteria' => [
                    ['column' => 'brand', 'operator' => 'equals', 'value' => 'Canon'],
                    ['column' => 'color', 'operator' => 'equals', 'value' => 'Red'],
                ],
                'expected' => true,
            ],
            'multiple criteria one does not match' => [
                'row' => ['brand' => 'Canon', 'color' => 'Black'],
                'criteria' => [
                    ['column' => 'brand', 'operator' => 'equals', 'value' => 'Canon'],
                    ['column' => 'color', 'operator' => 'equals', 'value' => 'Red'],
                ],
                'expected' => false,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function validateCriteriaDataProvider(): array
    {
        return [
            'valid criteria' => [
                'criteria' => [['column' => 'brand', 'operator' => 'equals', 'value' => 'Canon']],
                'availableColumns' => ['brand', 'color'],
                'expectedErrors' => [],
            ],
            'missing column field' => [
                'criteria' => [['operator' => 'equals', 'value' => 'Canon']],
                'availableColumns' => ['brand'],
                'expectedErrors' => ['Criterion at index 0 is missing "column" field'],
            ],
            'missing operator field' => [
                'criteria' => [['column' => 'brand', 'value' => 'Canon']],
                'availableColumns' => ['brand'],
                'expectedErrors' => ['Criterion at index 0 is missing "operator" field'],
            ],
            'invalid column name' => [
                'criteria' => [['column' => 'invalid', 'operator' => 'equals', 'value' => 'Canon']],
                'availableColumns' => ['brand'],
                'expectedErrors' => ['Column "invalid" not found in available columns'],
            ],
            'invalid operator' => [
                'criteria' => [['column' => 'brand', 'operator' => 'invalid', 'value' => 'Canon']],
                'availableColumns' => ['brand'],
                'expectedErrors' => ['Operator "invalid" is not supported'],
            ],
            'in operator with non-array value' => [
                'criteria' => [['column' => 'brand', 'operator' => 'in', 'value' => 'Canon']],
                'availableColumns' => ['brand'],
                'expectedErrors' => ['Operator "in" requires array value'],
            ],
            'not_in operator with non-array value' => [
                'criteria' => [['column' => 'brand', 'operator' => 'not_in', 'value' => 'Canon']],
                'availableColumns' => ['brand'],
                'expectedErrors' => ['Operator "not_in" requires array value'],
            ],
            'multiple errors' => [
                'criteria' => [
                    ['column' => 'invalid', 'operator' => 'invalid_op', 'value' => 'Canon'],
                    ['operator' => 'equals', 'value' => 'Sony'],
                ],
                'availableColumns' => ['brand'],
                'expectedErrors' => [
                    'Column "invalid" not found in available columns',
                    'Operator "invalid_op" is not supported',
                    'Criterion at index 1 is missing "column" field',
                ],
            ],
        ];
    }
}
