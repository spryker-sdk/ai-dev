<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
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
