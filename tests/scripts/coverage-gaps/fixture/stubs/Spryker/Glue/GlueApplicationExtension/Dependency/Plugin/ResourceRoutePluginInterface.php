<?php

namespace Spryker\Glue\GlueApplicationExtension\Dependency\Plugin;

interface ResourceRoutePluginInterface
{
    public function configure(ResourceRouteCollectionInterface $resourceRouteCollection): ResourceRouteCollectionInterface;

    public function getResourceType(): string;

    public function getController(): string;

    public function getResourceAttributesClassName(): string;
}
