<?php

namespace App\Services\Settlements;

use App\Documents\DocumentFormat;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceLineMode;
use App\Enums\ProjectBillingType;
use App\Models\BankAccount;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\MaterialEntry;
use App\Models\Project;
use App\Models\Settlement;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkWeek;
use App\Services\Invoices\InvoiceException;
use App\Services\Invoices\Parties;
use App\Services\Mileage\MileageCalculator;
use App\Services\Mileage\MileageTrip;
use App\Support\DefaultTemplates;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rozliczenie projektów godzinowych klienta z zamkniętych części tygodni (decyzje 10 i 11):
 * godziny × stawka klienta + kilometrówka × stawka za km → szkic faktury.
 */
final class SettlementService
{
    public function __construct(private readonly MileageCalculator $mileage) {}

    /**
     * Zamknięte części tygodni z godzinami klienta, jeszcze nierozliczone dla tego klienta.
     *
     * @return Collection<int, WorkWeek>
     */
    public function billableWeeks(Contractor $contractor): Collection
    {
        return WorkWeek::query()
            ->closed()
            ->whereNull('invoiced_at')
            ->whereHas('timeEntries', fn (Builder $entries) => $entries->whereHas('project', fn (Builder $projects) => $this->hourlyProjectsOf($projects, $contractor)))
            ->whereDoesntHave('settlements', fn (Builder $settlements) => $settlements->where('contractor_id', $contractor->id))
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * @param  Collection<int, WorkWeek>  $weeks
     */
    public function preview(Contractor $contractor, Collection $weeks): SettlementPreview
    {
        $entries = TimeEntry::query()
            ->with('project')
            ->whereIn('work_week_id', $weeks->pluck('id'))
            ->whereHas('project', fn (Builder $projects) => $this->hourlyProjectsOf($projects, $contractor))
            ->get();

        $hours = $entries->reduce(fn (BigDecimal $sum, TimeEntry $entry) => $sum->plus($entry->hours), BigDecimal::zero());

        // Miejsca realizacji, każde raz (bez względu na wielkość liter, np. „TKMS GmbH” i „TKMS Gmbh”).
        $labels = $entries->map(fn (TimeEntry $entry) => $entry->project->invoiceLabel())
            ->unique(fn (string $label) => mb_strtolower($label))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $trips = $this->mileage->trips($weeks, $contractor);

        $materials = MaterialEntry::query()
            ->whereHas('weeklyReport', fn (Builder $reports) => $reports
                ->whereIn('work_week_id', $weeks->pluck('id'))
                ->whereHas('project', fn (Builder $projects) => $projects->where('contractor_id', $contractor->id)))
            ->get();

        return new SettlementPreview(
            contractor: $contractor,
            weeks: $weeks,
            hours: $hours,
            hourlyRate: BigDecimal::of($contractor->hourly_rate ?? '0'),
            km: MileageCalculator::totalKm($trips),
            kmRate: BigDecimal::of($contractor->km_rate ?? '0'),
            periodFrom: $entries->min('work_date'),
            periodTo: $entries->max('work_date'),
            labels: array_values($labels),
            trips: $trips,
            materials: $materials,
        );
    }

    /**
     * Rozliczenie + szkic faktury VAT dla klienta.
     *
     * @param  Collection<int, WorkWeek>  $weeks
     *
     * @throws InvoiceException
     */
    public function createInvoice(Contractor $contractor, Collection $weeks, User $user): Settlement
    {
        $billable = $this->billableWeeks($contractor)->pluck('id');

        if ($weeks->pluck('id')->diff($billable)->isNotEmpty()) {
            throw new InvoiceException(__('Some of the chosen weeks are not closed or are already settled.'));
        }

        $preview = $this->preview($contractor, $weeks);

        if ($preview->problems() !== []) {
            throw new InvoiceException(implode(' ', $preview->problems()));
        }

        return DB::transaction(function () use ($contractor, $weeks, $user, $preview) {
            $invoice = $this->draftInvoice($contractor, $preview);

            $settlement = Settlement::query()->create([
                'contractor_id' => $contractor->id,
                'invoice_id' => $invoice->id,
                'period_from' => $preview->periodFrom,
                'period_to' => $preview->periodTo,
                'hours' => (string) $preview->hours,
                'hourly_rate' => (string) $preview->hourlyRate,
                'km' => (string) $preview->km,
                'km_rate' => (string) $preview->kmRate,
                'amount' => (string) $preview->total(),
                'currency' => $contractor->currency,
                'created_by' => $user->id,
            ]);

            $settlement->workWeeks()->sync($weeks->pluck('id'));
            $this->refreshInvoicedFlags($weeks);

            return $settlement;
        });
    }

    /**
     * Zwalnia tygodnie rozliczenia (np. po usunięciu szkicu faktury).
     */
    public function release(Settlement $settlement): void
    {
        $weeks = $settlement->workWeeks()->get();
        $settlement->workWeeks()->detach();
        $settlement->delete();

        WorkWeek::query()->whereIn('id', $weeks->pluck('id'))->update(['invoiced_at' => null]);
    }

    /**
     * Opis pozycji z szablonu klienta: okres i lista etykiet projektów.
     */
    public function description(Contractor $contractor, SettlementPreview $preview): string
    {
        $format = new DocumentFormat($contractor->document_language);
        $template = $contractor->invoice_description_template ?: DefaultTemplates::invoiceDescription($contractor->document_language);

        return trim(strtr($template, [
            '{period_from}' => $format->date($preview->periodFrom),
            '{period_to}' => $format->date($preview->periodTo),
            '{projects}' => implode("\n", array_map(fn (string $label) => '- '.$label, $preview->labels)),
        ]));
    }

    private function draftInvoice(Contractor $contractor, SettlementPreview $preview): Invoice
    {
        $today = CarbonImmutable::today();
        $account = $contractor->bankAccount ?? BankAccount::default();

        $invoice = new Invoice([
            'kind' => InvoiceKind::Vat,
            'contractor_id' => $contractor->id,
            'issue_date' => $today,
            'sale_date' => $preview->periodTo,
            'due_date' => $today->addDays($contractor->payment_days),
            'issue_place' => CompanySetting::current()->issue_place,
            'currency' => $contractor->currency,
            'language' => $contractor->invoice_language,
            'bank_account' => Parties::bankAccount($account),
        ]);
        Parties::apply($invoice);
        $invoice->save();

        $description = $this->description($contractor, $preview);
        $vatCode = $contractor->vat_code;

        if ($contractor->invoice_line_mode === InvoiceLineMode::Itemized) {
            $invoice->items()->create(['position' => 1, 'name' => $description, 'unit' => 'h', 'quantity' => (string) $preview->hours, 'unit_price' => (string) $preview->hourlyRate, 'vat_code' => $vatCode]);

            if (! $preview->km->isZero()) {
                $invoice->items()->create([
                    'position' => 2,
                    'name' => trans('workdocs.mileage.invoice_line', [], $contractor->document_language->value),
                    'unit' => 'km',
                    'quantity' => (string) $preview->km,
                    'unit_price' => (string) $preview->kmRate,
                    'vat_code' => $vatCode,
                ]);
            }
        } else {
            $invoice->items()->create(['position' => 1, 'name' => $description, 'unit' => 'szt.', 'quantity' => '1', 'unit_price' => (string) $preview->total(), 'vat_code' => $vatCode]);
        }

        $invoice->refreshTotals();

        return $invoice;
    }

    /**
     * Część tygodnia jest zafakturowana, gdy rozliczono ją dla każdego klienta z godzinami w niej.
     *
     * @param  Collection<int, WorkWeek>  $weeks
     */
    private function refreshInvoicedFlags(Collection $weeks): void
    {
        foreach ($weeks as $week) {
            $clients = Project::query()
                ->where('billing_type', ProjectBillingType::Hourly)
                ->whereHas('timeEntries', fn (Builder $entries) => $entries->where('work_week_id', $week->id))
                ->distinct()
                ->pluck('contractor_id');

            $settled = $week->settlements()->pluck('contractor_id');

            if ($clients->diff($settled)->isEmpty()) {
                $week->forceFill(['invoiced_at' => now()])->save();
            }
        }
    }

    /**
     * @param  Builder<Project>  $projects
     */
    private function hourlyProjectsOf(Builder $projects, Contractor $contractor): void
    {
        $projects->where('contractor_id', $contractor->id)->where('billing_type', ProjectBillingType::Hourly);
    }

    /**
     * @param  list<MileageTrip>  $trips
     */
    public static function tripsKm(array $trips): BigDecimal
    {
        return MileageCalculator::totalKm($trips);
    }
}
