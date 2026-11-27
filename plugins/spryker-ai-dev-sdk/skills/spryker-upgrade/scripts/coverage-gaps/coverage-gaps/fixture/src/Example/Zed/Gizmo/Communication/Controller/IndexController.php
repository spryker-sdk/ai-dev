<?php

namespace Example\Zed\Gizmo\Communication\Controller;

use Generated\Shared\Transfer\CartChangeTransfer;
use Spryker\Zed\Kernel\Communication\Controller\AbstractController;

/**
 * @method \Example\Zed\Gizmo\Business\GizmoFacade getFacade()
 */
// Controllers are excluded from the report, but they are production callers (addItems()).
class IndexController extends AbstractController
{
    public function indexAction(): array
    {
        $this->getFacade()->addItems(new CartChangeTransfer());

        return [];
    }
}
