<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport\Trait;

use SprykerSdk\Zed\AiDev\Business\DataImport\CsvConstants;

trait FileValidationTrait
{
    /**
     * @param string $filePath
     * @param string $errorCode
     * @param string|null $errorMessage
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>|null Returns null if validation passes, error array if validation fails
     */
    protected function validateFileExists(
        string $filePath,
        string $errorCode = CsvConstants::FILE_NOT_FOUND,
        ?string $errorMessage = null,
        array $details = []
    ): ?array {
        if (!file_exists($filePath)) {
            $message = $errorMessage ?? sprintf('File not found: %s', $filePath);

            return ['code' => $errorCode, 'message' => $message, 'details' => $details];
        }

        return null;
    }

    /**
     * @param string $filePath
     * @param string $errorCode
     * @param string|null $errorMessage
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>|null Returns null if validation passes, error array if validation fails
     */
    protected function validateFileReadable(
        string $filePath,
        string $errorCode = CsvConstants::FILE_NOT_READABLE,
        ?string $errorMessage = null,
        array $details = []
    ): ?array {
        if (!is_readable($filePath)) {
            $message = $errorMessage ?? sprintf('File is not readable: %s', $filePath);

            return ['code' => $errorCode, 'message' => $message, 'details' => $details];
        }

        return null;
    }

    /**
     * @param string $filePath
     * @param string $errorCode
     * @param string|null $errorMessage
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>|null Returns null if validation passes, error array if validation fails
     */
    protected function validateFileWritable(
        string $filePath,
        string $errorCode = CsvConstants::FILE_NOT_WRITABLE,
        ?string $errorMessage = null,
        array $details = []
    ): ?array {
        if (!is_writable($filePath)) {
            $message = $errorMessage ?? sprintf('File is not writable: %s', $filePath);

            return ['code' => $errorCode, 'message' => $message, 'details' => $details];
        }

        return null;
    }
}
