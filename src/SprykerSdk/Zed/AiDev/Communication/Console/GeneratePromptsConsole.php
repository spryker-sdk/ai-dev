<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Console;

use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 */
class GeneratePromptsConsole extends Console
{
    protected const string COMMAND_NAME = 'ai-dev:generate-prompts';

    protected const string COMMAND_DESCRIPTION = 'Generate prompts from GitHub repository.';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME)
            ->setDescription(static::COMMAND_DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->info('Generating prompts...');

        $this->getFacade()->generatePrompts();

        $this->success('Prompts generated successfully.');

        return static::CODE_SUCCESS;
    }
}
