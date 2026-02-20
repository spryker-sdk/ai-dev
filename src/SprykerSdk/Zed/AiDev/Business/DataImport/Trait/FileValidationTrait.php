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

    /**
     * @param string $filePath
     * @param string $errorCode
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>|null
     */
    protected function validateFilePath(
        string $filePath,
        string $errorCode = CsvConstants::INVALID_PATH,
        array $details = []
    ): ?array {
        if ($filePath === '') {
            return [
                'code' => $errorCode,
                'message' => 'File path cannot be empty',
                'details' => array_merge(['path' => $filePath], $details),
            ];
        }

        if (strpos($filePath, '..') !== false) {
            return [
                'code' => CsvConstants::PATH_TRAVERSAL_DETECTED,
                'message' => 'Path contains directory traversal sequence (..), use relative paths within project directory',
                'details' => array_merge(['path' => $filePath], $details),
            ];
        }

        if ($this->isAbsolutePath($filePath)) {
            return [
                'code' => $errorCode,
                'message' => 'Absolute paths are not allowed, use relative paths from project root',
                'details' => array_merge(['path' => $filePath], $details),
            ];
        }

        return null;
    }

    /**
     * @param string $directoryPath
     * @param string $errorCode
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>|null
     */
    protected function validateDirectoryPath(
        string $directoryPath,
        string $errorCode = CsvConstants::INVALID_PATH,
        array $details = []
    ): ?array {
        $pathError = $this->validateFilePath($directoryPath, $errorCode, $details);
        if ($pathError !== null) {
            return $pathError;
        }

        if (is_dir($directoryPath) && !is_writable($directoryPath)) {
            return [
                'code' => CsvConstants::FILE_NOT_WRITABLE,
                'message' => sprintf('Directory is not writable: %s', $directoryPath),
                'details' => array_merge(['directory' => $directoryPath], $details),
            ];
        }

        return null;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    protected function isAbsolutePath(string $path): bool
    {
        if (isset($path[0]) && $path[0] === '/') {
            return true;
        }

        if (preg_match('/^[A-Z]:/i', $path)) {
            return true;
        }

        return false;
    }
}
