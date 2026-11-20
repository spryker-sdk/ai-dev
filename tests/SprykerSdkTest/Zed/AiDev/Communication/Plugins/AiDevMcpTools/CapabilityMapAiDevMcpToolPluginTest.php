<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Codeception\Test\Unit;
use Mcp\Exception\ToolCallException;
use SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory;
use SprykerSdk\Zed\AiDev\Business\CapabilityMap\CapabilityMapFinderInterface;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetSprykerCapabilityAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\SearchSprykerCapabilitiesAiDevMcpToolPlugin;

/**
 * @group AiDev
 * @group Communication
 * @group Plugins
 * @group AiDevMcpTools
 * @group CapabilityMapAiDevMcpToolPluginTest
 */
class CapabilityMapAiDevMcpToolPluginTest extends Unit
{
    protected const string ERROR_MESSAGE = 'Unknown capability "missing". Use searchSprykerCapabilities to find the slug.';

    public function testSearchSprykerCapabilitiesReturnsJsonOnSuccess(): void
    {
        // Arrange
        $plugin = new SearchSprykerCapabilitiesAiDevMcpToolPlugin();
        $plugin->setBusinessFactory($this->createBusinessFactoryMock(
            'searchCapabilities',
            ['query' => 'wishlist', 'count' => 1, 'hits' => [['slug' => 'wishlist']]],
        ));

        // Act
        $result = json_decode($plugin->searchSprykerCapabilities('wishlist'), true);

        // Assert
        $this->assertSame('wishlist', $result['hits'][0]['slug']);
    }

    public function testSearchSprykerCapabilitiesThrowsToolCallExceptionOnError(): void
    {
        // Arrange
        $plugin = new SearchSprykerCapabilitiesAiDevMcpToolPlugin();
        $plugin->setBusinessFactory($this->createBusinessFactoryMock('searchCapabilities', ['error' => static::ERROR_MESSAGE]));

        // Assert
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(static::ERROR_MESSAGE);

        // Act
        $plugin->searchSprykerCapabilities('ab');
    }

    public function testGetSprykerCapabilityReturnsJsonOnSuccess(): void
    {
        // Arrange
        $plugin = new GetSprykerCapabilityAiDevMcpToolPlugin();
        $plugin->setBusinessFactory($this->createBusinessFactoryMock('getCapability', ['slug' => 'wishlist', 'installStatus' => 'installed']));

        // Act
        $result = json_decode($plugin->getSprykerCapability('wishlist'), true);

        // Assert
        $this->assertSame('installed', $result['installStatus']);
    }

    public function testGetSprykerCapabilityThrowsToolCallExceptionOnError(): void
    {
        // Arrange
        $plugin = new GetSprykerCapabilityAiDevMcpToolPlugin();
        $plugin->setBusinessFactory($this->createBusinessFactoryMock('getCapability', ['error' => static::ERROR_MESSAGE]));

        // Assert
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(static::ERROR_MESSAGE);

        // Act
        $plugin->getSprykerCapability('missing');
    }

    /**
     * @param array<string, mixed> $finderResult
     */
    protected function createBusinessFactoryMock(string $finderMethod, array $finderResult): AiDevBusinessFactory
    {
        $capabilityMapFinderMock = $this->createMock(CapabilityMapFinderInterface::class);
        $capabilityMapFinderMock->method($finderMethod)->willReturn($finderResult);

        $businessFactoryMock = $this->createMock(AiDevBusinessFactory::class);
        $businessFactoryMock->method('createCapabilityMapFinder')->willReturn($capabilityMapFinderMock);

        return $businessFactoryMock;
    }
}
