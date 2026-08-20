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
    public function extractContent(string $filePath): SimpleXMLElement;

    /**
     * @return array<array<string>>
     */
    public function extractRows(SimpleXMLElement $sheet): array;

    public function getCellValue(SimpleXMLElement $cell): string;
}
