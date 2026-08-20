<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\AiToolSetup;

interface RuleFrontmatterTransformerInterface
{
    /**
     * @api
     *
     * @param array<string, mixed> $spec
     */
    public function transform(string $content, array $spec): string;
}
