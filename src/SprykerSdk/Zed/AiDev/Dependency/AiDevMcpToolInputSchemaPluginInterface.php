<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Dependency;

interface AiDevMcpToolInputSchemaPluginInterface
{
    /**
     * Specification:
     * - Returns the JSON Schema for tool input parameters.
     * - Provides custom schema when auto-generation from method signature is insufficient.
     * - Particularly useful for complex types like associative arrays that need to be objects in JSON Schema.
     *
     * @api
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;
}
