<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 */
class SearchAlgoliaDocumentationAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    protected const string ALGOLIA_APP_ID = 'SF7W0R2XNG';

    protected const string ALGOLIA_API_KEY = '8f2c94685deea955c12e82e73f333f4a';

    protected const string ALGOLIA_INDEX_NAME = 'spryker_full_content';

    protected const string ALGOLIA_API_URL_PATTERN = 'https://%s-dsn.algolia.net/1/indexes/%s/query';

    protected const string HEADER_APP_ID = 'X-Algolia-Application-Id';

    protected const string HEADER_API_KEY = 'X-Algolia-API-Key';

    protected const string HEADER_CONTENT_TYPE = 'Content-Type';

    protected const string CONTENT_TYPE_JSON = 'application/json';

    protected const int DEFAULT_HITS_PER_PAGE = 15;

    protected const string ERROR_EMPTY_QUERY = 'Query parameter cannot be empty';

    protected const string DOCS_SPRYKER_BASE_URL = 'https://docs.spryker.com/';

    protected const string GITHUB_SPRYKER_DOCS_BASE_URL = 'https://github.com/spryker/spryker-docs/tree/master/';

    protected const string GITHUB_API_URL = 'https://api.github.com';

    protected const string GITHUB_OWNER = 'spryker';

    protected const string GITHUB_REPO = 'spryker-docs';

    protected const string LATEST_VERSION_SEGMENT = '/latest/';

    protected const string MARKDOWN_EXTENSION = '.md';

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'searchAlgoliaDocumentation';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Searches and find Spryker documentation by query and returns array of matching document paths to fetch. ' .
            'Use this to find relevant Spryker documentation pages for AI context. ' .
            'Use 3-5 keywords for query, separated by spaces.' .
            'Choose only relevant pages from the list. ' .
            'Download content from `github_api_url`';
    }

    /**
     * @param string $query
     *
     * @return string
     */
    public function searchAlgoliaDocumentation(string $query): string
    {
        if (trim($query) === '') {
            return $this->formatErrorResponse(static::ERROR_EMPTY_QUERY);
        }

        $paths = $this->executeAlgoliaSearch($query);

        return json_encode([
            'query' => $query,
            'count' => count($paths),
            'paths' => $paths,
        ], JSON_PRETTY_PRINT);
    }

    /**
     * @param string $query
     *
     * @return array<array<string, string>>
     */
    protected function executeAlgoliaSearch(string $query): array
    {
        $client = new Client();
        $url = $this->buildAlgoliaUrl();

        try {
            $response = $client->post($url, [
                'headers' => $this->buildHeaders(),
                'json' => $this->buildRequestBody($query),
                'timeout' => 10,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->extractPathsFromResponse($data);
        } catch (GuzzleException $exception) {
            return [];
        }
    }

    /**
     * @return string
     */
    protected function buildAlgoliaUrl(): string
    {
        return sprintf(
            static::ALGOLIA_API_URL_PATTERN,
            static::ALGOLIA_APP_ID,
            static::ALGOLIA_INDEX_NAME,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function buildHeaders(): array
    {
        return [
            static::HEADER_APP_ID => static::ALGOLIA_APP_ID,
            static::HEADER_API_KEY => static::ALGOLIA_API_KEY,
            static::HEADER_CONTENT_TYPE => static::CONTENT_TYPE_JSON,
        ];
    }

    /**
     * @param string $query
     *
     * @return array<string, mixed>
     */
    protected function buildRequestBody(string $query): array
    {
        return [
            'query' => $query,
            'hitsPerPage' => static::DEFAULT_HITS_PER_PAGE,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<array<string, string>>
     */
    protected function extractPathsFromResponse(array $data): array
    {
        if (!isset($data['hits']) || !is_array($data['hits'])) {
            return [];
        }

        $uniquePaths = [];
        foreach ($data['hits'] as $hit) {
            if (isset($hit['url'])) {
                $docsUrl = (string)$hit['url'];
                $githubUrl = $this->convertDocsUrlToGithubUrl($docsUrl);
                $githubApiUrl = $this->convertDocsUrlToGithubApiUrl($docsUrl);

                $uniquePaths[$docsUrl] = [
                    'github_api_url' => $githubApiUrl,
                    'url' => $docsUrl,
                    'github_url' => $githubUrl,
                    'description' => $hit['description'] ?? '',
                ];
            }
        }

        return array_values($uniquePaths);
    }

    /**
     * @param string $docsUrl
     *
     * @return string
     */
    protected function convertDocsUrlToGithubUrl(string $docsUrl): string
    {
        $githubUrl = str_replace(
            static::DOCS_SPRYKER_BASE_URL,
            static::GITHUB_SPRYKER_DOCS_BASE_URL,
            $docsUrl,
        );

        $githubUrl = str_replace(
            static::LATEST_VERSION_SEGMENT,
            '/',
            $githubUrl,
        );

        return $githubUrl . static::MARKDOWN_EXTENSION;
    }

    /**
     * @param string $docsUrl
     *
     * @return string
     */
    protected function convertDocsUrlToGithubApiUrl(string $docsUrl): string
    {
        $path = str_replace(static::DOCS_SPRYKER_BASE_URL, '', $docsUrl);

        $path = str_replace(static::LATEST_VERSION_SEGMENT, '/', $path);

        $path .= static::MARKDOWN_EXTENSION;

        return sprintf(
            '%s/repos/%s/%s/contents/%s',
            static::GITHUB_API_URL,
            static::GITHUB_OWNER,
            static::GITHUB_REPO,
            $path,
        );
    }

    /**
     * @param string $errorMessage
     *
     * @return string
     */
    protected function formatErrorResponse(string $errorMessage): string
    {
        return json_encode([
            'error' => $errorMessage,
            'paths' => [],
        ], JSON_PRETTY_PRINT);
    }
}
