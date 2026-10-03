<?php

namespace App\Services\LegacyImport;

use App\Enums\ContractorType;
use App\Enums\InvoiceLanguage;
use App\Enums\Language;
use App\Enums\PackageDocument;
use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Enums\VatCode;
use App\Enums\WorkType;
use App\Models\ActivityLog;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkWeek;
use App\Support\DefaultTemplates;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Import danych starej aplikacji TM (tabele ascomm_login, tm_contr, tm_costnumber, tm_events, tm_timesheet).
 *
 * Zasady:
 * - można uruchamiać wielokrotnie: rekordy są rozpoznawane po legacy_id (oraz e-mailu, nazwie, numerze projektu),
 * - nic, co już istnieje w nowej aplikacji, nie jest nadpisywane — tylko uzupełniane są puste pola,
 * - wszystkie projekty trafiają do wskazanego klienta (Gärtner),
 * - zamknięte tygodnie (tm_timesheet) są zamykane, a zafakturowane oznaczane jako zafakturowane.
 */
final class LegacyImporter
{
    private LegacyImportReport $report;

    /** @var array<int, User> stary ID użytkownika => konto */
    private array $users = [];

    private ?User $owner = null;

    /** @var array<string, Project> numer projektu => projekt */
    private array $projects = [];

    public function __construct(private readonly ConnectionInterface $legacy) {}

    public static function connection(): self
    {
        return new self(DB::connection('legacy'));
    }

    public static function isConfigured(): bool
    {
        return filled(config('database.connections.legacy.database'));
    }

    /**
     * Liczba rekordów w tabelach źródłowych (sprawdza też połączenie).
     *
     * @return array<string, int>
     */
    public function sourceCounts(): array
    {
        return collect(['ascomm_login', 'tm_contr', 'tm_costnumber', 'tm_events', 'tm_timesheet'])
            ->mapWithKeys(fn (string $table) => [$table => $this->legacy->table($table)->count()])
            ->all();
    }

    public function run(Contractor $client, bool $dryRun = false): LegacyImportReport
    {
        $this->report = new LegacyImportReport;
        $this->report->dryRun = $dryRun;

        DB::beginTransaction();

        try {
            ActivityLog::withoutLogging(function () use ($client) {
                $this->importUsers();
                $this->importContractors($client);
                $this->importProjects($client);
                $this->importTimeEntries();
                $this->importTimesheets();
            });

            $this->compareHours();

            if ($dryRun) {
                DB::rollBack();
            } else {
                ActivityLog::query()->create([
                    'user_id' => Auth::id(),
                    'subject_type' => $client->getMorphClass(),
                    'subject_id' => $client->id,
                    'event' => 'legacy-import',
                    'properties' => $this->report->summary(),
                ]);

                DB::commit();
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $this->report;
    }

    private function importUsers(): void
    {
        $rows = $this->legacy->table('ascomm_login')->orderBy('ID')->get();

        foreach ($rows as $row) {
            $this->report->add('users', 'source');
            $email = Str::lower((string) LegacyText::clean($row->EMAIL));
            $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : Str::slug((string) $row->LOGIN).'@legacy.invalid';

            $user = User::query()->where('legacy_id', $row->ID)->first()
                ?? User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user !== null) {
                if ($user->legacy_id === null) {
                    $user->forceFill(['legacy_id' => $row->ID])->save();
                }

                $this->report->add('users', 'linked');
            } else {
                $user = new User([
                    'name' => trim(LegacyText::clean($row->NAME1).' '.LegacyText::clean($row->NAME2)) ?: (string) $row->LOGIN,
                    'email' => $email,
                    // Hasło ze starej bazy (jawny tekst) trafia tu wyłącznie jako hash.
                    'password' => filled($row->PASS) ? (string) $row->PASS : Str::random(40),
                    'role' => $row->LOGIN === 'admin' ? Role::Admin : Role::Employee,
                    'locale' => Language::Polish,
                    'is_active' => (int) $row->ACTIV === 1,
                ]);
                $user->forceFill(['legacy_id' => $row->ID, 'email_verified_at' => now()])->save();

                $this->report->add('users', 'created');
            }

            $this->users[(int) $row->ID] = $user;

            if ($row->LOGIN === 'admin') {
                $this->owner = $user;
            }
        }

        $this->owner ??= User::query()->where('role', Role::Admin)->orderBy('id')->first();
    }

    /**
     * Kontrahenci z tm_contr (bez własnej firmy). Klient docelowy dostaje legacy_id swojego wiersza.
     */
    private function importContractors(Contractor $client): void
    {
        $companyNip = preg_replace('/\D/', '', (string) CompanySetting::current()->nip);

        foreach ($this->legacy->table('tm_contr')->orderBy('ID')->get() as $row) {
            $this->report->add('contractors', 'source');
            $name = (string) LegacyText::clean($row->NAME);
            [$prefix, $taxId] = LegacyText::taxId(LegacyText::clean($row->TAX_ID));

            if ($taxId !== null && $taxId === $companyNip) {
                $this->report->add('contractors', 'own_company_skipped');

                continue;
            }

            $contractor = Contractor::query()->where('legacy_id', $row->ID)->first()
                ?? (Str::lower($name) === Str::lower($client->name) ? $client : null)
                ?? Contractor::query()->whereRaw('lower(name) = ?', [Str::lower($name)])->first();

            if ($contractor !== null) {
                if ($contractor->legacy_id === null) {
                    $contractor->forceFill(['legacy_id' => $row->ID])->save();
                }

                $this->report->add('contractors', 'linked');

                continue;
            }

            $country = LegacyText::countryCode($row->COUNTRY);
            $language = $country === 'PL' ? Language::Polish : Language::German;

            $contractor = new Contractor([
                'type' => ContractorType::Client,
                'name' => $name,
                'street' => LegacyText::clean($row->STREET),
                'zip' => LegacyText::zip($row->ZIPCODE, $country),
                'city' => LegacyText::clean($row->CITY),
                'country_code' => $country,
                'vat_prefix' => $prefix,
                'tax_id' => $taxId,
                'document_language' => $language,
                'invoice_language' => $country === 'PL' ? InvoiceLanguage::Polish : InvoiceLanguage::PolishEnglish,
                'currency' => $country === 'PL' ? 'PLN' : 'EUR',
                'vat_code' => $country === 'PL' ? VatCode::Rate23 : VatCode::ReverseCharge,
                'payment_days' => (int) $row->PAYDAY ?: 14,
                'package_documents' => PackageDocument::defaultOrder(),
                'invoice_description_template' => DefaultTemplates::invoiceDescription($language),
                'email_subject_template' => DefaultTemplates::emailSubject($language),
                'email_body_template' => DefaultTemplates::emailBody($language),
                'is_active' => (int) $row->ACT === 1,
            ]);
            $contractor->forceFill(['legacy_id' => $row->ID])->save();

            $this->report->add('contractors', 'created');
            $this->report->warn(__('Contractor :name was created — check its rates and billing settings.', ['name' => $name]));
        }
    }

    /**
     * Projekty z tm_costnumber. Godziny wskazują projekt tylko numerem, więc wiersze z tym samym
     * numerem są łączone w jeden projekt (nazwy pozostałych trafiają do notatek).
     */
    private function importProjects(Contractor $client): void
    {
        $groups = $this->legacy->table('tm_costnumber')->orderBy('ID')->get()->groupBy(fn ($row) => trim((string) $row->COST_N));
        $usedNumbers = $this->legacy->table('tm_events')->distinct()->pluck('COST_ACC')->map(fn ($number) => (string) $number)->all();

        foreach ($groups as $number => $rows) {
            $number = (string) $number;
            $this->report->add('projects', 'source', $rows->count());

            if (($number === '' || $number === '0') && ! in_array($number, $usedNumbers, true)) {
                $this->report->add('projects', 'skipped_without_number', $rows->count());

                continue;
            }

            $main = $rows->sortByDesc('ID')->first();

            if ($main === null) {
                continue;
            }

            if ($rows->count() > 1) {
                $this->report->add('projects', 'merged_duplicates', $rows->count() - 1);
                $this->report->warn(__('Project number :number appeared :count times — merged into one project.', ['number' => $number, 'count' => $rows->count()]));
            }

            $otherNames = $rows->where('ID', '!=', $main->ID)
                ->map(fn ($row) => LegacyText::clean($row->PROJECT))
                ->filter()
                ->unique()
                ->implode('; ');

            $values = [
                'name' => LegacyText::clean($main->PROJECT) ?? LegacyText::clean($main->INVESTOR) ?? __('Project :number', ['number' => $number]),
                'site_name' => LegacyText::clean($main->INVESTOR),
                'site_street' => LegacyText::clean($main->ADDRESS_1),
                'site_zip' => LegacyText::zip($main->ADDRESS_3, 'DE'),
                'site_city' => LegacyText::clean($main->ADDRESS_2),
                'site_country' => 'DE',
                'billing_type' => ProjectBillingType::Hourly,
                'km_one_way' => (int) $main->DIST > 0 ? (string) $main->DIST : null,
                'status' => $rows->contains(fn ($row) => (int) $row->ACT === 1) ? ProjectStatus::Active : ProjectStatus::Closed,
                'notes' => $otherNames !== '' ? __('Also: :names', ['names' => $otherNames]) : null,
            ];

            $project = Project::query()->where('legacy_id', $main->ID)->first()
                ?? Project::query()->where('contractor_id', $client->id)->where('number', $number)->first();

            if ($project === null) {
                $project = new Project(['contractor_id' => $client->id, 'number' => $number, ...$values]);
                $project->forceFill(['legacy_id' => $main->ID])->save();
                $this->report->add('projects', 'created');
            } else {
                foreach ($values as $attribute => $value) {
                    if (blank($project->getAttribute($attribute)) && $value !== null) {
                        $project->setAttribute($attribute, $value);
                    }
                }

                $project->forceFill(['legacy_id' => $project->legacy_id ?? $main->ID])->save();
                $this->report->add('projects', 'linked');
            }

            $this->projects[$number] = $project;
        }
    }

    private function importTimeEntries(): void
    {
        $existing = TimeEntry::query()->whereNotNull('legacy_id')->pluck('legacy_id')->flip();
        $assignments = [];

        foreach ($this->legacy->table('tm_events')->orderBy('ID')->get() as $row) {
            $this->report->add('time_entries', 'source');

            if ($existing->has($row->ID)) {
                $this->report->add('time_entries', 'already_imported');

                continue;
            }

            $project = $this->projects[(string) $row->COST_ACC] ?? null;

            if ($project === null) {
                $this->report->add('time_entries', 'skipped');
                $this->report->warn(__('Entry :id (:date): unknown project :number — skipped.', ['id' => $row->ID, 'date' => $row->DATE, 'number' => $row->COST_ACC]));

                continue;
            }

            // Stara edycja wpisu zapisywała login zamiast ID (USER = 0) — to wpisy właściciela konta „admin”.
            $user = $this->users[(int) $row->USER] ?? null;

            if ($user === null) {
                $user = $this->owner;
                $this->report->add('time_entries', 'assigned_to_owner');
            }

            $entry = new TimeEntry([
                'user_id' => $user?->id,
                'project_id' => $project->id,
                'work_date' => CarbonImmutable::parse($row->DATE)->toDateString(),
                'start_time' => substr((string) $row->T_STR, 0, 5),
                'end_time' => substr((string) $row->T_END, 0, 5),
                'break_minutes' => (int) $row->BR,
                'work_type' => (int) $row->WO_ART === 2 ? WorkType::Demontage : WorkType::Montage,
                'description' => LegacyText::clean($row->DESCR),
                'count_mileage' => false,
            ]);
            $entry->forceFill(['legacy_id' => $row->ID])->save();

            if (! BigDecimal::of($entry->hours)->isEqualTo(BigDecimal::of((string) $row->CAL_H))) {
                $this->report->warn(__('Entry :id (:date): :legacy h in the old app, :hours h from start/end/break.', [
                    'id' => $row->ID, 'date' => $row->DATE, 'legacy' => $row->CAL_H, 'hours' => $entry->hours,
                ]));
            }

            $assignments[$project->id][$entry->user_id] = true;
            $this->report->add('time_entries', 'created');
        }

        foreach ($assignments as $projectId => $userIds) {
            Project::query()->find($projectId)?->users()->syncWithoutDetaching(array_keys($userIds));
        }
    }

    /**
     * tm_timesheet: opisy tygodni per projekt, zamknięcie części tygodnia i znacznik „zafakturowane”.
     */
    private function importTimesheets(): void
    {
        foreach ($this->legacy->table('tm_timesheet')->orderBy('ID')->get() as $row) {
            $this->report->add('weeks', 'source');

            $week = WorkWeek::query()
                ->where('iso_week', $row->WN)
                ->where('year', $row->YEAR)
                ->where('month', $row->M)
                ->first();

            if ($week === null) {
                $this->report->warn(__('Closed week :week :month/:year has no working time — skipped.', ['week' => $row->WN, 'month' => $row->M, 'year' => $row->YEAR]));

                continue;
            }

            for ($slot = 1; $slot <= 10; $slot++) {
                $number = trim((string) $row->{'P'.$slot});

                if ($number === '' || $number === '0') {
                    continue;
                }

                $project = $this->projects[$number] ?? null;

                if ($project === null) {
                    $this->report->warn(__('Closed week :week: unknown project :number in the description.', ['week' => $week->label(), 'number' => $number]));

                    continue;
                }

                $report = WeeklyReport::query()->firstOrNew(['work_week_id' => $week->id, 'project_id' => $project->id]);
                $description = LegacyText::clean($row->{'P'.$slot.'_DESC'});

                if (blank($report->performed_work) && $description !== null) {
                    $report->performed_work = $description;
                    $this->report->add('weekly_reports', 'descriptions');
                }

                $report->legacy_id ??= (int) $row->ID;
                $report->save();
            }

            $stamp = $row->TIMESTAMP ? CarbonImmutable::parse($row->TIMESTAMP) : now();

            if (! $week->isClosed()) {
                $week->forceFill(['closed_at' => $stamp, 'closed_by' => $this->owner?->id])->save();
                $this->report->add('weeks', 'closed');
            }

            if ((int) $row->INVOICED === 1 && ! $week->isInvoiced()) {
                $week->forceFill(['invoiced_at' => $stamp])->save();
                $this->report->add('weeks', 'invoiced');
            }
        }
    }

    /**
     * Suma godzin per KW: stara aplikacja (CAL_H) kontra zaimportowane wpisy.
     */
    private function compareHours(): void
    {
        /** @var Collection<string, BigDecimal> $legacy */
        $legacy = collect();

        foreach ($this->legacy->table('tm_events')->get(['DATE', 'CAL_H']) as $row) {
            $date = CarbonImmutable::parse($row->DATE);
            $key = sprintf('%d-W%02d', $date->isoWeekYear(), $date->isoWeek());
            $legacy[$key] = ($legacy[$key] ?? BigDecimal::zero())->plus((string) $row->CAL_H);
        }

        /** @var Collection<string, BigDecimal> $imported */
        $imported = collect();

        TimeEntry::query()
            ->whereNotNull('legacy_id')
            ->with('workWeek:id,iso_year,iso_week')
            ->get(['id', 'work_week_id', 'hours'])
            ->each(function (TimeEntry $entry) use ($imported) {
                $key = sprintf('%d-W%02d', $entry->workWeek->iso_year, $entry->workWeek->iso_week);
                $imported[$key] = ($imported[$key] ?? BigDecimal::zero())->plus($entry->hours);
            });

        $weeks = $legacy->keys()->merge($imported->keys())->unique()->sortDesc()->values();

        foreach ($weeks as $week) {
            $this->report->weeks[] = [
                'week' => $week,
                'legacy' => (string) ($legacy[$week] ?? BigDecimal::zero())->toScale(2),
                'imported' => (string) ($imported[$week] ?? BigDecimal::zero())->toScale(2),
            ];
        }

        $this->report->legacyHours = (string) $legacy->reduce(fn (BigDecimal $sum, BigDecimal $hours) => $sum->plus($hours), BigDecimal::zero())->toScale(2);
        $this->report->importedHours = (string) $imported->reduce(fn (BigDecimal $sum, BigDecimal $hours) => $sum->plus($hours), BigDecimal::zero())->toScale(2);
    }
}
