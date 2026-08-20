<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Validator;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;
use SprykerSdk\Zed\AiDev\Business\DataImport\Trait\FileValidationTrait;

class TargetFileValidator implements ValidatorInterface
{
    use FileValidationTrait;

    public function isApplicable(ValidationContext $context): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validate(ValidationContext $context): ?array
    {
        $pathError = $this->validateFilePath(
            $context->targetPath,
            CsvConstants::INVALID_PATH,
            ['file_path' => $context->targetPath],
        );
        if ($pathError !== null) {
            return $pathError;
        }

        $error = $this->validateFileExists(
            $context->targetPath,
            CsvConstants::FILE_NOT_FOUND,
            'Target file does not exist',
            ['file_path' => $context->targetPath],
        );
        if ($error !== null) {
            return $error;
        }

        return $this->validateFileWritable(
            $context->targetPath,
            CsvConstants::FILE_NOT_WRITABLE,
            'Target file is not writable',
            ['file_path' => $context->targetPath],
        );
    }
}
