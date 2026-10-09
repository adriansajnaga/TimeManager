<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tabele techniczne frameworka — bez znaczenia dla użytkownika (i często bez kolumny id). */
    private const SKIP = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /**
     * Aplikacja działała w UTC, więc godziny (np. zapisania notatki) były o 1–2 h wcześniejsze.
     * Od teraz strefa to Europe/Warsaw — zapisane godziny przeliczamy z UTC na czas polski
     * (z uwzględnieniem czasu letniego i zimowego dla każdej daty).
     */
    public function up(): void
    {
        $this->convert('UTC', 'Europe/Warsaw');
    }

    public function down(): void
    {
        $this->convert('Europe/Warsaw', 'UTC');
    }

    private function convert(string $from, string $to): void
    {
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, self::SKIP, true) || ! Schema::hasColumn($name, 'id')) {
                continue;
            }

            $columns = collect(Schema::getColumns($name))
                ->filter(fn (array $column) => in_array(strtolower($column['type_name']), ['datetime', 'timestamp'], true))
                ->pluck('name')
                ->all();

            if ($columns === []) {
                continue;
            }

            DB::table($name)->select(['id', ...$columns])->orderBy('id')->chunk(500, function ($rows) use ($name, $columns, $from, $to) {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach ($columns as $column) {
                        if ($row->{$column} !== null) {
                            $changes[$column] = CarbonImmutable::parse($row->{$column}, $from)->setTimezone($to)->format('Y-m-d H:i:s');
                        }
                    }

                    if ($changes !== []) {
                        DB::table($name)->where('id', $row->id)->update($changes);
                    }
                }
            });
        }
    }
};
