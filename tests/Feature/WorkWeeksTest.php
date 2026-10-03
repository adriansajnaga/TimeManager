<?php

use App\Models\TimeEntry;
use App\Models\WorkWeek;
use Carbon\CarbonImmutable;

test('a week crossing a month boundary is split into two parts', function () {
    // KW 40/2026: pon 28.09 – nd 04.10
    $september = WorkWeek::forDate(CarbonImmutable::parse('2026-09-29'));
    $october = WorkWeek::forDate(CarbonImmutable::parse('2026-10-02'));

    expect($september->is($october))->toBeFalse()
        ->and([$september->iso_week, $september->month])->toBe([40, 9])
        ->and($september->starts_on->toDateString())->toBe('2026-09-28')
        ->and($september->ends_on->toDateString())->toBe('2026-09-30')
        ->and([$october->iso_week, $october->month])->toBe([40, 10])
        ->and($october->starts_on->toDateString())->toBe('2026-10-01')
        ->and($october->ends_on->toDateString())->toBe('2026-10-04');

    expect(WorkWeek::forDate(CarbonImmutable::parse('2026-09-28'))->is($september))->toBeTrue();
});

test('a week inside one month is a single part', function () {
    $week = WorkWeek::forDate(CarbonImmutable::parse('2026-08-05'));

    expect($week->starts_on->toDateString())->toBe('2026-08-03')
        ->and($week->ends_on->toDateString())->toBe('2026-08-09')
        ->and($week->label())->toBe('KW 32/2026 (03.08–09.08)');
});

test('KW 53 at the turn of the year belongs to the ISO year but splits by calendar month', function () {
    // 31.12.2026 (czw) i 01.01.2027 (pt) to oba KW 53/2026
    $december = WorkWeek::forDate(CarbonImmutable::parse('2026-12-31'));
    $january = WorkWeek::forDate(CarbonImmutable::parse('2027-01-01'));
    $nextWeek = WorkWeek::forDate(CarbonImmutable::parse('2027-01-04'));

    expect([$december->iso_year, $december->iso_week, $december->year, $december->month])->toBe([2026, 53, 2026, 12])
        ->and([$january->iso_year, $january->iso_week, $january->year, $january->month])->toBe([2026, 53, 2027, 1])
        ->and($january->ends_on->toDateString())->toBe('2027-01-03')
        ->and([$nextWeek->iso_year, $nextWeek->iso_week])->toBe([2027, 1]);
});

test('week days always cover the full ISO week', function () {
    $part = WorkWeek::forDate(CarbonImmutable::parse('2026-10-01'));

    expect(array_map(fn ($day) => $day->toDateString(), $part->weekDays()))->toBe([
        '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04',
    ]);
});

test('time entries compute hours and their week part on save', function () {
    $entry = TimeEntry::factory()->on('2026-07-31', '06:00', '17:15', 45)->create();

    expect($entry->hours)->toBe('10.50')
        ->and($entry->workWeek->iso_week)->toBe(31)
        ->and($entry->workWeek->month)->toBe(7);

    $entry->update(['work_date' => '2026-08-01']);

    expect($entry->fresh()->workWeek->month)->toBe(8);
});

test('end of work at midnight is allowed', function () {
    expect(TimeEntry::calculateHours('16:00', '24:00', 30))->toBe('7.50');
});
