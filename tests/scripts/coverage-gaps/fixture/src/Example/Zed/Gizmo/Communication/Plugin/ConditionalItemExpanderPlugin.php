<?php

namespace Example\Zed\Gizmo\Communication\Plugin;

use Generated\Shared\Transfer\CartChangeTransfer;
use Spryker\Zed\CartExtension\Dependency\Plugin\ItemExpanderPluginInterface;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;

/**
 * @method \Example\Zed\Gizmo\Business\GizmoFacade getFacade()
 */
// Delegates to a tested method but has own logic (if) -> still listed.
class ConditionalItemExpanderPlugin extends AbstractPlugin implements ItemExpanderPluginInterface
{
    public function expandItems(CartChangeTransfer $cartChangeTransfer): CartChangeTransfer
    {
        if ($cartChangeTransfer->getItems()->count() > 0) {
            $this->getFacade()->calculate(1);
        }

        return $cartChangeTransfer;
    }
}
