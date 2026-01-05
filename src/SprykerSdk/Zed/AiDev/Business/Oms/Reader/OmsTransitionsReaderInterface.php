<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\Oms\Reader;

interface OmsTransitionsReaderInterface
{
    /**
     * @param string $orderReference
     *
     * @return string
     */
    public function getOrderOmsTransitions(string $orderReference): string;

    /**
     * @param string $stateName
     * @param string $processName
     *
     * @return string
     */
    public function getOmsTransitionsByState(string $stateName, string $processName = ''): string;
}
