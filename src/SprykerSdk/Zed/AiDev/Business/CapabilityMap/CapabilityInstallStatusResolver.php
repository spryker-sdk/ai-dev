<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Business\CapabilityMap;

class CapabilityInstallStatusResolver implements CapabilityInstallStatusResolverInterface
{
    public const string STATUS_INSTALLED = 'installed';

    public const string STATUS_PARTIALLY_INSTALLED = 'partially_installed';

    public const string STATUS_NOT_INSTALLED = 'not_installed';

    public const string STATUS_UNKNOWN = 'unknown';

    protected const string VENDOR_PATH_PREFIX = 'vendor/';

    protected const array OPTIONAL_API_PACKAGE_MARKERS = ['-rest-api', '-storefront-api', '-backend-api'];

    protected const string COMPOSER_REQUIRE_COMMAND = 'composer require';

    protected const string KEY_FEATURES = 'features';

    protected const string KEY_MODULES = 'modules';

    /**
     * @param array<string, mixed> $card
     * @param array<string, string>|null $installedPackages
     *
     * @return array<string, mixed>
     */
    public function resolve(array $card, ?array $installedPackages): array
    {
        $features = $this->resolveFeatures($card[static::KEY_FEATURES] ?? [], $installedPackages);
        $modules = $this->resolveModules($card[static::KEY_MODULES] ?? [], $installedPackages);

        $missingFeatures = $this->collectMissing($features, 'name');
        $missingPackages = $this->collectMissing(array_filter($modules, fn (array $module): bool => !$module['optional']), 'package');
        $missingOptionalPackages = $this->collectMissing(array_filter($modules, fn (array $module): bool => $module['optional']), 'package');

        $installStatus = $this->resolveInstallStatus($features, $modules, $installedPackages);

        return [
            'installStatus' => $installStatus,
            'features' => $features,
            'modules' => $modules,
            'missingPackages' => $missingPackages,
            'missingOptionalPackages' => $missingOptionalPackages,
            'composerRequire' => $installStatus === static::STATUS_INSTALLED ? null : $this->buildComposerRequire($features, $missingFeatures, $missingPackages),
        ];
    }

    /**
     * @param array<string> $featureNames
     * @param array<string, string>|null $installedPackages
     *
     * @return array<array{name: string, installed: bool|null, version: string|null}>
     */
    protected function resolveFeatures(array $featureNames, ?array $installedPackages): array
    {
        $features = [];
        foreach ($featureNames as $featureName) {
            $features[] = [
                'name' => $featureName,
                'installed' => $installedPackages === null ? null : isset($installedPackages[strtolower($featureName)]),
                'version' => $installedPackages[strtolower($featureName)] ?? null,
            ];
        }

        return $features;
    }

    /**
     * @param array<string> $modulePaths
     * @param array<string, string>|null $installedPackages
     *
     * @return array<array{package: string, path: string, optional: bool, installed: bool|null, version: string|null}>
     */
    protected function resolveModules(array $modulePaths, ?array $installedPackages): array
    {
        $modules = [];
        foreach ($modulePaths as $modulePath) {
            if (!str_starts_with($modulePath, static::VENDOR_PATH_PREFIX)) {
                continue;
            }

            $packageName = strtolower(substr($modulePath, strlen(static::VENDOR_PATH_PREFIX)));
            $modules[] = [
                'package' => $packageName,
                'path' => $modulePath,
                'optional' => $this->isOptionalApiPackage($packageName),
                'installed' => $installedPackages === null ? null : isset($installedPackages[$packageName]),
                'version' => $installedPackages[$packageName] ?? null,
            ];
        }

        return $modules;
    }

    protected function isOptionalApiPackage(string $packageName): bool
    {
        foreach (static::OPTIONAL_API_PACKAGE_MARKERS as $marker) {
            if (str_contains($packageName, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array<string, mixed>> $features
     * @param array<array<string, mixed>> $modules
     * @param array<string, string>|null $installedPackages
     */
    protected function resolveInstallStatus(array $features, array $modules, ?array $installedPackages): string
    {
        if ($installedPackages === null) {
            return static::STATUS_UNKNOWN;
        }

        $requiredModules = array_filter($modules, fn (array $module): bool => !$module['optional']) ?: $modules;
        if ($requiredModules === []) {
            return count($this->collectMissing($features, 'name')) < count($features) ? static::STATUS_INSTALLED : static::STATUS_NOT_INSTALLED;
        }

        $installedCount = count(array_filter($requiredModules, fn (array $module): bool => $module['installed'] === true));

        if ($installedCount === count($requiredModules)) {
            return static::STATUS_INSTALLED;
        }

        return $installedCount > 0 ? static::STATUS_PARTIALLY_INSTALLED : static::STATUS_NOT_INSTALLED;
    }

    /**
     * @param array<array<string, mixed>> $entries
     *
     * @return array<string>
     */
    protected function collectMissing(array $entries, string $nameKey): array
    {
        $missing = [];
        foreach ($entries as $entry) {
            if ($entry['installed'] === false) {
                $missing[] = $entry[$nameKey];
            }
        }

        return $missing;
    }

    /**
     * A card with several features lists alternatives (each ships some of its modules), so only a single missing feature is required by name.
     *
     * @param array<array<string, mixed>> $features
     * @param array<string> $missingFeatures
     * @param array<string> $missingPackages
     */
    protected function buildComposerRequire(array $features, array $missingFeatures, array $missingPackages): ?string
    {
        $packagesToRequire = count($features) === 1 && $missingFeatures !== [] ? $missingFeatures : $missingPackages;

        if ($packagesToRequire === []) {
            return null;
        }

        return static::COMPOSER_REQUIRE_COMMAND . ' ' . implode(' ', $packagesToRequire);
    }
}
