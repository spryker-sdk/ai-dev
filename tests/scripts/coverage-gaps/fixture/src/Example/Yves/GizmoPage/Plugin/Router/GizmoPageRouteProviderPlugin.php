<?php

namespace Example\Yves\GizmoPage\Plugin\Router;

use Spryker\Yves\Router\Route\Route;
use Spryker\Yves\Router\Route\RouteCollection;
use Spryker\Yves\RouterExtension\Dependency\Plugin\RouteProviderPluginInterface;

// Yves route provider (routing configuration) -> whole class skipped.
class GizmoPageRouteProviderPlugin implements RouteProviderPluginInterface
{
    public function addRoutes(RouteCollection $routeCollection): RouteCollection
    {
        $routeCollection->add('gizmo', new Route('/gizmo'));

        return $routeCollection;
    }
}
