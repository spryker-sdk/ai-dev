<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
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
