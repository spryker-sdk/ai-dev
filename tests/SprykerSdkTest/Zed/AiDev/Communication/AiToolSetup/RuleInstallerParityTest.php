<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Communication\AiToolSetup;

use Codeception\Test\Unit;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SprykerSdk\Zed\AiDev\AiDevConfig;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\RuleFrontmatterTransformer;

/**
 * The rules have two installers: `ai-dev:setup` (this module) and the plugin's `sync-rules.sh`.
 * The script records what it wrote by SHA, so any byte difference makes a file one installer wrote
 * look locally edited to the other.
 *
 * @group AiDev
 * @group Communication
 * @group AiToolSetup
 * @group RuleInstallerParityTest
 */
class RuleInstallerParityTest extends Unit
{
    protected const string PLUGIN_DIR = __DIR__ . '/../../../../../../plugins/spryker-ai-dev-sdk';

    /**
     * Harness name passed to sync-rules.sh => [AiDevConfig tool name, rules folder, file suffix].
     *
     * @var array<string, array{string, string, string}>
     */
    protected const array HARNESSES = [
        'claude' => ['Claude Code', '.claude/rules', '.md'],
        'copilot' => ['GitHub Copilot', '.github/instructions', '.instructions.md'],
        'vscode' => ['GitHub Copilot', '.github/instructions', '.instructions.md'],
    ];

    protected string $projectDir;

    protected function _before(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/ai-dev-parity-' . uniqid();
        mkdir($this->projectDir);
    }

    protected function _after(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->projectDir);
    }

    /**
     * @dataProvider harnessProvider
     */
    public function testSyncScriptWritesWhatTheConsoleWrites(string $harness): void
    {
        // Arrange
        [$tool, $rulesFolder, $suffix] = static::HARNESSES[$harness];
        $spec = (new AiDevConfig())->getToolFrontmatterSpec($tool);
        $transformer = new RuleFrontmatterTransformer();
        $ruleFiles = glob(static::PLUGIN_DIR . '/rules/*.md') ?: [];

        // Act
        exec(sprintf(
            'bash %s --target %s --harness %s --force 2>&1',
            escapeshellarg(static::PLUGIN_DIR . '/scripts/sync-rules.sh'),
            escapeshellarg($this->projectDir),
            escapeshellarg($harness),
        ), $output, $exitCode);

        // Assert
        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertNotEmpty($ruleFiles);

        foreach ($ruleFiles as $ruleFile) {
            $installed = sprintf('%s/%s/%s%s', $this->projectDir, $rulesFolder, basename($ruleFile, '.md'), $suffix);

            $this->assertFileExists($installed);
            $this->assertSame(
                $transformer->transform((string)file_get_contents($ruleFile), $spec),
                file_get_contents($installed),
                sprintf('%s differs between ai-dev:setup and sync-rules.sh for %s', basename($ruleFile), $harness),
            );
        }
    }

    /**
     * @return array<string, array<string>>
     */
    public function harnessProvider(): array
    {
        $cases = [];

        foreach (array_keys(static::HARNESSES) as $harness) {
            $cases[$harness] = [$harness];
        }

        return $cases;
    }
}
