<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\CapabilityMap;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\CapabilityMap\CapabilityInstallStatusResolver;

/**
 * @group AiDev
 * @group Business
 * @group CapabilityMap
 * @group CapabilityInstallStatusResolverTest
 */
class CapabilityInstallStatusResolverTest extends Unit
{
    use CapabilityMapTestDataTrait;

    protected CapabilityInstallStatusResolver $capabilityInstallStatusResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capabilityInstallStatusResolver = new CapabilityInstallStatusResolver();
    }

    public function testResolveReturnsInstalledWhenFeatureAndRequiredPackagesAreInstalled(): void
    {
        // Arrange
        $installedPackages = ['spryker-feature/cart' => '202512.0', 'spryker/cart' => '7.20.0', 'spryker/cart-extension' => '4.5.0'];

        // Act
        $result = $this->capabilityInstallStatusResolver->resolve($this->getCards()[0], $installedPackages);

        // Assert
        $this->assertSame(CapabilityInstallStatusResolver::STATUS_INSTALLED, $result['installStatus']);
        $this->assertSame([['name' => 'spryker-feature/cart', 'installed' => true, 'version' => '202512.0']], $result['features']);
        $this->assertNull($result['composerRequire']);
    }

    public function testResolveIgnoresMissingApiPackagesForInstalledStatus(): void
    {
        // Arrange
        $installedPackages = ['spryker/cart' => '7.20.0', 'spryker/cart-extension' => '4.5.0'];

        // Act
        $result = $this->capabilityInstallStatusResolver->resolve($this->getCards()[0], $installedPackages);

        // Assert
        $this->assertSame(CapabilityInstallStatusResolver::STATUS_INSTALLED, $result['installStatus']);
        $this->assertSame([], $result['missingPackages']);
        $this->assertSame(['spryker/carts-rest-api'], $result['missingOptionalPackages']);
    }

    public function testResolveReturnsPartiallyInstalledWithMissingFeatureInComposerRequire(): void
    {
        // Arrange
        $installedPackages = ['spryker/purchasing-control' => '1.2.0'];

        // Act
        $result = $this->capabilityInstallStatusResolver->resolve($this->getCards()[1], $installedPackages);

        // Assert
        $this->assertSame(CapabilityInstallStatusResolver::STATUS_PARTIALLY_INSTALLED, $result['installStatus']);
        $this->assertSame(['spryker/purchasing-control-extension'], $result['missingPackages']);
        $this->assertSame('composer require spryker-feature/purchasing-control', $result['composerRequire']);
    }

    public function testResolveReturnsNotInstalledWithModulePackagesWhenCardHasNoFeature(): void
    {
        // Act
        $result = $this->capabilityInstallStatusResolver->resolve($this->getCards()[2], ['spryker/cart' => '7.20.0']);

        // Assert
        $this->assertSame(CapabilityInstallStatusResolver::STATUS_NOT_INSTALLED, $result['installStatus']);
        $this->assertSame('composer require spryker/oauth-client', $result['composerRequire']);
    }

    public function testResolveReturnsUnknownWithoutComposerLock(): void
    {
        // Act
        $result = $this->capabilityInstallStatusResolver->resolve($this->getCards()[0], null);

        // Assert
        $this->assertSame(CapabilityInstallStatusResolver::STATUS_UNKNOWN, $result['installStatus']);
        $this->assertNull($result['modules'][0]['installed']);
        $this->assertNull($result['composerRequire']);
    }

    public function testResolveRequiresMissingPackagesInsteadOfAlternativeFeatures(): void
    {
        // Arrange
        $card = $this->createCard(
            'wishlist.md',
            'Wishlist',
            ['wishlist'],
            ['spryker-feature/marketplace-wishlist', 'spryker-feature/wishlist'],
            ['vendor/spryker/wishlist', 'vendor/spryker/merchant-product-wishlist'],
        );

        // Act
        $result = $this->capabilityInstallStatusResolver->resolve($card, ['spryker/wishlist' => '8.0.0']);

        // Assert
        $this->assertSame(CapabilityInstallStatusResolver::STATUS_PARTIALLY_INSTALLED, $result['installStatus']);
        $this->assertSame('composer require spryker/merchant-product-wishlist', $result['composerRequire']);
    }

    public function testResolveUsesFeaturesForCardWithoutVendorModules(): void
    {
        // Arrange
        $card = $this->createCard('glue.md', 'Glue', ['glue'], ['spryker-feature/glue-application'], ['src/Spryker/Glue']);

        // Act
        $result = $this->capabilityInstallStatusResolver->resolve($card, ['spryker-feature/glue-application' => '202512.0']);

        // Assert
        $this->assertSame(CapabilityInstallStatusResolver::STATUS_INSTALLED, $result['installStatus']);
    }

    public function testResolveSkipsModulesOutsideVendor(): void
    {
        // Arrange
        $card = $this->createCard('custom.md', 'Custom', [], [], ['src/Pyz/Zed/Custom', 'vendor/spryker/custom']);

        // Act
        $result = $this->capabilityInstallStatusResolver->resolve($card, []);

        // Assert
        $this->assertCount(1, $result['modules']);
        $this->assertSame('spryker/custom', $result['modules'][0]['package']);
    }
}
