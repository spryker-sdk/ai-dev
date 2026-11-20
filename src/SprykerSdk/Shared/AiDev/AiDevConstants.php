<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Shared\AiDev;

/**
 * Declares global environment configuration keys. Do not use it for other class constants.
 */
interface AiDevConstants
{
    /**
     * Specification:
     * - URL of the capability map `index.json` the capability tools read, for example a mirror of the public bucket.
     *
     * @api
     *
     * @var string
     */
    public const CAPABILITY_MAP_INDEX_URL = 'AI_DEV:CAPABILITY_MAP_INDEX_URL';
}
