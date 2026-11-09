<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Mcp\Exception\ToolCallException;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getBusinessFactory()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 */
class SearchSprykerCapabilitiesAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getName(): string
    {
        return 'searchSprykerCapabilities';
    }

    public function getDescription(): string
    {
        return 'Finds which Spryker capability, feature and modules already do something, in the Spryker capability map (one card per capability, also covering packages the project has not installed). '
            . 'Call it BEFORE planning or building a new module or feature, to extend or install what Spryker ships instead of rebuilding it. '
            . 'Parameters: query (required, space-separated domain terms and synonyms of 3+ characters, e.g. "budget spending limit approval"; several short queries beat one long one), limit (default 10). '
            . 'Returns ranked hits with slug, capability, status (shipped/partial), matched terms, installStatus from composer.lock (installed, partially_installed, not_installed, unknown), features, missing packages, a composer require line for what is missing, and the card\'s "Extend, don\'t duplicate" advice. '
            . 'Open a hit with getSprykerCapability for its extension points, configuration and install guide. No hit is not proof of absence.';
    }

    /**
     * @throws \Mcp\Exception\ToolCallException
     */
    public function searchSprykerCapabilities(string $query, int $limit = 10): string
    {
        $result = $this->getBusinessFactory()
            ->createCapabilityMapFinder()
            ->searchCapabilities($query, $limit);

        if (isset($result['error'])) {
            throw new ToolCallException($result['error']);
        }

        return (string)json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
