<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
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
