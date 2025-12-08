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
    protected const string GITHUB_OWNER = 'spryker-dev';

    protected const string GITHUB_REPO = 'prompt-library';

    protected const string GITHUB_BASE_PATH = 'prompts';

    protected const string GITHUB_API_URL = 'https://api.github.com';

    public function __construct(protected MarkdownPromptParserInterface $markdownPromptParser)
    {
    }

    /**
     * @return array<\Generated\Shared\Transfer\AiDevGitHubPromptTransfer>
     */
    public function getAllPrompts(): array
    {
        $files = $this->getGitHubFilesRecursive(static::GITHUB_OWNER, static::GITHUB_REPO, static::GITHUB_BASE_PATH);
        $prompts = [];

        foreach ($files as $file) {
            if ($file['type'] !== 'file' || !str_ends_with($file['name'], '.md')) {
                continue;
            }

            $content = $this->fetchFileContent($file['download_url']);
            $prompt = $this->markdownPromptParser->parsePromptFile($content, $file['name']);
            $prompts[] = $prompt;
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
