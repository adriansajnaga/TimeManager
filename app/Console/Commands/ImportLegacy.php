<?php

namespace App\Console\Commands;

use App\Models\Contractor;
use App\Services\LegacyImport\LegacyImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:legacy {--client= : ID klienta, do którego trafią projekty (domyślnie Gärtner)} {--dry-run : Sprawdź bez zapisywania}')]
#[Description('Import projektów, godzin i zamkniętych tygodni ze starej aplikacji TM (połączenie "legacy")')]
class ImportLegacy extends Command
{
    public function handle(): int
    {
        if (! LegacyImporter::isConfigured()) {
            $this->error('Ustaw LEGACY_DB_DATABASE (i ew. LEGACY_DB_USERNAME / LEGACY_DB_PASSWORD) w .env.');

            return self::FAILURE;
        }

        $client = $this->option('client')
            ? Contractor::query()->find($this->option('client'))
            : Contractor::query()->where('name', 'like', 'Gärtner%')->first();

        if ($client === null) {
            $this->error('Nie znaleziono klienta. Utwórz go w Kontrahentach albo podaj --client=ID.');

            return self::FAILURE;
        }

        $report = LegacyImporter::connection()->run($client, (bool) $this->option('dry-run'));

        $this->info(($report->dryRun ? '[SPRAWDZENIE — nic nie zapisano] ' : '').'Klient: '.$client->name);

        $rows = [];
        foreach ($report->counts as $section => $counts) {
            foreach ($counts as $key => $value) {
                $rows[] = [$section, $key, $value];
            }
        }
        $this->table(['Sekcja', 'Pozycja', 'Liczba'], $rows);

        $this->line(sprintf('Godziny: stara aplikacja %s h, zaimportowane %s h, tygodni %d.', $report->legacyHours, $report->importedHours, count($report->weeks)));

        if ($report->weekMismatches() !== []) {
            $this->warn('Tygodnie z różnicą godzin:');
            $this->table(['KW', 'Stara aplikacja', 'Import'], $report->weekMismatches());
        } else {
            $this->info('Suma godzin zgadza się w każdym tygodniu.');
        }

        foreach ($report->warnings as $warning) {
            $this->warn('- '.$warning);
        }

        return self::SUCCESS;
    }
}
