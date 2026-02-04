<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\Trait\FileValidationTrait;

class SourceFileValidator implements ValidatorInterface
{
    use FileValidationTrait;

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidationContext $context
     *
     * @return bool
     */
    public function isApplicable(ValidationContext $context): bool
    {
        return $context->hasSourceFile();
    }

    /**
     * @param \SprykerSdk\Zed\AiDev\Business\DataImport\Validator\ValidationContext $context
     *
     * @return array<string, mixed>|null
     */
    public function validate(ValidationContext $context): ?array
    {
        return $this->validateFileExists(
            $context->sourcePath,
            CsvConstants::FILE_NOT_FOUND,
            'Source file does not exist',
            ['file_path' => $context->sourcePath],
        );
    }
}
