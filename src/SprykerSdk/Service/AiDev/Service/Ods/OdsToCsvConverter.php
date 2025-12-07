<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\Ods;

use RuntimeException;
use SprykerSdk\Service\AiDev\Service\Csv\CsvWriterInterface;
use SprykerSdk\Service\AiDev\Service\FileSystem\PathResolverInterface;

class OdsToCsvConverter implements OdsToCsvConverterInterface
{
    public function __construct(
        protected PathResolverInterface $pathResolver,
        protected CsvWriterInterface $csvWriter,
        protected OdsParserInterface $odsParser
    ) {
    }

    /**
     * @throws \RuntimeException
     *
     * @return array<int, string>
     */
    public function convertOdsToCsvFiles(string $odsFilePath, string $outputDirectory): array
    {
        $sheets = $this->odsParser->parseOdsFile($odsFilePath);

        $resolvedDirectory = $this->pathResolver->resolvePath($outputDirectory);

        if (!is_dir($resolvedDirectory)) {
            if (!mkdir($resolvedDirectory, 0777, true) && !is_dir($resolvedDirectory)) {
                throw new RuntimeException(sprintf('Failed to create directory: %s. Check permissions.', $resolvedDirectory));
            }
        }

        if (!is_writable($resolvedDirectory)) {
            throw new RuntimeException(sprintf('Directory is not writable: %s', $resolvedDirectory));
        }

        $createdFiles = [];

        foreach ($sheets as $sheet) {
            $filePath = $this->createCsvFile($sheet, $resolvedDirectory);
            if ($filePath !== null) {
                $createdFiles[] = $filePath;
            }
        }

        return $createdFiles;
    }

    /**
     * @param array<string, mixed> $sheet
     */
    protected function createCsvFile(array $sheet, string $outputDirectory): ?string
    {
        $sheetName = $sheet['name'] ?? 'unnamed';
        $rows = $sheet['rows'] ?? [];

        if (count($rows) === 0) {
            return null;
        }

        $fileName = $this->sanitizeFileName($sheetName) . '.csv';
        $filePath = rtrim($outputDirectory, '/') . '/' . $fileName;

        $headers = array_shift($rows);
        if ($headers === null || count($headers) === 0) {
            return null;
        }

        $dataRows = array_map(function ($row) use ($headers) {
            $paddedRow = array_pad($row, count($headers), '');

            return array_combine($headers, array_slice($paddedRow, 0, count($headers))) ?: [];
        }, $rows);

        $result = $this->csvWriter->writeCsvFile(
            $filePath,
            $headers,
            $dataRows,
            'w',
        );

        return $result->getIsSuccess() ? $filePath : null;
    }

    protected function sanitizeFileName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);

        return $sanitized ?? 'unnamed';
    }
}
