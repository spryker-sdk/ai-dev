<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

interface CsvTransformerInterface
{
    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param string $sourcePath
     * @param string $targetPath
     * @param array<string, string> $columnMappings
     * @param array<int, array<string, mixed>> $rowFilters
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param bool $createBackup
     *
     * @return string
     */
    public function transformAndAppend(
        string $sourcePath,
        string $targetPath,
        array $columnMappings,
        array $rowFilters = [],
        array $valueTransformations = [],
        bool $createBackup = true
    ): string;
}
