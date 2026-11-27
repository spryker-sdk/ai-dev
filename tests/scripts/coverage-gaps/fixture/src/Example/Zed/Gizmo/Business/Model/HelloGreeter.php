<?php

namespace Example\Zed\Gizmo\Business\Model;

// getGreeting() is only called from the abstract parent -> tested and not unused.
class HelloGreeter extends AbstractGreeter
{
    protected function getGreeting(): string
    {
        return ucfirst('hello');
    }
}
