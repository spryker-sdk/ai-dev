<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Finder;

use SprykerSdk\Service\AiDev\AiDevServiceInterface;

class DataImportCsvFilesFinder implements DataImportCsvFilesFinderInterface
{
    public function __construct(
        protected AiDevServiceInterface $aiDevService
    ) {
    }

    public function findDataImportCsvFiles(string $path, string $searchString = ''): array
    {
        return $this->aiDevService->findFiles($path, 'csv', $searchString);
    }
}
