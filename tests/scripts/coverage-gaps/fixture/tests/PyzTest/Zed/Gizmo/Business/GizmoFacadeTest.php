<?php

namespace PyzTest\Zed\Gizmo\Business;

use Codeception\Test\Unit;
use Example\Zed\Gizmo\Business\Model\Formatter;

/**
 * Parsed by the analyser only, never executed.
 */
class GizmoFacadeTest extends Unit
{
    protected $tester;

    public function testCalculate(): void
    {
        $this->assertSame(2, $this->tester->getFacade()->calculate(1));
    }

    public function testRunSteps(): void
    {
        $this->tester->getFacade()->runSteps();
    }

    public function testExpand(): void
    {
        $this->assertSame(2, $this->tester->getFacade()->expand(1));
    }

    public function testGreet(): void
    {
        $facade = $this->tester->getFacade();

        $this->assertSame('hello', $facade->greet());
    }

    public function testLegacy(): void
    {
        $this->assertSame('legacy', $this->tester->getFacade()->legacyOnlyInTests());
    }

    public function testMockedFormatterDoesNotCount(): void
    {
        $formatter = $this->createMock(Formatter::class);
        $formatter->format(1);
    }
}
