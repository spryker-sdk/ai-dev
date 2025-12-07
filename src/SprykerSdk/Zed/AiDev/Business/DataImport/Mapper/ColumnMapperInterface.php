<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Mapper;

interface ColumnMapperInterface
{
    /**
     * @param array<array<string, mixed>> $data
     * @param array<string> $headers
     * @param array<string, string> $columnMapping
     *
     * @return array<array<string, mixed>>
     */
    public function mapDataToHeaders(array $data, array $headers, array $columnMapping): array;

    /**
     * @param array<array<string, mixed>> $sourceRows
     * @param array<string, mixed> $columnMapping
     * @param array<string> $targetHeaders
     * @param array<string, array<string, string>> $valueReplacements
     *
     * @return array<array<string, mixed>>
     */
    public function transformRows(
        array $sourceRows,
        array $columnMapping,
        array $targetHeaders,
        array $valueReplacements = []
    ): array;
}
