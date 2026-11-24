<?php

namespace ExampleTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;

/**
 * Parsed by the analyser only, never executed. A second test namespace next to PyzTest: it calls a facade method
 * that the PyzTest test also calls, so it changes no expected result.
 */
class GizmoFacadeCalculateTest extends Unit
{
    protected $tester;

    public function testCalculate(): void
    {
        $this->assertSame(2, $this->tester->getFacade()->calculate(1));
    }
}
