<?php

namespace PyzTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;
use Example\Zed\Gizmo\GizmoDependencyProvider;
use Example\Zed\Gizmo\Business\Model\Formatter;
use Example\Zed\Gizmo\Communication\Plugin\GizmoExpanderPlugin;

/**
 * setDependency() with a mock: GizmoFacade::format() and Formatter::format() are reachable, not proven.
 */
class SetDependencyMockTest extends Unit
{
    protected $tester;

    public function testFormat(): void
    {
        $this->tester->setDependency(GizmoDependencyProvider::PLUGINS_EXPANDER, [$this->tester->createExpanderDouble()]);
        $this->tester->getFacade()->format(1);
    }
}
