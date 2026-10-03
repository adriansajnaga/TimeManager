<?php

namespace App\Documents;

use App\Enums\Language;
use App\Models\Contractor;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkWeek;
use Illuminate\Support\Collection;

/**
 * Stundennachweis — tygodniowy formularz godzin klienta (nagłówek i logo klienta), poziomo.
 * Jedna osoba, jedna część tygodnia, projekty tego klienta.
 */
final class Stundennachweis extends WorkDocument
{
    /** Minimalna liczba wierszy formularza. */
    private const ROWS = 15;

    public function __construct(
        private readonly WorkWeek $week,
        private readonly User $user,
        private readonly Contractor $client,
    ) {}

    public function view(): string
    {
        return 'pdf.stundennachweis';
    }

    public function orientation(): string
    {
        return 'L';
    }

    public function language(): Language
    {
        return $this->client->document_language;
    }

    public function filename(): string
    {
        return sprintf('Stundennachweis_KW%02d-%d_%02d_%s.pdf', $this->week->iso_week, $this->week->iso_year, $this->week->month, str($this->user->name)->slug());
    }

    /**
     * @return Collection<int, TimeEntry>
     */
    public function entries(): Collection
    {
        return TimeEntry::query()
            ->with('project')
            ->where('work_week_id', $this->week->id)
            ->where('user_id', $this->user->id)
            ->whereHas('project', fn ($query) => $query->where('contractor_id', $this->client->id))
            ->orderBy('work_date')
            ->orderBy('start_time')
            ->get();
    }

    public function data(): array
    {
        $entries = $this->entries();

        return [
            ...$this->common(),
            'client' => $this->client,
            'clientLogo' => self::logoPath($this->client->logo_path),
            'user' => $this->user,
            'week' => $this->week,
            'entries' => $entries,
            'emptyRows' => max(0, self::ROWS - $entries->count()),
            'lastDate' => $entries->max('work_date'),
        ];
    }
}
