<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Communication\Plugins\AiDevMcpTools;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use SprykerSdk\Zed\AiDev\Dependency\AiDevMcpToolPluginInterface;

class GetInterfaceMethodsAiDevMcpToolPlugin extends AbstractPlugin implements AiDevMcpToolPluginInterface
{
    public function getName(): string
    {
        return 'getInterfaceMethodsByNamespace';
    }

    public function getDescription(): string
    {
        return 'Tool to retrieve all method signatures, parameters, return types, and PhpDoc for a given interface FQN.';
    }

    public function getInterfaceMethodsByNamespace(string $namespace): string
    {
        if (!interface_exists($namespace)) {
            return $this->buildErrorResponse('Interface not found or is not an interface.', $namespace);
        }

        $reflectionInterface = new ReflectionClass($namespace);
        $methodsData = $this->extractPublicMethods($reflectionInterface);

        return json_encode([
            'interface' => $reflectionInterface->getShortName(),
            'namespace' => $namespace,
            'methods' => $methodsData,
        ], JSON_PRETTY_PRINT);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractPublicMethods(ReflectionClass $reflectionInterface): array
    {
        $methodsData = [];

        foreach ($reflectionInterface->getMethods() as $reflectionMethod) {
            if ($reflectionMethod->isPublic()) {
                $methodsData[] = $this->getMethodStructure($reflectionMethod);
            }
        }

        return $methodsData;
    }

    private function buildErrorResponse(string $message, string $namespace = ''): string
    {
        $response = [
            'status' => 'error',
            'message' => $message,
        ];

        if ($namespace !== '') {
            $response['namespace'] = $namespace;
        }

        return json_encode($response, JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getMethodStructure(ReflectionMethod $reflectionMethod): array
    {
        $docComment = $reflectionMethod->getDocComment();
        $docDescription = $this->extractDocDescription($docComment);

        $returnType = $reflectionMethod->getReturnType();
        $returnTypeName = 'void';
        if ($returnType !== null) {
            $returnTypeName = $this->getTypeName($returnType);
        }

        $parameters = [];
        foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
            $parameters[] = $this->getParameterStructure($reflectionParameter);
        }

        return [
            'name' => $reflectionMethod->getName(),
            'description' => $docDescription,
            'return_type' => $returnTypeName,
            'parameters' => $parameters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getParameterStructure(ReflectionParameter $reflectionParameter): array
    {
        $type = $reflectionParameter->getType();
        $typeName = 'mixed';
        if ($type !== null) {
            $typeName = $this->getTypeName($type);
        }

        $defaultValue = null;
        if ($reflectionParameter->isDefaultValueAvailable()) {
            $defaultValue = $reflectionParameter->getDefaultValue();
            if (is_array($defaultValue)) {
                $defaultValue = '[]';
            } elseif (is_string($defaultValue)) {
                $defaultValue = '"' . $defaultValue . '"';
            } elseif ($defaultValue === null) {
                $defaultValue = 'null';
            }
        }

        return [
            'name' => '$' . $reflectionParameter->getName(),
            'type' => $typeName,
            'is_optional' => $reflectionParameter->isOptional(),
            'default' => $defaultValue,
        ];
    }

    protected function getTypeName(ReflectionType $type): string
    {
        $typeName = '';

        if ($type instanceof ReflectionUnionType) {
            $types = array_map(fn ($t) => $t->getName(), $type->getTypes());
            $typeName = implode('|', $types);
        } elseif ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
        }

        if ($type->allowsNull() && !str_starts_with($typeName, '?')) {
            $typeName = '?' . $typeName;
        }

        return $typeName;
    }

    protected function extractDocDescription(string|false $docComment): string
    {
        if (!$docComment) {
            return '';
        }

        $lines = explode("\n", $docComment);
        $description = '';
        $inDescriptionBlock = false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Remove leading ' * ' or '/**'
            if (str_starts_with($line, '/**')) {
                $inDescriptionBlock = true;

                continue;
            }
            if (str_ends_with($line, '*/')) {
                break;
            }

            $line = preg_replace('/^\s*\* ?/', '', $line);

            // Stop when we hit the first @tag
            if (str_starts_with($line, '@')) {
                break;
            }

            if ($inDescriptionBlock && $line !== '') {
                $description .= ($description !== '' ? ' ' : '') . $line;
            }
        }

        return trim($description);
    }
}
