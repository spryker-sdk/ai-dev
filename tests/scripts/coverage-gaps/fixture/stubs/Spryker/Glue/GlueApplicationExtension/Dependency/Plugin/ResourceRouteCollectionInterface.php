<?php

namespace Spryker\Glue\GlueApplicationExtension\Dependency\Plugin;

interface ResourceRouteCollectionInterface
{
    public function addGet(string $actionName, bool $isProtected = true, array $context = []);
}
