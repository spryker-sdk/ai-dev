<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Communication\Console;

use Codeception\Test\Unit;
use ReflectionClass;
use SprykerSdk\Zed\AiDev\AiDevConfig;
use SprykerSdk\Zed\AiDev\Communication\Console\AiToolSetupConsole;

/**
 * @group AiDev
 * @group Communication
 * @group Console
 * @group AiToolSetupConsole
 */
class AiToolSetupConsoleTest extends Unit
{
    public function testGetSelectableToolsOffersEachToolOnce(): void
    {
        // Arrange
        $reflection = new ReflectionClass(AiToolSetupConsole::class);
        $console = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('getSelectableTools');
        $method->setAccessible(true);
        $detectionMap = (new AiDevConfig())->getToolDetectionMap();

        // Act
        $tools = $method->invoke($console, $detectionMap);

        // Assert
        $this->assertSame(
            ['Claude Code', 'Windsurf', 'GitHub Copilot', 'Cursor', 'OpenCode', 'Codex CLI'],
            $tools,
        );
    }
}
