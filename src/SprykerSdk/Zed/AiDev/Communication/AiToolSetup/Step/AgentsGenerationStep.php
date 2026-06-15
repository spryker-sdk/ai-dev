<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step;

use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGeneratorInterface;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode;
use Symfony\Component\Console\Output\OutputInterface;

class AgentsGenerationStep implements AiToolSetupStepInterface
{
    /**
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGeneratorInterface $generator
     */
    public function __construct(protected AiToolArtifactGeneratorInterface $generator)
    {
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return 'Generate agents';
    }

    /**
     * @param string $tool
     *
     * @return bool
     */
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

    /**
     * @return bool
     */
    public function supportsExampleMode(): bool
    {
        return true;
    }

    /**
     * @param string $tool
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
     * @return array<string>
     */
    public function listTargetPaths(string $tool, ArtifactMode $mode = ArtifactMode::Real): array
    {
        return $this->generator->listAgentsTargetPaths($tool, $mode);
    }

    /**
     * @param string $tool
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array<string> $skipPaths
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode $mode
     *
     * @return void
     */
    public function execute(string $tool, OutputInterface $output, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): void
    {
        $generated = $this->generator->generateAgents($tool, $skipPaths, $mode);

        foreach ($generated as $path) {
            $output->writeln(sprintf('<info>Generated agent:</info> <fg=cyan>%s</>', $this->generator->toRelativePath($path)));
        }

        if ($generated !== [] && $mode === ArtifactMode::Example) {
            $output->writeln('<comment>Rename each agent file by removing the "-example" suffix when ready to use.</comment>');
        }
    }
}
