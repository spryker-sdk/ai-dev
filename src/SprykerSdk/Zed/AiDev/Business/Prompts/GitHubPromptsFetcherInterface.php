<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

interface GitHubPromptsFetcherInterface
{
    /**
     * Specification:
     * - Retrieves all prompts.
     * - Returns cached prompts if available.
     * - Loads all prompts from GitHub if cache is empty.
     *
     * @return list<\Generated\Shared\Transfer\AiDevGitHubPromptTransfer>
     */
    public function getAllPrompts(): array;
}
