<?php

namespace Spryker\Zed\ProductManagementExtension\Dependency\Plugin;

use Generated\Shared\Transfer\ProductAbstractTransfer;

interface ProductAbstractFormDataProviderExpanderPluginInterface
{
    public function expand(array $formData, ProductAbstractTransfer $productAbstractTransfer): array;
}
