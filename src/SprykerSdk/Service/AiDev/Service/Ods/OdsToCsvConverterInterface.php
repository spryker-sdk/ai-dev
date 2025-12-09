<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\Ods;

interface OdsToCsvConverterInterface
{
    /**
     * @return array<int, string>
     */
    public function convertOdsToCsvFiles(string $odsFilePath, string $outputDirectory): array;
}
