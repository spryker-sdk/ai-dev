<?php

namespace Spryker\Zed\ProductManagementExtension\Dependency\Plugin;

use Symfony\Component\Form\FormBuilderInterface;

interface ProductAbstractFormExpanderPluginInterface
{
    public function expand(FormBuilderInterface $builder, array $options): FormBuilderInterface;
}
