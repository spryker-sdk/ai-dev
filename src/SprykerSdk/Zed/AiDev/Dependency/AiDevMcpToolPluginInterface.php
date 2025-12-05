<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Dependency;

interface AiDevMcpToolPluginInterface
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
