<?php

namespace Example\Zed\Gizmo\Business;

use Generated\Shared\Transfer\CartChangeTransfer;
use Generated\Shared\Transfer\QuoteTransfer;
use Spryker\Zed\Kernel\Business\AbstractFacade;

/**
 * @method \Example\Zed\Gizmo\Business\GizmoBusinessFactory getFactory()
 */
class GizmoFacade extends AbstractFacade
{
    // Tested: GizmoFacadeTest -> facade -> factory -> Calculator -> Rounder (constructor dependency).
    public function calculate(int $value): int
    {
        return $this->getFactory()->createCalculator()->calculate($value);
    }

    // Not tested: Formatter is reported with "test via GizmoFacade::format()".
    public function format(int $value): string
    {
        return $this->getFactory()->createFormatter()->format($value);
    }

    // Tested: only DefaultStep is added to this importer (setter injection is tracked per call site).
    public function runSteps(): void
    {
        $this->getFactory()->createDefaultImporter()->import();
    }

    // Not tested: OtherStep must NOT be counted as tested just because the same StepImporter class is tested.
    public function runOtherSteps(): void
    {
        $this->getFactory()->createOtherImporter()->import();
    }

    // Tested: plugin stack resolved through GizmoDependencyProvider.
    public function expand(int $value): int
    {
        return $this->getFactory()->createExpander()->expand($value);
    }

    // Tested: template method of an abstract project parent resolves to the subclass.
    public function greet(): string
    {
        return $this->getFactory()->createGreeter()->greet();
    }

    // Not tested: runs a class that extends a core class -> section 2 / entry point.
    public function addItems(CartChangeTransfer $cartChangeTransfer): QuoteTransfer
    {
        return $this->getFactory()->createStorageProvider()->addItems($cartChangeTransfer);
    }

    // Called only from tests -> unused, [tests only].
    public function legacyOnlyInTests(): string
    {
        return $this->getFactory()->createLegacy()->run();
    }
}
