<?php

namespace Example\Client\Gizmo;

use Example\Client\Gizmo\Reader\LocalReader;
use Example\Client\Gizmo\Zed\GizmoStub;
use Spryker\Client\Kernel\AbstractFactory;

class GizmoFactory extends AbstractFactory
{
    public function createGizmoStub(): GizmoStub
    {
        return new GizmoStub();
    }

    public function createLocalReader(): LocalReader
    {
        return new LocalReader();
    }
}
