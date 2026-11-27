<?php

namespace Spryker\Zed\Cart\Business\StorageProvider;

use Generated\Shared\Transfer\CartChangeTransfer;
use Generated\Shared\Transfer\QuoteTransfer;

class NonPersistentProvider
{
    public function addItems(CartChangeTransfer $cartChangeTransfer): QuoteTransfer
    {
        return $cartChangeTransfer->getQuote();
    }
}
