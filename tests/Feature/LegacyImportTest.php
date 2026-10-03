<?php

use App\Enums\Role;
use App\Enums\WorkType;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkWeek;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\LegacyText;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * Stara baza w osobnej bazie SQLite w pamięci, z tymi samymi błędami co prawdziwe dane:
 * podwójnie zakodowane teksty, USER = 0, zduplikowany numer projektu, tydzień na przełomie miesięcy.
 */
beforeEach(function () {
    config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('legacy');
    $legacy = Schema::connection('legacy');

    $legacy->create('ascomm_login', function (Blueprint $table) {
        $table->integer('ID');
        $table->string('LOGIN');
        $table->string('PASS');
        $table->string('NAME1');
        $table->string('NAME2');
        $table->string('EMAIL');
        $table->string('ACTIV');
    });
    $legacy->create('tm_contr', function (Blueprint $table) {
        $table->integer('ID');
        foreach (['NAME', 'COUNTRY', 'CITY', 'ZIPCODE', 'STREET', 'TAX_ID', 'PAYDAY', 'ACT'] as $column) {
            $table->string($column)->nullable();
        }
    });
    $legacy->create('tm_costnumber', function (Blueprint $table) {
        $table->integer('ID');
        $table->integer('COST_N');
        foreach (['INVESTOR', 'ADDRESS_1', 'ADDRESS_2', 'PROJECT'] as $column) {
            $table->string($column)->nullable();
        }
        $table->integer('ADDRESS_3')->nullable();
        $table->integer('DIST')->default(0);
        $table->integer('ACT')->default(1);
    });
    $legacy->create('tm_events', function (Blueprint $table) {
        $table->integer('ID');
        $table->integer('USER');
        $table->date('DATE');
        $table->string('T_STR');
        $table->string('T_END');
        $table->integer('BR');
        $table->integer('COST_ACC');
        $table->string('WO_ART');
        $table->text('DESCR')->nullable();
        $table->decimal('CAL_H', 15, 2);
        $table->integer('WN');
    });
    $legacy->create('tm_timesheet', function (Blueprint $table) {
        $table->integer('ID');
        $table->integer('WN');
        $table->integer('M');
        $table->integer('YEAR');
        $table->integer('INVOICED');
        for ($slot = 1; $slot <= 10; $slot++) {
            $table->integer('P'.$slot)->default(0);
            $table->text('P'.$slot.'_DESC')->nullable();
        }
        $table->timestamp('TIMESTAMP')->nullable();
    });

    $db = DB::connection('legacy');
    $db->table('ascomm_login')->insert([
        ['ID' => 1, 'LOGIN' => 'admin', 'PASS' => 'old-secret', 'NAME1' => 'Adrian', 'NAME2' => 'Sajnaga', 'EMAIL' => 'adrian@example.com', 'ACTIV' => '1'],
        ['ID' => 2, 'LOGIN' => 'anna', 'PASS' => 'anna-secret', 'NAME1' => 'Anna', 'NAME2' => 'Sajnaga', 'EMAIL' => 'anna@example.com', 'ACTIV' => '1'],
    ]);
    $db->table('tm_contr')->insert([
        ['ID' => 1, 'NAME' => 'ASCOMM Adrian Sajnaga', 'COUNTRY' => 'Polska', 'CITY' => 'Torun', 'ZIPCODE' => '87-100', 'STREET' => 'ul. Konstytucji 3 Maja 15/12', 'TAX_ID' => 'NIP: 8792451081', 'PAYDAY' => '', 'ACT' => '0'],
        ['ID' => 2, 'NAME' => 'Gärtner Elektrotechnik GmbH', 'COUNTRY' => 'Deutschland', 'CITY' => 'Kiel', 'ZIPCODE' => 'D-24143', 'STREET' => 'Zum Brook 9', 'TAX_ID' => 'USt-Id-Nr.: DE 286771111', 'PAYDAY' => '7', 'ACT' => '1'],
        ['ID' => 3, 'NAME' => 'Kisin & Bhatti Immobilien GmbH', 'COUNTRY' => 'Deutschland', 'CITY' => 'Kronshagen', 'ZIPCODE' => '24119', 'STREET' => 'Kopperpahler Allee 130a', 'TAX_ID' => 'USt-IdNr. DE353468347', 'PAYDAY' => '7', 'ACT' => '1'],
    ]);
    $db->table('tm_costnumber')->insert([
        ['ID' => 10, 'COST_N' => 160223073, 'INVESTOR' => 'LĂźrssen KrĂźger Werft Rendsburg', 'ADDRESS_1' => 'HĂźttenstraĂe 25', 'ADDRESS_2' => 'Schacht-Audorf', 'ADDRESS_3' => 24790, 'PROJECT' => 'LWL Dock 1 und 2', 'DIST' => 33, 'ACT' => 1],
        ['ID' => 11, 'COST_N' => 160222048, 'INVESTOR' => 'Werft', 'ADDRESS_1' => null, 'ADDRESS_2' => null, 'ADDRESS_3' => 4113, 'PROJECT' => 'Halle 14 - MĂ¤ngel', 'DIST' => 0, 'ACT' => 0],
        ['ID' => 12, 'COST_N' => 160222048, 'INVESTOR' => 'Werft', 'ADDRESS_1' => null, 'ADDRESS_2' => null, 'ADDRESS_3' => 4113, 'PROJECT' => 'Halle 14 - Heizung', 'DIST' => 0, 'ACT' => 1],
        ['ID' => 13, 'COST_N' => 0, 'INVESTOR' => '', 'ADDRESS_1' => null, 'ADDRESS_2' => null, 'ADDRESS_3' => 0, 'PROJECT' => '', 'DIST' => 0, 'ACT' => 1],
    ]);
    $db->table('tm_events')->insert([
        // KW 40/2026: poniedziałek we wrześniu, czwartek w październiku
        ['ID' => 100, 'USER' => 1, 'DATE' => '2026-09-28', 'T_STR' => '06:00:00.0000', 'T_END' => '17:00:00.0000', 'BR' => 45, 'COST_ACC' => 160223073, 'WO_ART' => '1', 'DESCR' => 'LadegerĂ¤t fĂźr Stapler angeschloĂen', 'CAL_H' => 10.25, 'WN' => 40],
        ['ID' => 101, 'USER' => 0, 'DATE' => '2026-10-01', 'T_STR' => '07:00:00.0000', 'T_END' => '15:00:00.0000', 'BR' => 0, 'COST_ACC' => 160222048, 'WO_ART' => '2', 'DESCR' => '', 'CAL_H' => 8.00, 'WN' => 40],
        ['ID' => 102, 'USER' => 1, 'DATE' => '2026-10-05', 'T_STR' => '07:00:00.0000', 'T_END' => '09:30:00.0000', 'BR' => 30, 'COST_ACC' => 160223073, 'WO_ART' => '1', 'DESCR' => null, 'CAL_H' => 2.00, 'WN' => 41],
    ]);
    $db->table('tm_timesheet')->insert([
        ['ID' => 500, 'WN' => 40, 'M' => 9, 'YEAR' => 2026, 'INVOICED' => 1, 'P1' => 160223073, 'P1_DESC' => '6x Kernbohrungen ?160 mm, DurchbrĂźche verschlossen', 'TIMESTAMP' => '2026-09-30 18:00:00'],
        ['ID' => 501, 'WN' => 40, 'M' => 10, 'YEAR' => 2026, 'INVOICED' => 0, 'P1' => 160222048, 'P1_DESC' => 'Heizung angeschlossen', 'TIMESTAMP' => '2026-10-04 18:00:00'],
    ]);

    $this->admin = User::factory()->admin()->create(['email' => 'adrian@example.com']);
    $this->gaertner = Contractor::factory()->german()->create(['name' => 'Gärtner Elektrotechnik GmbH']);
});

test('double-encoded texts are repaired and correct texts stay untouched', function () {
    expect(LegacyText::repair('LadegerĂ¤t fĂźr Stapler angeschloĂen'))->toBe('Ladegerät für Stapler angeschloßen')
        ->and(LegacyText::repair('WĹaĹciciel'))->toBe('Właściciel')
        ->and(LegacyText::repair('Gärtner Elektrotechnik GmbH'))->toBe('Gärtner Elektrotechnik GmbH')
        ->and(LegacyText::repair('Hopfenstraße 1 a-d'))->toBe('Hopfenstraße 1 a-d')
        ->and(LegacyText::clean('6x Kernbohrungen ?160 mm'))->toBe('6x Kernbohrungen Ø160 mm')
        ->and(LegacyText::clean('   '))->toBeNull()
        ->and(LegacyText::taxId('USt-Id-Nr.: DE 286771111'))->toBe(['DE', '286771111'])
        ->and(LegacyText::taxId('NIP: 8792451081'))->toBe([null, '8792451081'])
        ->and(LegacyText::zip('D-24143', 'DE'))->toBe('24143')
        ->and(LegacyText::zip(4113, 'DE'))->toBe('04113');
});

test('import brings users, contractors, projects, hours and closed weeks', function () {
    $report = LegacyImporter::connection()->run($this->gaertner);

    // Konto właściciela połączone po e-mailu (bez zmiany hasła), drugie konto utworzone z zahashowanym hasłem.
    expect($this->admin->fresh()->legacy_id)->toBe(1)
        ->and(Hash::check('password', $this->admin->fresh()->password))->toBeTrue();

    $anna = User::query()->where('email', 'anna@example.com')->sole();
    expect($anna->role)->toBe(Role::Employee)
        ->and(Hash::check('anna-secret', $anna->password))->toBeTrue()
        ->and($anna->password)->not->toBe('anna-secret');

    // Kontrahenci: własna firma pominięta, Gärtner połączony, nowy utworzony.
    expect($this->gaertner->fresh()->legacy_id)->toBe(2);
    $kisin = Contractor::query()->where('name', 'Kisin & Bhatti Immobilien GmbH')->sole();
    expect($kisin->vatId())->toBe('DE 353468347')->and($kisin->currency)->toBe('EUR');

    // Projekty: naprawione teksty, zduplikowany numer połączony, numer 0 pominięty.
    $lurssen = Project::query()->where('number', '160223073')->sole();
    expect($lurssen->contractor_id)->toBe($this->gaertner->id)
        ->and($lurssen->site_name)->toBe('Lürssen Krüger Werft Rendsburg')
        ->and($lurssen->site_street)->toBe('Hüttenstraße 25')
        ->and($lurssen->km_one_way)->toBe('33.0');

    $halle = Project::query()->where('number', '160222048')->sole();
    expect($halle->name)->toBe('Halle 14 - Heizung')
        ->and($halle->notes)->toContain('Halle 14 - Mängel')
        ->and($halle->site_zip)->toBe('04113');
    expect(Project::query()->where('number', '0')->exists())->toBeFalse();

    // Godziny: USER = 0 → właściciel, Demontage, opisy naprawione.
    $entries = TimeEntry::query()->orderBy('legacy_id')->get();
    expect($entries)->toHaveCount(3)
        ->and($entries[0]->description)->toBe('Ladegerät für Stapler angeschloßen')
        ->and($entries[1]->user_id)->toBe($this->admin->id)
        ->and($entries[1]->work_type)->toBe(WorkType::Demontage)
        ->and($entries[1]->hours)->toBe('8.00');

    // Tydzień 40 na przełomie miesięcy: obie części zamknięte, wrzesień zafakturowany, październik nie.
    $september = WorkWeek::query()->where('iso_week', 40)->where('month', 9)->sole();
    $october = WorkWeek::query()->where('iso_week', 40)->where('month', 10)->sole();
    expect($september->isClosed())->toBeTrue()
        ->and($september->isInvoiced())->toBeTrue()
        ->and($october->isClosed())->toBeTrue()
        ->and($october->isInvoiced())->toBeFalse();

    // KW 41 nie miała timesheetu — zostaje otwarta.
    expect(WorkWeek::query()->where('iso_week', 41)->sole()->isClosed())->toBeFalse();

    $report41 = WeeklyReport::query()->where('work_week_id', $september->id)->sole();
    expect($report41->performed_work)->toBe('6x Kernbohrungen Ø160 mm, Durchbrüche verschlossen');

    expect($report->hoursMatch())->toBeTrue()
        ->and($report->legacyHours)->toBe('20.25')
        ->and($report->counts['time_entries']['assigned_to_owner'])->toBe(1)
        ->and($report->counts['projects']['merged_duplicates'])->toBe(1);
});

test('running the import twice creates no duplicates and keeps later edits', function () {
    LegacyImporter::connection()->run($this->gaertner);

    $project = Project::query()->where('number', '160223073')->sole();
    $project->update(['name' => 'Mein eigener Name']);

    $report = LegacyImporter::connection()->run($this->gaertner);

    expect(TimeEntry::count())->toBe(3)
        ->and(Project::count())->toBe(2)
        ->and(User::count())->toBe(2)
        ->and($project->fresh()->name)->toBe('Mein eigener Name')
        ->and($report->counts['time_entries']['already_imported'])->toBe(3);
});

test('dry run saves nothing', function () {
    $report = LegacyImporter::connection()->run($this->gaertner, dryRun: true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->counts['time_entries']['created'])->toBe(3)
        ->and(TimeEntry::count())->toBe(0)
        ->and(Project::count())->toBe(0);
});

test('activity log gets one summary entry instead of one per record', function () {
    $this->actingAs($this->admin);

    $before = DB::table('activity_log')->count();

    LegacyImporter::connection()->run($this->gaertner);

    expect(DB::table('activity_log')->where('event', 'legacy-import')->count())->toBe(1)
        ->and(DB::table('activity_log')->count())->toBe($before + 1);
});

test('administrator runs the import from the admin page', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.legacy-import')
        ->assertSet('client_id', (string) $this->gaertner->id)
        ->call('run', false)
        ->assertSet('result.legacy_hours', '20.25')
        ->assertSet('result.imported_hours', '20.25');

    expect(TimeEntry::count())->toBe(3);
});

test('employees cannot run the import', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.legacy-import')
        ->call('run', false)
        ->assertForbidden();
});
