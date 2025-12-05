<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\Ods;

use SimpleXMLElement;
use ZipArchive;

class OdsParser implements OdsParserInterface
{
    protected const string NAMESPACE_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    protected const string NAMESPACE_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    protected const string NAMESPACE_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseOdsFile(string $odsFilePath): array
    {
        if (!file_exists($odsFilePath)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($odsFilePath) !== true) {
            return [];
        }

        $contentXml = $zip->getFromName('content.xml');
        $zip->close();

        if ($contentXml === false) {
            return [];
        }

        return $this->parseContentXml($contentXml);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseContentXml(string $contentXml): array
    {
        $xml = simplexml_load_string($contentXml);
        if ($xml === false) {
            return [];
        }

        $this->registerNamespaces($xml);

        $sheets = $xml->xpath('//table:table');
        if ($sheets === false) {
            return [];
        }

        $result = [];
        foreach ($sheets as $sheet) {
            $result[] = $this->parseSheet($sheet, $xml);
        }

        return $result;
    }

    protected function registerNamespaces(SimpleXMLElement $xml): void
    {
        $xml->registerXPathNamespace('office', static::NAMESPACE_OFFICE);
        $xml->registerXPathNamespace('table', static::NAMESPACE_TABLE);
        $xml->registerXPathNamespace('text', static::NAMESPACE_TEXT);
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseSheet(SimpleXMLElement $sheet, SimpleXMLElement $xml): array
    {
        $namespaces = $xml->getNamespaces(true);
        $sheetName = (string)$sheet->attributes($namespaces['table'])['name'];

        $rows = $sheet->xpath('.//table:table-row');
        if ($rows === false) {
            $rows = [];
        }

        $parsedRows = [];
        foreach ($rows as $row) {
            $parsedRow = $this->parseRow($row);
            if (!$this->isEmptyRow($parsedRow)) {
                $parsedRows[] = $parsedRow;
            }
        }

        $normalizedRows = $this->normalizeRowLengths($parsedRows);

        return [
            'name' => $sheetName,
            'rows' => $normalizedRows,
            'rowCount' => count($normalizedRows),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function parseRow(SimpleXMLElement $row): array
    {
        $cells = $row->xpath('.//table:table-cell');
        if ($cells === false) {
            return [];
        }

        $namespaces = $row->getNamespaces(true);
        $values = [];

        foreach ($cells as $cell) {
            $cellValue = $this->parseCellValue($cell);
            $cellAttrs = $cell->attributes($namespaces['table']);
            $repeatCount = isset($cellAttrs['number-columns-repeated'])
                ? (int)$cellAttrs['number-columns-repeated']
                : 1;

            for ($i = 0; $i < $repeatCount; $i++) {
                $values[] = $cellValue;
            }
        }

        return $values;
    }

    protected function parseCellValue(SimpleXMLElement $cell): string
    {
        $textNodes = $cell->xpath('.//text:p');
        if ($textNodes === false || count($textNodes) === 0) {
            return '';
        }

        $cellValue = '';
        foreach ($textNodes as $textNode) {
            $cellValue .= (string)$textNode;
        }

        return $cellValue;
    }

    /**
     * @param array<int, string> $row
     */
    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<int, string>> $rows
     *
     * @return array<int, array<int, string>>
     */
    protected function normalizeRowLengths(array $rows): array
    {
        if (count($rows) === 0) {
            return $rows;
        }

        $maxLength = 0;
        foreach ($rows as $row) {
            $trimmedLength = $this->getTrimmedLength($row);
            if ($trimmedLength > $maxLength) {
                $maxLength = $trimmedLength;
            }
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            $normalizedRows[] = $this->padOrTrimRow($row, $maxLength);
        }

        return $normalizedRows;
    }

    /**
     * @param array<int, string> $row
     */
    protected function getTrimmedLength(array $row): int
    {
        $length = count($row);
        while ($length > 0 && $row[$length - 1] === '') {
            $length--;
        }

        return $length;
    }

    /**
     * @param array<int, string> $row
     *
     * @return array<int, string>
     */
    protected function padOrTrimRow(array $row, int $targetLength): array
    {
        $currentLength = count($row);

        if ($currentLength > $targetLength) {
            return array_slice($row, 0, $targetLength);
        }

        if ($currentLength < $targetLength) {
            return array_pad($row, $targetLength, '');
        }

        return $row;
    }
}
