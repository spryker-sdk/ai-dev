<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

/**
 * Port of the ranking in `capability_lookup.py` of the Spryker suite, so that a query ranks the same cards in both.
 */
class CapabilitySearcher implements CapabilitySearcherInterface
{
    protected const int MIN_TERM_LENGTH = 3;

    protected const int SHORT_TERM_LENGTH = 4;

    protected const int MIN_STEM_LENGTH = 3;

    protected const array STEM_SUFFIXES = ['ies', 'es', 's'];

    protected const array STATUS_RANK = ['shipped' => 0, 'partial' => 1, 'planned' => 2];

    protected const int UNKNOWN_STATUS_RANK = 3;

    protected const string KEY_CAPABILITY = 'capability';

    protected const string KEY_SYNONYMS = 'synonyms';

    protected const string KEY_MODULES = 'modules';

    protected const string KEY_SECTIONS = 'sections';

    protected const string KEY_STATUS = 'status';

    /**
     * @return array<string>
     */
    public function extractTerms(string $query): array
    {
        $terms = preg_split('/[\s,;]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $terms,
            fn (string $term): bool => mb_strlen($term) >= static::MIN_TERM_LENGTH,
        )));
    }

    /**
     * @param array<array<string, mixed>> $cards
     * @param array<string> $terms
     *
     * @return array<array{card: array<string, mixed>, matched: array{capability: array<string>, modules: array<string>, body: array<string>}}>
     */
    public function search(array $cards, array $terms, int $limit): array
    {
        if ($terms === []) {
            return [];
        }

        $scoredHits = [];
        foreach ($cards as $card) {
            $scoredHit = $this->scoreCard($card, $terms);
            if ($scoredHit !== null) {
                $scoredHits[] = $scoredHit;
            }
        }

        usort($scoredHits, fn (array $left, array $right): int => $left['sortKey'] <=> $right['sortKey']);

        return array_map(
            fn (array $scoredHit): array => ['card' => $scoredHit['card'], 'matched' => $scoredHit['matched']],
            array_slice($scoredHits, 0, max(1, $limit)),
        );
    }

    /**
     * @param array<string, mixed> $card
     * @param array<string> $terms
     *
     * @return array<string, mixed>|null
     */
    protected function scoreCard(array $card, array $terms): ?array
    {
        $strongText = ($card[static::KEY_CAPABILITY] ?? '') . ' ' . implode(' ', $card[static::KEY_SYNONYMS] ?? []);
        $moduleText = implode(' ', $card[static::KEY_MODULES] ?? []);
        $bodyText = implode("\n", $card[static::KEY_SECTIONS] ?? []);

        $strongWeight = 0;
        $matchedStrong = [];
        $matchedModules = [];
        $matchedBody = [];

        foreach ($terms as $term) {
            $strongCount = $this->countOccurrences($term, $strongText);
            $strongWeight += $strongCount;

            if ($strongCount > 0) {
                $matchedStrong[] = $term;
            }

            if ($this->countOccurrences($term, $moduleText) > 0) {
                $matchedModules[] = $term;
            }

            if ($this->countOccurrences($term, $bodyText) > 0) {
                $matchedBody[] = $term;
            }
        }

        if ($matchedStrong === [] && $matchedModules === [] && $matchedBody === []) {
            return null;
        }

        $matchedBodyOnly = array_values(array_diff($matchedBody, $matchedStrong, $matchedModules));

        return [
            'card' => $card,
            'matched' => [
                'capability' => $matchedStrong,
                'modules' => $matchedModules,
                'body' => $matchedBodyOnly,
            ],
            'sortKey' => [
                -count($matchedStrong),
                -$strongWeight,
                -count($matchedModules),
                -count($matchedBodyOnly),
                static::STATUS_RANK[$card[static::KEY_STATUS] ?? ''] ?? static::UNKNOWN_STATUS_RANK,
            ],
        ];
    }

    protected function countOccurrences(string $term, string $text): int
    {
        $lowerText = mb_strtolower($text);
        $lowerTerm = mb_strtolower($term);
        $variants = array_unique([$this->stem($lowerTerm), $lowerTerm]);

        $total = 0;
        foreach ($variants as $variant) {
            if (mb_strlen($variant) <= static::SHORT_TERM_LENGTH) {
                $total += (int)preg_match_all('/\b' . preg_quote($variant, '/') . '\w{0,2}\b/u', $lowerText);

                continue;
            }

            $total += substr_count($lowerText, $variant);
        }

        return $total;
    }

    protected function stem(string $term): string
    {
        foreach (static::STEM_SUFFIXES as $suffix) {
            if (str_ends_with($term, $suffix) && mb_strlen($term) - mb_strlen($suffix) >= static::MIN_STEM_LENGTH) {
                return mb_substr($term, 0, -mb_strlen($suffix));
            }
        }

        return $term;
    }
}
