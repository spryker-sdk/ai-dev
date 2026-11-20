<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

interface CapabilityMapReaderInterface
{
    /**
     * Specification:
     * - Returns the published capability cards, without `planned` cards.
     * - Uses the local cache while it is younger than the TTL, otherwise downloads the index.
     * - Falls back to an expired cache when the download fails.
     *
     * @throws \SprykerSdk\Zed\AiDev\Business\CapabilityMap\Exception\CapabilityMapUnavailableException
     *
     * @return array<array<string, mixed>>
     */
    public function getCards(): array;

    public function getSourceUrl(): string;

    /**
     * Specification:
     * - Returns true when the last `getCards()` call had to use an expired cache.
     */
    public function isStale(): bool;
}
