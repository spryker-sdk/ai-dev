<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business;

use Spryker\Zed\Kernel\Business\AbstractFacade;

/**
 * {@inheritDoc}
 *
 * @api
 *
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevBusinessFactory getFactory()
 */
class AiDevFacade extends AbstractFacade implements AiDevFacadeInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return void
     */
    public function generatePrompts(): void
    {
        $this->getFactory()
            ->createPromptsGenerator()
            ->generate();
    }
}
