<?php

namespace App\Services\Ksef;

use App\Models\Invoice;
use App\Services\Invoices\InvoiceException;
use App\Services\Invoices\InvoiceNumbering;

/**
 * Numeracja z KSeF. Bez połączenia z KSeF wystawienie jest zablokowane (decyzja 4 w docs/PLAN.md).
 */
final class KsefInvoiceNumbering implements InvoiceNumbering
{
    public function next(Invoice $invoice): string
    {
        throw new InvoiceException(__('Invoice numbers are assigned from KSeF. Configure the KSeF connection first.'));
    }
}
