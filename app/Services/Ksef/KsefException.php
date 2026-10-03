<?php

namespace App\Services\Ksef;

use App\Services\Invoices\InvoiceException;

/**
 * Błąd komunikacji z KSeF albo odmowa przyjęcia dokumentu (komunikat dla użytkownika).
 */
class KsefException extends InvoiceException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
