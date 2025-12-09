<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class WriteDataImportCsvContentAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getDescription(): string
    {
        return 'Tool to write data to existing CSV data import files. Supports only relative paths. Appends new rows to the end of the file while preserving existing data. Parameters: filePath (string), data (JSON string containing array of objects), columnMapping (JSON string containing object). Example data: "[{\"key\":\"value\"}]". Example columnMapping: "{\"dataKey\":\"csvHeader\"}". Returns success status, rows written count, and any validation errors.';
    }

    public function getName(): string
    {
        return 'writeDataImportCsvContent';
    }

    /**
     * @param string $filePath
     * @param string $data
     * @param string $columnMapping
     *
     * @return string
     */
    public function writeDataImportCsvContent(string $filePath, string $data, string $columnMapping = '{}'): string
    {
        $dataArray = json_decode($data, true);
        if (!is_array($dataArray)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid data parameter: must be a JSON string containing an array',
                'rowsWritten' => 0,
            ], JSON_PRETTY_PRINT);
        }

        $columnMappingArray = json_decode($columnMapping, true);
        if (!is_array($columnMappingArray)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid columnMapping parameter: must be a JSON string containing an object',
                'rowsWritten' => 0,
            ], JSON_PRETTY_PRINT);
        }

        $responseTransfer = $this->getBusinessFactory()
            ->createDataImportCsvFileWriter()
            ->writeDataImportCsvFile($filePath, $dataArray, $columnMappingArray);

        $result = [
            'success' => $responseTransfer->getIsSuccess(),
            'rowsWritten' => $responseTransfer->getRowsWritten(),
            'filePath' => $responseTransfer->getFilePath(),
            'totalRowsBeforeAppend' => $responseTransfer->getTotalRowsBeforeAppend(),
            'totalRowsAfterAppend' => $responseTransfer->getTotalRowsAfterAppend(),
        ];

        if (!$responseTransfer->getIsSuccess()) {
            $result['error'] = $responseTransfer->getError();
            if ($responseTransfer->getValidationErrors()) {
                $result['validationErrors'] = $responseTransfer->getValidationErrors();
            }
        }

        return json_encode($result, JSON_PRETTY_PRINT);
    }
}
