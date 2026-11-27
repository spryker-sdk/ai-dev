<?php

namespace PyzTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;
use Example\Zed\Gizmo\GizmoDependencyProvider;
use Example\Zed\Gizmo\Business\Model\Formatter;
use Example\Zed\Gizmo\Communication\Plugin\GizmoExpanderPlugin;

/**
 * No mock: GizmoFacade::format() and Formatter::format() are tested.
 */
class PlainTest extends Unit
{
    protected $tester;

    public function testFormat(): void
    {
        $this->tester->getFacade()->format(1);
    }
}
