<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use ReflectionClass;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

/**
 * @method \SprykerSdk\Zed\AiDev\Business\AiDevFacadeInterface getFacade()
 * @method \SprykerSdk\Zed\AiDev\Communication\AiDevCommunicationFactory getFactory()
 * @method \SprykerSdk\Zed\AiDev\AiDevConfig getConfig()
 */
class GetTransferStructureByNameAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    /**
     * @var string
     */
    private const TRANSFER_SUFFIX = 'Transfer';

    /**
     * @var string
     */
    private const TRANSFER_NAMESPACE_PREFIX = 'Generated\\Shared\\Transfer\\';

    /**
     * @var array
     */
    private const METADATA_FIELDS_TO_REMOVE = ['name_underscore', 'type_shim', 'is_strict', 'is_nullable'];

    public function getName(): string
    {
        return 'getTransferStructureByName';
    }

    public function getDescription(): string
    {
        return 'Tool to get transfer structure by name. Uses reflection to get transfer metadata.';
    }

    public function getTransferStructureByName(string $name): string
    {
        $name = $this->ensureTransferSuffix($name);
        $namespace = static::TRANSFER_NAMESPACE_PREFIX . $name;

        if (!class_exists($namespace)) {
            return 'Class not found';
        }

        $reflectionClass = new ReflectionClass($namespace);
        $metadata = $reflectionClass->getProperty('transferMetadata')->getDefaultValue();

        if (!is_array($metadata)) {
            return 'Transfer metadata is not an array';
        }

        $cleanedMetadata = $this->cleanMetadata($metadata);

        return json_encode([
            'class' => $reflectionClass->getShortName(),
            'namespace' => $namespace,
            'properties' => $cleanedMetadata,
        ], JSON_PRETTY_PRINT);
    }

    private function ensureTransferSuffix(string $name): string
    {
        if (strcasecmp(substr($name, -strlen(static::TRANSFER_SUFFIX)), static::TRANSFER_SUFFIX) !== 0) {
            return $name . static::TRANSFER_SUFFIX;
        }

        return $name;
    }

    /**
     * @param array<int|string, mixed> $metadata
     *
     * @return array<int|string, mixed>
     */
    private function cleanMetadata(array $metadata): array
    {
        foreach ($metadata as &$value) {
            foreach (static::METADATA_FIELDS_TO_REMOVE as $field) {
                unset($value[$field]);
            }
        }

        return $metadata;
    }
}
