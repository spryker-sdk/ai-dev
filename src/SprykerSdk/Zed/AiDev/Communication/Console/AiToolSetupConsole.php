<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Console;

use Spryker\Zed\Kernel\Communication\Console\Console;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\AiToolArtifactGeneratorInterface;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\ArtifactMode;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\Step\AiToolSetupStepInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class AiToolSetupConsole extends Console
{
    protected const string COMMAND_NAME = 'ai-dev:setup';

    protected const string COMMAND_DESCRIPTION = 'Set up AI coding assistant files (rules, agents file, skills) for your Spryker project.';

    protected const bool DEFAULT_YES = true;

    protected const bool DEFAULT_NO = false;

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME)
            ->setDescription(static::COMMAND_DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->getFactory()->getConfig();
        $tool = $this->resolveToolSelection(
            $config->getToolDetectionMap(),
            $config->getToolRequiringConfirmation(),
        );

        if ($tool === null) {
            $this->error('No AI tool selected. Aborting.');

            return static::CODE_ERROR;
        }

        $mode = $this->resolveExampleMode($tool, $output);
        $generator = $this->getFactory()->createAiToolArtifactGenerator();

        foreach ($this->getFactory()->getAiToolSetupSteps() as $step) {
            $effectiveTool = $this->resolveEffectiveToolForStep($step, $tool, $output);

            if ($effectiveTool === null) {
                continue;
            }

            $skipPaths = $this->resolveSkipPaths($step->listTargetPaths($effectiveTool, $mode), $output, $generator);
            $step->execute($effectiveTool, $output, $skipPaths, $mode);
        }

        $output->writeln('');
        $this->success(sprintf('Setup complete for %s.', $tool));

        return static::CODE_SUCCESS;
    }

    /**
     * @param array<string, string> $detectionMap
     */
    protected function resolveToolSelection(array $detectionMap, string $toolRequiringConfirmation): ?string
    {
        $detected = $this->getFactory()->createAiToolDetector()->detect($detectionMap);
        $tools = array_values($detectionMap);

        if ($detected === null || !$this->isDetectedToolAccepted($detected, $toolRequiringConfirmation)) {
            return $this->select('Select your AI tool:', $tools, $tools[0]);
        }

        return $detected;
    }

    protected function isDetectedToolAccepted(string $detected, string $toolRequiringConfirmation): bool
    {
        if ($detected === $toolRequiringConfirmation) {
            return $this->confirm(sprintf('%s detected. Confirm this is the tool you use?', $detected), static::DEFAULT_NO);
        }

        return $this->confirm(sprintf('%s detected. Proceed with it?', $detected), static::DEFAULT_YES);
    }

    protected function resolveEffectiveToolForStep(AiToolSetupStepInterface $step, string $tool, OutputInterface $output): ?string
    {
        if ($step->canExecuteForTool($tool)) {
            return $this->confirm(sprintf('%s?', $step->getLabel()), static::DEFAULT_YES) ? $tool : null;
        }

        return $this->resolveFallbackTool($step, $tool, $output);
    }

    protected function resolveFallbackTool(AiToolSetupStepInterface $step, string $tool, OutputInterface $output): ?string
    {
        $artifact = strtolower(trim(str_ireplace('Generate', '', $step->getLabel())));

        $output->writeln(sprintf('<comment>%s is not supported for %s.</comment>', ucfirst($artifact), $tool));

        $fallbackOptions = $step->getFallbackToolOptions();

        if ($fallbackOptions === []) {
            return null;
        }

        if (!$this->confirm(sprintf('Generate %s in another tool\'s format instead?', $artifact), static::DEFAULT_NO)) {
            return null;
        }

        return $this->select(sprintf('Select tool format for %s:', $artifact), $fallbackOptions, $fallbackOptions[0]);
    }

    /**
     * @param array<string> $targetPaths
     *
     * @return array<string>
     */
    protected function resolveSkipPaths(array $targetPaths, OutputInterface $output, AiToolArtifactGeneratorInterface $generator): array
    {
        $existingPaths = array_values(array_filter($targetPaths, 'file_exists'));

        if ($existingPaths === []) {
            return [];
        }

        $output->writeln('<comment>The following files already exist:</comment>');

        foreach ($existingPaths as $path) {
            $output->writeln(sprintf('  <fg=yellow>%s</>', $generator->toRelativePath($path)));
        }

        if ($this->confirm('Overwrite existing files?', static::DEFAULT_NO)) {
            return [];
        }

        return $existingPaths;
    }

    protected function resolveExampleMode(string $tool, OutputInterface $output): ArtifactMode
    {
        $artifactConfig = $this->getFactory()->getConfig()->getToolArtifacts($tool);
        $agentsFile = (string)$artifactConfig['agents_file'];
        $skillsDir = basename((string)$artifactConfig['skills_dir']);
        $rulesDir = basename((string)($artifactConfig['rules_dir'] ?? 'rules'));

        $output->writeln('');
        $output->writeln('How should generated files be named?');
        $output->writeln(sprintf('  <fg=green>Ready to use</> [y]: %s, %s/propel-schema, %s/dependency-provider.md', $agentsFile, $skillsDir, $rulesDir));
        $output->writeln(sprintf('  <comment>Example</comment>      [N]: example.%s, %s/propel-schema-example, %s-example/dependency-provider.md  <comment>(rename when ready)</comment>', $agentsFile, $skillsDir, $rulesDir));
        $output->writeln('');

        return $this->confirm('Generate as ready to use?', static::DEFAULT_NO) ? ArtifactMode::Real : ArtifactMode::Example;
    }

    protected function confirm(string $question, bool $isDefaultYes): bool
    {
        $label = $isDefaultYes ? 'Y/n' : 'y/N';
        $confirmationQuestion = new ConfirmationQuestion(
            sprintf('<question>%s [%s]:</question> ', $question, $label),
            $isDefaultYes,
        );

        return (bool)$this->getQuestionHelper()->ask($this->input, $this->output, $confirmationQuestion);
    }
}
