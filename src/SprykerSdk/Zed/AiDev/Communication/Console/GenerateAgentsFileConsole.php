<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Console;

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
class GenerateAgentsFileConsole extends Console
{
    protected const string COMMAND_NAME = 'ai-dev:generate-agents-file';

    protected const string COMMAND_DESCRIPTION = 'Generate an example AGENTS.md or CLAUDE.md context file for AI agents for Spryker project development.';

    protected const string FORMAT_AGENTS = 'AGENTS.md';

    protected const string FORMAT_CLAUDE = 'CLAUDE.md';

    /**
     * @var array<string, string>
     */
    protected const array FORMAT_DESCRIPTIONS = [
        self::FORMAT_AGENTS => 'Universal format (agents.md) https://agents.md . Supported by: Codex, OpenCode, Cursor, GitHub Copilot, Windsurf, VS Code, and more.',
        self::FORMAT_CLAUDE => 'Claude Code only (Anthropic). Supported by: Claude Code CLI.',
    ];

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME)
            ->setDescription(static::COMMAND_DESCRIPTION);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (static::FORMAT_DESCRIPTIONS as $format => $description) {
            $output->writeln(sprintf('  <info>%s</info>  %s', $format, $description));
        }

        $output->writeln('');

        $format = $this->select(
            'Which AI context file format do you want to generate?',
            [static::FORMAT_AGENTS, static::FORMAT_CLAUDE],
            static::FORMAT_AGENTS,
        );

        $config = $this->getFactory()->getConfig();
        $sourcePath = $config->getAgentsExampleFilePath();

        if (!file_exists($sourcePath)) {
            $this->error(sprintf('Source file not found: %s', $sourcePath));

            return static::CODE_ERROR;
        }

        $outputFileName = sprintf('%s.example.md', pathinfo((string)$format, PATHINFO_FILENAME));
        $outputPath = rtrim($config->getAgentsFileOutputDirectory(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $outputFileName;

        file_put_contents($outputPath, file_get_contents($sourcePath));

        $this->success(sprintf('Generated: %s', $outputPath));
        $this->info(sprintf('Rename it to "%s" when ready to use.', $format));

        return static::CODE_SUCCESS;
    }
}
