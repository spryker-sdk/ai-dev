<?php

namespace Example\Glue\GizmosRestApi\Mapper;

// Behind the processor -> "unit test of GizmoProcessor::process() - it runs this class".
class GizmoMapper
{
    public function map(int $id): array
    {
        return ['id' => $id * 1];
    }
}
