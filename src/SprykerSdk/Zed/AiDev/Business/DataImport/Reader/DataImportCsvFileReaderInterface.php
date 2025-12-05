<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Reader;

use SprykerSdk\Zed\AiDev\AiDevConfig;

interface DataImportCsvFileReaderInterface
{
    /**
     * @param string $filePath
     * @param int $offset
     * @param int $limit
     * @param array<array<string, mixed>> $filters
     * @param string $filterLogic
     *
     * @return array<string, mixed>
     */
    public function readDataImportCsvFile(
        string $filePath,
        int $offset = 0,
        int $limit = 100,
        array $filters = [],
        string $filterLogic = AiDevConfig::FILTER_LOGIC_AND
    ): array;
}
