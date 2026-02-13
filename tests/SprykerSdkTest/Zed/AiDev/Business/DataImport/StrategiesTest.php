<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvReader;
use SprykerSdk\Zed\AiDev\Business\DataImport\CsvWriter;
use SprykerSdk\Zed\AiDev\Business\DataImport\FilterEvaluator;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\ColumnMappingOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\ColumnRemovalOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\DefaultValuesOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig;
use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\TransformationOperation;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\AppendStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\ReplaceStrategy;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\TransformContext;
use SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\UpdateStrategy;

/**
 * @group AiDev
 * @group Business
 * @group DataImport
 * @group Strategies
 */
class StrategiesTest extends Unit
{
    use CsvTestDataTrait;

    /**
     * @var string
     */
    protected string $tempDir;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/csv_test_' . uniqid();
        mkdir($this->tempDir);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*'));
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testAppendStrategyAppendsRows(): void
    {
        // Arrange
        $targetPath = $this->tempDir . '/target.csv';
        $targetHeaders = ['brand', 'price'];
        $targetContent = implode(',', $targetHeaders) . "\n" . 'Canon,99.99';
        file_put_contents($targetPath, $targetContent);

        $sourceRows = [
            ['brand' => 'Sony', 'price' => '149.99'],
        ];

        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: $targetHeaders,
            columnsToRemove: [],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $context = new TransformContext(
            targetPath: $targetPath,
            config: $config,
            sourceRows: $sourceRows,
            sourceHeaders: $targetHeaders,
            targetHeaders: $targetHeaders,
            targetRows: null,
        );

        $strategy = $this->createAppendStrategy();

        // Act
        $result = $strategy->execute($context);

        // Assert
        $this->assertSame(1, $result['rows_appended']);

        $csvReader = new CsvReader();
        $rows = $csvReader->getRows($targetPath);
        $this->assertCount(2, $rows);
        $this->assertSame('Sony', $rows[1]['brand']);
    }

    /**
     * @return void
     */
    public function testAppendStrategyPreservesExistingData(): void
    {
        // Arrange
        $targetPath = $this->tempDir . '/target.csv';
        $targetHeaders = ['brand', 'price'];
        $targetContent = implode(',', $targetHeaders) . "\n" . 'Canon,99.99';
        file_put_contents($targetPath, $targetContent);

        $sourceRows = [
            ['brand' => 'Sony', 'price' => '149.99'],
        ];

        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: $targetHeaders,
            columnsToRemove: [],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $context = new TransformContext(
            targetPath: $targetPath,
            config: $config,
            sourceRows: $sourceRows,
            sourceHeaders: $targetHeaders,
            targetHeaders: $targetHeaders,
            targetRows: null,
        );

        $strategy = $this->createAppendStrategy();

        // Act
        $strategy->execute($context);

        // Assert
        $csvReader = new CsvReader();
        $rows = $csvReader->getRows($targetPath);
        $this->assertSame('Canon', $rows[0]['brand']);
        $this->assertSame('Sony', $rows[1]['brand']);
    }

    /**
     * @return void
     */
    public function testReplaceStrategyReplacesEntireFile(): void
    {
        // Arrange
        $targetPath = $this->tempDir . '/target.csv';
        $targetHeaders = ['brand', 'price'];
        $targetContent = implode(',', $targetHeaders) . "\n" . 'Canon,99.99' . "\n" . 'Nikon,129.99';
        file_put_contents($targetPath, $targetContent);

        $sourceRows = [
            ['brand' => 'Sony', 'price' => '149.99'],
        ];

        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: $targetHeaders,
            columnsToRemove: [],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $context = new TransformContext(
            targetPath: $targetPath,
            config: $config,
            sourceRows: $sourceRows,
            sourceHeaders: $targetHeaders,
            targetHeaders: $targetHeaders,
            targetRows: null,
        );

        $strategy = $this->createReplaceStrategy();

        // Act
        $result = $strategy->execute($context);

        // Assert
        $this->assertSame(1, $result['rows_written']);

        $csvReader = new CsvReader();
        $rows = $csvReader->getRows($targetPath);
        $this->assertCount(1, $rows);
        $this->assertSame('Sony', $rows[0]['brand']);
    }

    /**
     * @return void
     */
    public function testReplaceStrategyValidatesNewContent(): void
    {
        // Arrange
        $targetPath = $this->tempDir . '/target.csv';
        $targetHeaders = ['brand', 'price'];
        $targetContent = implode(',', $targetHeaders) . "\n";
        file_put_contents($targetPath, $targetContent);

        $sourceRows = [
            ['brand' => 'Sony', 'price' => '149.99'],
            ['brand' => 'Canon', 'price' => '99.99'],
        ];

        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: $targetHeaders,
            columnsToRemove: [],
            defaultValues: [],
            valueTransformations: [],
            rowFilters: [],
        );

        $context = new TransformContext(
            targetPath: $targetPath,
            config: $config,
            sourceRows: $sourceRows,
            sourceHeaders: $targetHeaders,
            targetHeaders: $targetHeaders,
            targetRows: null,
        );

        $strategy = $this->createReplaceStrategy();

        // Act
        $result = $strategy->execute($context);

        // Assert
        $csvReader = new CsvReader();
        $rows = $csvReader->getRows($targetPath);
        $this->assertCount(2, $rows);
        $this->assertSame('Sony', $rows[0]['brand']);
        $this->assertSame('Canon', $rows[1]['brand']);
    }

    /**
     * @return void
     */
    public function testUpdateStrategyUpdatesExistingRows(): void
    {
        // Arrange
        $targetPath = $this->tempDir . '/target.csv';
        $targetHeaders = ['brand', 'price', 'category'];
        $targetContent = implode(',', $targetHeaders) . "\n"
            . 'Canon,99.99,' . "\n"
            . 'Sony,149.99,';
        file_put_contents($targetPath, $targetContent);

        $csvReader = new CsvReader();
        $targetRows = $csvReader->getRows($targetPath);

        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: $targetHeaders,
            columnsToRemove: [],
            defaultValues: ['category' => 'Electronics'],
            valueTransformations: [],
            rowFilters: [],
        );

        $context = new TransformContext(
            targetPath: $targetPath,
            config: $config,
            sourceRows: null,
            sourceHeaders: null,
            targetHeaders: $targetHeaders,
            targetRows: $targetRows,
        );

        $strategy = $this->createUpdateStrategy();

        // Act
        $result = $strategy->execute($context);

        // Assert
        $this->assertSame(2, $result['rows_updated']);
        $this->assertSame(2, $result['total_rows']);

        $rows = $csvReader->getRows($targetPath);
        $this->assertSame('Electronics', $rows[0]['category']);
        $this->assertSame('Electronics', $rows[1]['category']);
    }

    /**
     * @return void
     */
    public function testUpdateStrategyPreservesNonMatchingRows(): void
    {
        // Arrange
        $targetPath = $this->tempDir . '/target.csv';
        $targetHeaders = ['brand', 'price', 'category'];
        $targetContent = implode(',', $targetHeaders) . "\n"
            . 'Canon,99.99,Cameras' . "\n"
            . 'Sony,149.99,';
        file_put_contents($targetPath, $targetContent);

        $csvReader = new CsvReader();
        $targetRows = $csvReader->getRows($targetPath);

        $config = new RowProcessingConfig(
            columnMappings: [],
            finalHeaders: $targetHeaders,
            columnsToRemove: [],
            defaultValues: ['category' => 'Electronics'],
            valueTransformations: [],
            rowFilters: [['column' => 'brand', 'operator' => 'equals', 'value' => 'Sony']],
        );

        $context = new TransformContext(
            targetPath: $targetPath,
            config: $config,
            sourceRows: null,
            sourceHeaders: null,
            targetHeaders: $targetHeaders,
            targetRows: $targetRows,
        );

        $strategy = $this->createUpdateStrategy();

        // Act
        $result = $strategy->execute($context);

        // Assert
        $this->assertSame(1, $result['rows_updated']);

        $rows = $csvReader->getRows($targetPath);
        $this->assertCount(2, $rows);
        $this->assertSame('Cameras', $rows[0]['category']);
        $this->assertSame('Electronics', $rows[1]['category']);
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\AppendStrategy
     */
    protected function createAppendStrategy(): AppendStrategy
    {
        $csvReader = new CsvReader();
        $csvWriter = new CsvWriter($csvReader);
        $filterEvaluator = new FilterEvaluator();

        $rowOperations = [
            new ColumnMappingOperation(),
            new DefaultValuesOperation(),
            new TransformationOperation(),
            new ColumnRemovalOperation(),
        ];

        return new AppendStrategy($csvReader, $csvWriter, $filterEvaluator, $rowOperations);
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\ReplaceStrategy
     */
    protected function createReplaceStrategy(): ReplaceStrategy
    {
        $csvReader = new CsvReader();
        $csvWriter = new CsvWriter($csvReader);
        $filterEvaluator = new FilterEvaluator();

        $rowOperations = [
            new ColumnMappingOperation(),
            new DefaultValuesOperation(),
            new TransformationOperation(),
            new ColumnRemovalOperation(),
        ];

        return new ReplaceStrategy($csvReader, $csvWriter, $filterEvaluator, $rowOperations);
    }

    /**
     * @return \SprykerSdk\Zed\AiDev\Business\DataImport\Strategy\UpdateStrategy
     */
    protected function createUpdateStrategy(): UpdateStrategy
    {
        $csvReader = new CsvReader();
        $csvWriter = new CsvWriter($csvReader);
        $filterEvaluator = new FilterEvaluator();

        $rowOperations = [
            new ColumnMappingOperation(),
            new DefaultValuesOperation(),
            new TransformationOperation(),
            new ColumnRemovalOperation(),
        ];

        return new UpdateStrategy($csvReader, $csvWriter, $filterEvaluator, $rowOperations);
    }
}
