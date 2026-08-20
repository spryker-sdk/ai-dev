<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Strategy;

use SprykerSdk\Zed\AiDev\Business\DataImport\RowOperation\RowProcessingConfig;

class TransformContext
{
    /**
     * @param array<int, array<string, mixed>>|null $sourceRows
     * @param array<string>|null $sourceHeaders
     * @param array<string>|null $targetHeaders
     * @param array<int, array<string, mixed>>|null $targetRows
     */
    public function __construct(
        public string $targetPath,
        public RowProcessingConfig $config,
        public ?array $sourceRows = null,
        public ?array $sourceHeaders = null,
        public ?array $targetHeaders = null,
        public ?array $targetRows = null,
    ) {
    }
}
