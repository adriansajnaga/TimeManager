<?php

namespace App\Services\LegacyImport;

/**
 * Wynik importu: liczby rekordów, ostrzeżenia i porównanie godzin per KW ze starą aplikacją.
 */
final class LegacyImportReport
{
    /** @var array<string, array<string, int>> */
    public array $counts = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<array{week: string, legacy: string, imported: string}> */
    public array $weeks = [];

    public string $legacyHours = '0.00';

    public string $importedHours = '0.00';

    public bool $dryRun = false;

    public function add(string $section, string $key, int $amount = 1): void
    {
        $this->counts[$section][$key] = ($this->counts[$section][$key] ?? 0) + $amount;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Tygodnie, w których suma godzin różni się od starej aplikacji.
     *
     * @return list<array{week: string, legacy: string, imported: string}>
     */
    public function weekMismatches(): array
    {
        return array_values(array_filter($this->weeks, fn (array $week) => $week['legacy'] !== $week['imported']));
    }

    public function hoursMatch(): bool
    {
        return $this->legacyHours === $this->importedHours && $this->weekMismatches() === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'counts' => $this->counts,
            'legacy_hours' => $this->legacyHours,
            'imported_hours' => $this->importedHours,
            'weeks' => count($this->weeks),
            'week_mismatches' => count($this->weekMismatches()),
            'warnings' => count($this->warnings),
        ];
    }
}
