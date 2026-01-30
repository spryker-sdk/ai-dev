<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class AnalyzeCsvFileAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'analyzeCsvFile';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Analyze CSV file metadata without loading full content. Returns headers, row count, sample rows, and optional column analysis (unique values, null counts). Use this before transforming or deleting data to understand file structure. Parameters: filePath (required), sampleRows (default 5), analyzeColumns (optional array of column names to inspect).';
    }

    /**
     * @param string $filePath
     * @param int $sampleRows
     * @param array<string> $analyzeColumns
     *
     * @return string
     */
    public function analyzeCsvFile(string $filePath, int $sampleRows = 5, array $analyzeColumns = []): string
    {
        return $this->getBusinessFactory()
            ->createCsvAnalyzer()
            ->analyze($filePath, $sampleRows, $analyzeColumns);
    }
}
