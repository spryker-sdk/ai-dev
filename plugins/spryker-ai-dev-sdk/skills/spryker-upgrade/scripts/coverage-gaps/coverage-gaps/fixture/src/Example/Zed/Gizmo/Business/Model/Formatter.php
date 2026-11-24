<?php

namespace Example\Zed\Gizmo\Business\Model;

// Only a mock of it is used in the tests -> untested.
class Formatter
{
    public function format(int $value): string
    {
        return $this->pad((string)$value);
    }

    // Pure protected helper (no override) -> not counted; it is reached through format().
    protected function pad(string $value): string
    {
        return str_pad($value, 2, '0', STR_PAD_LEFT);
    }
}
