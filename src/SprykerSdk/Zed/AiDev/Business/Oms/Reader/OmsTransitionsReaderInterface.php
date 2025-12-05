<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Business\Oms\Reader;

interface OmsTransitionsReaderInterface
{
    public function getOrderOmsTransitions(string $orderReference): string;

    public function getOmsTransitionsByState(string $stateName, string $processName = ''): string;
}
