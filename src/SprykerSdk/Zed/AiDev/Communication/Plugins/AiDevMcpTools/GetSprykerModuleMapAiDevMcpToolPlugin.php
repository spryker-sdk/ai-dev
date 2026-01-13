<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use Exception;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 */
class GetSprykerModuleMapAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public bool $isCacheEnabled = true;

    protected const string CACHE_DIR = APPLICATION_ROOT_DIR . '/data/cache/module-map';

    protected const int CACHE_TTL = 3600; // 1 hour

    /**
     * @var array<string>
     */
    protected const PROJECT_PATH_PATTERNS = [
        'src/Pyz/%s/src/Pyz/Zed/%s',
        'src/Pyz/%s/src/Pyz/Client/%s',
        'src/Pyz/%s/src/Pyz/Service/%s',
        'src/Pyz/%s/src/Pyz/Shared/%s',
        'src/Pyz/%s/src/Pyz/Yves/%s',
        'src/Pyz/%s/src/Pyz/Glue/%s',
    ];

    /**
     * @var array<string>
     */
    protected const CUSTOM_NAMESPACE_LAYERS = [
        'Zed',
        'Client',
        'Service',
        'Shared',
        'Yves',
        'Glue',
    ];

    /**
     * @var array<string>
     */
    protected const VENDOR_PATH_PATTERNS = [
        'src/Spryker/%s/src/Spryker/Zed/%s',
        'src/Spryker/%s/src/Spryker/Client/%s',
        'src/Spryker/%s/src/Spryker/Service/%s',
        'src/Spryker/%s/src/Spryker/Shared/%s',
        'src/Spryker/%s/src/Spryker/Yves/%s',
        'src/Spryker/%s/src/Spryker/Glue/%s',
        'src/SprykerShop/%s/src/SprykerShop/Zed/%s',
        'src/SprykerShop/%s/src/SprykerShop/Yves/%s',
        'src/SprykerShop/%s/src/SprykerShop/Glue/%s',
        'src/SprykerEco/%s/src/SprykerEco/Zed/%s',
        'src/SprykerEco/%s/src/SprykerEco/Client/%s',
        'src/SprykerEco/%s/src/SprykerEco/Service/%s',
        'src/SprykerEco/%s/src/SprykerEco/Shared/%s',
        'src/SprykerEco/%s/src/SprykerEco/Yves/%s',
        'src/SprykerEco/%s/src/SprykerEco/Glue/%s',
        'src/SprykerSdk/%s/src/SprykerSdk/Zed/%s',
        'src/SprykerSdk/%s/src/SprykerSdk/Client/%s',
        'src/SprykerSdk/%s/src/SprykerSdk/Service/%s',
        'src/SprykerSdk/%s/src/SprykerSdk/Shared/%s',
        'src/SprykerFeature/%s/src/SprykerFeature/Zed/%s',
        'src/SprykerFeature/%s/src/SprykerFeature/Client/%s',
        'src/SprykerFeature/%s/src/SprykerFeature/Service/%s',
        'src/SprykerFeature/%s/src/SprykerFeature/Shared/%s',
        'src/SprykerFeature/%s/src/SprykerFeature/Yves/%s',
        'src/SprykerFeature/%s/src/SprykerFeature/Glue/%s',
        'src/SprykerConfig/%s/src/SprykerConfig/Zed/%s',
        'src/SprykerConfig/%s/src/SprykerConfig/Shared/%s',
        'src/SprykerMiddleware/%s/src/SprykerMiddleware/Zed/%s',
        'src/SprykerMiddleware/%s/src/SprykerMiddleware/Service/%s',
        'src/SprykerMiddleware/%s/src/SprykerMiddleware/Shared/%s',
    ];

    /**
     * @var array<string>
     */
    protected const VENDOR_DIRECTORY_PATTERNS = [
        'vendor/spryker-sdk/*/src/SprykerSdk/Zed/%s',
        'vendor/spryker-sdk/*/src/SprykerSdk/Client/%s',
        'vendor/spryker-sdk/*/src/SprykerSdk/Service/%s',
        'vendor/spryker-sdk/*/src/SprykerSdk/Shared/%s',
        'vendor/spryker-eco/*/src/SprykerEco/Zed/%s',
        'vendor/spryker-eco/*/src/SprykerEco/Client/%s',
        'vendor/spryker-eco/*/src/SprykerEco/Service/%s',
        'vendor/spryker-eco/*/src/SprykerEco/Shared/%s',
        'vendor/spryker-eco/*/src/SprykerEco/Yves/%s',
        'vendor/spryker-eco/*/src/SprykerEco/Glue/%s',
        'vendor/spryker-shop/*/src/SprykerShop/Yves/%s',
        'vendor/spryker-shop/*/src/SprykerShop/Zed/%s',
        'vendor/spryker-feature/*/src/SprykerFeature/Zed/%s',
        'vendor/spryker-feature/*/src/SprykerFeature/Client/%s',
        'vendor/spryker-feature/*/src/SprykerFeature/Service/%s',
        'vendor/spryker-feature/*/src/SprykerFeature/Shared/%s',
        'vendor/spryker-middleware/*/src/SprykerMiddleware/Zed/%s',
        'vendor/spryker-middleware/*/src/SprykerMiddleware/Service/%s',
    ];

    /**
     * @var array<string>
     */
    protected const EXTENSION_MODULE_PATTERNS = [
        'src/Spryker/%sExtension/src/Spryker/Zed/%sExtension',
        'src/SprykerEco/%sExtension/src/SprykerEco/Zed/%sExtension',
        'src/SprykerSdk/%sExtension/src/SprykerSdk/Zed/%sExtension',
        'src/SprykerFeature/%sExtension/src/SprykerFeature/Zed/%sExtension',
        'vendor/spryker/spryker/Bundles/%sExtension/src/Spryker/Zed/%sExtension',
    ];

    /**
     * @var array<string, string>
     */
    protected const FILE_PATTERNS = [
        'facades' => 'Business/*FacadeInterface.php',
        'clients' => '*ClientInterface.php',
        'services' => '*ServiceInterface.php',
        'configs' => '*Config.php',
        'pluginInterfaces' => 'Dependency/Plugin/*PluginInterface.php',
        'plugins' => 'Communication/Plugin/**/*Plugin.php',
    ];

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'getSprykerModuleMap';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Tool to get comprehensive module map with paths and FQNs for classes, interfaces, methods, configs, facades, clients, services, plugins and plugin interfaces. ' .
            'Parameter: array of module names like ["Sales", "Cart"].' .
            'Use to find|get|search module paths, facades methods, clients methods, services methods, configs methods, plugin interfaces as extension points and plugins.';
    }

    /**
     * {@inheritDoc}
     *
     * @param array $moduleNames
     *
     * @return string
     */
    public function getSprykerModuleMap(array $moduleNames): string
    {
        $results = [];

        foreach ($moduleNames as $moduleName) {
            $moduleMap = $this->buildModuleMap($moduleName);
            $results[] = $moduleMap;
        }

        $jsonResult = json_encode($results, JSON_PRETTY_PRINT);

        return $jsonResult !== false ? $jsonResult : '[]';
    }

    /**
     * @param string $moduleName
     *
     * @return array<string, mixed>
     */
    protected function buildModuleMap(string $moduleName): array
    {
        $cachedData = $this->loadFromCache($moduleName);

        if ($cachedData !== null && $this->isCacheEnabled === true) {
            return $cachedData;
        }

        $absolutePaths = $this->findAllModulePathsAbsolute($moduleName);

        $moduleMap = [
            'name' => $moduleName,
            'paths' => [],
            'facades' => [],
            'clients' => [],
            'services' => [],
            'configs' => [],
            'pluginInterfaces' => [],
            'plugins' => [],
        ];

        foreach ($absolutePaths as $path) {
            $this->extractFqnsFromPath($path, $moduleMap);
        }

        $this->combineExtensionModuleData($moduleName, $moduleMap);

        foreach ($absolutePaths as $path) {
            $moduleMap['paths'][] = $this->makePathRelative($path);
        }

        $this->saveToCache($moduleName, $moduleMap);

        return $moduleMap;
    }

    /**
     * @param string $moduleName
     *
     * @return array<string, mixed>|null
     */
    protected function loadFromCache(string $moduleName): ?array
    {
        $cacheFilePath = $this->getCacheFilePath($moduleName);

        if (!file_exists($cacheFilePath)) {
            return null;
        }

        $cacheAge = time() - filemtime($cacheFilePath);

        if ($cacheAge > static::CACHE_TTL) {
            return null;
        }

        $cachedContent = file_get_contents($cacheFilePath);

        if ($cachedContent === false) {
            return null;
        }

        $cachedData = json_decode($cachedContent, true);

        if (!is_array($cachedData)) {
            return null;
        }

        return $cachedData;
    }

    /**
     * @param string $moduleName
     * @param array<string, mixed> $moduleMap
     *
     * @return void
     */
    protected function saveToCache(string $moduleName, array $moduleMap): void
    {
        $cacheDir = static::CACHE_DIR;

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheFilePath = $this->getCacheFilePath($moduleName);
        $jsonData = json_encode($moduleMap, JSON_PRETTY_PRINT);

        file_put_contents($cacheFilePath, $jsonData);
    }

    /**
     * @param string $moduleName
     *
     * @return string
     */
    protected function getCacheFilePath(string $moduleName): string
    {
        return sprintf('%s/%s.json', static::CACHE_DIR, $moduleName);
    }

    /**
     * @param string $moduleName
     *
     * @return array<string>
     */
    protected function findAllModulePathsAbsolute(string $moduleName): array
    {
        $paths = [];

        $allPatterns = array_merge(
            static::PROJECT_PATH_PATTERNS,
            static::VENDOR_PATH_PATTERNS,
        );

        foreach ($allPatterns as $pattern) {
            $searchPattern = sprintf($pattern, $moduleName, $moduleName);
            $matches = glob(APPLICATION_ROOT_DIR . '/' . $searchPattern, 0);

            if ($matches) {
                $paths = array_merge($paths, $matches);
            }
        }

        foreach (static::VENDOR_DIRECTORY_PATTERNS as $pattern) {
            $searchPattern = sprintf($pattern, $moduleName);
            $matches = glob(APPLICATION_ROOT_DIR . '/' . $searchPattern, 0);

            if ($matches) {
                $paths = array_merge($paths, $matches);
            }
        }

        $customNamespacePaths = $this->findCustomNamespaceModulePaths($moduleName);
        $paths = array_merge($paths, $customNamespacePaths);

        return array_unique($paths);
    }

    /**
     * @param string $moduleName
     *
     * @return array<string>
     */
    protected function findCustomNamespaceModulePaths(string $moduleName): array
    {
        $paths = [];
        $excludedNamespaces = [
            'Pyz',
            'Spryker',
            'SprykerShop',
            'SprykerEco',
            'SprykerSdk',
            'SprykerFeature',
            'SprykerConfig',
            'SprykerMiddleware',
            'Orm',
            'Generated',
        ];

        foreach (static::CUSTOM_NAMESPACE_LAYERS as $layer) {
            $searchPattern = sprintf('src/*/%s/%s', $layer, $moduleName);
            $matches = glob(APPLICATION_ROOT_DIR . '/' . $searchPattern, 0);

            if (!$matches) {
                continue;
            }

            foreach ($matches as $match) {
                $pathParts = explode('/', $match);
                $srcIndex = array_search('src', $pathParts, true);

                if ($srcIndex === false || !isset($pathParts[$srcIndex + 1])) {
                    continue;
                }

                $namespace = $pathParts[$srcIndex + 1];

                if (in_array($namespace, $excludedNamespaces, true)) {
                    continue;
                }

                $paths[] = $match;
            }
        }

        return $paths;
    }

    /**
     * @param string $extensionModuleName
     *
     * @return array<string>
     */
    protected function findExtensionModulePaths(string $extensionModuleName): array
    {
        $paths = [];
        $excludedNamespaces = [
            'Pyz',
            'Spryker',
            'SprykerShop',
            'SprykerEco',
            'SprykerSdk',
            'SprykerFeature',
            'SprykerConfig',
            'SprykerMiddleware',
            'Orm',
            'Generated',
        ];

        foreach (static::EXTENSION_MODULE_PATTERNS as $pattern) {
            $searchPattern = sprintf($pattern, $extensionModuleName, $extensionModuleName);
            $matches = glob(APPLICATION_ROOT_DIR . '/' . $searchPattern, 0);

            if ($matches) {
                $paths = array_merge($paths, $matches);
            }
        }

        $customExtensionPattern = sprintf('src/*/%sExtension/src/*/Zed/%sExtension', $extensionModuleName, $extensionModuleName);
        $matches = glob(APPLICATION_ROOT_DIR . '/' . $customExtensionPattern, 0);

        if ($matches) {
            foreach ($matches as $match) {
                $pathParts = explode('/', $match);
                $srcIndex = array_search('src', $pathParts, true);

                if ($srcIndex === false || !isset($pathParts[$srcIndex + 1])) {
                    continue;
                }

                $namespace = $pathParts[$srcIndex + 1];

                if (in_array($namespace, $excludedNamespaces, true)) {
                    continue;
                }

                $paths[] = $match;
            }
        }

        $vendorExtensionPatterns = [
            sprintf('vendor/spryker/*-extension/src/Spryker/Zed/%sExtension', $extensionModuleName),
            sprintf('vendor/spryker-shop/*-extension/src/SprykerShop/Yves/%sExtension', $extensionModuleName),
            sprintf('vendor/spryker-shop/*-extension/src/SprykerShop/Zed/%sExtension', $extensionModuleName),
            sprintf('vendor/spryker-eco/*-extension/src/SprykerEco/Zed/%sExtension', $extensionModuleName),
            sprintf('vendor/spryker-sdk/*-extension/src/SprykerSdk/Zed/%sExtension', $extensionModuleName),
        ];

        foreach ($vendorExtensionPatterns as $pattern) {
            $matches = glob(APPLICATION_ROOT_DIR . '/' . $pattern, 0);

            if ($matches) {
                $paths = array_merge($paths, $matches);
            }
        }

        return array_unique($paths);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $moduleMap
     *
     * @return void
     */
    protected function extractFqnsFromPath(string $path, array &$moduleMap): void
    {
        [$namespace, $layer] = $this->extractNamespaceAndLayer($path);

        if (!$namespace || !$layer) {
            return;
        }

        $facadeFiles = glob($path . '/' . static::FILE_PATTERNS['facades']) ?: [];

        foreach ($facadeFiles as $file) {
            $moduleMap['facades'] = array_merge(
                $moduleMap['facades'],
                $this->extractFqnsWithMethods($file),
            );
        }

        $clientFiles = glob($path . '/' . static::FILE_PATTERNS['clients']) ?: [];

        foreach ($clientFiles as $file) {
            if (!$this->isRootLevelFile($file, $path)) {
                continue;
            }

            $moduleMap['clients'] = array_merge(
                $moduleMap['clients'],
                $this->extractFqnsWithMethods($file),
            );
        }

        $serviceFiles = glob($path . '/' . static::FILE_PATTERNS['services']) ?: [];

        foreach ($serviceFiles as $file) {
            if (!$this->isRootLevelFile($file, $path)) {
                continue;
            }

            $moduleMap['services'] = array_merge(
                $moduleMap['services'],
                $this->extractFqnsWithMethods($file),
            );
        }

        $configFiles = glob($path . '/' . static::FILE_PATTERNS['configs']) ?: [];

        foreach ($configFiles as $file) {
            if (!$this->isRootLevelFile($file, $path)) {
                continue;
            }

            $moduleMap['configs'] = array_merge(
                $moduleMap['configs'],
                $this->extractFqnsWithMethods($file),
            );
        }

        $pluginInterfaceFiles = glob($path . '/' . static::FILE_PATTERNS['pluginInterfaces']) ?: [];

        foreach ($pluginInterfaceFiles as $file) {
            $moduleMap['pluginInterfaces'] = array_merge(
                $moduleMap['pluginInterfaces'],
                $this->extractFqnsWithoutMethods($file),
            );
        }

        $pluginFiles = glob($path . '/' . static::FILE_PATTERNS['plugins']) ?: [];

        foreach ($pluginFiles as $file) {
            $moduleMap['plugins'] = array_merge(
                $moduleMap['plugins'],
                $this->extractFqnsWithoutMethods($file),
            );
        }
    }

    /**
     * @param string $filePath
     *
     * @return array<string>
     */
    protected function extractFqnsWithMethods(string $filePath): array
    {
        $parsedData = $this->parsePhpFile($filePath);

        if (empty($parsedData['className']) || empty($parsedData['methods'])) {
            return [];
        }

        $fqns = [];
        $baseFqn = $this->buildBaseFqn($parsedData['namespace'], $parsedData['className']);

        foreach ($parsedData['methods'] as $methodName) {
            $fqns[] = sprintf('%s::%s', $baseFqn, $methodName);
        }

        return $fqns;
    }

    /**
     * @param string $filePath
     *
     * @return array<string>
     */
    protected function extractFqnsWithoutMethods(string $filePath): array
    {
        $parsedData = $this->parsePhpFile($filePath);

        if (empty($parsedData['className'])) {
            return [];
        }

        return [$this->buildBaseFqn($parsedData['namespace'], $parsedData['className'])];
    }

    /**
     * @param string|null $namespace
     * @param string $className
     *
     * @return string
     */
    protected function buildBaseFqn(?string $namespace, string $className): string
    {
        if ($namespace === null) {
            return $className;
        }

        return sprintf('%s\\%s', $namespace, $className);
    }

    /**
     * @param string $filePath
     * @param string $basePath
     *
     * @return bool
     */
    protected function isRootLevelFile(string $filePath, string $basePath): bool
    {
        $relativePath = str_replace($basePath . '/', '', $filePath);

        return !str_contains($relativePath, '/');
    }

    /**
     * @param string $absolutePath
     *
     * @return string
     */
    protected function makePathRelative(string $absolutePath): string
    {
        $rootDir = APPLICATION_ROOT_DIR . '/';

        if (str_starts_with($absolutePath, $rootDir)) {
            return substr($absolutePath, strlen($rootDir));
        }

        return $absolutePath;
    }

    /**
     * @param string $path
     *
     * @return array<string|null>
     */
    protected function extractNamespaceAndLayer(string $path): array
    {
        if (preg_match('#/src/([^/]+)/([^/]+)/#', $path, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [null, null];
    }

    /**
     * @param string $moduleName
     * @param array<string, mixed> $moduleMap
     *
     * @return void
     */
    protected function combineExtensionModuleData(string $moduleName, array &$moduleMap): void
    {
        $extensionModuleName = $moduleName;
        $extensionPaths = $this->findExtensionModulePaths($extensionModuleName);

        foreach ($extensionPaths as $path) {
            [$namespace, $layer] = $this->extractNamespaceAndLayer($path);

            if (!$namespace || !$layer) {
                continue;
            }

            $pluginInterfaceFiles = glob($path . '/' . static::FILE_PATTERNS['pluginInterfaces']) ?: [];

            foreach ($pluginInterfaceFiles as $file) {
                $moduleMap['pluginInterfaces'] = array_merge(
                    $moduleMap['pluginInterfaces'],
                    $this->extractFqnsWithoutMethods($file),
                );
            }
        }
    }

    /**
     * @param string $filePath
     *
     * @return array<string, mixed>
     */
    protected function parsePhpFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $code = file_get_contents($filePath);

        if ($code === false) {
            return [];
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($code);
        } catch (Exception $e) {
            return [];
        }

        if ($ast === null) {
            return [];
        }

        $extractedData = [
            'namespace' => null,
            'className' => null,
            'methods' => [],
        ];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($extractedData) extends \PhpParser\NodeVisitorAbstract {
            /**
             * @param array<string, mixed> $extractedData
             */
            public function __construct(protected array &$extractedData)
            {
            }

            /**
             * @param \PhpParser\Node $node
             *
             * @return int|null
             */
            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Namespace_) {
                    if ($node->name !== null) {
                        $this->extractedData['namespace'] = $node->name->toString();
                    }
                }

                if ($node instanceof Class_ || $node instanceof Interface_) {
                    if ($node->name !== null) {
                        $this->extractedData['className'] = $node->name->toString();
                    }
                }

                if ($node instanceof ClassMethod && $node->isPublic()) {
                    $this->extractedData['methods'][] = $node->name->toString();
                }

                return null;
            }
        });

        $traverser->traverse($ast);

        return $extractedData;
    }
}
