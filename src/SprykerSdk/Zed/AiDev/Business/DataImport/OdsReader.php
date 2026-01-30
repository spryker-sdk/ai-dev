<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class OdsReader implements OdsReaderInterface
{
    protected const string NAMESPACE_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    protected const string NAMESPACE_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    protected const string NAMESPACE_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    protected const string ODS_CONTENT_FILE = 'content.xml';

    protected const string XPATH_TABLE_ROW = './/table:table-row';

    protected const string XPATH_TABLE_CELL = './/table:table-cell';

    protected const string XPATH_TEXT_PARAGRAPH = './/text:p';

    protected const string VALUE_TYPE_STRING = 'string';

    protected const string VALUE_TYPE_FLOAT = 'float';

    protected const string VALUE_TYPE_PERCENTAGE = 'percentage';

    protected const string VALUE_TYPE_CURRENCY = 'currency';

    protected const string VALUE_TYPE_DATE = 'date';

    protected const string VALUE_TYPE_TIME = 'time';

    protected const string VALUE_TYPE_BOOLEAN = 'boolean';

    protected const string BOOLEAN_TRUE = 'true';

    protected const string BOOLEAN_VALUE_TRUE = '1';

    protected const string BOOLEAN_VALUE_FALSE = '0';

    protected const int REPEAT_OFFSET = 1;

    /**
     * @param string $filePath
     *
     * @return \SimpleXMLElement
     */
    public function extractContent(string $filePath): SimpleXMLElement
    {
        $this->validateOdsFile($filePath);
        $this->validateRequiredExtensions();

        $content = $this->extractContentXml($filePath);
        $xml = $this->parseXmlContent($content);

        $this->registerNamespaces($xml);

        return $xml;
    }

    /**
     * @param \SimpleXMLElement $sheet
     *
     * @return array<array<string>>
     */
    public function extractRows(SimpleXMLElement $sheet): array
    {
        $this->registerNamespaces($sheet);
        $tableRows = $sheet->xpath(static::XPATH_TABLE_ROW);

        if ($tableRows === false) {
            return [];
        }

        if ($tableRows === []) {
            return [];
        }

        $rows = [];
        foreach ($tableRows as $tableRow) {
            $this->registerNamespaces($tableRow);
            $rows[] = $this->extractRowCells($tableRow);
        }

        return $this->trimTrailingEmptyRows($rows);
    }

    /**
     * @param \SimpleXMLElement $cell
     *
     * @return string
     */
    public function getCellValue(SimpleXMLElement $cell): string
    {
        $this->registerNamespaces($cell);
        $attributes = $cell->attributes(static::NAMESPACE_OFFICE);

        if (!isset($attributes['value-type'])) {
            return '';
        }

        $valueType = (string)$attributes['value-type'];

        return $this->extractValueByType($valueType, $attributes, $cell);
    }

    /**
     * @param string $filePath
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function validateOdsFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException(
                sprintf('ODS file not found: %s', $filePath),
            );
        }
    }

    /**
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function validateRequiredExtensions(): void
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('PHP zip extension is required to read ODS files');
        }

        if (!extension_loaded('simplexml')) {
            throw new RuntimeException('PHP simplexml extension is required to parse ODS files');
        }
    }

    /**
     * @param string $filePath
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    protected function extractContentXml(string $filePath): string
    {
        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException(
                sprintf('Invalid ODS file format: %s', $filePath),
            );
        }

        $content = $zip->getFromName(static::ODS_CONTENT_FILE);
        $zip->close();

        if ($content === false) {
            throw new RuntimeException(
                sprintf('Failed to extract %s from ODS file', static::ODS_CONTENT_FILE),
            );
        }

        return $content;
    }

    /**
     * @param string $content
     *
     * @throws \RuntimeException
     *
     * @return \SimpleXMLElement
     */
    protected function parseXmlContent(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            throw new RuntimeException('Failed to parse ODS content.xml');
        }

        return $xml;
    }

    /**
     * @param string $valueType
     * @param \SimpleXMLElement $attributes
     * @param \SimpleXMLElement $cell
     *
     * @return string
     */
    protected function extractValueByType(string $valueType, SimpleXMLElement $attributes, SimpleXMLElement $cell): string
    {
        switch ($valueType) {
            case static::VALUE_TYPE_STRING:
                return $this->extractStringValue($attributes, $cell);
            case static::VALUE_TYPE_FLOAT:
            case static::VALUE_TYPE_PERCENTAGE:
            case static::VALUE_TYPE_CURRENCY:
                return (string)($attributes['value'] ?? '');
            case static::VALUE_TYPE_DATE:
            case static::VALUE_TYPE_TIME:
                return (string)($attributes['date-value'] ?? '');
            case static::VALUE_TYPE_BOOLEAN:
                return $this->extractBooleanValue($attributes);
            default:
                return '';
        }
    }

    /**
     * @param \SimpleXMLElement $attributes
     * @param \SimpleXMLElement $cell
     *
     * @return string
     */
    protected function extractStringValue(SimpleXMLElement $attributes, SimpleXMLElement $cell): string
    {
        $stringValue = $attributes['string-value'] ?? '';

        if ((string)$stringValue === '') {
            return $this->extractTextContent($cell);
        }

        return (string)$stringValue;
    }

    /**
     * @param \SimpleXMLElement $cell
     *
     * @return string
     */
    protected function extractTextContent(SimpleXMLElement $cell): string
    {
        $textNodes = $cell->xpath(static::XPATH_TEXT_PARAGRAPH);

        if ($textNodes === false) {
            return '';
        }

        if (count($textNodes) === 0) {
            return '';
        }

        return (string)$textNodes[0];
    }

    /**
     * @param \SimpleXMLElement $attributes
     *
     * @return string
     */
    protected function extractBooleanValue(SimpleXMLElement $attributes): string
    {
        $boolValue = (string)($attributes['boolean-value'] ?? '');

        return $boolValue === static::BOOLEAN_TRUE
            ? static::BOOLEAN_VALUE_TRUE
            : static::BOOLEAN_VALUE_FALSE;
    }

    /**
     * @param \SimpleXMLElement $tableRow
     *
     * @return array<string>
     */
    protected function extractRowCells(SimpleXMLElement $tableRow): array
    {
        $this->registerNamespaces($tableRow);
        $tableCells = $tableRow->xpath(static::XPATH_TABLE_CELL);

        if ($tableCells === false) {
            return [];
        }

        if ($tableCells === []) {
            return [];
        }

        $cells = [];
        foreach ($tableCells as $tableCell) {
            $this->registerNamespaces($tableCell);
            $cells = array_merge($cells, $this->extractCellWithRepeats($tableCell));
        }

        return $cells;
    }

    /**
     * @param \SimpleXMLElement $tableCell
     *
     * @return array<string>
     */
    protected function extractCellWithRepeats(SimpleXMLElement $tableCell): array
    {
        $cellValue = $this->getCellValue($tableCell);
        $repeats = $this->getColumnRepeats($tableCell);

        return array_fill(0, $repeats + static::REPEAT_OFFSET, $cellValue);
    }

    /**
     * @param \SimpleXMLElement $tableCell
     *
     * @return int
     */
    protected function getColumnRepeats(SimpleXMLElement $tableCell): int
    {
        $attributes = $tableCell->attributes(static::NAMESPACE_TABLE);

        if (!isset($attributes['number-columns-repeated'])) {
            return 0;
        }

        return (int)$attributes['number-columns-repeated'] - static::REPEAT_OFFSET;
    }

    /**
     * @param array<array<string>> $rows
     *
     * @return array<array<string>>
     */
    protected function trimTrailingEmptyRows(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $lastRow = end($rows);

        if (!$this->isRowEmpty($lastRow)) {
            return $rows;
        }

        array_pop($rows);

        return $this->trimTrailingEmptyRows($rows);
    }

    /**
     * @param array<string> $row
     *
     * @return bool
     */
    protected function isRowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \SimpleXMLElement $xml
     *
     * @return void
     */
    protected function registerNamespaces(SimpleXMLElement $xml): void
    {
        $xml->registerXPathNamespace('table', static::NAMESPACE_TABLE);
        $xml->registerXPathNamespace('office', static::NAMESPACE_OFFICE);
        $xml->registerXPathNamespace('text', static::NAMESPACE_TEXT);
    }
}
