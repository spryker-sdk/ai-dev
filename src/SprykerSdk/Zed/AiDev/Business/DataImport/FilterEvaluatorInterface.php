<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

interface FilterEvaluatorInterface
{
    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $criteria
     */
    public function evaluate(array $row, array $criteria): bool;

    public function isValidOperator(string $operator): bool;

    /**
     * @param array<int, array<string, mixed>> $criteria
     * @param array<string> $availableColumns
     *
     * @return array<string>
     */
    public function validateCriteria(array $criteria, array $availableColumns): array;
}
