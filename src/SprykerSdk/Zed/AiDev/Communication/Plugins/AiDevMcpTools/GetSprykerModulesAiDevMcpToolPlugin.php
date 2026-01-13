<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 */
class GetSprykerModulesAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'getSprykerModules';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Tool to get all Spryker modules from project and vendor. Returns a list of unique module names.';
    }

    /**
     * @return string
     */
    public function getSprykerModules(): string
    {
        $allModules = $this->getFactory()
            ->getModuleFinderFacade()
            ->getModules();

        $projectModules = $this->getFactory()
            ->getModuleFinderFacade()
            ->getProjectModules();

        $mergedModules = array_merge($allModules, $projectModules);

        $moduleNames = [];
        foreach ($mergedModules as $moduleTransfer) {
            $moduleNames[] = $moduleTransfer->getName();
        }

        $moduleNames = array_unique($moduleNames);
        sort($moduleNames);

        return json_encode($moduleNames, JSON_PRETTY_PRINT);
    }
}
