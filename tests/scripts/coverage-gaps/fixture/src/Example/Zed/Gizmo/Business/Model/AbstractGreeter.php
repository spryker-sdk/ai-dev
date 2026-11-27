<?php

namespace Example\Zed\Gizmo\Business\Model;

abstract class AbstractGreeter
{
    public function greet(): string
    {
        return $this->getGreeting();
    }

    // Not tested: reported in the abstract class that declares it.
    public function shout(): string
    {
        return strtoupper($this->getGreeting()) . '!';
    }

    abstract protected function getGreeting(): string;
}
