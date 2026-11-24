<?php

namespace Example\Glue\GizmosRestApi\Processor;

use Example\Glue\GizmosRestApi\Mapper\GizmoMapper;

// Called by the controller -> "unit test of this class", created with GizmosRestApiFactory::createGizmoProcessor().
class GizmoProcessor
{
    public function __construct(protected GizmoMapper $mapper)
    {
    }

    public function process(int $id): array
    {
        return $this->mapper->map($id);
    }
}
