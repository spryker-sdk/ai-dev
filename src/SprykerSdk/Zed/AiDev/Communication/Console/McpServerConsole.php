<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Console;

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Spryker\Zed\Kernel\BundleConfigResolverAwareTrait;
use Spryker\Zed\Kernel\Communication\Console\Console;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolInputSchemaPluginInterface;
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

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME)
            ->setDescription(static::COMMAND_DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $serverBuilder = Server::builder()
                ->setServerInfo(...$this->getFactory()->getConfig()->getMcpServerInfo());

            $promptClassTargetDirectory = $this->getConfig()->getPromptClassTargetDirectory();

            // Discovery is configured on the builder, before build(). The generated prompt
            // classes sit flat in one directory, so that directory itself is the only scan path.
            if (is_dir($promptClassTargetDirectory)) {
                $serverBuilder->setDiscovery($promptClassTargetDirectory, ['.']);
            }

            foreach ($this->getFactory()->getMcpPromptPlugins() as $promptPlugin) {
                $serverBuilder->addPrompt(
                    [$promptPlugin::class, $promptPlugin->getName()],
                    $promptPlugin->getName(),
                    description: $promptPlugin->getDescription(),
                );
            }

            foreach ($this->getFactory()->getMcpToolPlugins() as $toolPlugin) {
                $inputSchema = $toolPlugin instanceof AiDevMcpToolInputSchemaPluginInterface
                    ? $toolPlugin->getInputSchema()
                    : null;

                $serverBuilder->addTool(
                    [$toolPlugin::class, $toolPlugin->getName()],
                    $toolPlugin->getName(),
                    description: $toolPlugin->getDescription(),
                    inputSchema: $inputSchema,
                );
            }

            $serverBuilder->build()->run(new StdioTransport());
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }
}
