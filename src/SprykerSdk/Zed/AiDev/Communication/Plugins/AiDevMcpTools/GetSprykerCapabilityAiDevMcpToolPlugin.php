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
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class GetSprykerCapabilityAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getName(): string
    {
        return 'getSprykerCapability';
    }

    public function getDescription(): string
    {
        return 'Returns one Spryker capability map card by slug (from searchSprykerCapabilities, e.g. "purchasing-control"). '
            . 'Sections: What it does, Public surface (entry points), Extension points, Data model, Configuration, Data flow, Storefront surface, Extend don\'t duplicate, Install (feature metapackage, required features, install guide URLs), Related cards ([[slug]] links). '
            . 'Adds the install status of every feature and module from composer.lock and a composer require line for what is missing. '
            . 'Use the extension points to plan an extension and the Install section to install a missing feature; check exact classes with getSprykerModuleMap.';
    }

    public function getSprykerCapability(string $slug): string
    {
        $result = $this->getBusinessFactory()
            ->createCapabilityMapFinder()
            ->getCapability($slug);

        return (string)json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
