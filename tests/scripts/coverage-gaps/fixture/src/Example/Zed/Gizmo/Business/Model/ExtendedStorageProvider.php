<?php

namespace Example\Zed\Gizmo\Business\Model;

use Generated\Shared\Transfer\CartChangeTransfer;
use Generated\Shared\Transfer\QuoteTransfer;
use Spryker\Zed\Cart\Business\StorageProvider\NonPersistentProvider;

// Extends a core class, not tested -> section 2, test via GizmoFacade::addItems().
class ExtendedStorageProvider extends NonPersistentProvider
{
    public function addItems(CartChangeTransfer $cartChangeTransfer): QuoteTransfer
    {
        return parent::addItems($cartChangeTransfer);
    }
}
