<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

interface ValidatorInterface
{
    public function isApplicable(ValidationContext $context): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function validate(ValidationContext $context): ?array;
}
