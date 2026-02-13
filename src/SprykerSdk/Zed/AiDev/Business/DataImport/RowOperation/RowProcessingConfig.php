<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation;

class RowProcessingConfig
{
    /**
     * @param array<string, string>|null $columnMappings
     * @param array<string> $finalHeaders
     * @param array<string> $columnsToRemove
     * @param array<string, mixed> $defaultValues
     * @param array<int, array<string, mixed>> $valueTransformations
     * @param array<int, array<string, mixed>> $rowFilters
     */
    public function __construct(
        public ?array $columnMappings = null,
        public array $finalHeaders = [],
        public array $columnsToRemove = [],
        public array $defaultValues = [],
        public array $valueTransformations = [],
        public array $rowFilters = [],
    ) {
    }
}
