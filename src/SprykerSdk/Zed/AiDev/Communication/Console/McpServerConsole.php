<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Console;

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Spryker\Zed\Kernel\BundleConfigResolverAwareTrait;
use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 */
class McpServerConsole extends Console
{
    use BundleConfigResolverAwareTrait;

    /**
     * @var string
     */
    protected const string COMMAND_NAME = 'ai-dev:mcp-server';

    /**
     * @var string
     */
    protected const string COMMAND_DESCRIPTION = 'Run MCP server for AiDev.';

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
        try {
            $this->ensurePromptsGenerated();
        } catch (Throwable) {
            // Ignore errors
        }

        try {
            $serverBuilder = Server::make()
                ->withServerInfo(...$this->getFactory()->getConfig()->getMcpServerInfo());

            foreach ($this->getFactory()->getMcpPromptPlugins() as $promptPlugin) {
                $serverBuilder->withPrompt(
                    [$promptPlugin::class, $promptPlugin->getName()],
                    $promptPlugin->getName(),
                    $promptPlugin->getDescription(),
                );
            }

            foreach ($this->getFactory()->getMcpToolPlugins() as $toolPlugin) {
                $serverBuilder->withTool(
                    [$toolPlugin::class, $toolPlugin->getName()],
                    $toolPlugin->getName(),
                    $toolPlugin->getDescription(),
                );
            }

            $server = $serverBuilder->build();
            $server->discover($this->getConfig()->getPromptClassTargetDirectory());

            $transport = new StdioServerTransport();
            $server->listen($transport);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }

    /**
     * @return void
     */
    protected function ensurePromptsGenerated(): void
    {
        $promptsDirectory = $this->getConfig()->getPromptClassTargetDirectory();

        if (!is_dir($promptsDirectory) || count(glob($promptsDirectory . '*.php')) === 0) {
            $this->getFacade()->generatePrompts();
        }
    }
}
