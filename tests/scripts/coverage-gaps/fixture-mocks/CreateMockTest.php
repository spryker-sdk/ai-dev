<?php

namespace PyzTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;
use Example\Zed\Gizmo\GizmoDependencyProvider;
use Example\Zed\Gizmo\Business\Model\Formatter;
use Example\Zed\Gizmo\Communication\Plugin\GizmoExpanderPlugin;

/**
 * A mock of the project class Formatter: GizmoFacade::format() is tested, Formatter is not.
 */
class CreateMockTest extends Unit
{
    protected $tester;

    public function testFormat(): void
    {
        $formatter = $this->createMock(Formatter::class);
        $this->tester->getFacade()->format(1);
    }
}
