<?php

namespace App\Services\Ksef;

use App\Enums\InvoiceKind;
use App\Enums\VatCode;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Invoices\InvoiceException;
use App\Services\Invoices\VatSummary;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DOMDocument;
use DOMElement;

/**
 * XML faktury w schemie FA(3). Kolejność elementów wynika z XSD (resources/ksef/fa3/schemat.xsd);
 * wzorem były faktury z Aplikacji Podatnika KSeF (krajowa 23% i Gärtner z nabywcą z UE).
 */
class Fa3InvoiceBuilder
{
    public const NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    /** Kody państw UE dla KodUE (Grecja = EL). */
    private const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];

    private DOMDocument $document;

    public function build(Invoice $invoice): string
    {
        $invoice->loadMissing(['items', 'advances', 'correctedInvoice']);

        if (blank($invoice->seller['tax_id'] ?? null) || blank($invoice->number)) {
            throw new InvoiceException(__('The invoice needs a number and the seller\'s NIP before it goes to KSeF.'));
        }

        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;

        $root = $this->document->createElementNS(self::NAMESPACE, 'Faktura');
        $this->document->appendChild($root);

        $this->header($root);
        $this->seller($root, $invoice);
        $this->buyer($root, $invoice);
        $this->fa($root, $invoice);
        $this->footer($root, $invoice);

        return (string) $this->document->saveXML();
    }

    private function header(DOMElement $root): void
    {
        $header = $this->child($root, 'Naglowek');

        $formCode = $this->child($header, 'KodFormularza', 'FA');
        $formCode->setAttribute('kodSystemowy', 'FA (3)');
        $formCode->setAttribute('wersjaSchemy', '1-0E');

        $this->child($header, 'WariantFormularza', '3');
        $this->child($header, 'DataWytworzeniaFa', now()->utc()->format('Y-m-d\TH:i:s.v\Z'));
        $this->child($header, 'SystemInfo', (string) config('app.name'));
    }

    private function seller(DOMElement $root, Invoice $invoice): void
    {
        $party = (array) $invoice->seller;
        $seller = $this->child($root, 'Podmiot1');
        $this->child($seller, 'PrefiksPodatnika', 'PL');

        $identity = $this->child($seller, 'DaneIdentyfikacyjne');
        $this->child($identity, 'NIP', $this->digits($party['tax_id'] ?? null));
        $this->child($identity, 'Nazwa', (string) ($party['name'] ?? ''));

        $this->address($seller, $party);

        $email = trim((string) ($party['email'] ?? ''));
        $phone = preg_replace('/[^\d+]/', '', (string) ($party['phone'] ?? '')) ?? '';

        if ($email !== '' || $phone !== '') {
            $contact = $this->child($seller, 'DaneKontaktowe');
            $this->optional($contact, 'Email', $email);
            $this->optional($contact, 'Telefon', strlen($phone) <= 16 ? $phone : '');
        }
    }

    private function buyer(DOMElement $root, Invoice $invoice): void
    {
        $party = (array) $invoice->buyer;
        $buyer = $this->child($root, 'Podmiot2');
        $identity = $this->child($buyer, 'DaneIdentyfikacyjne');

        $country = strtoupper((string) ($party['country_code'] ?? 'PL'));
        $taxId = preg_replace('/[^0-9A-Za-z]/', '', (string) ($party['tax_id'] ?? '')) ?? '';

        if ($taxId === '') {
            $this->child($identity, 'BrakID', '1');
        } elseif ($country === 'PL') {
            $this->child($identity, 'NIP', $this->digits($taxId));
        } elseif (in_array($euCode = $this->euCode($party), self::EU, true)) {
            $this->child($identity, 'KodUE', $euCode);
            $this->child($identity, 'NrVatUE', $taxId);
        } else {
            $this->child($identity, 'KodKraju', $country);
            $this->child($identity, 'NrID', $taxId);
        }

        $this->child($identity, 'Nazwa', (string) ($party['name'] ?? ''));
        $this->address($buyer, $party);

        // Nabywca nie jest jednostką samorządu ani członkiem grupy VAT.
        $this->child($buyer, 'JST', '2');
        $this->child($buyer, 'GV', '2');
    }

    /**
     * @param  array<string, string|null>  $party
     */
    private function address(DOMElement $parent, array $party): void
    {
        $address = $this->child($parent, 'Adres');
        $this->child($address, 'KodKraju', strtoupper((string) ($party['country_code'] ?? 'PL')));
        $this->child($address, 'AdresL1', trim((string) ($party['street'] ?? '')) ?: '-');
        $this->optional($address, 'AdresL2', trim(($party['zip'] ?? '').' '.($party['city'] ?? '')));
    }

    private function fa(DOMElement $root, Invoice $invoice): void
    {
        $fa = $this->child($root, 'Fa');
        $summary = $invoice->summary();
        $foreign = $invoice->currency !== 'PLN';

        $this->child($fa, 'KodWaluty', $invoice->currency);
        $this->child($fa, 'P_1', $invoice->issue_date->toDateString());
        $this->optional($fa, 'P_1M', (string) $invoice->issue_place);
        $this->child($fa, 'P_2', (string) $invoice->number);

        if ($invoice->sale_date !== null) {
            $this->child($fa, 'P_6', $invoice->sale_date->toDateString());
        }

        $this->sums($fa, $invoice, $summary, $foreign);
        $this->child($fa, 'P_15', $this->amount($summary->gross()));

        if ($invoice->kind === InvoiceKind::Advance && $foreign && $invoice->exchange_rate !== null) {
            $this->child($fa, 'KursWalutyZ', $this->quantity($invoice->exchange_rate));
        }

        $this->annotations($fa, $invoice);
        $this->child($fa, 'RodzajFaktury', $this->kindCode($invoice));

        if ($invoice->kind === InvoiceKind::Correction) {
            $this->correction($fa, $invoice);
        }

        if ($invoice->kind === InvoiceKind::Final) {
            foreach ($invoice->advances as $advance) {
                $reference = $this->child($fa, 'FakturaZaliczkowa');

                if (filled($advance->ksef_number)) {
                    $this->child($reference, 'NrKSeFFaZaliczkowej', (string) $advance->ksef_number);
                } else {
                    $this->child($reference, 'NrKSeFZN', '1');
                    $this->child($reference, 'NrFaZaliczkowej', (string) $advance->number);
                }
            }
        }

        if ($invoice->kind !== InvoiceKind::Advance) {
            $position = 1;

            foreach ($invoice->items->sortBy([['is_before', 'desc'], ['position', 'asc']]) as $item) {
                $this->line($fa, $item, $position++, $invoice);
            }
        }

        $this->payment($fa, $invoice);

        if ($invoice->kind === InvoiceKind::Advance) {
            $this->order($fa, $invoice);
        }
    }

    private function sums(DOMElement $fa, Invoice $invoice, VatSummary $summary, bool $foreign): void
    {
        foreach ($summary->rows() as $row) {
            [$netField, $vatField, $vatPlnField] = $this->sumFields($row['code']);
            $this->child($fa, $netField, $this->amount($row['net']));

            if ($vatField !== null) {
                $this->child($fa, $vatField, $this->amount($row['vat']));

                if ($foreign && $vatPlnField !== null) {
                    $pln = $invoice->toPln($row['vat']);

                    if ($pln === null) {
                        throw new InvoiceException(__('Foreign currency invoices with VAT need the exchange rate (VAT is shown in PLN).'));
                    }

                    $this->child($fa, $vatPlnField, $this->amount($pln));
                }
            }
        }
    }

    /**
     * Pola sum dla stawki: [netto, VAT, VAT w PLN].
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function sumFields(VatCode $code): array
    {
        return match ($code) {
            VatCode::Rate23 => ['P_13_1', 'P_14_1', 'P_14_1W'],
            VatCode::Rate8 => ['P_13_2', 'P_14_2', 'P_14_2W'],
            VatCode::Rate5 => ['P_13_3', 'P_14_3', 'P_14_3W'],
            VatCode::ZeroDomestic => ['P_13_6_1', null, null],
            VatCode::ZeroIntraEu => ['P_13_6_2', null, null],
            VatCode::ZeroExport => ['P_13_6_3', null, null],
            VatCode::Exempt => ['P_13_7', null, null],
            VatCode::OutsideScope => ['P_13_8', null, null],
            VatCode::OutsideScopeEuServices => ['P_13_9', null, null],
            VatCode::ReverseCharge => ['P_13_10', null, null],
        };
    }

    private function annotations(DOMElement $fa, Invoice $invoice): void
    {
        $codes = $invoice->items->map(fn (InvoiceItem $item) => $item->vat_code);
        $annotations = $this->child($fa, 'Adnotacje');

        $this->child($annotations, 'P_16', '2');
        $this->child($annotations, 'P_17', '2');
        $this->child($annotations, 'P_18', $codes->contains(fn (VatCode $code) => $code->isReverseCharge()) ? '1' : '2');
        $this->child($annotations, 'P_18A', '2');

        $exemption = $this->child($annotations, 'Zwolnienie');

        if ($codes->contains(VatCode::Exempt)) {
            $this->child($exemption, 'P_19', '1');
            $this->child($exemption, 'P_19A', (string) $invoice->vat_exemption_basis);
        } else {
            $this->child($exemption, 'P_19N', '1');
        }

        $vehicles = $this->child($annotations, 'NoweSrodkiTransportu');
        $this->child($vehicles, 'P_22N', '1');
        $this->child($annotations, 'P_23', '2');

        $margin = $this->child($annotations, 'PMarzy');
        $this->child($margin, 'P_PMarzyN', '1');
    }

    private function correction(DOMElement $fa, Invoice $invoice): void
    {
        $this->child($fa, 'PrzyczynaKorekty', (string) $invoice->correction_reason);
        // Korekta ujmowana w dacie jej wystawienia.
        $this->child($fa, 'TypKorekty', '2');

        $corrected = $this->child($fa, 'DaneFaKorygowanej');
        $this->child($corrected, 'DataWystFaKorygowanej', (string) $invoice->corrected_issue_date?->toDateString());
        $this->child($corrected, 'NrFaKorygowanej', (string) $invoice->corrected_number);

        $ksefNumber = $invoice->corrected_ksef_number ?? $invoice->correctedInvoice?->ksef_number;

        if (filled($ksefNumber)) {
            $this->child($corrected, 'NrKSeF', '1');
            $this->child($corrected, 'NrKSeFFaKorygowanej', (string) $ksefNumber);
        } else {
            $this->child($corrected, 'NrKSeFN', '1');
        }
    }

    private function line(DOMElement $fa, InvoiceItem $item, int $position, Invoice $invoice): void
    {
        $row = $this->child($fa, 'FaWiersz');
        $this->child($row, 'NrWierszaFa', (string) $position);
        $this->child($row, 'P_7', mb_substr($this->singleLine($item->name), 0, 512));
        // Jednostka zawsze (jak w Aplikacji Podatnika) — bez podanej: „szt.”.
        $this->child($row, 'P_8A', mb_substr(trim((string) $item->unit) ?: 'szt.', 0, 50));
        $this->child($row, 'P_8B', $this->quantity($item->quantity));
        $this->child($row, 'P_9A', $this->amount($item->unit_price));
        $this->child($row, 'P_11', $this->amount($item->net));
        $this->child($row, 'P_12', $item->vat_code->value);

        // Kurs w wierszu tylko przy odwrotnym obciążeniu (jak w Aplikacji Podatnika) — do przeliczenia przychodu.
        if ($invoice->currency !== 'PLN' && $invoice->exchange_rate !== null && $item->vat_code === VatCode::ReverseCharge) {
            $this->child($row, 'KursWaluty', $this->quantity($invoice->exchange_rate));
        }

        if ($item->is_before) {
            $this->child($row, 'StanPrzed', '1');
        }
    }

    private function payment(DOMElement $fa, Invoice $invoice): void
    {
        $payment = $this->child($fa, 'Platnosc');

        if ($invoice->due_date !== null) {
            $term = $this->child($payment, 'TerminPlatnosci');
            $this->child($term, 'Termin', $invoice->due_date->toDateString());
        }

        $this->child($payment, 'FormaPlatnosci', $invoice->payment_method->ksefCode());

        $account = (array) $invoice->bank_account;

        if (filled($account['iban'] ?? null)) {
            $bank = $this->child($payment, 'RachunekBankowy');
            $this->child($bank, 'NrRB', (string) $account['iban']);
            $this->optional($bank, 'SWIFT', (string) ($account['swift'] ?? ''));
        }
    }

    /**
     * Faktura zaliczkowa: pełne zamówienie, a sumy dokumentu to otrzymana zaliczka.
     */
    private function order(DOMElement $fa, Invoice $invoice): void
    {
        $order = $this->child($fa, 'Zamowienie');
        $this->child($order, 'WartoscZamowienia', $this->amount($invoice->itemsSummary()->gross()));

        foreach ($invoice->items->where('is_before', false)->sortBy('position')->values() as $index => $item) {
            $row = $this->child($order, 'ZamowienieWiersz');
            $this->child($row, 'NrWierszaZam', (string) ($index + 1));
            $this->child($row, 'P_7Z', mb_substr($this->singleLine($item->name), 0, 512));
            $this->child($row, 'P_8AZ', mb_substr(trim((string) $item->unit) ?: 'szt.', 0, 50));
            $this->child($row, 'P_8BZ', $this->quantity($item->quantity));
            $this->child($row, 'P_9AZ', $this->amount($item->unit_price));
            $this->child($row, 'P_11NettoZ', $this->amount($item->net));

            if ($item->vat_code->percent() !== null) {
                $this->child($row, 'P_11VatZ', $this->amount(VatSummary::vatOf($item->net, $item->vat_code)));
            }

            $this->child($row, 'P_12Z', $item->vat_code->value);
        }
    }

    private function footer(DOMElement $root, Invoice $invoice): void
    {
        $notes = trim((string) $invoice->notes);
        $regon = $this->digits($invoice->seller['regon'] ?? null);

        if ($notes === '' && $regon === '') {
            return;
        }

        $footer = $this->child($root, 'Stopka');

        if ($notes !== '') {
            $information = $this->child($footer, 'Informacje');
            $this->child($information, 'StopkaFaktury', mb_substr($notes, 0, 3500));
        }

        if (in_array(strlen($regon), [9, 14], true)) {
            $registers = $this->child($footer, 'Rejestry');
            $this->child($registers, 'REGON', $regon);
        }
    }

    private function kindCode(Invoice $invoice): string
    {
        return match ($invoice->kind) {
            InvoiceKind::Correction => match ($invoice->correctedInvoice?->kind) {
                InvoiceKind::Advance => 'KOR_ZAL',
                InvoiceKind::Final => 'KOR_ROZ',
                default => 'KOR',
            },
            InvoiceKind::Advance => 'ZAL',
            InvoiceKind::Final => 'ROZ',
            default => 'VAT',
        };
    }

    /**
     * @param  array<string, string|null>  $party
     */
    private function euCode(array $party): string
    {
        $code = strtoupper((string) ($party['vat_prefix'] ?? '') ?: (string) ($party['country_code'] ?? ''));

        return $code === 'GR' ? 'EL' : $code;
    }

    private function child(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $this->document->createElementNS(self::NAMESPACE, $name);

        if ($value !== null) {
            $element->appendChild($this->document->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }

    private function optional(DOMElement $parent, string $name, string $value): void
    {
        if (trim($value) !== '') {
            $this->child($parent, $name, trim($value));
        }
    }

    /**
     * Kwota bez zbędnych zer, jak w dokumentach z Aplikacji Podatnika („1230”, „3977.8”).
     */
    private function amount(BigDecimal|string|null $value): string
    {
        $formatted = (string) BigDecimal::of($value ?? '0')->toScale(2, RoundingMode::HalfUp);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    private function quantity(BigDecimal|string $value): string
    {
        $formatted = (string) BigDecimal::of($value)->toScale(6, RoundingMode::HalfUp);

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function singleLine(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
