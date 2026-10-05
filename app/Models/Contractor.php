<?php

namespace App\Models;

use App\Enums\ContractorType;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceLineMode;
use App\Enums\Language;
use App\Enums\PackageDocument;
use App\Enums\VatCode;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\LogsActivity;
use Database\Factories\ContractorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Klient i/lub dostawca.
 *
 * @property int $id
 * @property ContractorType $type
 * @property string $name
 * @property string|null $street
 * @property string|null $zip
 * @property string|null $city
 * @property string $country_code
 * @property string|null $vat_prefix
 * @property string|null $tax_id
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $fax
 * @property string|null $website
 * @property string|null $logo_path
 * @property Language $document_language
 * @property InvoiceLanguage $invoice_language
 * @property string $currency
 * @property VatCode $vat_code
 * @property InvoiceLineMode $invoice_line_mode
 * @property string|null $invoice_description_template
 * @property int $payment_days
 * @property int|null $bank_account_id
 * @property list<string>|null $package_documents
 * @property string|null $hourly_rate
 * @property string|null $km_rate
 * @property string|null $base_address
 * @property int|null $vehicle_id
 * @property list<string>|null $email_to
 * @property list<string>|null $email_cc
 * @property string|null $email_subject_template
 * @property string|null $email_body_template
 * @property string|null $notes
 * @property bool $is_active
 * @property int|null $legacy_id
 */
#[Fillable([
    'type', 'name', 'street', 'zip', 'city', 'country_code', 'vat_prefix', 'tax_id', 'email', 'phone', 'fax', 'website', 'logo_path',
    'document_language', 'invoice_language', 'currency', 'vat_code', 'invoice_line_mode',
    'invoice_description_template', 'payment_days', 'bank_account_id', 'package_documents',
    'hourly_rate', 'km_rate', 'base_address', 'vehicle_id',
    'email_to', 'email_cc', 'email_subject_template', 'email_body_template', 'notes', 'is_active',
])]
class Contractor extends Model
{
    /** @use HasFactory<ContractorFactory> */
    use HasAttachments, HasFactory, LogsActivity;

    public function attachmentDirectory(): string
    {
        return 'contractors/'.$this->id.'/documents';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContractorType::class,
            'document_language' => Language::class,
            'invoice_language' => InvoiceLanguage::class,
            'vat_code' => VatCode::class,
            'invoice_line_mode' => InvoiceLineMode::class,
            'payment_days' => 'integer',
            'package_documents' => 'array',
            'hourly_rate' => 'decimal:2',
            'km_rate' => 'decimal:4',
            'email_to' => 'array',
            'email_cc' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Contractor>  $query
     */
    public function scopeClients(Builder $query): void
    {
        $query->whereIn('type', [ContractorType::Client, ContractorType::Both]);
    }

    /**
     * Numer VAT do wyświetlenia, np. „DE 286771111” albo „8792451081”.
     */
    public function vatId(): ?string
    {
        if (blank($this->tax_id)) {
            return null;
        }

        return trim(($this->vat_prefix ?? '').' '.$this->tax_id);
    }

    /**
     * Dokumenty pakietu w kolejności z ustawień klienta.
     *
     * @return list<PackageDocument>
     */
    public function packageDocuments(): array
    {
        $documents = [];

        foreach ($this->package_documents ?? PackageDocument::defaultOrder() as $value) {
            $document = PackageDocument::tryFrom($value);

            if ($document !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
