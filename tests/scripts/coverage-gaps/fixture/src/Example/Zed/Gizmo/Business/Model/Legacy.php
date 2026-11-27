<?php

namespace Example\Zed\Gizmo\Business\Model;

// Only reachable from GizmoFacade::legacyOnlyInTests() -> unused, [tests only].
class Legacy
{
    public function run(): string
    {
        return implode(',', ['legacy']);
    }
}
