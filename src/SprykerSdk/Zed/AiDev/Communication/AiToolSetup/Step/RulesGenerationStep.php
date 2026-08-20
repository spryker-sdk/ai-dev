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

class RulesGenerationStep implements AiToolSetupStepInterface
{
    public function __construct(
        protected AiToolArtifactGeneratorInterface $generator,
        protected AiDevConfig $config,
    ) {
    }

    public function getLabel(): string
    {
        return 'Generate rules';
    }

    public function canExecuteForTool(string $tool): bool
    {
        return $this->config->getToolArtifacts($tool)['rules_dir'] !== null;
    }

    /**
     * @return array<string>
     */
    public function getFallbackToolOptions(): array
    {
        return $this->config->getRuleCompatibleTools();
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
        return $this->generator->listRuleTargetPaths($tool, $mode);
    }

    /**
     * @param array<string> $skipPaths
     */
    public function execute(string $tool, OutputInterface $output, array $skipPaths = [], ArtifactMode $mode = ArtifactMode::Real): void
    {
        $generated = $this->generator->generateRules($tool, $skipPaths, $mode);

        foreach ($generated as $path) {
            $output->writeln(sprintf('<info>Generated rule:</info> <fg=cyan>%s</>', $this->generator->toRelativePath($path)));
        }
    }
}
