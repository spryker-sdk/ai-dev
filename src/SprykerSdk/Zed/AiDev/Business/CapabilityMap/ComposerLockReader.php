<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

class ComposerLockReader implements ComposerLockReaderInterface
{
    protected const array PACKAGE_SECTIONS = ['packages', 'packages-dev'];

    protected const string KEY_NAME = 'name';

    protected const string KEY_VERSION = 'version';

    public function __construct(protected string $composerLockFilePath)
    {
    }

    /**
     * @return array<string, string>|null
     */
    public function getInstalledPackages(): ?array
    {
        if (!is_file($this->composerLockFilePath)) {
            return null;
        }

        $content = file_get_contents($this->composerLockFilePath);
        $lock = $content === false ? null : json_decode($content, true);
        if (!is_array($lock)) {
            return null;
        }

        $installedPackages = [];
        foreach (static::PACKAGE_SECTIONS as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                if (!is_array($package) || !isset($package[static::KEY_NAME])) {
                    continue;
                }

                $installedPackages[strtolower((string)$package[static::KEY_NAME])] = (string)($package[static::KEY_VERSION] ?? '');
            }
        }

        return $installedPackages;
    }
}
