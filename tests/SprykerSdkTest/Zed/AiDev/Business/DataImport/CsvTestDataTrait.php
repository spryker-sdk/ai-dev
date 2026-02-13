<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\DataImport;

trait CsvTestDataTrait
{
    /**
     * @var array<string>
     */
    protected const array PRODUCT_HEADERS = [
        'abstract_sku',
        'name.en_US',
        'name.de_DE',
        'brand',
        'color',
        'color_code',
        'price',
        'tax_set_name',
        'description.en_US',
    ];

    /**
     * @var array<int, array<string, string>>
     */
    protected const array PRODUCT_ROWS = [
        [
            'abstract_sku' => '001',
            'name.en_US' => 'Canon IXUS 160',
            'name.de_DE' => 'Canon IXUS 160',
            'brand' => 'Canon',
            'color' => 'Red',
            'color_code' => '#DC2E09',
            'price' => '99.99',
            'tax_set_name' => 'Electronics',
            'description.en_US' => 'Add a personal touch',
        ],
        [
            'abstract_sku' => '002',
            'name.en_US' => 'Sony Camera',
            'name.de_DE' => 'Sony Kamera',
            'brand' => 'Sony',
            'color' => 'Black',
            'color_code' => '#000000',
            'price' => '149.99',
            'tax_set_name' => 'Electronics',
            'description.en_US' => 'Professional quality',
        ],
        [
            'abstract_sku' => '003',
            'name.en_US' => 'Nikon Book',
            'name.de_DE' => 'Nikon Buch',
            'brand' => 'Nikon',
            'color' => 'White',
            'color_code' => '#FFFFFF',
            'price' => '29.99',
            'tax_set_name' => 'Books',
            'description.en_US' => 'Photography guide',
        ],
    ];

    /**
     * @var array<string>
     */
    protected const array PRODUCT_SOURCE_HEADERS = [
        'manufacturer',
        'product_name_en',
        'product_name_de',
        'product_color',
        'hex_color',
        'cost',
        'vat_category',
        'product_desc_en',
        'product_id',
        'stock_quantity',
        'weight_kg',
        'status',
    ];

    /**
     * @var array<int, array<string, string>>
     */
    protected const array PRODUCT_SOURCE_ROWS = [
        [
            'manufacturer' => 'Canon',
            'product_name_en' => 'Canon IXUS 160',
            'product_name_de' => 'Canon IXUS 160',
            'product_color' => 'Red',
            'hex_color' => '#DC2E09',
            'cost' => '99.99',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'Add a personal touch',
            'product_id' => 'SKU001',
            'stock_quantity' => '50',
            'weight_kg' => '0.15',
            'status' => 'active',
        ],
        [
            'manufacturer' => 'Sony',
            'product_name_en' => 'Sony Camera',
            'product_name_de' => 'Sony Kamera',
            'product_color' => 'Black',
            'hex_color' => '#000000',
            'cost' => '149.99',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'Professional quality',
            'product_id' => 'SKU002',
            'stock_quantity' => '30',
            'weight_kg' => '0.25',
            'status' => 'active',
        ],
        [
            'manufacturer' => 'Nikon',
            'product_name_en' => 'Nikon D3500',
            'product_name_de' => 'Nikon D3500',
            'product_color' => 'Black',
            'hex_color' => '#000000',
            'cost' => '399.99',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'DSLR camera',
            'product_id' => 'SKU003',
            'stock_quantity' => '15',
            'weight_kg' => '0.85',
            'status' => 'active',
        ],
        [
            'manufacturer' => 'Fuji',
            'product_name_en' => 'Fuji X-T4',
            'product_name_de' => 'Fuji X-T4',
            'product_color' => 'Silver',
            'hex_color' => '#C0C0C0',
            'cost' => '1299.99',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'Mirrorless camera',
            'product_id' => 'SKU004',
            'stock_quantity' => '8',
            'weight_kg' => '0.65',
            'status' => 'active',
        ],
        [
            'manufacturer' => 'Canon',
            'product_name_en' => 'Canon EOS R5',
            'product_name_de' => 'Canon EOS R5',
            'product_color' => 'Black',
            'hex_color' => '#000000',
            'cost' => '2499.99',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'Professional mirrorless',
            'product_id' => 'SKU005',
            'stock_quantity' => '5',
            'weight_kg' => '0.73',
            'status' => 'active',
        ],
        [
            'manufacturer' => 'Sony',
            'product_name_en' => 'Sony A7 III',
            'product_name_de' => 'Sony A7 III',
            'product_color' => 'Black',
            'hex_color' => '#000000',
            'cost' => '1799.99',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'Full frame mirrorless',
            'product_id' => 'SKU006',
            'stock_quantity' => '12',
            'weight_kg' => '0.65',
            'status' => 'active',
        ],
        [
            'manufacturer' => 'Nikon',
            'product_name_en' => 'Nikon Z6',
            'product_name_de' => 'Nikon Z6',
            'product_color' => 'Black',
            'hex_color' => '#000000',
            'cost' => '1499.99',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'Full frame mirrorless',
            'product_id' => 'SKU007',
            'stock_quantity' => '10',
            'weight_kg' => '0.67',
            'status' => 'inactive',
        ],
        [
            'manufacturer' => 'Olympus',
            'product_name_en' => 'Olympus OM-D',
            'product_name_de' => 'Olympus OM-D',
            'product_color' => 'Black',
            'hex_color' => '#000000',
            'cost' => '899.99',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'Micro four thirds',
            'product_id' => 'SKU008',
            'stock_quantity' => '20',
            'weight_kg' => '0.41',
            'status' => 'active',
        ],
        [
            'manufacturer' => 'Panasonic',
            'product_name_en' => 'Panasonic GH5',
            'product_name_de' => 'Panasonic GH5',
            'product_color' => 'Black',
            'hex_color' => '#000000',
            'cost' => '1199.99',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'Video focused camera',
            'product_id' => 'SKU009',
            'stock_quantity' => '7',
            'weight_kg' => '0.72',
            'status' => 'active',
        ],
        [
            'manufacturer' => 'Leica',
            'product_name_en' => 'Leica Q2',
            'product_name_de' => 'Leica Q2',
            'product_color' => 'Black',
            'hex_color' => '#000000',
            'cost' => '4995.00',
            'vat_category' => 'Electronics',
            'product_desc_en' => 'Premium compact',
            'product_id' => 'SKU010',
            'stock_quantity' => '3',
            'weight_kg' => '0.72',
            'status' => 'inactive',
        ],
    ];

    /**
     * @var array<string>
     */
    protected const PRODUCT_TARGET_HEADERS = [
        'brand',
        'name.en_US',
        'name.de_DE',
        'color',
        'color_code',
        'price',
        'tax_set_name',
        'description.en_US',
        'sku',
        'stock',
        'weight',
    ];

    /**
     * @return array<string, string>
     */
    protected function getStandardColumnMappings(): array
    {
        return [
            'manufacturer' => 'brand',
            'product_name_en' => 'name.en_US',
            'product_name_de' => 'name.de_DE',
            'product_color' => 'color',
            'hex_color' => 'color_code',
            'cost' => 'price',
            'vat_category' => 'tax_set_name',
            'product_desc_en' => 'description.en_US',
            'product_id' => 'sku',
            'stock_quantity' => 'stock',
            'weight_kg' => 'weight',
        ];
    }

    /**
     * @param array<int, array<string, string>> $sourceRows
     *
     * @return array<int, array<string, string>>
     */
    protected function mapSourceToTargetRows(array $sourceRows): array
    {
        $mappings = $this->getStandardColumnMappings();
        $mappedRows = [];

        foreach ($sourceRows as $sourceRow) {
            $mappedRow = [];
            foreach ($mappings as $sourceColumn => $targetColumn) {
                if (isset($sourceRow[$sourceColumn])) {
                    $mappedRow[$targetColumn] = $sourceRow[$sourceColumn];
                }
            }
            $mappedRows[] = $mappedRow;
        }

        return $mappedRows;
    }

    /**
     * @param string $delimiter
     *
     * @return string
     */
    protected function buildCsvContent(string $delimiter = ','): string
    {
        $lines = [];
        $lines[] = implode($delimiter, static::PRODUCT_HEADERS);

        foreach (static::PRODUCT_ROWS as $row) {
            $values = array_map(fn ($header) => $row[$header] ?? '', static::PRODUCT_HEADERS);
            $lines[] = implode($delimiter, $values);
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $content CSV content to write
     * @param string|null $tempDir Optional temporary directory. If not provided, uses sys_get_temp_dir()
     *
     * @return string Path to the created temporary file
     */
    protected function createTempCsv(string $content, ?string $tempDir = null): string
    {
        $directory = $tempDir ?? sys_get_temp_dir();
        $filePath = $directory . '/test_' . uniqid() . '.csv';
        file_put_contents($filePath, $content);

        return $filePath;
    }
}
