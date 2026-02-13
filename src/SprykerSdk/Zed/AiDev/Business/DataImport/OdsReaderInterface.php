<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

use SimpleXMLElement;

interface OdsReaderInterface
{
    /**
     * @param string $filePath
     *
     * @return \SimpleXMLElement
     */
    public function extractContent(string $filePath): SimpleXMLElement;

    /**
     * @param \SimpleXMLElement $sheet
     *
     * @return array<array<string>>
     */
    public function extractRows(SimpleXMLElement $sheet): array;

    /**
     * @param \SimpleXMLElement $cell
     *
     * @return string
     */
    public function getCellValue(SimpleXMLElement $cell): string;
}
