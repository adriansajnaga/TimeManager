<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\WorkType;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkWeek;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Tygodnie KW 31–32/2026 z pakietu 4/8/2026 (faktura 3 977,80 €): godziny dzienne na projektach,
 * dni z kilometrówką i opisy „Ausgeführte Arbeiten”. Godziny rozpoczęcia są przykładowe
 * (stary PDF ich nie zawiera) — sumy godzin zgadzają się z oryginałem.
 *
 * Wymaga GaertnerSeeder.
 */
class ReferencePackageSeeder extends Seeder
{
    /**
     * Dzień => lista [numer projektu, godziny, kilometrówka, rodzaj pracy].
     */
    private const DAYS = [
        '2026-07-27' => [['160226043', '2.25', true], ['160226044', '3', true], ['160226039', '5', true, WorkType::Demontage]],
        '2026-07-28' => [['160226039', '10', false]],
        '2026-07-29' => [['160226044', '5.75', false], ['160226025', '3', false]],
        '2026-07-30' => [['160406010', '11', true]],
        '2026-07-31' => [['160226039', '7.5', true], ['160245002', '3', false]],
        '2026-08-01' => [['160245002', '8', false]],
        '2026-08-03' => [['160406010', '10.75', true]],
        '2026-08-04' => [['160226006', '9.5', true]],
        '2026-08-05' => [['160245002', '9.75', false]],
        '2026-08-06' => [['160245002', '8.25', false], ['160556005', '2', false]],
    ];

    /**
     * [dzień z części tygodnia, numer projektu] => Ausgeführte Arbeiten.
     */
    private const REPORTS = [
        ['2026-07-27', '160226043', 'Zwei Verteiler für die Blitzschutzanlage montiert.'],
        ['2026-07-27', '160226044', 'Kernbohrung durchgeführt, Manschette für die Kernbohrung montiert, Leitungen durchgeführt. Außenschrank montiert, Materialliste für LWL-Anschlüsse vorbereitet, CEE- und Schuko-Steckdosen im Außenschrank montiert.'],
        ['2026-07-27', '160226025', 'Lampe ausgetauscht, Deckenbaldachin montiert.'],
        ['2026-07-27', '160406010', 'Steuerungsanlage für das Heizregister montiert.'],
        ['2026-07-27', '160245002', 'Vorbereitungsarbeiten für die Montage des Repschalters durchgeführt und Material zusammengestellt.'],
        ['2026-07-27', '160226039', 'Demontage durchgeführt. Leitung 5x16 verlegt. Elektroverteilung umgebaut.'],
        ['2026-08-01', '160245002', 'Montage der Repschalter durchgeführt.'],
        ['2026-08-03', '160406010', 'Montage der Steuerungsanlage für das Heizregister fertiggestellt.'],
        ['2026-08-03', '160226006', '6x Kernbohrungen Ø160 mm durchgeführt, Montagematerial für die Kernbohrmaschine besorgt.'],
        ['2026-08-03', '160245002', 'Linke Hallenseite: Leitung 5x6 verlegt. Rechte Hallenseite: Klimaanschlüsse auf dem Dach in Betrieb genommen (NH00-Sicherungen in die Anschlusskästen eingesetzt, beschriftet, an den Repschaltern Spannung gemessen und beschriftet).'],
        ['2026-08-03', '160556005', 'Sämtliche Datenleitungen der Telefon- und Internetanlage gegen versehentliches Lösen gesichert. Stromzuleitungen mit Isolierband geschützt. Gehäuse der Zentrale wegen eingeschränkter Luftzirkulation entfernt, da sich die Zentrale während der Umbauarbeiten vorübergehend in horizontaler Lage befand. Alarmhistorie der Zentrale ausgelesen und überprüft.'],
    ];

    public function run(): void
    {
        $user = User::query()->where('role', Role::Admin)->orderBy('id')->firstOrFail();
        $projects = Project::query()->pluck('id', 'number');

        foreach (self::DAYS as $date => $items) {
            $this->seedDay(CarbonImmutable::parse($date), $items, $user, $projects->all());
        }

        foreach (self::REPORTS as [$date, $number, $text]) {
            WeeklyReport::query()->updateOrCreate([
                'work_week_id' => WorkWeek::forDate(CarbonImmutable::parse($date))->id,
                'project_id' => $projects[$number],
            ], ['performed_work' => $text]);
        }

        WorkWeek::query()
            ->whereIn('id', TimeEntry::query()->whereIn('work_date', array_keys(self::DAYS))->pluck('work_week_id'))
            ->get()
            ->each(fn (WorkWeek $week) => $week->close($user));
    }

    /**
     * Wpisy dnia jeden po drugim od 06:00; przerwa 45 min przy najdłuższym wpisie.
     *
     * @param  list<array{0: string, 1: string, 2: bool, 3?: WorkType}>  $items
     * @param  array<string, int>  $projects
     */
    private function seedDay(CarbonImmutable $date, array $items, User $user, array $projects): void
    {
        $longest = collect($items)->sortByDesc(fn (array $item) => (float) $item[1])->keys()->first();
        $start = 6 * 60;

        foreach ($items as $index => $item) {
            $break = $index === $longest ? 45 : 0;
            $end = $start + (int) round((float) $item[1] * 60) + $break;

            TimeEntry::query()->updateOrCreate([
                'user_id' => $user->id,
                'project_id' => $projects[$item[0]],
                'work_date' => $date->toDateString(),
            ], [
                'start_time' => sprintf('%02d:%02d', intdiv($start, 60), $start % 60),
                'end_time' => sprintf('%02d:%02d', intdiv($end, 60), $end % 60),
                'break_minutes' => $break,
                'work_type' => $item[3] ?? WorkType::Montage,
                'count_mileage' => $item[2],
            ]);

            $start = $end;
        }
    }
}
