<?php

namespace App\Services\Invoices;

use App\Models\Invoice;

/**
 * Przekazanie wystawionej faktury do KSeF.
 */
interface InvoiceTransmitter
{
    /**
     * Wysyła fakturę. Po powrocie faktura jest przyjęta (ksef_status = accepted) albo czeka
     * na weryfikację (pending). Odmowę zgłasza wyjątkiem — dokumentu wtedy w KSeF nie ma.
     *
     * @throws InvoiceException
     */
    public function send(Invoice $invoice): void;

    /**
     * Ponowne sprawdzenie faktury, która czekała na weryfikację.
     *
     * @throws InvoiceException gdy KSeF ją odrzucił
     */
    public function refresh(Invoice $invoice): void;
}
