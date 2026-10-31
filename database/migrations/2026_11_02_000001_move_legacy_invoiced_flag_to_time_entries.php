<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Znacznik „zafakturowane” ze starej aplikacji dotyczył godzin, które w niej były — nie całej części tygodnia.
     * Przy tygodniu blokował też godziny dopisane później w nowej aplikacji (każdy klient), więc takie tygodnie
     * znikały z rozliczenia. Przenosimy go na zaimportowane godziny (legacy_id), a tydzień zostaje bez znacznika.
     * Tygodnie zafakturowane w nowej aplikacji mają rozliczenia — tych nie ruszamy.
     */
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->boolean('legacy_invoiced')->default(false)->after('count_mileage');
        });

        $weeks = DB::table('work_weeks')
            ->whereNotNull('invoiced_at')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('settlement_work_week')->whereColumn('settlement_work_week.work_week_id', 'work_weeks.id'))
            ->pluck('id');

        foreach ($weeks->chunk(200) as $chunk) {
            DB::table('time_entries')->whereIn('work_week_id', $chunk)->whereNotNull('legacy_id')->update(['legacy_invoiced' => true]);
            DB::table('work_weeks')->whereIn('id', $chunk)->update(['invoiced_at' => null]);
        }
    }

    public function down(): void
    {
        $weeks = DB::table('time_entries')->where('legacy_invoiced', true)->distinct()->pluck('work_week_id');

        foreach ($weeks->chunk(200) as $chunk) {
            DB::table('work_weeks')->whereIn('id', $chunk)->whereNull('invoiced_at')->update(['invoiced_at' => now()]);
        }

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn('legacy_invoiced');
        });
    }
};
