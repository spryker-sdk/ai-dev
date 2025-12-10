<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

use RuntimeException;

class GitHubPromptsFetcher implements PromptsFetcherInterface
{
    protected const string GITHUB_RAW_BASE_URL = 'https://raw.githubusercontent.com/spryker-dev/prompt-library/refs/heads/main/prompts';

    protected const string GITHUB_SITEMAP_URL = 'https://raw.githubusercontent.com/spryker-dev/prompt-library/refs/heads/main/prompts/sitemap.txt';

    public function __construct(protected MarkdownPromptParserInterface $markdownPromptParser)
    {
    }

    /**
     * @return array<\Generated\Shared\Transfer\AiDevGitHubPromptTransfer>
     */
    public function getAllPrompts(): array
    {
        $paths = $this->fetchSitemapPaths();
        $prompts = [];

        foreach ($paths as $path) {
            try {
                $rawUrl = sprintf('%s/%s', static::GITHUB_RAW_BASE_URL, ltrim($path, '/'));
                $content = $this->fetchFileContent($rawUrl);

                $filename = basename($path);
                $prompt = $this->markdownPromptParser->parsePromptFile($content, $filename);

                $prompts[] = $prompt;
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return $prompts;
    }

    /**
     * @return array<string>
     */
    protected function fetchSitemapPaths(): array
    {
        try {
            $options = [
                'http' => [
                    'method' => 'GET',
                    'header' => 'User-Agent: Spryker-MCP-Server',
                ],
            ];

            $context = stream_context_create($options);
            $content = file_get_contents(static::GITHUB_SITEMAP_URL, false, $context);

            if ($content === false) {
                return [];
            }

            $paths = array_filter(array_map('trim', explode("\n", $content)));

            return array_values($paths);
        } catch (\Throwable $exception) {
            return [];
        }
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

                continue;
            }

            if ($item['type'] !== 'dir') {
                continue;
            }

            $files = array_merge($files, $this->getGitHubFilesRecursive($owner, $repo, $item['path']));
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
}
