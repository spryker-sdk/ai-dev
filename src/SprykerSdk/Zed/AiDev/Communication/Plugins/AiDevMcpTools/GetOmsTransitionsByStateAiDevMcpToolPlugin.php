<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class GetOmsTransitionsByStateAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getDescription(): string
    {
        return 'Tool to get OMS state machine transitions for a specific state. Returns all transitions that start from the given state, optionally filtered by process name. Returns transitions with source state, target state, event, and condition information.';
    }

    public function getName(): string
    {
        return 'getOmsTransitionsByState';
    }

    public function getOmsTransitionsByState(string $stateName, string $processName = ''): string
    {
        return $this->getBusinessFactory()
            ->createOmsTransitionsReader()
            ->getOmsTransitionsByState($stateName, $processName);
    }
}
