<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\CapabilityMap;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Business\CapabilityMap\ComposerLockReader;

/**
 * @group AiDev
 * @group Business
 * @group CapabilityMap
 * @group ComposerLockReaderTest
 */
class ComposerLockReaderTest extends Unit
{
    protected string $composerLockFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->composerLockFilePath = sys_get_temp_dir() . '/ai-dev-composer-lock-test-' . uniqid() . '.lock';
    }

    protected function tearDown(): void
    {
        if (is_file($this->composerLockFilePath)) {
            unlink($this->composerLockFilePath);
        }

        parent::tearDown();
    }

    public function testGetInstalledPackagesReadsPackagesAndDevPackagesWithLowercaseNames(): void
    {
        // Arrange
        file_put_contents($this->composerLockFilePath, (string)json_encode([
            'packages' => [
                ['name' => 'Spryker/Cart', 'version' => '7.20.0'],
                ['name' => 'spryker-feature/cart', 'version' => '202512.0'],
                ['version' => '1.0.0'],
            ],
            'packages-dev' => [
                ['name' => 'spryker-sdk/ai-dev', 'version' => 'dev-master'],
            ],
        ]));

        // Act
        $installedPackages = (new ComposerLockReader($this->composerLockFilePath))->getInstalledPackages();

        // Assert
        $this->assertSame([
            'spryker/cart' => '7.20.0',
            'spryker-feature/cart' => '202512.0',
            'spryker-sdk/ai-dev' => 'dev-master',
        ], $installedPackages);
    }

    public function testGetInstalledPackagesReturnsNullWithoutLockFile(): void
    {
        // Act
        $installedPackages = (new ComposerLockReader($this->composerLockFilePath))->getInstalledPackages();

        // Assert
        $this->assertNull($installedPackages);
    }

    public function testGetInstalledPackagesReturnsNullForInvalidLockFile(): void
    {
        // Arrange
        file_put_contents($this->composerLockFilePath, 'not json');

        // Act
        $installedPackages = (new ComposerLockReader($this->composerLockFilePath))->getInstalledPackages();

        // Assert
        $this->assertNull($installedPackages);
    }
}
