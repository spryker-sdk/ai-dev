<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
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
