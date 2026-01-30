<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

interface CsvWriterInterface
{
    /**
     * @param string $filePath
     * @param array<string> $headers
     * @param array<int, array<string, mixed>> $rows
     * @param bool $createBackup
     *
     * @return string|null
     */
    public function write(string $filePath, array $headers, array $rows, bool $createBackup = true): ?string;

    /**
     * @param string $filePath
     * @param array<int, array<string, mixed>> $rows
     * @param bool $createBackup
     *
     * @return string|null
     */
    public function append(string $filePath, array $rows, bool $createBackup = true): ?string;
}
