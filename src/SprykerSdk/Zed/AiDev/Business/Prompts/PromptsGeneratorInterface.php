<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

interface PromptsGeneratorInterface
{
    /**
     * Specification:
     * - Fetches prompts from all configured fetchers.
     * - Generates prompt methods from all collected prompts.
     * - Writes the generated prompts class to the target directory.
     *
     * @return void
     */
    public function generate(): void;
}
