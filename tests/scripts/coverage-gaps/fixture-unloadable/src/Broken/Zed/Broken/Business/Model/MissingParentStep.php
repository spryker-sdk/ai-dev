<?php

namespace Broken\Zed\Broken\Business\Model;

use Spryker\Zed\Missing\Business\Model\RemovedStep;

// The core parent does not exist: loading the class throws "Class not found".
class MissingParentStep extends RemovedStep
{
    public function execute(): void
    {
    }
}
