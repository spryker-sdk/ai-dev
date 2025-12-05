<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

class PromptRenderer implements PromptRendererInterface
{
    public function __construct(protected PromptReaderInterface $promptReader)
    {
    }

    public function render(string $promptPath, array $variables): string
    {
        $prompt = $this->promptReader->get($promptPath);

        if (!$prompt) {
            return 'Prompt not found;';
        }

        return str_replace(array_keys($variables), array_values($variables), $prompt);
    }
}
