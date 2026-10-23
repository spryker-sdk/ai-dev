<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\ColumnMappingOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\ColumnRemovalOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\DefaultValuesOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\TransformationOperation;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group RowOperations
 */
class RowOperationsTest extends Unit
{
    public function testColumnRemovalOperationRemovesSingleColumn(): void
    {
        // Arrange
        $operation = new ColumnRemovalOperation();
        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: ['brand', 'price'],
            columnsToRemove: ['color'],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $row = [
            'brand' => 'Canon',
            'color' => 'Red',
            'price' => '99.99',
        ];

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertArrayNotHasKey('color', $result);
        $this->assertArrayHasKey('brand', $result);
        $this->assertArrayHasKey('price', $result);
    }

    public function testColumnRemovalOperationRemovesMultipleColumns(): void
    {
        // Arrange
        $operation = new ColumnRemovalOperation();
        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: ['brand'],
            columnsToRemove: ['color', 'price'],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $row = [
            'brand' => 'Canon',
            'color' => 'Red',
            'price' => '99.99',
        ];

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertArrayNotHasKey('color', $result);
        $this->assertArrayNotHasKey('price', $result);
        $this->assertArrayHasKey('brand', $result);
    }

    public function testColumnRemovalOperationHandlesNonExistentColumn(): void
    {
        // Arrange
        $operation = new ColumnRemovalOperation();
        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: ['brand', 'price'],
            columnsToRemove: ['nonexistent'],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $row = [
            'brand' => 'Canon',
            'price' => '99.99',
        ];

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertArrayHasKey('brand', $result);
        $this->assertArrayHasKey('price', $result);
    }

    public function testDefaultValuesOperationAddsDefaultValues(): void
    {
        // Arrange
        $operation = new DefaultValuesOperation();
        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: ['brand', 'category'],
            columnsToRemove: [],
            defaultValues: ['category' => 'Electronics'],
            valueTransformations: [],
            rowFilters: [],
        );

        $row = ['brand' => 'Canon'];

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertSame('Electronics', $result['category']);
    }

    public function testDefaultValuesOperationOverridesEmptyValues(): void
    {
        // Arrange
        $operation = new DefaultValuesOperation();
        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: ['brand', 'category'],
            columnsToRemove: [],
            defaultValues: ['category' => 'Electronics'],
            valueTransformations: [],
            rowFilters: [],
        );

        $row = ['brand' => 'Canon', 'category' => ''];

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertSame('Electronics', $result['category']);
    }

    public function testDefaultValuesOperationPreservesExistingValues(): void
    {
        // Arrange
        $operation = new DefaultValuesOperation();
        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: ['brand', 'category'],
            columnsToRemove: [],
            defaultValues: ['category' => 'Electronics'],
            valueTransformations: [],
            rowFilters: [],
        );

        $row = ['brand' => 'Canon', 'category' => 'Cameras'];

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertSame('Electronics', $result['category']);
    }

    /**
     * @dataProvider transformationDataProvider
     *
     * @param array<string, mixed> $transformation
     * @param array<string, mixed> $row
     */
    public function testTransformationOperation(
        array $transformation,
        array $row,
        string $expectedColumn,
        mixed $expectedValue
    ): void {
        // Arrange
        $operation = new TransformationOperation();
        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: array_keys($row),
            columnsToRemove: [],
            defaultValues: [],
            valueTransformations: [$transformation],
            rowFilters: [],
        );

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertEquals($expectedValue, $result[$expectedColumn]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function transformationDataProvider(): array
    {
        return [
            'add operation' => [
                'transformation' => ['column' => 'price', 'operation' => CsvConstants::OPERATION_ADD, 'value' => 10],
                'row' => ['price' => '99.99'],
                'expectedColumn' => 'price',
                'expectedValue' => 109.99,
            ],
            'subtract operation' => [
                'transformation' => ['column' => 'price', 'operation' => CsvConstants::OPERATION_SUBTRACT, 'value' => 10],
                'row' => ['price' => '99.99'],
                'expectedColumn' => 'price',
                'expectedValue' => 89.99,
            ],
            'multiply operation' => [
                'transformation' => ['column' => 'price', 'operation' => CsvConstants::OPERATION_MULTIPLY, 'value' => 2],
                'row' => ['price' => '99.99'],
                'expectedColumn' => 'price',
                'expectedValue' => 199.98,
            ],
            'divide operation' => [
                'transformation' => ['column' => 'price', 'operation' => CsvConstants::OPERATION_DIVIDE, 'value' => 2],
                'row' => ['price' => '100'],
                'expectedColumn' => 'price',
                'expectedValue' => 50.0,
            ],
            'string replacement' => [
                'transformation' => ['column' => 'brand', 'find' => 'Canon', 'replace' => 'Nikon'],
                'row' => ['brand' => 'Canon IXUS'],
                'expectedColumn' => 'brand',
                'expectedValue' => 'Nikon IXUS',
            ],
            'handle null value' => [
                'transformation' => ['column' => 'price', 'operation' => CsvConstants::OPERATION_ADD, 'value' => 10],
                'row' => ['price' => null],
                'expectedColumn' => 'price',
                'expectedValue' => 10,
            ],
        ];
    }

    public function testColumnMappingOperationMapsSingleColumn(): void
    {
        // Arrange
        $operation = new ColumnMappingOperation();
        $config = new RowProcessingConfig(
            columnMappings: ['brand' => 'manufacturer'],
            finalHeaders: ['manufacturer'],
            columnsToRemove: [],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $row = ['brand' => 'Canon'];

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertSame('Canon', $result['manufacturer']);
        $this->assertArrayNotHasKey('brand', $result);
    }

    public function testColumnMappingOperationMapsMultipleColumns(): void
    {
        // Arrange
        $operation = new ColumnMappingOperation();
        $config = new RowProcessingConfig(
            columnMappings: ['brand' => 'manufacturer', 'price' => 'cost'],
            finalHeaders: ['manufacturer', 'cost'],
            columnsToRemove: [],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $row = ['brand' => 'Canon', 'price' => '99.99'];

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertSame('Canon', $result['manufacturer']);
        $this->assertSame('99.99', $result['cost']);
    }

    public function testColumnMappingOperationHandlesMissingSourceColumn(): void
    {
        // Arrange
        $operation = new ColumnMappingOperation();
        $config = new RowProcessingConfig(
            columnMappings: ['brand' => 'manufacturer', 'nonexistent' => 'missing'],
            finalHeaders: ['manufacturer', 'missing'],
            columnsToRemove: [],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $row = ['brand' => 'Canon'];

        // Act
        $result = $operation->execute($row, $config);

        // Assert
        $this->assertSame('Canon', $result['manufacturer']);
        $this->assertSame('', $result['missing']);
    }
}
