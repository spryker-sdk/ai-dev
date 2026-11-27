<?php

namespace Broken\Zed\Broken\Business\Model;

// Loads without error: analysed and reported as usual.
class WorkingStep implements StepInterface
{
    public function execute(): void
    {
    }
}
