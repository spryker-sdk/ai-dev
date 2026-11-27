<?php

namespace PyzTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;
use Example\Zed\Gizmo\GizmoDependencyProvider;
use Example\Zed\Gizmo\Business\Model\Formatter;
use Example\Zed\Gizmo\Communication\Plugin\GizmoExpanderPlugin;

/**
 * setFactory() replaces the whole factory: GizmoFacade::format() is tested, Formatter is not.
 */
class SetFactoryTest extends Unit
{
    protected $tester;

    public function testFormat(): void
    {
        $facade = $this->tester->getFacade();
        $facade->setFactory($this->tester->createFactoryDouble());
        $facade->format(1);
    }
}
