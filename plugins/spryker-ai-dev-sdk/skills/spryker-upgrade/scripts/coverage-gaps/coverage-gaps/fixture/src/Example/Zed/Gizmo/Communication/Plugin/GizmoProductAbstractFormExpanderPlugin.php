<?php

namespace Example\Zed\Gizmo\Communication\Plugin;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\ProductManagementExtension\Dependency\Plugin\ProductAbstractFormExpanderPluginInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;

// Form expander plugin (has a loop and a condition): reported by default, whole class skipped with --skip-ui-definitions.
class GizmoProductAbstractFormExpanderPlugin extends AbstractPlugin implements ProductAbstractFormExpanderPluginInterface
{
    public function expand(FormBuilderInterface $builder, array $options): FormBuilderInterface
    {
        foreach (array_keys($builder->all()) as $name) {
            if (str_starts_with((string)$name, 'general_')) {
                $builder->get((string)$name)->add('is_gizmo', CheckboxType::class, ['required' => false]);
            }
        }

        return $builder;
    }
}
