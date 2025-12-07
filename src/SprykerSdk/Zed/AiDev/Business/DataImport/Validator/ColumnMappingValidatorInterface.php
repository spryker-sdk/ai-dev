<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

interface ColumnMappingValidatorInterface
{
    /**
     * @param array<string> $sourceHeaders
     * @param array<string> $targetHeaders
     * @param array<string, mixed> $columnMapping
     *
     * @return array<string>
     */
    public function validate(array $sourceHeaders, array $targetHeaders, array $columnMapping): array;

    /**
     * @param array<string> $headers
     * @param array<array<string, mixed>> $data
     * @param array<string, string> $columnMapping
     *
     * @return array<string>
     */
    public function validateDataAgainstHeaders(array $headers, array $data, array $columnMapping): array;
}
