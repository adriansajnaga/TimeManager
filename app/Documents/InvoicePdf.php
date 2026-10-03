<?php

namespace App\Documents;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceLanguage;
use App\Enums\Language;
use App\Enums\VatCode;
use App\Models\CompanySetting;
use App\Models\Invoice;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * Faktura w układzie z aplikacji PM; w wersji PL/EN etykiety jak na fakturach z Excela
 * („FAKTURA VAT / INVOICE”, „Sprzedawca / From”).
 */
final class InvoicePdf implements Document
{
    public function __construct(private readonly Invoice $invoice) {}

    public function view(): string
    {
        return 'pdf.invoice';
    }

    public function orientation(): string
    {
        return 'P';
    }

    /**
     * Liczby i daty zawsze w formacie polskim — faktura jest dokumentem polskiego podatnika.
     */
    public function language(): Language
    {
        return Language::Polish;
    }

    public function filename(): string
    {
        return $this->invoice->filename();
    }

    public function data(): array
    {
        $invoice = $this->invoice->loadMissing(['items', 'advances.items']);
        $company = CompanySetting::current();
        $format = new DocumentFormat(Language::Polish);
        $summary = $invoice->summary();
        $rows = $invoice->items->where('is_before', false)->values();
        $domestic = ($invoice->buyer['country_code'] ?? 'PL') === 'PL' && ($invoice->seller['country_code'] ?? 'PL') === 'PL';

        return [
            'invoice' => $invoice,
            't' => $this->translator(),
            'th' => $this->headerTranslator(),
            'money' => fn (BigDecimal|string|null $amount) => $format->number($amount ?? '0').' '.$invoice->currency,
            'number' => fn (BigDecimal|string $value, int $decimals = 2) => $format->number($value, max(0, $decimals)),
            'quantity' => fn (string $quantity) => str_replace('.', ',', rtrim(rtrim($quantity, '0'), '.')),
            'date' => fn ($date) => $date?->format('Y.m.d') ?? '',
            'logo' => $company->logo_path !== null && Storage::disk('local')->exists($company->logo_path)
                ? Storage::disk('local')->path($company->logo_path)
                : null,
            'title' => $this->title(),
            'summary' => $summary,
            'orderSummary' => $invoice->itemsSummary(),
            'rows' => $rows,
            'beforeRows' => $invoice->items->where('is_before', true)->values(),
            'beforeSummary' => $invoice->beforeSummary(),
            'reverseCharge' => $summary->hasReverseCharge() || $invoice->itemsSummary()->hasReverseCharge(),
            'npServices' => $rows->contains(fn ($item) => $item->vat_code === VatCode::OutsideScopeEuServices),
            'vatInPln' => $invoice->currency !== 'PLN' && ! $summary->vat()->isZero() ? $invoice->toPln($summary->vat()) : null,
            'taxLine' => fn (?array $party, bool $isSeller) => self::taxLine($party, $isSeller, $domestic),
        ];
    }

    /**
     * Numer podatkowy strony: „NIP: 8792451081” w kraju, „NIP/VAT: PL8792451081” i „VAT ID: DE286771111” za granicą.
     *
     * @param  array<string, string|null>|null  $party
     */
    private static function taxLine(?array $party, bool $isSeller, bool $domestic): ?string
    {
        if (blank($party['tax_id'] ?? null)) {
            return null;
        }

        if ($domestic) {
            return 'NIP: '.$party['tax_id'];
        }

        $prefix = (string) ($party['vat_prefix'] ?? $party['country_code'] ?? '');

        return ($isSeller ? 'NIP/VAT' : 'VAT ID').': '.$prefix.$party['tax_id'];
    }

    /**
     * Tytuł dokumentu, np. „FAKTURA VAT / INVOICE”.
     */
    private function title(): string
    {
        $key = match ($this->invoice->kind) {
            InvoiceKind::Vat => 'title_vat',
            InvoiceKind::Correction => 'title_kor',
            InvoiceKind::Advance => 'title_zal',
            InvoiceKind::Final => 'title_roz',
            InvoiceKind::Proforma => 'title_proforma',
        };

        return mb_strtoupper(($this->translator())($key));
    }

    private function bilingual(): bool
    {
        return $this->invoice->language === InvoiceLanguage::PolishEnglish;
    }

    /**
     * Etykieta w jednej linii: „Data wystawienia / Invoice date”.
     */
    private function translator(): Closure
    {
        $bilingual = $this->bilingual();

        return function (string $key) use ($bilingual): string {
            $polish = (string) trans('invoicepdf.'.$key, [], 'pl');

            return $bilingual ? $polish.' / '.trans('invoicepdf.'.$key, [], 'en') : $polish;
        };
    }

    /**
     * Nagłówek kolumny: polski, pod nim angielski mniejszą czcionką (HTML).
     */
    private function headerTranslator(): Closure
    {
        $bilingual = $this->bilingual();

        return function (string $key) use ($bilingual): string {
            $polish = e(trans('invoicepdf.'.$key, [], 'pl'));

            return $bilingual
                ? $polish.'<br><span class="en">'.e(trans('invoicepdf.'.$key, [], 'en')).'</span>'
                : $polish;
        };
    }
}
