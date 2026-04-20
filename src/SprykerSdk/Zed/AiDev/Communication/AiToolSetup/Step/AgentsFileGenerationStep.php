<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step;

use SprykerSdk\Zed\AiDev\AiDevConfig;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGeneratorInterface;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode;
use Symfony\Component\Console\Output\OutputInterface;

class AgentsFileGenerationStep implements AiToolSetupStepInterface
{
    /**
     * @param \SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGeneratorInterface $generator
     * @param \SprykerSdk\Zed\AiDev\AiDevConfig $config
     */
    public function __construct(
        protected AiToolArtifactGeneratorInterface $generator,
        protected AiDevConfig $config,
    ) {
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return 'Generate agents/context file';
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
        return [$this->generator->listAgentsFileTargetPath($tool, $mode)];
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
        $path = $this->generator->generateAgentsFile($tool, $skipPaths, $mode);

        if ($path === null) {
            return;
        }

        $output->writeln(sprintf('<info>Generated agents file:</info> <fg=cyan>%s</>', $this->generator->toRelativePath($path)));

        if ($mode === ArtifactMode::Example) {
            $agentsFileName = (string)$this->config->getToolArtifacts($tool)['agents_file'];

            $output->writeln(sprintf('<comment>Rename it to "%s" when ready to use.</comment>', $agentsFileName));
        }
    }
}
