<?php

namespace Example\Zed\Gizmo\Business\Model;

// Reached only through Calculator's promoted constructor property.
class Rounder
{
    public function round(int $value): int
    {
        return $value;
    }
}
