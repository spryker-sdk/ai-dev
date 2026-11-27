<?php

namespace PyzTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;
use Example\Zed\Gizmo\GizmoDependencyProvider;
use Example\Zed\Gizmo\Business\Model\Formatter;
use Example\Zed\Gizmo\Communication\Plugin\GizmoExpanderPlugin;

/**
 * mockFactoryMethod() replaces createFormatter(): GizmoFacade::format() is tested, Formatter is not.
 */
class MockFactoryMethodTest extends Unit
{
    protected $tester;

    public function testFormat(): void
    {
        $this->tester->mockFactoryMethod('createFormatter', $this->tester->createFormatterDouble());
        $this->tester->getFacade()->format(1);
    }
}
