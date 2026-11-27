<?php

namespace Example\Glue\GizmoApplication\Bootstrap;

// Application bootstrap (*Bootstrap) -> whole class skipped.
class GizmoBootstrap
{
    protected float $startedAt = 0.0;

    public function boot(): void
    {
        $this->startedAt = microtime(true);
    }
}
