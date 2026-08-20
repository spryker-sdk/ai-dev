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
     * @param array<string> $headers
     * @param array<int, array<string, mixed>> $rows
     */
    public function write(string $filePath, array $headers, array $rows): void;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function append(string $filePath, array $rows): void;
}
