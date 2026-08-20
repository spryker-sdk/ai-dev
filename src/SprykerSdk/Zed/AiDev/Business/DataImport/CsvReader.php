<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use League\Csv\Info;
use League\Csv\Reader;
use League\Csv\Statement;

class CsvReader implements CsvReaderInterface
{
 /**
  * @return array<string>
  */
    public function getHeaders(string $filePath): array
    {
        $csv = $this->createReader($filePath);
        $csv->setHeaderOffset(0);

        return $csv->getHeader();
    }

    public function getRowCount(string $filePath): int
    {
        $csv = $this->createReader($filePath);
        $csv->setHeaderOffset(0);

        return count($csv);
    }

    /**
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

    public function detectDelimiter(string $filePath): string
    {
        $csv = Reader::from($filePath, 'r');
        $delimiterStats = Info::getDelimiterStats($csv, CsvConstants::SUPPORTED_DELIMITERS, 5);

        if (!$delimiterStats) {
            return CsvConstants::DEFAULT_DELIMITER;
        }

        arsort($delimiterStats);
        $delimiter = array_key_first($delimiterStats);

        if ($delimiterStats[$delimiter] === 0) {
            return CsvConstants::DEFAULT_DELIMITER;
        }

        return $delimiter;
    }

    protected function createReader(string $filePath): Reader
    {
        $csv = Reader::from($filePath, 'r');
        $delimiter = $this->detectDelimiter($filePath);
        $csv->setDelimiter($delimiter);

        return $csv;
    }
}
