<?php

namespace App\Models;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\LogsActivity;
use App\Services\Invoices\VatSummary;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Faktura sprzedaży albo zakupu (VAT, korekta, zaliczkowa, rozliczeniowa, proforma).
 *
 * @property int $id
 * @property InvoiceDirection $direction
 * @property InvoiceKind $kind
 * @property InvoiceStatus $status
 * @property string|null $number
 * @property int|null $contractor_id
 * @property array<string, string|null>|null $seller
 * @property array<string, string|null>|null $buyer
 * @property string|null $counterparty_name
 * @property string|null $counterparty_tax_id
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable|null $sale_date
 * @property CarbonImmutable|null $due_date
 * @property string|null $issue_place
 * @property PaymentMethod $payment_method
 * @property array<string, string|null>|null $bank_account
 * @property CarbonImmutable|null $paid_on
 * @property string $currency
 * @property string|null $exchange_rate
 * @property CarbonImmutable|null $exchange_rate_date
 * @property string|null $exchange_rate_table
 * @property InvoiceLanguage $language
 * @property string $net
 * @property string $vat
 * @property string $gross
 * @property string|null $advance_amount
 * @property int|null $corrected_invoice_id
 * @property string|null $corrected_number
 * @property CarbonImmutable|null $corrected_issue_date
 * @property string|null $corrected_ksef_number
 * @property string|null $correction_reason
 * @property string|null $vat_exemption_basis
 * @property string|null $notes
 * @property CarbonImmutable|null $issued_at
 * @property int|null $issued_by
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Collection<int, Invoice> $advances
 */
#[Fillable([
    'direction', 'kind', 'number', 'contractor_id', 'seller', 'buyer', 'counterparty_name', 'counterparty_tax_id',
    'issue_date', 'sale_date', 'due_date', 'issue_place', 'payment_method', 'bank_account', 'paid_on',
    'currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_table', 'language',
    'advance_amount', 'corrected_invoice_id', 'corrected_number', 'corrected_issue_date', 'corrected_ksef_number',
    'correction_reason', 'vat_exemption_basis', 'notes',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, LogsActivity;

    protected $attributes = [
        'direction' => 'sales',
        'kind' => 'vat',
        'status' => 'draft',
        'currency' => 'PLN',
        'language' => 'pl',
        'payment_method' => 'transfer',
        'net' => '0.00',
        'vat' => '0.00',
        'gross' => '0.00',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => InvoiceDirection::class,
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'seller' => 'array',
            'buyer' => 'array',
            'issue_date' => 'date',
            'sale_date' => 'date',
            'due_date' => 'date',
            'payment_method' => PaymentMethod::class,
            'bank_account' => 'array',
            'paid_on' => 'date',
            'exchange_rate' => 'decimal:4',
            'exchange_rate_date' => 'date',
            'language' => InvoiceLanguage::class,
            'net' => 'decimal:2',
            'vat' => 'decimal:2',
            'gross' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'corrected_issue_date' => 'date',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    public function scopeSales(Builder $query): void
    {
        $query->where('direction', InvoiceDirection::Sales);
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    public function scopePurchases(Builder $query): void
    {
        $query->where('direction', InvoiceDirection::Purchase);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('is_before', 'desc')->orderBy('position');
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function correctedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'corrected_invoice_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(Invoice::class, 'corrected_invoice_id');
    }

    /**
     * Faktury zaliczkowe rozliczane fakturą rozliczeniową.
     *
     * @return BelongsToMany<Invoice, $this>
     */
    public function advances(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'invoice_advances', 'invoice_id', 'advance_invoice_id')->orderBy('issue_date');
    }

    /**
     * Faktury rozliczeniowe, które rozliczają tę zaliczkową.
     *
     * @return BelongsToMany<Invoice, $this>
     */
    public function settlements(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'invoice_advances', 'advance_invoice_id', 'invoice_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isSales(): bool
    {
        return $this->direction === InvoiceDirection::Sales;
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->status === InvoiceStatus::Issued;
    }

    public function isPaid(): bool
    {
        return $this->paid_on !== null;
    }

    /**
     * Szkic sprzedaży albo ręcznie wpisany zakup — tylko takie da się jeszcze zmieniać.
     */
    public function isEditable(): bool
    {
        return $this->isSales() ? $this->isDraft() : $this->status !== InvoiceStatus::Cancelled;
    }

    /**
     * Zestawienie VAT z pozycji, zależnie od rodzaju faktury.
     */
    public function summary(): VatSummary
    {
        $after = VatSummary::fromLines($this->items->where('is_before', false)->map(fn (InvoiceItem $item) => [
            'net' => $item->net, 'vat_code' => $item->vat_code,
        ]));

        return match ($this->kind) {
            InvoiceKind::Correction => $after->minus($this->beforeSummary()),
            InvoiceKind::Advance => VatSummary::advance($after, $this->advance_amount ?? '0'),
            InvoiceKind::Final => $this->advances->reduce(
                fn (VatSummary $rest, Invoice $advance) => $rest->minus($advance->summary()),
                $after,
            ),
            default => $after,
        };
    }

    /**
     * Zamówienie (zaliczkowa, rozliczeniowa) albo stan po korekcie — pełne pozycje bez odliczeń.
     */
    public function itemsSummary(): VatSummary
    {
        return VatSummary::fromLines($this->items->where('is_before', false)->map(fn (InvoiceItem $item) => [
            'net' => $item->net, 'vat_code' => $item->vat_code,
        ]));
    }

    public function beforeSummary(): VatSummary
    {
        return VatSummary::fromLines($this->items->where('is_before', true)->map(fn (InvoiceItem $item) => [
            'net' => $item->net, 'vat_code' => $item->vat_code,
        ]));
    }

    /**
     * Przelicza i zapisuje sumy dokumentu z pozycji.
     */
    public function refreshTotals(): void
    {
        $this->load(['items', 'advances.items']);
        $summary = $this->summary();

        $this->forceFill([
            'net' => (string) $summary->net(),
            'vat' => (string) $summary->vat(),
            'gross' => (string) $summary->gross(),
        ])->save();
    }

    /**
     * Kwota w PLN po kursie faktury (dla walut obcych), np. VAT do wykazania w złotych.
     */
    public function toPln(BigDecimal|string $amount): ?BigDecimal
    {
        if ($this->currency === 'PLN') {
            return BigDecimal::of($amount);
        }

        if ($this->exchange_rate === null) {
            return null;
        }

        return BigDecimal::of($amount)->multipliedBy($this->exchange_rate)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Kwota do zapłaty: brutto dokumentu (w korekcie może być ujemna).
     */
    public function amountDue(): BigDecimal
    {
        return BigDecimal::of($this->gross);
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('Draft #:id', ['id' => $this->id]);
    }

    public function filename(): string
    {
        $number = $this->number !== null ? str_replace(['/', '\\', ' '], ['-', '-', '_'], $this->number) : 'draft-'.$this->id;

        return ($this->kind === InvoiceKind::Proforma ? 'Proforma_' : 'Faktura_').$number.'.pdf';
    }
}
