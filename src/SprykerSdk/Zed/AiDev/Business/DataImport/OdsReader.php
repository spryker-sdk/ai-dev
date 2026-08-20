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
     * @return array<array<string>>
     */
    public function extractRows(SimpleXMLElement $sheet): array
    {
        $this->registerNamespaces($sheet);
        $tableRows = $sheet->xpath(OdsConstants::XPATH_TABLE_ROW);

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

    public function getCellValue(SimpleXMLElement $cell): string
    {
        $this->registerNamespaces($cell);
        $attributes = $cell->attributes(OdsConstants::NAMESPACE_OFFICE);

        if (!isset($attributes['value-type'])) {
            return '';
        }

        $valueType = (string)$attributes['value-type'];

        return $this->extractValueByType($valueType, $attributes, $cell);
    }

    /**
     * @throws \RuntimeException
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
     * @throws \RuntimeException
     */
    protected function extractContentXml(string $filePath): string
    {
        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException(
                sprintf('Invalid ODS file format: %s', $filePath),
            );
        }

        $content = $zip->getFromName(OdsConstants::ODS_CONTENT_FILE);
        $zip->close();

        if ($content === false) {
            throw new RuntimeException(
                sprintf('Failed to extract %s from ODS file', OdsConstants::ODS_CONTENT_FILE),
            );
        }

        return $content;
    }

    /**
     * @throws \RuntimeException
     */
    protected function parseXmlContent(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            throw new RuntimeException('Failed to parse ODS content.xml');
        }

        return $xml;
    }

    protected function extractValueByType(string $valueType, SimpleXMLElement $attributes, SimpleXMLElement $cell): string
    {
        switch ($valueType) {
            case OdsConstants::VALUE_TYPE_STRING:
                return $this->extractStringValue($attributes, $cell);
            case OdsConstants::VALUE_TYPE_FLOAT:
            case OdsConstants::VALUE_TYPE_PERCENTAGE:
            case OdsConstants::VALUE_TYPE_CURRENCY:
                return (string)($attributes['value'] ?? '');
            case OdsConstants::VALUE_TYPE_DATE:
            case OdsConstants::VALUE_TYPE_TIME:
                return (string)($attributes['date-value'] ?? '');
            case OdsConstants::VALUE_TYPE_BOOLEAN:
                return $this->extractBooleanValue($attributes);
            default:
                return '';
        }
    }

    protected function extractStringValue(SimpleXMLElement $attributes, SimpleXMLElement $cell): string
    {
        $stringValue = $attributes['string-value'] ?? '';

        if ((string)$stringValue === '') {
            return $this->extractTextContent($cell);
        }

        return (string)$stringValue;
    }

    protected function extractTextContent(SimpleXMLElement $cell): string
    {
        $textNodes = $cell->xpath(OdsConstants::XPATH_TEXT_PARAGRAPH);

        if ($textNodes === false) {
            return '';
        }

        if (count($textNodes) === 0) {
            return '';
        }

        return (string)$textNodes[0];
    }

    protected function extractBooleanValue(SimpleXMLElement $attributes): string
    {
        $boolValue = (string)($attributes['boolean-value'] ?? '');

        return $boolValue === OdsConstants::BOOLEAN_TRUE
        ? OdsConstants::BOOLEAN_VALUE_TRUE
        : OdsConstants::BOOLEAN_VALUE_FALSE;
    }

    /**
     * @return array<string>
     */
    protected function extractRowCells(SimpleXMLElement $tableRow): array
    {
        $this->registerNamespaces($tableRow);
        $tableCells = $tableRow->xpath(OdsConstants::XPATH_TABLE_CELL);

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
     * @return array<string>
     */
    protected function extractCellWithRepeats(SimpleXMLElement $tableCell): array
    {
        $cellValue = $this->getCellValue($tableCell);
        $repeats = $this->getColumnRepeats($tableCell);

        return array_fill(0, $repeats + OdsConstants::REPEAT_OFFSET, $cellValue);
    }

    protected function getColumnRepeats(SimpleXMLElement $tableCell): int
    {
        $attributes = $tableCell->attributes(OdsConstants::NAMESPACE_TABLE);

        if (!isset($attributes['number-columns-repeated'])) {
            return 0;
        }

        return (int)$attributes['number-columns-repeated'] - OdsConstants::REPEAT_OFFSET;
    }

    /**
     * @param array<array<string>> $rows
     *
     * @return array<array<string>>
     */
    protected function trimTrailingEmptyRows(array $rows): array
    {
        while ($rows !== [] && $this->isRowEmpty(end($rows))) {
            array_pop($rows);
        }

        return $rows;
    }

    /**
     * @param array<string> $row
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

    protected function registerNamespaces(SimpleXMLElement $xml): void
    {
        $xml->registerXPathNamespace('table', OdsConstants::NAMESPACE_TABLE);
        $xml->registerXPathNamespace('office', OdsConstants::NAMESPACE_OFFICE);
        $xml->registerXPathNamespace('text', OdsConstants::NAMESPACE_TEXT);
    }
}
