<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev;

use Generated\Shared\Transfer\CsvOperationResultTransfer;
use Spryker\Service\Kernel\AbstractService;

/**
 * @method \SprykerSdk\Service\AiDev\AiDevServiceFactory getFactory()
 */
class AiDevService extends AbstractService implements AiDevServiceInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $path
     *
     * @return string
     */
    public function resolvePath(string $path): string
    {
        return $this->getFactory()
            ->createPathResolver()
            ->resolvePath($path);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $filePath
     * @param string $content
     *
     * @return bool
     */
    public function writeFile(string $filePath, string $content): bool
    {
        return $this->getFactory()
            ->createFileWriter()
            ->writeFile($filePath, $content);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $path
     * @param string $extension
     * @param string $searchString
     *
     * @return array<string, mixed>
     */
    public function findFiles(string $path, string $extension, string $searchString = ''): array
    {
        return $this->getFactory()
            ->createFilesFinder()
            ->findFiles($path, $extension, $searchString);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $filePath
     * @param array<string> $headers
     * @param array<array<string, mixed>> $rows
     * @param string $mode
     *
     * @return \Generated\Shared\Transfer\CsvOperationResultTransfer
     */
    public function writeCsvFile(
        string $filePath,
        array $headers,
        array $rows,
        string $mode
    ): CsvOperationResultTransfer {
        return $this->getFactory()
            ->createCsvWriter()
            ->writeCsvFile($filePath, $headers, $rows, $mode);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param string $odsFilePath
     * @param string $outputDirectory
     *
     * @return array<int, string>
     */
    public function convertOdsToCsvFiles(string $odsFilePath, string $outputDirectory): array
    {
        return $this->getFactory()
            ->createOdsToCsvConverter()
            ->convertOdsToCsvFiles($odsFilePath, $outputDirectory);
    }
}
