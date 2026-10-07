<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // WLZ to odcinek kabla — nie rysujemy go na rzucie jako rozdzielnicy.
        DB::table('measurement_markers')
            ->whereIn('board_id', DB::table('measurement_boards')->where('kind', 'supply')->select('id'))
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
