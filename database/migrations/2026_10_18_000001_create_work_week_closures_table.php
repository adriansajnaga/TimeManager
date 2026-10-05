<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Zamknięcie części tygodnia osobno dla każdego klienta — zaległe godziny innego klienta można dopisać.
        // work_weeks.closed_at zostaje jako „zamknięty dla wszystkich klientów z godzinami”.
        Schema::create('work_week_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_week_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('closed_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['work_week_id', 'contractor_id']);
        });

        // Dotychczas zamknięte tygodnie: zamknięte dla każdego klienta, który ma w nich godziny.
        $rows = DB::table('work_weeks')
            ->join('time_entries', 'time_entries.work_week_id', '=', 'work_weeks.id')
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->whereNotNull('work_weeks.closed_at')
            ->select('work_weeks.id as work_week_id', 'projects.contractor_id', 'work_weeks.closed_at', 'work_weeks.closed_by')
            ->distinct()
            ->get();

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('work_week_closures')->insert($chunk->map(fn ($row) => [
                'work_week_id' => $row->work_week_id,
                'contractor_id' => $row->contractor_id,
                'closed_at' => $row->closed_at,
                'closed_by' => $row->closed_by,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_week_closures');
    }
};
