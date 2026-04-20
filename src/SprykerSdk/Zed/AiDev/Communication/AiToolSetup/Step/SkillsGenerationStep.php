<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step;

use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGeneratorInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SkillsGenerationStep implements AiToolSetupStepInterface
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
        return 'Generate skills';
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
     * @param bool $asExample
     *
     * @return array<string>
     */
    public function listTargetPaths(string $tool, bool $asExample = true): array
    {
        return $this->generator->listSkillsTargetPaths($tool, $asExample);
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
        $generated = $this->generator->generateSkills($tool, $skipPaths, $asExample);

        foreach ($generated as $path) {
            $output->writeln(sprintf('<info>Generated skill:</info> <fg=cyan>%s</>', $this->generator->toRelativePath($path)));
        }

        if ($generated !== [] && $asExample) {
            $output->writeln('<comment>Rename each skill directory by removing the "-example" suffix when ready to use.</comment>');
        }
    }
}
