<?php

namespace Example\Zed\Gizmo\Business\Model;

class Calculator
{
    public function __construct(protected Rounder $rounder)
    {
    }

    public function calculate(int $value): int
    {
        return $this->rounder->round($value * 2);
    }

    // Never called anywhere -> untested + unused (Calculator is partially tested).
    public function unusedHelper(): int
    {
        return abs(-42);
    }

    // Protected helper called nowhere -> not counted for coverage, but still reported as unused.
    protected function unusedProtected(): int
    {
        return abs(-1);
    }
}
