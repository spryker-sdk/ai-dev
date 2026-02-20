<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use League\Csv\Writer;
use RuntimeException;

class CsvWriter implements CsvWriterInterface
{
    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface $csvReader
     */
    public function __construct(protected CsvReaderInterface $csvReader)
    {
    }

    /**
     * @param string $filePath
     * @param array<string> $headers
     * @param array<int, array<string, mixed>> $rows
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    public function write(string $filePath, array $headers, array $rows): void
    {
        $tempPath = $filePath . CsvConstants::TEMP_EXTENSION;
        $delimiter = $this->getDelimiterForWrite($filePath);

        $writer = Writer::from($tempPath, 'w');
        $writer->setDelimiter($delimiter);

        $writer->insertOne($headers);
        $writer->insertAll($this->prepareRows($rows, $headers));

        if (!rename($tempPath, $filePath)) {
            throw new RuntimeException(sprintf('Failed to rename temp file "%s" to "%s"', $tempPath, $filePath));
        }
    }

    /**
     * @param string $filePath
     * @param array<int, array<string, mixed>> $rows
     *
     * @return void
     */
    public function append(string $filePath, array $rows): void
    {
        $this->ensureFileEndsWithNewline($filePath);

        $headers = $this->csvReader->getHeaders($filePath);
        $delimiter = $this->csvReader->detectDelimiter($filePath);

        $writer = Writer::from($filePath, 'a');
        $writer->setDelimiter($delimiter);

        $writer->insertAll($this->prepareRows($rows, $headers));
    }

    /**
     * @param string $filePath
     *
     * @return string
     */
    protected function getDelimiterForWrite(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return CsvConstants::DEFAULT_DELIMITER;
        }

        return $this->csvReader->detectDelimiter($filePath);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string> $headers
     *
     * @return array<int, array<int, mixed>>
     */
    protected function prepareRows(array $rows, array $headers): array
    {
        $prepared = [];

        foreach ($rows as $row) {
            $preparedRow = [];

            foreach ($headers as $header) {
                $preparedRow[] = $row[$header] ?? '';
            }

            $prepared[] = $preparedRow;
        }

        return $prepared;
    }

    /**
     * @param string $filePath
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    public function ensureFileEndsWithNewline(string $filePath): void
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            return;
        }

        $handle = fopen($filePath, 'r+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Failed to open file "%s" for newline check', $filePath));
        }

        fseek($handle, -1, SEEK_END);
        $lastChar = fread($handle, 1);

        if ($lastChar !== "\n") {
            fseek($handle, 0, SEEK_END);
            fwrite($handle, "\n");
        }

        fclose($handle);
    }
}
