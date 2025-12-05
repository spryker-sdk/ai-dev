<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Finder;

interface DataImportCsvFilesFinderInterface
{
    public function findDataImportCsvFiles(string $path, string $searchString = ''): array;
}
