<?php

namespace Example\Client\Gizmo\Reader;

class LocalReader
{
    public function read(): string
    {
        return strtolower('LOCAL');
    }
}
