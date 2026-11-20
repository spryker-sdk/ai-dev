<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\CapabilityMap;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\CapabilityMap\CapabilitySearcher;

/**
 * @group AiDev
 * @group Business
 * @group CapabilityMap
 * @group CapabilitySearcherTest
 */
class CapabilitySearcherTest extends Unit
{
    use CapabilityMapTestDataTrait;

    protected CapabilitySearcher $capabilitySearcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capabilitySearcher = new CapabilitySearcher();
    }

    public function testExtractTermsLowercasesDeduplicatesAndDropsShortTerms(): void
    {
        // Act
        $terms = $this->capabilitySearcher->extractTerms('Budget, budget  of SSO;approval');

        // Assert
        $this->assertSame(['budget', 'sso', 'approval'], $terms);
    }

    public function testSearchRanksSynonymHitAboveBodyOnlyHit(): void
    {
        // Act
        $hits = $this->capabilitySearcher->search($this->getCards(), ['budget'], 10);

        // Assert
        $this->assertSame('purchasing-control.md', $hits[0]['card']['file']);
        $this->assertSame(['budget'], $hits[0]['matched']['capability']);
        $this->assertSame('cart.md', $hits[1]['card']['file']);
        $this->assertSame(['budget'], $hits[1]['matched']['body']);
    }

    public function testSearchRanksMoreDistinctStrongTermsFirst(): void
    {
        // Act
        $hits = $this->capabilitySearcher->search($this->getCards(), ['cart', 'basket'], 10);

        // Assert
        $this->assertSame('cart.md', $hits[0]['card']['file']);
        $this->assertSame(['cart', 'basket'], $hits[0]['matched']['capability']);
    }

    public function testSearchMatchesPluralTermsThroughStem(): void
    {
        // Act
        $hits = $this->capabilitySearcher->search($this->getCards(), ['budgets'], 10);

        // Assert
        $this->assertSame('purchasing-control.md', $hits[0]['card']['file']);
    }

    public function testSearchMatchesShortTermsOnWholeWordsOnly(): void
    {
        // Act
        $hits = $this->capabilitySearcher->search($this->getCards(), ['sso'], 10);

        // Assert
        $this->assertCount(1, $hits);
        $this->assertSame('sso-authentication.md', $hits[0]['card']['file']);
    }

    public function testSearchCountsModulePathHits(): void
    {
        // Act
        $hits = $this->capabilitySearcher->search($this->getCards(), ['purchasing'], 10);

        // Assert
        $this->assertSame('purchasing-control.md', $hits[0]['card']['file']);
        $this->assertContains('purchasing', $hits[0]['matched']['modules']);
    }

    public function testSearchRanksShippedBeforePartialOnTies(): void
    {
        // Arrange
        $cards = [
            $this->createCard('a-partial.md', 'Loyalty points', ['loyalty'], status: 'partial'),
            $this->createCard('b-shipped.md', 'Loyalty points', ['loyalty']),
        ];

        // Act
        $hits = $this->capabilitySearcher->search($cards, ['loyalty'], 10);

        // Assert
        $this->assertSame('b-shipped.md', $hits[0]['card']['file']);
    }

    public function testSearchReturnsNothingForUnknownTerms(): void
    {
        // Act
        $hits = $this->capabilitySearcher->search($this->getCards(), ['spaceship'], 10);

        // Assert
        $this->assertSame([], $hits);
    }

    public function testSearchRespectsLimit(): void
    {
        // Act
        $hits = $this->capabilitySearcher->search($this->getCards(), ['budget'], 1);

        // Assert
        $this->assertCount(1, $hits);
    }
}
