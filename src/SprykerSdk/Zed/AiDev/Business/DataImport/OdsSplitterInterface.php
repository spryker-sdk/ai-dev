<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\DataImport;

interface OdsSplitterInterface
{
    /**
     * @param string $odsFilePath
     * @param string $outputDirectory
     *
     * @return string
     */
    public function split(string $odsFilePath, string $outputDirectory): string;
}
