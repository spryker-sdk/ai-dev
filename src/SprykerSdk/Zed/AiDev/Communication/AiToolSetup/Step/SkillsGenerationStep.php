<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step;

use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGeneratorInterface;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode;
use Symfony\Component\Console\Output\OutputInterface;

class SkillsGenerationStep implements AiToolSetupStepInterface
{
    public function __construct(protected AiToolArtifactGeneratorInterface $generator)
    {
    }

    public function getLabel(): string
    {
        return 'Generate skills';
    }

    public function canExecuteForTool(string $tool): bool
    {
        return true;
    }

    /**
     * @return array<string>
     */
    public function getFallbackToolOptions(): array
    {
        return [];
    }

    public function supportsExampleMode(): bool
    {
        return true;
    }

    /**
     * @return array<string>
     */
    public function listTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array
    {
        return $this->generator->listSkillsTargetPaths($tool, $mode);
    }

    /**
     * @param array<string> $skipPaths
     */
    public function execute(string $tool, OutputInterface $output, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): void
    {
        $generated = $this->generator->generateSkills($tool, $skipPaths, $mode);

        foreach ($generated as $path) {
            $output->writeln(sprintf('<info>Generated skill:</info> <fg=cyan>%s</>', $this->generator->toRelativePath($path)));
        }

        if ($generated !== [] && $mode === ArtifactMode::Example) {
            $output->writeln('<comment>Rename each skill directory by removing the "-example" suffix when ready to use.</comment>');
        }
    }
}
