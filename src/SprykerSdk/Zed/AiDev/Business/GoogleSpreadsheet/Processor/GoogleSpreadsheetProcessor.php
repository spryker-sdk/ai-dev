<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\GoogleSpreadsheet\Processor;

use RuntimeException;
use SprykerSdk\Service\AiDev\AiDevServiceInterface;
use SprykerSdk\Zed\AiDev\Business\GoogleSpreadsheet\Downloader\GoogleSpreadsheetDownloaderInterface;

class GoogleSpreadsheetProcessor implements GoogleSpreadsheetProcessorInterface
{
    protected const string TEMP_ODS_FILENAME = 'temp_spreadsheet.ods';

    public function __construct(
        protected GoogleSpreadsheetDownloaderInterface $googleSpreadsheetDownloader,
        protected AiDevServiceInterface $aiDevService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function process(string $spreadsheetUrl, string $outputDirectory): array
    {
        $tempOdsFile = sys_get_temp_dir() . '/' . static::TEMP_ODS_FILENAME;

        $downloadSuccess = $this->googleSpreadsheetDownloader->downloadSpreadsheet($spreadsheetUrl, $tempOdsFile);
        if (!$downloadSuccess) {
            return [
                'success' => false,
                'error' => 'Failed to download spreadsheet. Please check the URL and ensure it is publicly accessible.',
            ];
        }

        try {
            $createdFiles = $this->aiDevService->convertOdsToCsvFiles($tempOdsFile, $outputDirectory);
        } catch (RuntimeException $exception) {
            unlink($tempOdsFile);

            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }

        unlink($tempOdsFile);

        return [
            'success' => true,
            'filesCreated' => count($createdFiles),
            'files' => $createdFiles,
        ];
    }
}
