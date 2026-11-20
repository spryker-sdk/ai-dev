<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

interface CapabilityInstallStatusResolverInterface
{
    /**
     * Specification:
     * - Compares the card's feature metapackages and module packages with the installed packages.
     * - `*-rest-api`, `*-storefront-api` and `*-backend-api` packages are optional: they are only needed when the project exposes that API and do not count against the status.
     * - Status is `installed` when every required package is installed, `partially_installed` when some are, `not_installed` when none are, `unknown` without `composer.lock`; a card without vendor modules is `installed` when one of its features is.
     * - `composerRequire` names the feature when the card has exactly one and it is missing, otherwise the missing required packages (several features are alternatives); null when the status is `installed` or `unknown`.
     *
     * @param array<string, mixed> $card
     * @param array<string, string>|null $installedPackages
     *
     * @return array<string, mixed>
     */
    public function resolve(array $card, ?array $installedPackages): array;
}
