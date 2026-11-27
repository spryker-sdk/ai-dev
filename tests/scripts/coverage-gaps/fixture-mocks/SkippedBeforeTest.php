<?php

namespace PyzTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;
use Example\Zed\Gizmo\GizmoDependencyProvider;
use Example\Zed\Gizmo\Business\Model\Formatter;
use Example\Zed\Gizmo\Communication\Plugin\GizmoExpanderPlugin;

/**
 * Skipped before the call: nothing is tested.
 */
class SkippedBeforeTest extends Unit
{
    protected $tester;

    public function testFormat(): void
    {
        $this->markTestSkipped('Not ready.');
        $this->tester->getFacade()->format(1);
    }
}
