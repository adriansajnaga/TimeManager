<?php

namespace App\Documents;

use App\Enums\PackageDocument;
use App\Models\Invoice;
use App\Models\Settlement;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkWeek;
use App\Services\Ksef\InvoiceQrCode;
use Illuminate\Support\Collection;

/**
 * Pakiet dla klienta: faktura i dokumenty rozliczenia w kolejności z kartoteki kontrahenta
 * (domyślnie Faktura → Stundenzettel → Kilometrówka → Montageaufträge), jeden PDF.
 */
final class SettlementPackage
{
    public function __construct(private readonly InvoiceQrCode $qrCode) {}

    /**
     * @return list<Document>
     */
    public function documents(Invoice $invoice): array
    {
        $settlement = $invoice->settlement;

        if (! $settlement instanceof Settlement) {
            return [new InvoicePdf($invoice, $this->qrCode->url($invoice))];
        }

        $client = $settlement->contractor;
        $weeks = $settlement->workWeeks()->get();
        $documents = [];

        foreach ($client->packageDocuments() as $type) {
            array_push($documents, ...match ($type) {
                PackageDocument::Invoice => [new InvoicePdf($invoice, $this->qrCode->url($invoice))],
                PackageDocument::Stundenzettel => [new Stundenzettel($client, $weeks, $settlement->hourly_rate)],
                PackageDocument::Mileage => $this->mileage($settlement, $weeks),
                PackageDocument::Montageauftrag => $this->reports($settlement, $weeks),
                PackageDocument::Stundennachweis => $this->timeRecords($settlement, $weeks),
            });
        }

        return $documents;
    }

    public function filename(Invoice $invoice): string
    {
        return 'Paket_'.str_replace(['/', ' '], ['-', '_'], (string) ($invoice->number ?? 'draft-'.$invoice->id)).'.pdf';
    }

    /**
     * @param  Collection<int, WorkWeek>  $weeks
     * @return list<Document>
     */
    private function mileage(Settlement $settlement, Collection $weeks): array
    {
        $documents = [];

        foreach ($this->users($settlement, $weeks, mileageOnly: true) as $user) {
            $documents[] = new MileageAllowance($settlement->contractor, $weeks, $user, $settlement->km_rate);
        }

        return $documents;
    }

    /**
     * Montageaufträge: tydzień po tygodniu, w tygodniu wg numeru projektu.
     *
     * @param  Collection<int, WorkWeek>  $weeks
     * @return list<Document>
     */
    private function reports(Settlement $settlement, Collection $weeks): array
    {
        return array_values(WeeklyReport::query()
            ->with(['project', 'workWeek'])
            ->whereIn('work_week_id', $weeks->pluck('id'))
            ->whereHas('project', fn ($projects) => $projects->where('contractor_id', $settlement->contractor_id))
            ->get()
            ->filter(fn (WeeklyReport $report) => $report->entries()->exists())
            ->sortBy([fn (WeeklyReport $a, WeeklyReport $b) => $a->workWeek->starts_on <=> $b->workWeek->starts_on, fn (WeeklyReport $a, WeeklyReport $b) => strcmp($a->project->number, $b->project->number)])
            ->map(fn (WeeklyReport $report) => new Montageauftrag($report))
            ->all());
    }

    /**
     * @param  Collection<int, WorkWeek>  $weeks
     * @return list<Document>
     */
    private function timeRecords(Settlement $settlement, Collection $weeks): array
    {
        $documents = [];

        foreach ($weeks->sortBy('starts_on') as $week) {
            foreach ($this->users($settlement, collect([$week])) as $user) {
                $documents[] = new Stundennachweis($week, $user, $settlement->contractor);
            }
        }

        return $documents;
    }

    /**
     * Osoby z godzinami (lub kilometrówką) u klienta w tych tygodniach.
     *
     * @param  Collection<int, WorkWeek>  $weeks
     * @return Collection<int, User>
     */
    private function users(Settlement $settlement, Collection $weeks, bool $mileageOnly = false): Collection
    {
        $ids = TimeEntry::query()
            ->whereIn('work_week_id', $weeks->pluck('id'))
            ->whereHas('project', fn ($projects) => $projects->where('contractor_id', $settlement->contractor_id))
            ->when($mileageOnly, fn ($query) => $query->where('count_mileage', true))
            ->distinct()
            ->pluck('user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->get();
    }
}
