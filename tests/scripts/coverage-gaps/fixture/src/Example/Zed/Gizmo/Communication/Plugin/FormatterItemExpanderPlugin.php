<?php

namespace Example\Zed\Gizmo\Communication\Plugin;

use Generated\Shared\Transfer\CartChangeTransfer;
use Spryker\Zed\CartExtension\Dependency\Plugin\ItemExpanderPluginInterface;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;

/**
 * @method \Example\Zed\Gizmo\Business\GizmoFacade getFacade()
 */
// Thin plugin delegating to the untested GizmoFacade::format() -> still listed.
class FormatterItemExpanderPlugin extends AbstractPlugin implements ItemExpanderPluginInterface
{
    public function expandItems(CartChangeTransfer $cartChangeTransfer): CartChangeTransfer
    {
        $this->getFacade()->format(1);

        return $cartChangeTransfer;
    }
}
