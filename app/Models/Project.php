<?php

namespace App\Models;

use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Models\Concerns\LogsActivity;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $contractor_id
 * @property string $number
 * @property string $name
 * @property string|null $invoice_label
 * @property string|null $site_name
 * @property string|null $site_street
 * @property string|null $site_zip
 * @property string|null $site_city
 * @property string|null $site_country
 * @property ProjectBillingType $billing_type
 * @property string|null $km_one_way
 * @property bool $mileage_default
 * @property ProjectStatus $status
 * @property string|null $contract_value
 * @property string|null $contract_currency
 * @property string|null $notes
 * @property int|null $legacy_id
 */
#[Fillable([
    'contractor_id', 'number', 'name', 'invoice_label',
    'site_name', 'site_street', 'site_zip', 'site_city', 'site_country',
    'billing_type', 'km_one_way', 'mileage_default', 'status', 'contract_value', 'contract_currency', 'notes',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_type' => ProjectBillingType::class,
            'status' => ProjectStatus::class,
            'km_one_way' => 'decimal:1',
            'mileage_default' => 'boolean',
            'contract_value' => 'decimal:2',
        ];
    }

    /**
     * @param  Builder<Project>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ProjectStatus::Active);
    }

    /**
     * Numer i nazwa jak na Montageauftrag: „160406010 - Zuleitung Dampf Luftbefeuchter”.
     */
    public function fullName(): string
    {
        return $this->number.' - '.$this->name;
    }

    /**
     * Etykieta na fakturze; gdy nie ustawiono — nazwa projektu.
     */
    public function invoiceLabel(): string
    {
        return filled($this->invoice_label) ? $this->invoice_label : $this->name;
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * Pracownicy przypisani do projektu.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
