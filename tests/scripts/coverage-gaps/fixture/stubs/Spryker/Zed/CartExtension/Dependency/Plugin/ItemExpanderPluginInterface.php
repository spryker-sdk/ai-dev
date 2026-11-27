<?php

namespace Spryker\Zed\CartExtension\Dependency\Plugin;

use Generated\Shared\Transfer\CartChangeTransfer;

interface ItemExpanderPluginInterface
{
    public function expandItems(CartChangeTransfer $cartChangeTransfer): CartChangeTransfer;
}
