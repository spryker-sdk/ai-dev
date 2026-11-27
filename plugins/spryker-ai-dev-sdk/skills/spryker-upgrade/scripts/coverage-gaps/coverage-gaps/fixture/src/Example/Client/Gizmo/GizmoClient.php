<?php

namespace Example\Client\Gizmo;

use Spryker\Client\Kernel\AbstractClient;

/**
 * @method \Example\Client\Gizmo\GizmoFactory getFactory()
 */
class GizmoClient extends AbstractClient
{
    // Only forwards to the Zed stub -> skipped (nothing to test client-side).
    public function getRemoteGizmo(): string
    {
        return $this->getFactory()->createGizmoStub()->getRemoteGizmo();
    }

    // Has a real client-side dependency -> listed as untested.
    public function readLocal(): string
    {
        return $this->getFactory()->createLocalReader()->read();
    }
}
