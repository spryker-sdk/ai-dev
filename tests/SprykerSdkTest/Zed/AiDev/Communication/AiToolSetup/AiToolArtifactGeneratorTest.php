<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Communication\AiToolSetup;

use Codeception\Test\Unit;
use ReflectionClass;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGenerator;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode;

/**
 * @group AiDev
 * @group Communication
 * @group AiToolSetup
 * @group AiToolArtifactGenerator
 */
class AiToolArtifactGeneratorTest extends Unit
{
    /**
     * Agent files end in ".agent.md", which GitHub Copilot requires and Claude Code accepts. Example
     * artifacts must keep that compound extension intact, so the suffix lands on the agent name.
     *
     * @dataProvider agentFileNameDataProvider
     */
    public function testResolveAgentFileName(string $fileName, ArtifactMode $mode, string $expected): void
    {
        // Arrange
        $reflection = new ReflectionClass(AiToolArtifactGenerator::class);
        $generator = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveAgentFileName');
        $method->setAccessible(true);

        // Act
        $result = $method->invoke($generator, $fileName, $mode);

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string, \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode, string}>
     */
    public function agentFileNameDataProvider(): array
    {
        return [
            'example mode keeps the .agent.md extension together' => [
                'spryker-verifier.agent.md',
                ArtifactMode::Example,
                'spryker-verifier-example.agent.md',
            ],
            'real mode leaves the file name alone' => [
                'spryker-verifier.agent.md',
                ArtifactMode::Real,
                'spryker-verifier.agent.md',
            ],
            'a plain .md agent still gets the suffix before its extension' => [
                'legacy-agent.md',
                ArtifactMode::Example,
                'legacy-agent-example.md',
            ],
            'a file without an extension gets the suffix appended' => [
                'noext',
                ArtifactMode::Example,
                'noext-example',
            ],
        ];
    }
}
