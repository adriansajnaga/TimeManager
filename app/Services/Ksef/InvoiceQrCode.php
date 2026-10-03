<?php

namespace App\Services\Ksef;

use App\Models\Invoice;

/**
 * Link weryfikacyjny faktury w KSeF (KOD I) dla kodu QR na wydruku:
 * {adres}/invoice/{NIP sprzedawcy}/{DD-MM-RRRR}/{SHA-256 XML w Base64URL}.
 */
class InvoiceQrCode
{
    public function url(Invoice $invoice): ?string
    {
        if (blank($invoice->xml) || blank($invoice->ksef_number) || $invoice->ksef_environment === null) {
            return null;
        }

        return sprintf(
            '%s/invoice/%s/%s/%s',
            $invoice->ksef_environment->qrBaseUrl(),
            preg_replace('/\D+/', '', (string) ($invoice->seller['tax_id'] ?? '')),
            $invoice->issue_date->format('d-m-Y'),
            rtrim(strtr(base64_encode(hash('sha256', (string) $invoice->xml, true)), '+/', '-_'), '='),
        );
    }
}
