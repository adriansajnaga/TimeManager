<?php

namespace App\Services\Invoices;

use App\Models\BankAccount;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Invoice;

/**
 * Kopie danych stron i rachunku zapisywane w fakturze (dokument nie zmienia się razem z kartoteką).
 */
final class Parties
{
    /**
     * @return array<string, string|null>
     */
    public static function company(): array
    {
        $company = CompanySetting::current();

        return [
            'name' => $company->name,
            'street' => $company->street,
            'zip' => $company->zip,
            'city' => $company->city,
            'country_code' => $company->country_code,
            'vat_prefix' => $company->vat_prefix,
            'tax_id' => $company->nip,
            'regon' => $company->regon,
            'email' => $company->email,
            'phone' => $company->phone,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public static function contractor(Contractor $contractor): array
    {
        return [
            'name' => $contractor->name,
            'street' => $contractor->street,
            'zip' => $contractor->zip,
            'city' => $contractor->city,
            'country_code' => $contractor->country_code,
            'vat_prefix' => $contractor->vat_prefix,
            'tax_id' => $contractor->tax_id,
            'email' => $contractor->email,
            'phone' => $contractor->phone,
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    public static function bankAccount(?BankAccount $account): ?array
    {
        return $account === null ? null : [
            'label' => $account->label,
            'iban' => $account->iban,
            'swift' => $account->swift,
        ];
    }

    /**
     * Uzupełnia sprzedawcę i nabywcę: przy sprzedaży sprzedawcą jest firma, przy zakupie — kontrahent.
     */
    public static function apply(Invoice $invoice): void
    {
        $company = self::company();
        $contractor = $invoice->contractor_id !== null ? Contractor::query()->find($invoice->contractor_id) : null;
        $counterparty = $contractor !== null ? self::contractor($contractor) : ($invoice->isSales() ? $invoice->buyer : $invoice->seller);

        $invoice->seller = $invoice->isSales() ? $company : $counterparty;
        $invoice->buyer = $invoice->isSales() ? $counterparty : $company;
        $invoice->counterparty_name = $counterparty['name'] ?? null;
        $invoice->counterparty_tax_id = self::taxId($counterparty);
    }

    /**
     * Numer podatkowy do wyświetlenia: „8792451081” albo „DE 286771111”.
     *
     * @param  array<string, string|null>|null  $party
     */
    public static function taxId(?array $party): ?string
    {
        if (blank($party['tax_id'] ?? null)) {
            return null;
        }

        $prefix = ($party['country_code'] ?? 'PL') === 'PL' ? '' : (string) ($party['vat_prefix'] ?? '');

        return trim($prefix.' '.$party['tax_id']);
    }

    /**
     * Linie adresu: ulica, kod i miasto (z krajem dla zagranicznych).
     *
     * @param  array<string, string|null>|null  $party
     * @return list<string>
     */
    public static function addressLines(?array $party): array
    {
        if ($party === null) {
            return [];
        }

        $foreign = ($party['country_code'] ?? 'PL') !== 'PL';
        $city = trim(($foreign ? ($party['country_code'] ?? '').'-' : '').($party['zip'] ?? '').' '.($party['city'] ?? ''), ' -');

        return array_values(array_filter([(string) ($party['street'] ?? ''), $city]));
    }
}
