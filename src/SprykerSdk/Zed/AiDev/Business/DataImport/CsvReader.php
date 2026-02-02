<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use League\Csv\Reader;
use League\Csv\Statement;

class CsvReader implements CsvReaderInterface
{
    /**
     * @param string $filePath
     *
     * @return array<string>
     */
    public function getHeaders(string $filePath): array
    {
        $csv = $this->createReader($filePath);
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();

        return $headers;
    }

    /**
     * @param string $filePath
     *
     * @return int
     */
    public function getRowCount(string $filePath): int
    {
        $csv = $this->createReader($filePath);
        $csv->setHeaderOffset(0);

        $rowCount = count($csv);

        return $rowCount;
    }

    /**
     * @param string $filePath
     * @param int $offset
     * @param int|null $limit
     *
     * @return array<array<string, string>>
     */
    public function getRows(string $filePath, int $offset = 0, ?int $limit = null): array
    {
        $csv = $this->createReader($filePath);
        $csv->setHeaderOffset(0);

        $statement = new Statement();

        if ($offset > 0) {
            $statement = $statement->offset($offset);
        }

        if ($limit !== null) {
            $statement = $statement->limit($limit);
        }

        $records = $statement->process($csv);

        return iterator_to_array($records, false);
    }

    /**
     * @param string $filePath
     *
     * @return string
     */
    public function detectEncoding(string $filePath): string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return CsvConstants::DEFAULT_ENCODING;
        }

        if (mb_check_encoding($content, CsvConstants::DEFAULT_ENCODING)) {
            return CsvConstants::DEFAULT_ENCODING;
        }

        $detectedEncoding = mb_detect_encoding($content, CsvConstants::SUPPORTED_ENCODINGS, true);
        $encoding = $detectedEncoding !== false ? $detectedEncoding : CsvConstants::DEFAULT_ENCODING;

        return $encoding;
    }

    /**
     * @param string $filePath
     *
     * @return string
     */
    public function detectDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return CsvConstants::DEFAULT_DELIMITER;
        }

        $firstLine = fgets($handle);
        fclose($handle);

        if ($firstLine === false) {
            return CsvConstants::DEFAULT_DELIMITER;
        }

        $detectedDelimiter = $this->detectDelimiterFromLine($firstLine);

        return $detectedDelimiter;
    }

    /**
     * @param string $line
     *
     * @return string
     */
    protected function detectDelimiterFromLine(string $line): string
    {
        $delimiterCounts = [];

        foreach (CsvConstants::SUPPORTED_DELIMITERS as $delimiter) {
            $delimiterCounts[$delimiter] = substr_count($line, $delimiter);
        }

        arsort($delimiterCounts);

        $detectedDelimiter = array_key_first($delimiterCounts);

        if ($delimiterCounts[$detectedDelimiter] === 0) {
            return CsvConstants::DEFAULT_DELIMITER;
        }

        return $detectedDelimiter;
    }

    /**
     * @param string $filePath
     *
     * @return \League\Csv\Reader
     */
    protected function createReader(string $filePath): Reader
    {
        $csv = Reader::from($filePath, 'r');

        $delimiter = $this->detectDelimiter($filePath);
        $csv->setDelimiter($delimiter);

        $encoding = $this->detectEncoding($filePath);
        if ($encoding !== CsvConstants::DEFAULT_ENCODING) {
            $csv->addStreamFilter(sprintf('convert.iconv.%s/%s', $encoding, CsvConstants::DEFAULT_ENCODING));
        }

        return $csv;
    }
}
