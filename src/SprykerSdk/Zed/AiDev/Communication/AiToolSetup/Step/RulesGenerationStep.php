<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step;

use SprykerSdk\Zed\AiDev\AiDevConfig;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGeneratorInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RulesGenerationStep implements AiToolSetupStepInterface
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
        return 'Generate rules';
    }

    /**
     * @param string $tool
     *
     * @return bool
     */
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

    /**
     * @return bool
     */
    public function supportsExampleMode(): bool
    {
        return true;
    }

    /**
     * @param string $tool
     * @param bool $asExample
     *
     * @return array<string>
     */
    public function listTargetPaths(string $tool, bool $asExample = true): array
    {
        return $this->generator->listRuleTargetPaths($tool, $asExample);
    }

    /**
     * @param string $tool
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array<string> $skipPaths
     * @param bool $asExample
     *
     * @return void
     */
    public function execute(string $tool, OutputInterface $output, array $skipPaths = [], bool $asExample = true): void
    {
        $generated = $this->generator->generateRules($tool, $skipPaths, $asExample);

        foreach ($generated as $path) {
            $output->writeln(sprintf('<info>Generated rule:</info> <fg=cyan>%s</>', $this->generator->toRelativePath($path)));
        }
    }
}
