<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication;

use Spryker\Zed\Kernel\Communication\AbstractCommunicationFactory;
use Spryker\Zed\ModuleFinder\Business\ModuleFinderFacadeInterface;
use SprykerSdk\Zed\AiDev\AiDevDependencyProvider;

/**
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class AiDevCommunicationFactory extends AbstractCommunicationFactory
{
    /**
     * @return array<\SprykerSdk\Zed\AiDev\Dependency\AiDevMcpPromptPluginInterface>
     */
    public function getMcpPromptPlugins(): array
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::PLUGINS_MCP_PROMPT);
    }

    /**
     * @return array<\SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface>
     */
    public function getMcpToolPlugins(): array
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::PLUGINS_MCP_TOOL);
    }

    /**
     * @return \Spryker\Zed\ModuleFinder\Business\ModuleFinderFacadeInterface
     */
    public function getModuleFinderFacade(): ModuleFinderFacadeInterface
    {
        return $this->getProvidedDependency(AiDevDependencyProvider::FACADE_MODULE_FINDER);
    }
}
