<?php

namespace Example\Zed\Gizmo\Communication\Plugin;

use Example\Zed\Gizmo\Dependency\Plugin\GizmoExpanderPluginInterface;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;

// Part of the plugin stack executed by GizmoFacade::expand() -> tested.
class GizmoExpanderPlugin extends AbstractPlugin implements GizmoExpanderPluginInterface
{
    public function expandValue(int $value): int
    {
        return $value + 1;
    }
}
