<?php

namespace Example\Zed\Gizmo\Business\Model;

use Generated\Shared\Transfer\QuoteTransfer;

class MandatoryColumns
{
    protected const COLUMN_NAME = 'name';

    protected const PRICE_MODE = 'GROSS_MODE';

    // Declarative (array of constants) -> skipped.
    public function getMandatoryColumns(): array
    {
        return ['sku', static::COLUMN_NAME];
    }

    // Declarative (transfer built from constants with fluent setters) -> skipped.
    public function getDefaultQuote(): QuoteTransfer
    {
        return (new QuoteTransfer())->setCartNote('default')->setPriceMode(static::PRICE_MODE);
    }

    // Real logic -> reported.
    public function isSatisfiedBy(array $row): bool
    {
        return !array_diff($this->getMandatoryColumns(), array_keys($row));
    }
}
