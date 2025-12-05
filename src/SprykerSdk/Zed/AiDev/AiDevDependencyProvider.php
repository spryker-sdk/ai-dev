<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev;

use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetInterfaceMethodsAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetTransferStructureByNameAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetTransferStructureByNamespaceAiDevMcpToolPlugin;

/**
 * @method SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 */
class AiDevDependencyProvider extends AbstractBundleDependencyProvider
{
    public const string PLUGINS_MCP_PROMPT = 'PLUGINS_MCP_PROMPT';

    public const string PLUGINS_MCP_TOOL = 'PLUGINS_MCP_TOOL';

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    public function provideBusinessLayerDependencies(Container $container): Container
    {
        $container = parent::provideBusinessLayerDependencies($container);

        return $container;
    }

    public function provideCommunicationLayerDependencies(Container $container): Container
    {
        $container = parent::provideCommunicationLayerDependencies($container);
        $container = $this->addMcpPromptPlugins($container);
        $container = $this->addMcpToolPlugins($container);

        return $container;
    }

    protected function addMcpPromptPlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_MCP_PROMPT, function () {
            return $this->getMcpPromptPlugins();
        });

        return $container;
    }

    /**
     * @return array<SprykerSdk\Zed\AiDev\Dependency\AiDevMcpPromptPluginInterface>
     */
    protected function getMcpPromptPlugins(): array
    {
        return [];
    }

    protected function addMcpToolPlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_MCP_TOOL, function () {
            return $this->getMcpToolPlugins();
        });

        return $container;
    }

    /**
     * @return array<SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface>
     */
    protected function getMcpToolPlugins(): array
    {
        return [
            new GetTransferStructureByNamespaceAiDevMcpToolPlugin(),
            new GetTransferStructureByNameAiDevMcpToolPlugin(),
            new GetInterfaceMethodsAiDevMcpToolPlugin(),
        ];
    }
}
