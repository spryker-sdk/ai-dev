<?php

namespace Example\Yves\GizmoPage;

use Example\Client\Gizmo\GizmoClient;
use Spryker\Yves\Kernel\AbstractFactory;

class GizmoPageFactory extends AbstractFactory
{
    public function getGizmoClient(): GizmoClient
    {
        return new GizmoClient();
    }
}
