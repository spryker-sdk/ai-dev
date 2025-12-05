<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use ReflectionClass;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

class GetTransferStructureByNamespaceAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    private const METADATA_FIELDS_TO_REMOVE = ['name_underscore', 'type_shim', 'is_strict', 'is_nullable'];

    public function getName(): string
    {
        return 'getTransferStructureByNamespace';
    }

    public function getDescription(): string
    {
        return 'Tool to get transfer structure by namespace. Uses reflection to get transfer metadata.';
    }

    public function getTransferStructureByNamespace(string $namespace): string
    {
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
