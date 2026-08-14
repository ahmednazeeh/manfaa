<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Models\Merchant;
use DomainException;

/**
 * The (merchant_id, invoice_no) unique constraint fired — the same sale is
 * already recorded (§11, the defence against retry loops and double entry).
 */
final class DuplicateInvoiceException extends DomainException
{
    public static function for(Merchant $merchant, string $invoiceNo): self
    {
        return new self(sprintf(
            'Invoice %s is already recorded for merchant %s.',
            $invoiceNo,
            $merchant->name,
        ));
    }
}
