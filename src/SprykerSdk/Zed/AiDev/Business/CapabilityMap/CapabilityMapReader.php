<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SprykerSdk\Zed\AiDev\Business\CapabilityMap\Exception\CapabilityMapUnavailableException;
use Throwable;

class CapabilityMapReader implements CapabilityMapReaderInterface
{
    protected const string KEY_CARDS = 'cards';

    protected const string KEY_STATUS = 'status';

    protected const string STATUS_PLANNED = 'planned';

    protected const int REQUEST_TIMEOUT = 15;

    protected bool $isStale = false;

    public function __construct(
        protected ClientInterface $httpClient,
        protected string $indexUrl,
        protected string $cacheFilePath,
        protected int $cacheTtl,
    ) {
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getCards(): array
    {
        $cards = $this->loadIndex()[static::KEY_CARDS];

        return array_values(array_filter(
            $cards,
            fn (array $card): bool => ($card[static::KEY_STATUS] ?? '') !== static::STATUS_PLANNED,
        ));
    }

    public function getSourceUrl(): string
    {
        return $this->indexUrl;
    }

    public function isStale(): bool
    {
        return $this->isStale;
    }

    /**
     * @throws \SprykerSdk\Zed\AiDev\Business\CapabilityMap\Exception\CapabilityMapUnavailableException
     *
     * @return array<string, mixed>
     */
    protected function loadIndex(): array
    {
        $this->isStale = false;

        if ($this->isCacheFresh()) {
            $index = $this->readCache();
            if ($index !== null) {
                return $index;
            }
        }

        $downloadError = 'the response is not a capability map index';

        try {
            $body = (string)$this->httpClient->request('GET', $this->indexUrl, ['timeout' => static::REQUEST_TIMEOUT])->getBody();
            $index = $this->decodeIndex($body);
            if ($index !== null) {
                $this->writeCache($body);

                return $index;
            }
        } catch (GuzzleException $exception) {
            $downloadError = $exception->getMessage();
        }

        $index = $this->readCache();
        if ($index !== null) {
            $this->isStale = true;

            return $index;
        }

        throw new CapabilityMapUnavailableException(sprintf(
            'The capability map could not be loaded from %s: %s',
            $this->indexUrl,
            $downloadError,
        ));
    }

    protected function isCacheFresh(): bool
    {
        if (!is_file($this->cacheFilePath)) {
            return false;
        }

        return time() - (int)filemtime($this->cacheFilePath) < $this->cacheTtl;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readCache(): ?array
    {
        if (!is_file($this->cacheFilePath)) {
            return null;
        }

        $content = file_get_contents($this->cacheFilePath);

        return $content === false ? null : $this->decodeIndex($content);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeIndex(string $content): ?array
    {
        $index = json_decode($content, true);

        if (!is_array($index) || !isset($index[static::KEY_CARDS]) || !is_array($index[static::KEY_CARDS])) {
            return null;
        }

        return $index;
    }

    /**
     * The cache is an optimisation: a project directory that cannot be written must not fail the lookup.
     */
    protected function writeCache(string $content): void
    {
        $directory = dirname($this->cacheFilePath);
        $temporaryFilePath = $this->cacheFilePath . '.' . getmypid() . '.tmp';

        try {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return;
            }

            if (!is_writable($directory) || file_put_contents($temporaryFilePath, $content) === false) {
                return;
            }

            rename($temporaryFilePath, $this->cacheFilePath);
        } catch (Throwable $exception) {
            return;
        }
    }
}
