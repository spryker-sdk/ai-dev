<?php

namespace Example\Glue\GizmosRestApi\Controller;

use Spryker\Glue\Kernel\Controller\AbstractController;

/**
 * @method \Example\Glue\GizmosRestApi\GizmosRestApiFactory getFactory()
 */
// Only reachable over HTTP: classes behind it get a unit-test suggestion instead of "test via the controller".
class GizmosResourceController extends AbstractController
{
    public function getAction(): array
    {
        return $this->executeGetAction(1);
    }

    // A controller helper is skipped on the path: the suggestion is the processor, not this method.
    protected function executeGetAction(int $id): array
    {
        return $this->getFactory()->createGizmoProcessor()->process($id);
    }
}
