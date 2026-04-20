<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup;

interface AiToolDetectorInterface
{
    /**
     * @param array<string, string> $detectionMap
     *
     * @return string|null
     */
    public function detect(array $detectionMap): ?string;
}
