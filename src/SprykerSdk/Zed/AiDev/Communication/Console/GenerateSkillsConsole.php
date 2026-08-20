<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Console;

use DirectoryIterator;
use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
/**
 * @deprecated Use {@link \SprykerSdk\Zed\AiDev\Communication\Console\AiToolSetupConsole} instead.
 */
class GenerateSkillsConsole extends Console
{
    protected const string COMMAND_NAME = 'ai-dev:generate-skills';

    protected const string COMMAND_DESCRIPTION = 'Generate example AI coding skill files for Spryker project development.';

    protected const string TOOL_CLAUDE_CODE = 'Claude Code';

    protected const string TOOL_CURSOR = 'Cursor';

    protected const string TOOL_CODEX = 'OpenAI Codex';

    protected const string TOOL_OPENCODE = 'OpenCode';

    protected const string TOOL_WINDSURF = 'Windsurf';

    protected const string TOOL_COPILOT = 'GitHub Copilot';

    protected const string TOOL_AGENTS_CONVENTION = 'Agents Convention';

    /**
     * @var array<string, string>
     */
    protected const array TOOL_DESCRIPTIONS = [
        self::TOOL_CLAUDE_CODE => '.claude/skills/ — Claude Code (https://code.claude.com/docs/en/skills)',
        self::TOOL_CURSOR => '.agents/skills/ — Cursor (https://cursor.com/docs/context/skills)',
        self::TOOL_CODEX => '.agents/skills/ — OpenAI Codex (https://developers.openai.com/codex/skills)',
        self::TOOL_OPENCODE => '.agents/skills/ — OpenCode (https://opencode.ai/docs/skills)',
        self::TOOL_WINDSURF => '.windsurf/skills/ — Windsurf (https://docs.windsurf.com/windsurf/cascade/skills)',
        self::TOOL_COPILOT => '.github/skills/ — GitHub Copilot (https://docs.github.com/en/copilot/concepts/agents/about-agent-skills)',
        self::TOOL_AGENTS_CONVENTION => '.agents/skills/ — Agents convention (https://agentskills.io/home)',
    ];

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME)
            ->setDescription(static::COMMAND_DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (static::TOOL_DESCRIPTIONS as $tool => $description) {
            $output->writeln(sprintf('  <info>%s</info>  %s', $tool, $description));
        }

        $output->writeln('');

        $selectedTool = $this->select(
            'Which AI tool do you want to generate skills for?',
            array_keys(static::TOOL_DESCRIPTIONS),
            static::TOOL_CLAUDE_CODE,
        );

        $skillsDir = $this->getFactory()->getConfig()->getSkillsExamplesDirectory();
        $outputDir = $this->resolveOutputDirectory($selectedTool);

        if (!is_dir($skillsDir)) {
            $this->error(sprintf('Skills examples directory not found: %s', $skillsDir));

            return static::CODE_ERROR;
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        foreach (new DirectoryIterator($skillsDir) as $item) {
            if (!$item->isDir() || $item->isDot()) {
                continue;
            }

            $targetDir = $outputDir . DIRECTORY_SEPARATOR . $item->getFilename() . '-example';
            $this->copyDirectory($item->getPathname(), $targetDir);
            $this->info(sprintf('Generated: %s', $targetDir));
        }

        $output->writeln('');
        $this->success(sprintf('Skills generated in: %s', $outputDir));
        $this->info('Rename each directory by removing the "-example" suffix when ready to use.');

        return static::CODE_SUCCESS;
    }

    protected function resolveOutputDirectory(string $tool): string
    {
        return match ($tool) {
            static::TOOL_CLAUDE_CODE => APPLICATION_ROOT_DIR . '/.claude/skills',
            static::TOOL_WINDSURF => APPLICATION_ROOT_DIR . '/.windsurf/skills',
            static::TOOL_COPILOT => APPLICATION_ROOT_DIR . '/.github/skills',
            default => APPLICATION_ROOT_DIR . '/.agents/skills',
        };
    }

    protected function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        foreach (new DirectoryIterator($source) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $destinationPath = $destination . DIRECTORY_SEPARATOR . $item->getFilename();

            if ($item->isDir()) {
                $this->copyDirectory($item->getPathname(), $destinationPath);

                continue;
            }

            copy($item->getPathname(), $destinationPath);
        }
    }
}
