<?php

use App\Documents\DocumentFormat;
use App\Documents\Montageauftrag;
use App\Documents\Stundennachweis;
use App\Documents\Stundenzettel;
use App\Enums\Language;
use App\Models\Contractor;
use App\Models\MaterialEntry;
use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkWeek;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanySeeder;
use Database\Seeders\GaertnerSeeder;
use Database\Seeders\ReferencePackageSeeder;

/**
 * Dane pakietu 4/8/2026 (KW 31–32): 98,75 h × 38 € = 3 752,50 €.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Adrian Sajnaga']);
    $this->seed([CompanySeeder::class, GaertnerSeeder::class, ReferencePackageSeeder::class]);
    $this->gaertner = Contractor::query()->where('name', 'Gärtner Elektrotechnik GmbH')->sole();
});

function reportFor(string $number, int $isoWeek, int $month): WeeklyReport
{
    return WeeklyReport::query()
        ->whereHas('project', fn ($query) => $query->where('number', $number))
        ->whereHas('workWeek', fn ($query) => $query->where('iso_week', $isoWeek)->where('month', $month))
        ->sole();
}

test('Stundenzettel reproduces the reference package 4/8/2026', function () {
    $document = new Stundenzettel($this->gaertner, WorkWeek::closed()->get());

    $sections = collect($document->sections())->map(fn (array $section) => [
        $section['week']->iso_week.'/'.$section['week']->month,
        (string) $section['rows'][0]->total,
    ])->all();

    // Kolejność jak w oryginale: KW 32, KW 31 (lipiec), KW 31 (sierpień).
    expect($sections)->toBe([['32/8', '40.25'], ['31/7', '50.50'], ['31/8', '8.00']]);

    $summary = $document->summary();

    expect($summary)->toHaveCount(1)
        ->and($summary[0]['user']->name)->toBe('Adrian Sajnaga')
        ->and((string) $summary[0]['hours'])->toBe('98.75')
        ->and((string) $summary[0]['rate'])->toBe('38.00')
        ->and((string) $summary[0]['amount'])->toBe('3752.50');
});

test('Stundenzettel uses German labels and number formats for a German client', function () {
    $html = view('pdf.stundenzettel', (new Stundenzettel($this->gaertner, WorkWeek::closed()->get()))->data())->render();

    expect($html)
        ->toContain('Stundenzettel')
        ->toContain('Zusammenfassung')
        ->toContain('STUNDENPREIS')
        ->toContain('98,75')
        ->toContain('3.752,50')
        ->toContain('Auftraggeber')
        ->toContain('Gärtner Elektrotechnik GmbH')
        ->toContain('Auftragnehmer');
});

test('Montageauftrag shows client, contractor, project, hours per day and report date', function () {
    $report = reportFor('160245002', 32, 8);
    MaterialEntry::query()->create(['weekly_report_id' => $report->id, 'position' => 0, 'name' => 'NYM-J 5x6', 'quantity' => '25', 'unit' => 'm']);

    $document = new Montageauftrag($report->fresh());
    $data = $document->data();

    expect($data['hours'])->toHaveCount(1)
        ->and((string) $data['hours'][0]->day(2))->toBe('9.75')
        ->and((string) $data['hours'][0]->day(3))->toBe('8.25')
        ->and((string) $data['hours'][0]->total)->toBe('18.00')
        ->and($data['reportDate']->toDateString())->toBe('2026-08-06')
        ->and($data['materialColumns'][0][0]['material']->name)->toBe('NYM-J 5x6')
        ->and($data['materialColumns'][0])->toHaveCount(5)
        ->and($data['materialColumns'][1])->toHaveCount(5)
        ->and($document->filename())->toBe('Montageauftrag_KW32-2026_08_160245002.pdf');

    $html = view($document->view(), $data)->render();

    expect($html)
        ->toContain('Montageauftrag')
        ->toContain('Auftraggeber')
        ->toContain('Gärtner Elektrotechnik GmbH')
        ->toContain('Auftragnehmer')
        ->toContain('ASCOMM Adrian Sajnaga')
        ->toContain('160245002')
        ->toContain('TKMS GmbH')
        ->toContain('Linke Hallenseite')
        ->toContain('9,75')
        ->toContain('06.08.2026');
});

test('a week crossing months gives separate Montageaufträge per month', function () {
    // TKMS w KW 31: 31.07 (lipiec, 3 h) i 01.08 (sierpień, 8 h) — dwa dokumenty, jak w oryginale.
    $july = new Montageauftrag(reportFor('160245002', 31, 7));
    $august = new Montageauftrag(reportFor('160245002', 31, 8));

    expect((string) $july->data()['hours'][0]->total)->toBe('3.00')
        ->and((string) $august->data()['hours'][0]->total)->toBe('8.00');
});

test('Stundennachweis lists the entries of one person with times, breaks and work type', function () {
    $week = WorkWeek::query()->where('iso_week', 31)->where('month', 7)->sole();
    $document = new Stundennachweis($week, $this->admin, $this->gaertner);
    $data = $document->data();

    expect($document->orientation())->toBe('L')
        ->and($data['entries'])->toHaveCount(9)
        ->and($data['emptyRows'])->toBe(6)
        ->and($data['clientLogo'])->not->toBeNull();

    $html = view($document->view(), $data)->render();

    expect($html)
        ->toContain('Stundennachweis')
        ->toContain('Kalenderwoche')
        ->toContain('0431 / 570 918 100')
        ->toContain('DEMONTAGE')
        ->toContain('11:15')
        ->toContain('17:00');
});

test('PDF endpoints return PDF files', function () {
    $this->actingAs($this->admin);
    $week = WorkWeek::query()->where('iso_week', 32)->sole();
    $project = Project::query()->where('number', '160245002')->sole();

    $this->get(route('documents.montageauftrag', [$week, $project]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $response = $this->get(route('documents.stundenzettel', ['client' => $this->gaertner->id, 'weeks' => WorkWeek::closed()->pluck('id')->all()]));
    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');

    $this->get(route('documents.stundennachweis', [$week, $this->admin, $this->gaertner]))->assertOk();
    $this->get(route('documents.montageauftraege', $week))->assertOk();
});

test('employees cannot download the timesheet with rates or other people\'s time records', function () {
    $employee = User::factory()->create();
    $week = WorkWeek::query()->where('iso_week', 32)->sole();

    $this->actingAs($employee)
        ->get(route('documents.stundenzettel', ['client' => $this->gaertner->id, 'weeks' => [$week->id]]))
        ->assertForbidden();

    $this->actingAs($employee)
        ->get(route('documents.stundennachweis', [$week, $this->admin, $this->gaertner]))
        ->assertForbidden();

    $this->actingAs($employee)
        ->get(route('documents.montageauftrag', [$week, Project::query()->where('number', '160245002')->sole()]))
        ->assertForbidden();
});

test('document formats follow the client language', function () {
    $de = new DocumentFormat(Language::German);
    $pl = new DocumentFormat(Language::Polish);
    $en = new DocumentFormat(Language::English);

    expect($de->money('1996.40', 'EUR'))->toBe("1.996,40\u{00A0}€")
        ->and($pl->money('1996.40', 'PLN'))->toBe("1\u{00A0}996,40\u{00A0}zł")
        ->and($en->number('1996.4'))->toBe('1,996.40')
        ->and($de->hours('8.00'))->toBe('8')
        ->and($de->hours('9.50'))->toBe('9,5')
        ->and($en->hours('10.75'))->toBe('10.75')
        ->and($de->date(CarbonImmutable::parse('2026-08-24')))->toBe('24.08.2026');
});
