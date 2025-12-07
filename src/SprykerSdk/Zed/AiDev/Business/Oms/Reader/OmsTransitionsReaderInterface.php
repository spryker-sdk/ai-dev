<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\Oms\Reader;

interface OmsTransitionsReaderInterface
{
    public function getOrderOmsTransitions(string $orderReference): string;

    public function getOmsTransitionsByState(string $stateName, string $processName = ''): string;
}
