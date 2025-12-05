<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev;

use Generated\Shared\Transfer\CsvOperationResultTransfer;

interface AiDevServiceInterface
{
    /**
     * Specification:
     * - Resolves a relative or absolute path to an absolute path.
     * - If path starts with '/', returns as-is.
     * - Otherwise, resolves relative to APPLICATION_ROOT_DIR.
     *
     * @api
     */
    public function resolvePath(string $path): string;

    /**
     * Specification:
     * - Writes content to a file.
     * - Creates directory structure if it doesn't exist.
     * - Returns true on success, false on failure.
     *
     * @api
     */
    public function writeFile(string $filePath, string $content): bool;

    /**
     * Specification:
     * - Finds files in a directory recursively by extension.
     * - Filters files by search string if provided.
     * - Returns array with path, searchString, extension, totalFiles, and files list.
     *
     * @api
     */
    public function findFiles(string $path, string $extension, string $searchString = ''): array;

    /**
     * Specification:
     * - Writes rows to a CSV file.
     * - Supports both append and overwrite modes.
     * - Returns operation result with success status and rows affected.
     *
     * @api
     *
     * @param array<string>|string $headers
     * @param array<array<string, mixed>> $rows
     */
    public function writeCsvFile(
        string $filePath,
        array $headers,
        array $rows,
        string $mode
    ): CsvOperationResultTransfer;

    /**
     * Specification:
     * - Converts ODS file to separate CSV files.
     * - Parses ODS file and extracts sheets.
     * - Creates output directory if it doesn't exist.
     * - Returns array of created file paths.
     *
     * @api
     *
     * @return array<int, string>
     */
    public function convertOdsToCsvFiles(string $odsFilePath, string $outputDirectory): array;
}
