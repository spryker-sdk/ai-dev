<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Service\AiDev\Service\Ods;

interface OdsParserInterface
{
    /**
     * @return array<int, array<string, mixed>> Array of sheets with metadata: [['name' => string, 'rows' => array, 'rowCount' => int], ...]
     */
    public function parseOdsFile(string $odsFilePath): array;
}
