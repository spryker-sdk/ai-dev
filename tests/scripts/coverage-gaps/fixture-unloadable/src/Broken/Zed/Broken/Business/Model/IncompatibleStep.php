<?php

namespace Broken\Zed\Broken\Business\Model;

// The signature is incompatible with StepInterface::execute(): loading the class is a compile-time fatal error.
class IncompatibleStep implements StepInterface
{
    public function execute(int $times): void
    {
    }
}
