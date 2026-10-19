<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup;

class AiToolDetector implements AiToolDetectorInterface
{
    public function __construct(protected string $projectRoot)
    {
    }

    /**
     * @param array<string, string> $detectionMap
     */
    public function detect(array $detectionMap): ?string
    {
        foreach ($detectionMap as $indicator => $tool) {
            if (file_exists($this->projectRoot . DIRECTORY_SEPARATOR . $indicator)) {
                return $tool;
            }
        }

        return null;
    }
}
