<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

class PromptReader implements PromptReaderInterface
{
    protected const string PROMPTS_PATH = __DIR__ . '/../../../../../../data/prompts';

    public function get(string $promptPath): ?string
    {
        $path = static::PROMPTS_PATH . '/' . ltrim($promptPath, '/');

        if (!file_exists($path)) {
            return null;
        }

        return file_get_contents($path);
    }
}
