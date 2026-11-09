<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

use SprykerSdk\Zed\AiDev\Business\CapabilityMap\Exception\CapabilityMapUnavailableException;

class CapabilityMapFinder implements CapabilityMapFinderInterface
{
    protected const int MAX_LIMIT = 50;

    protected const string CARD_FILE_EXTENSION = '.md';

    protected const string SECTION_EXTEND = "Extend, don't duplicate";

    protected const string NOTE_NO_PROOF_OF_ABSENCE = 'The map describes the latest Spryker release line; an installed package may be older than its card. '
        . 'No hit is not proof that Spryker lacks the capability: also search the documentation and the installed modules before building new.';

    protected const string ERROR_EMPTY_QUERY = 'The query needs at least one term of 3 or more characters.';

    protected const string ERROR_UNKNOWN_SLUG = 'Unknown capability "%s". Use searchSprykerCapabilities to find the slug.';

    public function __construct(
        protected CapabilityMapReaderInterface $capabilityMapReader,
        protected CapabilitySearcherInterface $capabilitySearcher,
        protected CapabilityInstallStatusResolverInterface $capabilityInstallStatusResolver,
        protected ComposerLockReaderInterface $composerLockReader,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function searchCapabilities(string $query, int $limit): array
    {
        $terms = $this->capabilitySearcher->extractTerms($query);
        if ($terms === []) {
            return ['error' => static::ERROR_EMPTY_QUERY];
        }

        try {
            $cards = $this->capabilityMapReader->getCards();
        } catch (CapabilityMapUnavailableException $exception) {
            return ['error' => $exception->getMessage()];
        }

        $installedPackages = $this->composerLockReader->getInstalledPackages();
        $searchHits = $this->capabilitySearcher->search($cards, $terms, min(max(1, $limit), static::MAX_LIMIT));

        $hits = [];
        foreach ($searchHits as $searchHit) {
            $card = $searchHit['card'];
            $installStatus = $this->capabilityInstallStatusResolver->resolve($card, $installedPackages);

            $hits[] = [
                'slug' => $this->getSlug($card),
                'capability' => $card['capability'] ?? '',
                'status' => $card['status'] ?? '',
                'matched' => $searchHit['matched'],
                'installStatus' => $installStatus['installStatus'],
                'features' => $installStatus['features'],
                'missingPackages' => $installStatus['missingPackages'],
                'missingOptionalPackages' => $installStatus['missingOptionalPackages'],
                'composerRequire' => $installStatus['composerRequire'],
                'extend' => $card['sections'][static::SECTION_EXTEND] ?? '',
                'url' => $this->getCardUrl($card),
            ];
        }

        return [
            'query' => $query,
            'terms' => $terms,
            'count' => count($hits),
            'hits' => $hits,
            'source' => $this->getSource(),
            'note' => static::NOTE_NO_PROOF_OF_ABSENCE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapability(string $slug): array
    {
        try {
            $cards = $this->capabilityMapReader->getCards();
        } catch (CapabilityMapUnavailableException $exception) {
            return ['error' => $exception->getMessage()];
        }

        $card = $this->findCardBySlug($cards, $slug);
        if ($card === null) {
            return ['error' => sprintf(static::ERROR_UNKNOWN_SLUG, $slug)];
        }

        $installStatus = $this->capabilityInstallStatusResolver->resolve($card, $this->composerLockReader->getInstalledPackages());

        return [
            'slug' => $this->getSlug($card),
            'capability' => $card['capability'] ?? '',
            'status' => $card['status'] ?? '',
            'synonyms' => $card['synonyms'] ?? [],
            'url' => $this->getCardUrl($card),
        ] + $installStatus + [
            'sections' => $card['sections'] ?? [],
            'source' => $this->getSource(),
        ];
    }

    /**
     * @param array<array<string, mixed>> $cards
     *
     * @return array<string, mixed>|null
     */
    protected function findCardBySlug(array $cards, string $slug): ?array
    {
        $normalizedSlug = strtolower(trim($slug));
        if (str_ends_with($normalizedSlug, static::CARD_FILE_EXTENSION)) {
            $normalizedSlug = substr($normalizedSlug, 0, -strlen(static::CARD_FILE_EXTENSION));
        }

        foreach ($cards as $card) {
            if ($this->getSlug($card) === $normalizedSlug) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $card
     */
    protected function getSlug(array $card): string
    {
        return basename((string)($card['file'] ?? ''), static::CARD_FILE_EXTENSION);
    }

    /**
     * @param array<string, mixed> $card
     */
    protected function getCardUrl(array $card): string
    {
        $sourceUrl = $this->capabilityMapReader->getSourceUrl();

        return substr($sourceUrl, 0, (int)strrpos($sourceUrl, '/') + 1) . ($card['file'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSource(): array
    {
        return [
            'url' => $this->capabilityMapReader->getSourceUrl(),
            'stale' => $this->capabilityMapReader->isStale(),
        ];
    }
}
