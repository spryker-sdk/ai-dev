<?php

namespace Example\Zed\Gizmo\Dependency\Plugin;

interface GizmoExpanderPluginInterface
{
    public function expandValue(int $value): int;
}
