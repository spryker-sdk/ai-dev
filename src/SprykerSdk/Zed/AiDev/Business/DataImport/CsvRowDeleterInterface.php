<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

interface CsvRowDeleterInterface
{
    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param string $filePath
     * @param array<int, array<string, mixed>> $criteria
     * @param bool $createBackup
     *
     * @return string
     */
    public function deleteRows(string $filePath, array $criteria, bool $createBackup = true): string;
}
