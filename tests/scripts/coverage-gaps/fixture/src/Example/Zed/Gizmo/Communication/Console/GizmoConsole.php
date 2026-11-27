<?php

namespace Example\Zed\Gizmo\Communication\Console;

use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \Example\Zed\Gizmo\Business\GizmoFacade getFacade()
 */
// The production caller of the facade (execute() is called by the framework).
class GizmoConsole extends Console
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->getFacade()->calculate(1);
        $this->getFacade()->format(1);
        $this->getFacade()->runSteps();
        $this->getFacade()->runOtherSteps();
        $this->getFacade()->expand(1);
        $this->getFacade()->greet();

        return static::CODE_SUCCESS;
    }
}
