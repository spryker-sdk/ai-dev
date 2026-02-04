<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

interface CsvReaderInterface
{
    /**
     * @param string $filePath
     *
     * @return array<string>
     */
    public function getHeaders(string $filePath): array;

    /**
     * @param string $filePath
     *
     * @return int
     */
    public function getRowCount(string $filePath): int;

    /**
     * @param string $filePath
     * @param int $offset
     * @param int|null $limit
     *
     * @return array<array<string, string>>
     */
    public function getRows(string $filePath, int $offset = 0, ?int $limit = null): array;

    /**
     * @param string $filePath
     *
     * @return string
     */
    public function detectDelimiter(string $filePath): string;
}
