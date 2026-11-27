<?php

namespace PyzTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;
use Example\Zed\Gizmo\GizmoDependencyProvider;
use Example\Zed\Gizmo\Business\Model\Formatter;
use Example\Zed\Gizmo\Communication\Plugin\GizmoExpanderPlugin;

/**
 * Conditional skip: GizmoFacade::format() and Formatter::format() are reachable, not proven.
 */
class ConditionalSkipTest extends Unit
{
    protected $tester;

    public function testFormat(): void
    {
        if (!getenv('GIZMO_FORMAT')) {
            $this->markTestSkipped('GIZMO_FORMAT is not set.');
        }
        $this->tester->getFacade()->format(1);
    }
}
