<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

interface CapabilityMapFinderInterface
{
    /**
     * Specification:
     * - Ranks the capability cards against the query terms.
     * - Adds the project install status of each hit, read from `composer.lock`.
     * - Returns an `error` key when the query has no usable term or the map cannot be loaded.
     *
     * @return array<string, mixed>
     */
    public function searchCapabilities(string $query, int $limit): array;

    /**
     * Specification:
     * - Returns the full card for the slug (`cart` or `cart.md`), with the install status of each feature and module.
     * - Returns an `error` key when the slug is unknown or the map cannot be loaded.
     *
     * @return array<string, mixed>
     */
    public function getCapability(string $slug): array;
}
