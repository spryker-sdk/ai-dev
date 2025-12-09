<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

use Generated\Shared\Transfer\AiDevGitHubPromptTransfer;

class MarkdownPromptParser implements MarkdownPromptParserInterface
{
    public function parsePromptFile(string $content, string $filename): AiDevGitHubPromptTransfer
    {
        $frontmatter = $this->parseFrontmatter($content);
        $bodyContent = $this->extractBody($content);

        $description = $frontmatter['description'] ?? '';
        $whenToUse = $frontmatter['when_to_use'] ?? '';
        $description = implode(' ', array_filter([$description, $whenToUse]));

        return (new AiDevGitHubPromptTransfer())
            ->setFilename($filename)
            ->setTitle($frontmatter['title'] ?? '')
            ->setDescription($description)
            ->setContent($bodyContent);
    }

    /**
     * @param string $content
     *
     * @return array<string>
     */
    protected function parseFrontmatter(string $content): array
    {
        if (!preg_match('/^---\\s*\\n(.*?)\\n---\\s*\\n/s', $content, $matches)) {
            return [];
        }

        $yamlContent = $matches[1];
        $frontmatter = [];

        $lines = explode("\n", $yamlContent);
        $currentKey = null;

        foreach ($lines as $line) {
            if (preg_match('/^(\\w+):\\s*(.*)$/', trim($line), $lineMatches)) {
                $currentKey = $lineMatches[1];
                $value = trim($lineMatches[2]);
                $frontmatter[$currentKey] = $value === '' || $value === '[]' ? [] : $value;

                continue;
            }

            if (!$currentKey || !preg_match('/^\\s*-\\s*(.+)$/', $line, $arrayMatches)) {
                continue;
            }

            if (!is_array($frontmatter[$currentKey])) {
                $frontmatter[$currentKey] = [];
            }

            $frontmatter[$currentKey][] = trim($arrayMatches[1]);
        }

        return $frontmatter;
    }

    protected function extractBody(string $content): string
    {
        if (!preg_match('/^---\\s*\\n.*?\\n---\\s*\\n(.*)$/s', $content, $matches)) {
            return trim($content);
        }

        return trim($matches[1]);
    }
}
