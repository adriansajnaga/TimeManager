<?php

namespace App\Models;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\Permission;
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
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Dodaje kolumnę is_unsettled: godzinowy — godziny w części tygodnia bez rozliczenia tego klienta
     * (i niezafakturowanej w starej aplikacji); ryczałtowy — aktywny bez wystawionej faktury rozliczeniowej.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeWithUnsettled(Builder $query): void
    {
        $hourly = <<<'SQL'
            EXISTS (
                SELECT 1 FROM time_entries te
                JOIN work_weeks ww ON ww.id = te.work_week_id
                WHERE te.project_id = projects.id
                  AND ww.invoiced_at IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM settlement_work_week sww
                      JOIN settlements s ON s.id = sww.settlement_id
                      WHERE sww.work_week_id = ww.id AND s.contractor_id = projects.contractor_id
                  )
            )
            SQL;

        $fixed = <<<'SQL'
            projects.status = ? AND NOT EXISTS (
                SELECT 1 FROM invoices i
                WHERE i.project_id = projects.id AND i.kind = ? AND i.status = ?
            )
            SQL;

        if ($query->getQuery()->columns === null) {
            $query->select('projects.*');
        }

        $query->selectRaw(
            "CASE WHEN projects.billing_type = ? THEN ($fixed) ELSE ($hourly) END AS is_unsettled",
            [ProjectBillingType::Fixed->value, ProjectStatus::Active->value, InvoiceKind::Final->value, InvoiceStatus::Issued->value],
        );
    }

    /**
     * Projekty, na które użytkownik może wpisywać godziny: aktywne, a dla pracownika — przypisane.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeAvailableFor(Builder $query, User $user): void
    {
        $query->active();

        if (! $user->hasPermission(Permission::ViewAllTimeEntries)) {
            $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->id));
        }
    }

    /**
     * Numer i nazwa jak na Montageauftrag: „160406010 - Zuleitung Dampf Luftbefeuchter”.
     */
    public function fullName(): string
    {
        return $this->number.' - '.$this->name;
    }

    /**
     * Przystanek na trasie kilometrówki: miejscowość budowy (albo nazwa miejsca/projektu).
     */
    public function routeStop(): string
    {
        return $this->site_city ?: ($this->site_name ?: $this->name);
    }

    /**
     * Miejsce realizacji na fakturze: etykieta, a gdy jej brak — klient końcowy / miejsce, miejscowość, nazwa projektu.
     */
    public function invoiceLabel(): string
    {
        foreach ([$this->invoice_label, $this->site_name, $this->site_city] as $label) {
            if (filled($label)) {
                return trim((string) $label);
            }
        }

        return $this->name;
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * Faktury projektu ryczałtowego (zaliczki, transze, faktura końcowa).
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<WeeklyReport, $this>
     */
    public function weeklyReports(): HasMany
    {
        return $this->hasMany(WeeklyReport::class);
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
