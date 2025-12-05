<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business;

interface AiDevFacadeInterface
{
    /**
     * Specification:
     * - Fetches all prompts from GitHub repository
     * - Generates prompt methods from templates
     * - Writes GeneratedPrompts class to target directory
     *
     * @api
     */
    public function generatePrompts(): void;
}
