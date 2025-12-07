<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Finder;

interface DataImportCsvFilesFinderInterface
{
    /**
     * @param string $path
     * @param string $searchString
     *
     * @return array<string>
     */
    public function findDataImportCsvFiles(string $path, string $searchString = ''): array;
}
