<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\GoogleSpreadsheet\Downloader;

use SprykerSdk\Service\AiDev\AiDevServiceInterface;

class GoogleSpreadsheetDownloader implements GoogleSpreadsheetDownloaderInterface
{
    protected const string GOOGLE_SHEETS_EXPORT_URL_PATTERN = 'https://docs.google.com/spreadsheets/d/%s/export?format=ods';

    protected const string SPREADSHEET_ID_PATTERN = '/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/';

    public function __construct(
        protected AiDevServiceInterface $aiDevService
    ) {
    }

    public function downloadSpreadsheet(string $spreadsheetUrl, string $outputPath): bool
    {
        $spreadsheetId = $this->extractSpreadsheetId($spreadsheetUrl);
        if ($spreadsheetId === null) {
            return false;
        }

        $exportUrl = sprintf(static::GOOGLE_SHEETS_EXPORT_URL_PATTERN, $spreadsheetId);
        $content = @file_get_contents($exportUrl);

        if ($content === false) {
            return false;
        }

        return $this->aiDevService->writeFile($outputPath, $content);
    }

    protected function extractSpreadsheetId(string $url): ?string
    {
        if (preg_match(static::SPREADSHEET_ID_PATTERN, $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
