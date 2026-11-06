<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

interface CapabilitySearcherInterface
{
    /**
     * Specification:
     * - Splits the query into lowercase terms of 3 or more characters.
     *
     * @return array<string>
     */
    public function extractTerms(string $query): array;

    /**
     * Specification:
     * - Ranks cards by the number of distinct terms found in the capability title and synonyms, then by how often they occur there, then by module path hits, then by body-only hits, then shipped before partial before planned.
     * - Terms of 4 characters or fewer match whole words only, so `sso` does not hit `processor`.
     * - Returns at most `$limit` hits with the card and the terms matched per field.
     *
     * @param array<array<string, mixed>> $cards
     * @param array<string> $terms
     *
     * @return array<array{card: array<string, mixed>, matched: array{capability: array<string>, modules: array<string>, body: array<string>}}>
     */
    public function search(array $cards, array $terms, int $limit): array;
}
