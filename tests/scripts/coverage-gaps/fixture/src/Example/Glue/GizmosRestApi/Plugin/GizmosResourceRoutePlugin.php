<?php

namespace Example\Glue\GizmosRestApi\Plugin;

use Spryker\Glue\GlueApplicationExtension\Dependency\Plugin\ResourceRouteCollectionInterface;
use Spryker\Glue\GlueApplicationExtension\Dependency\Plugin\ResourceRoutePluginInterface;
use Spryker\Glue\Kernel\AbstractPlugin;

// Glue resource route plugin (routing configuration) -> whole class skipped.
class GizmosResourceRoutePlugin extends AbstractPlugin implements ResourceRoutePluginInterface
{
    public function configure(ResourceRouteCollectionInterface $resourceRouteCollection): ResourceRouteCollectionInterface
    {
        $resourceRouteCollection->addGet('getAction', true);

        return $resourceRouteCollection;
    }

    public function getResourceType(): string
    {
        return 'gizmos';
    }

    public function getController(): string
    {
        return 'gizmos-resource';
    }

    public function getResourceAttributesClassName(): string
    {
        return 'Generated\Shared\Transfer\RestGizmosAttributesTransfer';
    }
}
