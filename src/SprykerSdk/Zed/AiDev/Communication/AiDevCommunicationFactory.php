<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Communication;

use Spryker\Zed\Kernel\Communication\AbstractCommunicationFactory;
use SprykerSdk\Zed\AiDev\AiDevDependencyProvider;

/**
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
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
}
