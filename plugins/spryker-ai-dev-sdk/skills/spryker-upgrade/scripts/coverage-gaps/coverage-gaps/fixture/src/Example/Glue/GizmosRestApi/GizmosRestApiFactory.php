<?php

namespace Example\Glue\GizmosRestApi;

use Example\Glue\GizmosRestApi\Mapper\GizmoMapper;
use Example\Glue\GizmosRestApi\Processor\GizmoProcessor;
use Spryker\Glue\Kernel\AbstractFactory;

class GizmosRestApiFactory extends AbstractFactory
{
    public function createGizmoProcessor(): GizmoProcessor
    {
        return new GizmoProcessor($this->createGizmoMapper());
    }

    public function createGizmoMapper(): GizmoMapper
    {
        return new GizmoMapper();
    }
}
