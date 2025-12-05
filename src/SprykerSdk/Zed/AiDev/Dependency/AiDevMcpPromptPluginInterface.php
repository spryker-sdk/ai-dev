<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Dependency;

interface AiDevMcpPromptPluginInterface
{
    /**
     * Specification:
     * - Returns the name of the MCP prompt.
     *
     * @api
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Specification:
     * - Returns the description of the MCP prompt.
     *
     * @api
     *
     * @return string
     */
    public function getDescription(): string;
}
