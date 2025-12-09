<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SprykerSdk\Zed\AiDev\AiDevConfig;

class LocalPromptsFetcher implements PromptsFetcherInterface
{
    public function __construct(
        protected AiDevConfig $config,
        protected MarkdownPromptParserInterface $markdownPromptParser,
    ) {
    }

    /**
     * @return array<\Generated\Shared\Transfer\AiDevGitHubPromptTransfer>
     */
    public function getAllPrompts(): array
    {
        $promptsDirectory = $this->config->getPromptsDirectory();

        if (!is_dir($promptsDirectory)) {
            return [];
        }

        $files = $this->getMarkdownFilesRecursive($promptsDirectory);
        $prompts = [];

        foreach ($files as $file) {
            $content = $this->fetchFileContent($file);
            $filename = basename($file);
            $prompt = $this->markdownPromptParser->parsePromptFile($content, $filename);
            $prompts[] = $prompt;
        }

        return $prompts;
    }

    /**
     * @param string $directory
     *
     * @return list<string>
     */
    protected function getMarkdownFilesRecursive(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.md')) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        return $files;
    }

    protected function fetchFileContent(string $filePath): string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new RuntimeException(sprintf('Failed to read file: %s', $filePath));
        }

        return $content;
    }
}
