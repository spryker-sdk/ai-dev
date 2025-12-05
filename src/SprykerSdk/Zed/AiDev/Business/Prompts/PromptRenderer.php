<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
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
