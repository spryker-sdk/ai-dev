<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\CapabilityMap;

use Codeception\Test\Unit;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use SprykerSdk\Zed\AiDev\Business\CapabilityMap\CapabilityMapReader;
use SprykerSdk\Zed\AiDev\Business\CapabilityMap\Exception\CapabilityMapUnavailableException;

/**
 * @group AiDev
 * @group Business
 * @group CapabilityMap
 * @group CapabilityMapReaderTest
 */
class CapabilityMapReaderTest extends Unit
{
    use CapabilityMapTestDataTrait;

    protected const string INDEX_URL = 'https://capability-map.test/index.json';

    protected string $cacheFilePath;

    protected MockHandler $mockHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFilePath = sys_get_temp_dir() . '/ai-dev-capability-map-test-' . uniqid() . '/index.json';
        $this->mockHandler = new MockHandler();
    }

    protected function tearDown(): void
    {
        if (is_file($this->cacheFilePath)) {
            unlink($this->cacheFilePath);
        }
        if (is_dir(dirname($this->cacheFilePath))) {
            rmdir(dirname($this->cacheFilePath));
        }

        parent::tearDown();
    }

    public function testGetCardsDownloadsIndexWritesCacheAndDropsPlannedCards(): void
    {
        // Arrange
        $this->mockHandler->append(new Response(200, [], $this->createIndexJson()));

        // Act
        $cards = $this->createReader()->getCards();

        // Assert
        $this->assertSame(['cart.md'], array_column($cards, 'file'));
        $this->assertFileExists($this->cacheFilePath);
    }

    public function testGetCardsUsesFreshCacheWithoutDownload(): void
    {
        // Arrange
        $this->writeCacheFile($this->createIndexJson());
        $reader = $this->createReader();

        // Act
        $cards = $reader->getCards();

        // Assert
        $this->assertCount(1, $cards);
        $this->assertNull($this->mockHandler->getLastRequest());
        $this->assertFalse($reader->isStale());
    }

    public function testGetCardsFallsBackToExpiredCacheWhenDownloadFails(): void
    {
        // Arrange
        $this->writeCacheFile($this->createIndexJson());
        touch($this->cacheFilePath, time() - 7200);
        $this->mockHandler->append(new ConnectException('Network down', new Request('GET', static::INDEX_URL)));
        $reader = $this->createReader();

        // Act
        $cards = $reader->getCards();

        // Assert
        $this->assertCount(1, $cards);
        $this->assertTrue($reader->isStale());
    }

    public function testGetCardsIgnoresInvalidResponseAndFallsBackToCache(): void
    {
        // Arrange
        $this->writeCacheFile($this->createIndexJson());
        touch($this->cacheFilePath, time() - 7200);
        $this->mockHandler->append(new Response(200, [], '<html>Access denied</html>'));
        $reader = $this->createReader();

        // Act
        $cards = $reader->getCards();

        // Assert
        $this->assertCount(1, $cards);
        $this->assertTrue($reader->isStale());
    }

    public function testGetCardsThrowsWhenDownloadFailsWithoutCache(): void
    {
        // Arrange
        $this->mockHandler->append(new ConnectException('Network down', new Request('GET', static::INDEX_URL)));

        // Assert
        $this->expectException(CapabilityMapUnavailableException::class);
        $this->expectExceptionMessage('Network down');

        // Act
        $this->createReader()->getCards();
    }

    protected function createReader(): CapabilityMapReader
    {
        return new CapabilityMapReader(
            new Client(['handler' => HandlerStack::create($this->mockHandler)]),
            static::INDEX_URL,
            $this->cacheFilePath,
            3600,
        );
    }

    protected function createIndexJson(): string
    {
        return (string)json_encode([
        'cards' => [
            $this->getCards()[0],
            $this->createCard('invoice.md', 'Invoices', ['invoice'], status: 'planned'),
        ]]);
    }

    protected function writeCacheFile(string $content): void
    {
        mkdir(dirname($this->cacheFilePath), 0777, true);
        file_put_contents($this->cacheFilePath, $content);
    }
}
