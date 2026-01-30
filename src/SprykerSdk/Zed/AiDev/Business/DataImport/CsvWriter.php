<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use League\Csv\Writer;

class CsvWriter implements CsvWriterInterface
{
    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\CsvReaderInterface $csvReader
     */
    public function __construct(protected CsvReaderInterface $csvReader)
    {
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param string $filePath
     * @param array<string> $headers
     * @param array<int, array<string, mixed>> $rows
     * @param bool $createBackup
     *
     * @return string|null
     */
    public function write(string $filePath, array $headers, array $rows, bool $createBackup = true): ?string
    {
        $backupPath = null;

        if ($createBackup && file_exists($filePath)) {
            $backupPath = $this->createBackup($filePath);
        }

        $tempPath = $filePath . CsvConstants::TEMP_EXTENSION;
        $delimiter = file_exists($filePath) ? $this->csvReader->detectDelimiter($filePath) : CsvConstants::DEFAULT_DELIMITER;

        $writer = Writer::from($tempPath, 'w');
        $writer->setDelimiter($delimiter);

        $writer->insertOne($headers);
        $writer->insertAll($this->prepareRows($rows, $headers));

        rename($tempPath, $filePath);

        return $backupPath;
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param string $filePath
     * @param array<int, array<string, mixed>> $rows
     * @param bool $createBackup
     *
     * @return string|null
     */
    public function append(string $filePath, array $rows, bool $createBackup = true): ?string
    {
        $backupPath = null;

        if ($createBackup && file_exists($filePath)) {
            $backupPath = $this->createBackup($filePath);
        }

        $headers = $this->csvReader->getHeaders($filePath);
        $delimiter = $this->csvReader->detectDelimiter($filePath);
        $writer = Writer::from($filePath, 'a');
        $writer->setDelimiter($delimiter);

        $writer->insertAll($this->prepareRows($rows, $headers));

        return $backupPath;
    }

    /**
     * @param string $filePath
     *
     * @return string
     */
    protected function createBackup(string $filePath): string
    {
        $backupPath = $filePath . CsvConstants::BACKUP_EXTENSION;
        copy($filePath, $backupPath);

        return $backupPath;
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
}
