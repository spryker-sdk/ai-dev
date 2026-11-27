<?php

namespace Example\Zed\Gizmo\Business\Model;

class Expander
{
    /**
     * @var array<\Example\Zed\Gizmo\Dependency\Plugin\GizmoExpanderPluginInterface>
     */
    protected array $expanderPlugins;

    /**
     * @param array<\Example\Zed\Gizmo\Dependency\Plugin\GizmoExpanderPluginInterface> $expanderPlugins
     */
    public function __construct(array $expanderPlugins)
    {
        $this->expanderPlugins = $expanderPlugins;
    }

    public function expand(int $value): int
    {
        foreach ($this->expanderPlugins as $expanderPlugin) {
            $value = $expanderPlugin->expandValue($value);
        }

        return $value;
    }
}
