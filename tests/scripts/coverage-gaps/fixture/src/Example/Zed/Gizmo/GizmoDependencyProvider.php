<?php

namespace Example\Zed\Gizmo;

use Example\Zed\Gizmo\Communication\Console\GizmoConsole;
use Example\Zed\Gizmo\Communication\Plugin\CalculatorItemExpanderPlugin;
use Example\Zed\Gizmo\Communication\Plugin\ConditionalItemExpanderPlugin;
use Example\Zed\Gizmo\Communication\Plugin\FormatterItemExpanderPlugin;
use Example\Zed\Gizmo\Communication\Plugin\GizmoExpanderPlugin;
use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;

class GizmoDependencyProvider extends AbstractBundleDependencyProvider
{
    public const PLUGINS_EXPANDER = 'PLUGINS_EXPANDER';

    public function provideBusinessLayerDependencies(Container $container): Container
    {
        $container = parent::provideBusinessLayerDependencies($container);
        $container->set(static::PLUGINS_EXPANDER, function () {
            return $this->getExpanderPlugins();
        });

        return $container;
    }

    /**
     * @return array<\Example\Zed\Gizmo\Dependency\Plugin\GizmoExpanderPluginInterface>
     */
    protected function getExpanderPlugins(): array
    {
        return [new GizmoExpanderPlugin()];
    }

    /**
     * Registrations (what a project DependencyProvider of another module would do).
     *
     * @return array<object>
     */
    protected function getRegisteredPlugins(): array
    {
        return [
            new CalculatorItemExpanderPlugin(),
            new FormatterItemExpanderPlugin(),
            new ConditionalItemExpanderPlugin(),
            new GizmoConsole(),
        ];
    }
}
