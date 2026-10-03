<?php

namespace App\Services\Invoices;

use App\Models\Invoice;

/**
 * Numer faktury sprzedaży trafiającej do KSeF: „{nr}/{miesiąc}/{rok}”, zawsze ostatni numer z KSeF + 1.
 */
interface InvoiceNumbering
{
    /**
     * @throws InvoiceException gdy KSeF nie odpowiada — numeru nie zgadujemy
     */
    public function next(Invoice $invoice): string;
}
