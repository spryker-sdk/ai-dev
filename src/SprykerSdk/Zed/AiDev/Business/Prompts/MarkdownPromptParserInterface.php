<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

use Generated\Shared\Transfer\AiDevGitHubPromptTransfer;

interface MarkdownPromptParserInterface
{
    /**
     * Specification:
     * - Parses markdown file content.
     * - Extracts frontmatter metadata (title, description, when_to_use).
     * - Extracts body content.
     * - Returns prompt transfer object.
     *
     * @param string $content
     * @param string $filename
     *
     * @return \Generated\Shared\Transfer\AiDevGitHubPromptTransfer
     */
    public function parsePromptFile(string $content, string $filename): AiDevGitHubPromptTransfer;
}
