<?php

namespace Example\Zed\Gizmo\Business\Model;

class StepImporter
{
    /**
     * @var array<\Example\Zed\Gizmo\Business\Model\StepInterface>
     */
    protected array $steps = [];

    public function addStep(StepInterface $step): void
    {
        $this->steps[] = $step;
    }

    public function import(): void
    {
        foreach ($this->steps as $step) {
            $step->execute();
        }
    }
}
