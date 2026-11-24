<?php

namespace Example\Zed\Gizmo\Business\Model;

abstract class AbstractGreeter
{
    public function greet(): string
    {
        return $this->getGreeting();
    }

    abstract protected function getGreeting(): string;
}
