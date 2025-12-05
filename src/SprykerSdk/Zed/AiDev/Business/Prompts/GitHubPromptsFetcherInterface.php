<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
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
