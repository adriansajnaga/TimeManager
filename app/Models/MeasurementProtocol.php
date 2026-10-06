<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\Attachable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Protokół z pomiarów elektrycznych: strona tytułowa, parametry sieci i wszystkie wyniki.
 * Rzuty i zdjęcia obiektu są załącznikami protokołu.
 *
 * @property int $id
 * @property string $number
 * @property int $year
 * @property int $month
 * @property int $sequence
 * @property int|null $contractor_id
 * @property string|null $investor
 * @property string $place
 * @property string|null $description
 * @property CarbonImmutable $measured_on
 * @property int|null $instrument_id
 * @property string $network
 * @property int $phase_voltage
 * @property int $line_voltage
 * @property int $touch_voltage
 * @property string $disconnection_time
 * @property string|null $weather
 * @property string|null $temperature
 * @property string|null $remarks
 * @property string|null $verdict
 * @property CarbonImmutable|null $next_test_on
 * @property int|null $created_by
 * @property-read Contractor|null $contractor
 * @property-read MeasurementInstrument|null $instrument
 */
#[Fillable([
    'number', 'year', 'month', 'sequence', 'contractor_id', 'investor', 'place', 'description', 'measured_on', 'instrument_id',
    'network', 'phase_voltage', 'line_voltage', 'touch_voltage', 'disconnection_time', 'weather', 'temperature',
    'remarks', 'verdict', 'next_test_on', 'created_by',
])]
class MeasurementProtocol extends Model implements Attachable
{
    use HasAttachments;

    /** Badania okresowe co 5 lat (termin na stronie tytułowej). */
    public const NEXT_TEST_YEARS = 5;

    public const DEFAULT_VERDICT = 'Instalacja elektryczna w przedmiotowym zakresie wykonanych pomiarów spełnia wymagania norm i przepisów. Instalacja nadaje się do eksploatacji.';

    public const NETWORKS = ['TN-C-S', 'TN-S', 'TN-C', 'TT', 'IT'];

    /**
     * Oględziny jak w dotychczasowym protokole: sekcja, punkt, normy.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const INSPECTION_TEMPLATE = [
        ['OCHRONA PRZED DOTYKIEM BEZPOŚREDNIM', 'Sposób ochrony przed porażeniem prądem elektrycznym', 'PN-HD 60364-4-41:2017-09, PN-HD 60364-6-61:2016-07'],
        ['OCHRONA PRZED DOTYKIEM BEZPOŚREDNIM', 'Dobór urządzeń i środków ochrony w zależności od wpływów środowiskowych', 'PN-HD 60364-6:2016-07'],
        ['WYPOSAŻENIE', 'Sprawdzenie poprawności połączeń przewodów', 'PN-EN 60998-1:2006, PN-EN 60998-2-1:2006, PN-EN 60998-2-2:2006, PN-EN 60999-1:2002, PN-EN 61210:2010'],
        ['WYPOSAŻENIE', 'Stan urządzeń – brak widocznych uszkodzeń wpływających na pogorszenie bezpieczeństwa', 'PN-HD 60364-6:2016-07'],
        ['WYPOSAŻENIE', 'Dostęp do urządzeń dla wygodnej ich obsługi, konserwacji i napraw', 'PN-HD 60364-5-51:2011'],
        ['WYPOSAŻENIE', 'Sprawdzenie prawidłowości doboru przewodów do obciążalności prądowej', 'PN-HD 60364-5-52:2011, PN-HD 60364-4-43:2012'],
        ['IDENTYFIKACJA', 'Sprawdzenie prawidłowości oznaczania przewodów neutralnych i ochronnych oraz ochronno-neutralnych', 'PN-HD 60364-5-54:2011, PN-EN IEC 60445:2022-04'],
        ['IDENTYFIKACJA', 'Umieszczenie schematów, tablic ostrzegawczych i informacyjnych', 'PN-HD 60364-6:2016-07'],
        ['IDENTYFIKACJA', 'Oznaczenia obwodów, zabezpieczeń, łączników, zacisków i innych elementów instalacji', 'PN-HD 60364-5-51:2011, PN-HD 60364-6:2016-07'],
        ['BADANIE CIĄGŁOŚCI MAŁYCH REZYSTANCJI', 'Pomiar ciągłości przewodów ochronnych, w tym głównych i dodatkowych połączeń wyrównawczych oraz pomiar rezystancji przewodów ochronnych', 'PN-HD 60364-6, p. 6.4.3.2'],
    ];

    public function attachmentDirectory(): string
    {
        return 'measurements/'.$this->id;
    }

    /**
     * Kolejny numer w miesiącu pomiaru: PROT/{nr}/{miesiąc}/{rok}.
     *
     * @return array{number: string, year: int, month: int, sequence: int}
     */
    public static function nextNumber(CarbonInterface $date): array
    {
        $sequence = (int) static::query()->where('year', $date->year)->where('month', $date->month)->max('sequence') + 1;

        return [
            'number' => sprintf('PROT/%d/%d/%d', $sequence, $date->month, $date->year),
            'year' => $date->year,
            'month' => $date->month,
            'sequence' => $sequence,
        ];
    }

    /** Wstawia domyślną listę oględzin (wszystko „zgodny”). */
    public function seedInspections(): void
    {
        foreach (self::INSPECTION_TEMPLATE as $position => [$section, $item, $standard]) {
            $this->inspections()->create(['position' => $position + 1, 'section' => $section, 'item' => $item, 'standard' => $standard]);
        }
    }

    public function disconnectionTime(): float
    {
        return (float) $this->disconnection_time;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'sequence' => 'integer',
            'measured_on' => 'immutable_date',
            'next_test_on' => 'immutable_date',
            'phase_voltage' => 'integer',
            'line_voltage' => 'integer',
            'touch_voltage' => 'integer',
            'disconnection_time' => 'decimal:1',
            'temperature' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return BelongsTo<MeasurementInstrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(MeasurementInstrument::class);
    }

    /**
     * @return BelongsToMany<MeasurementPerformer, $this>
     */
    public function performers(): BelongsToMany
    {
        return $this->belongsToMany(MeasurementPerformer::class, 'measurement_performer_protocol', 'protocol_id', 'performer_id');
    }

    /**
     * @return HasMany<MeasurementInspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(MeasurementInspection::class, 'protocol_id')->orderBy('position');
    }

    /**
     * @return HasMany<MeasurementBoard, $this>
     */
    public function boards(): HasMany
    {
        return $this->hasMany(MeasurementBoard::class, 'protocol_id')->orderBy('position');
    }

    /**
     * @return HasMany<MeasurementEarthing, $this>
     */
    public function earthings(): HasMany
    {
        return $this->hasMany(MeasurementEarthing::class, 'protocol_id')->orderBy('position');
    }

    /**
     * @return HasMany<MeasurementContinuity, $this>
     */
    public function continuities(): HasMany
    {
        return $this->hasMany(MeasurementContinuity::class, 'protocol_id')->orderBy('position');
    }

    /**
     * @return HasMany<MeasurementMarker, $this>
     */
    public function markers(): HasMany
    {
        return $this->hasMany(MeasurementMarker::class, 'protocol_id')->orderBy('number');
    }

    /**
     * @return HasMany<MeasurementCableTest, $this>
     */
    public function cableTests(): HasMany
    {
        return $this->hasMany(MeasurementCableTest::class, 'protocol_id')->orderBy('position');
    }
}
