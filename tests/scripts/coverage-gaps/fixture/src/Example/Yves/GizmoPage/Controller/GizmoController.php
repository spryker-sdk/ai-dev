<?php

namespace Example\Yves\GizmoPage\Controller;

use SprykerShop\Yves\ShopApplication\Controller\AbstractController;

/**
 * @method \Example\Yves\GizmoPage\GizmoPageFactory getFactory()
 */
// The only production caller of GizmoClient::readLocal(). With --skip-layer=Yves that method and LocalReader become unused.
class GizmoController extends AbstractController
{
    public function indexAction(): array
    {
        return ['gizmo' => $this->getFactory()->getGizmoClient()->readLocal()];
    }
}
