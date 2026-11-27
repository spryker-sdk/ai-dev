<?php

namespace Example\Zed\Gizmo\Communication\Plugin;

use Generated\Shared\Transfer\ProductAbstractTransfer;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\ProductManagementExtension\Dependency\Plugin\ProductAbstractFormDataProviderExpanderPluginInterface;

// Form data provider expander (loads data, not fields): always reported, also with --skip-ui-definitions.
class GizmoProductAbstractFormDataProviderExpanderPlugin extends AbstractPlugin implements ProductAbstractFormDataProviderExpanderPluginInterface
{
    public function expand(array $formData, ProductAbstractTransfer $productAbstractTransfer): array
    {
        $formData['is_gizmo'] = $productAbstractTransfer->getSku() !== null;

        return $formData;
    }
}
