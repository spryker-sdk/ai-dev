<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

interface ComposerLockReaderInterface
{
    /**
     * Specification:
     * - Returns the installed packages from `composer.lock` (`packages` and `packages-dev`), keyed by lowercase package name, with the installed version as value.
     * - Returns null when the project has no readable `composer.lock`.
     *
     * @return array<string, string>|null
     */
    public function getInstalledPackages(): ?array;
}
