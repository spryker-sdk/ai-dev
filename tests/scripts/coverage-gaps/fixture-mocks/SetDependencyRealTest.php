<?php

namespace PyzTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;
use Example\Zed\Gizmo\GizmoDependencyProvider;
use Example\Zed\Gizmo\Business\Model\Formatter;
use Example\Zed\Gizmo\Communication\Plugin\GizmoExpanderPlugin;

/**
 * setDependency() with real objects: GizmoFacade::format() and Formatter::format() are tested.
 */
class SetDependencyRealTest extends Unit
{
    protected $tester;

    public function testFormat(): void
    {
        $this->tester->setDependency(GizmoDependencyProvider::PLUGINS_EXPANDER, [new GizmoExpanderPlugin()]);
        $this->tester->getFacade()->format(1);
    }
}
