<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

use Generated\Shared\Transfer\AiDevGitHubPromptTransfer;
use RuntimeException;

class GitHubPromptsFetcher implements GitHubPromptsFetcherInterface
{
    protected const string GITHUB_OWNER = 'spryker-dev';

    protected const string GITHUB_REPO = 'prompt-library';

    protected const string GITHUB_BASE_PATH = 'prompts';

    protected const string GITHUB_API_URL = 'https://api.github.com';

    /**
     * @return list<\Generated\Shared\Transfer\AiDevGitHubPromptTransfer>
     */
    public function getAllPrompts(): array
    {
        $files = $this->getGitHubFilesRecursive(static::GITHUB_OWNER, static::GITHUB_REPO, static::GITHUB_BASE_PATH);
        $prompts = [];

        foreach ($files as $file) {
            if ($file['type'] === 'file' && str_ends_with($file['name'], '.md')) {
                $content = $this->fetchFileContent($file['download_url']);
                $prompt = $this->parsePromptFile($content, $file['name']);
                $prompts[] = $prompt;
            }
        }

        return $prompts;
    }

    /**
     * @param string $owner
     * @param string $repo
     * @param string $path
     *
     * @throws \RuntimeException
     *
     * @return array
     */
    protected function getGitHubFilesRecursive(string $owner, string $repo, string $path = ''): array
    {
        $url = sprintf('%s/repos/%s/%s/contents/%s', static::GITHUB_API_URL, $owner, $repo, $path);
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Spryker-MCP-Server',
                    'Accept: application/vnd.github.v3+json',
                ],
            ],
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException(sprintf('Failed to fetch files from GitHub: %s', $url));
        }

        $items = json_decode($response, true);

        if (!is_array($items)) {
            throw new RuntimeException('Invalid response from GitHub API');
        }

        $files = [];
        foreach ($items as $item) {
            if ($item['type'] === 'file') {
                $files[] = $item;
            } elseif ($item['type'] === 'dir') {
                $files = array_merge($files, $this->getGitHubFilesRecursive($owner, $repo, $item['path']));
            }
        }

        return $files;
    }

    protected function fetchFileContent(string $downloadUrl): string
    {
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: Spryker-MCP-Server',
            ],
        ];

        $context = stream_context_create($options);
        $content = file_get_contents($downloadUrl, false, $context);

        if ($content === false) {
            throw new RuntimeException(sprintf('Failed to fetch file content from: %s', $downloadUrl));
        }

        return $content;
    }

    protected function parsePromptFile(string $content, string $filename): AiDevGitHubPromptTransfer
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
     * @return array
     */
    protected function parseFrontmatter(string $content): array
    {
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            $yamlContent = $matches[1];
            $frontmatter = [];

            $lines = explode("\n", $yamlContent);
            $currentKey = null;

            foreach ($lines as $line) {
                if (preg_match('/^(\w+):\s*(.*)$/', trim($line), $lineMatches)) {
                    $currentKey = $lineMatches[1];
                    $value = trim($lineMatches[2]);
                    $frontmatter[$currentKey] = $value === '' || $value === '[]' ? [] : $value;

                } elseif ($currentKey && preg_match('/^\s*-\s*(.+)$/', $line, $arrayMatches)) {
                    if (!is_array($frontmatter[$currentKey])) {
                        $frontmatter[$currentKey] = [];
                    }

                    $frontmatter[$currentKey][] = trim($arrayMatches[1]);
                }
            }

            return $frontmatter;
        }

        return [];
    }

    protected function extractBody(string $content): string
    {
        if (preg_match('/^---\s*\n.*?\n---\s*\n(.*)$/s', $content, $matches)) {
            return trim($matches[1]);
        }

        return trim($content);
    }
}
