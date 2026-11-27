<?php

namespace ExampleExtra\Zed\Extra\Business;

use Spryker\Zed\Kernel\Business\AbstractFacade;

// Lives in a second project dir (src/ExampleExtra) -> scanned too.
class ExtraFacade extends AbstractFacade
{
    public function ping(): string
    {
        return strrev('gnop');
    }
}
