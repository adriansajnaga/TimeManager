<?php

namespace App\Support;

use App\Enums\Language;

/**
 * Domyślne szablony klienta w języku jego dokumentów.
 *
 * Opis pozycji faktury: {period_from}, {period_to}, {projects} (lista etykiet projektów, każda w linii „- …”).
 * E-mail: {number} (numer faktury), {date} (data wystawienia), {sender} (nadawca).
 */
class DefaultTemplates
{
    public static function invoiceDescription(Language $language): string
    {
        return match ($language) {
            Language::German => "Ausführungszeitraum: {period_from} - {period_to}\n\n{projects}",
            Language::Polish => "Okres realizacji: {period_from} - {period_to}\n\n{projects}",
            Language::English => "Period of performance: {period_from} - {period_to}\n\n{projects}",
        };
    }

    public static function emailSubject(Language $language): string
    {
        return match ($language) {
            Language::German => 'Rechnung {number}',
            Language::Polish => 'Faktura {number}',
            Language::English => 'Invoice {number}',
        };
    }

    public static function emailBody(Language $language): string
    {
        return match ($language) {
            Language::German => "Sehr geehrte Damen und Herren,\n\nanbei erhalten Sie Ihre Rechnung Nr. {number} vom {date}.\n\nMit freundlichen Grüßen,\n{sender}",
            Language::Polish => "Dzień dobry,\n\nw załączniku przesyłam fakturę nr {number} z dnia {date}.\n\nZ poważaniem,\n{sender}",
            Language::English => "Dear Sir or Madam,\n\nplease find attached invoice no. {number} dated {date}.\n\nKind regards,\n{sender}",
        };
    }
}
