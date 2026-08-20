<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev;

use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\AnalyzeCsvFileAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\DeleteCsvRowsAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\ExecuteDatabaseQueryAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetInterfaceMethodsAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetOmsTransitionsByOrderAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetOmsTransitionsByStateAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetSprykerModuleMapAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetSprykerModulesAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\GetTransferStructureByNameAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\SearchAlgoliaDocumentationAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\SplitOdsToCsvAiDevMcpToolPlugin;
use SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools\TransformCsvAiDevMcpToolPlugin;

/**
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 */
class AiDevDependencyProvider extends AbstractBundleDependencyProvider
{
    public const string PLUGINS_MCP_PROMPT = 'PLUGINS_MCP_PROMPT';

    public const string PLUGINS_MCP_TOOL = 'PLUGINS_MCP_TOOL';

    public const string FACADE_OMS = 'FACADE_OMS';

    public const string FACADE_MODULE_FINDER = 'FACADE_MODULE_FINDER';

    public function provideBusinessLayerDependencies(Container $container): Container
    {
        $container = parent::provideBusinessLayerDependencies($container);
        $container = $this->addOmsFacade($container);

        return $container;
    }

    protected function addOmsFacade(Container $container): Container
    {
        $container->set(static::FACADE_OMS, function (Container $container) {
            return $container->getLocator()->oms()->facade();
        });

        return $container;
    }

    protected function addModuleFinderFacade(Container $container): Container
    {
        $container->set(static::FACADE_MODULE_FINDER, function (Container $container) {
            return $container->getLocator()->moduleFinder()->facade();
        });

        return $container;
    }

    public function provideCommunicationLayerDependencies(Container $container): Container
    {
        $container = parent::provideCommunicationLayerDependencies($container);
        $container = $this->addMcpPromptPlugins($container);
        $container = $this->addMcpToolPlugins($container);
        $container = $this->addModuleFinderFacade($container);

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
     * @return array<\SprykerSdk\Zed\AiDev\Dependency\AiDevMcpPromptPluginInterface>
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
     * @return array<\SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface>
     */
    protected function getMcpToolPlugins(): array
    {
        return [
            new GetTransferStructureByNameAiDevMcpToolPlugin(),
            new GetInterfaceMethodsAiDevMcpToolPlugin(),
            new GetOmsTransitionsByOrderAiDevMcpToolPlugin(),
            new GetOmsTransitionsByStateAiDevMcpToolPlugin(),
            new ExecuteDatabaseQueryAiDevMcpToolPlugin(),
            new SearchAlgoliaDocumentationAiDevMcpToolPlugin(),
            new GetSprykerModulesAiDevMcpToolPlugin(),
            new GetSprykerModuleMapAiDevMcpToolPlugin(),
            new AnalyzeCsvFileAiDevMcpToolPlugin(),
            new DeleteCsvRowsAiDevMcpToolPlugin(),
            new TransformCsvAiDevMcpToolPlugin(),
            new SplitOdsToCsvAiDevMcpToolPlugin(),
        ];
    }
}
