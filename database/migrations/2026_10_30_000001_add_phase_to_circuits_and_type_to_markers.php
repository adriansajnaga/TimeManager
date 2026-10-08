<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faza obwodu jednofazowego (L1/L2/L3) — w protokole izolacji zamiast zawsze L1;
     * typ znacznika na rzucie: 'bonding' — główna szyna wyrównawcza (GSW).
     */
    public function up(): void
    {
        Schema::table('measurement_circuits', function (Blueprint $table) {
            $table->string('phase', 2)->default('L1')->after('phases');
        });

        Schema::table('measurement_markers', function (Blueprint $table) {
            $table->string('type', 20)->nullable()->after('board_id');
        });
    }

    public function down(): void
    {
        Schema::table('measurement_circuits', function (Blueprint $table) {
            $table->dropColumn('phase');
        });

        Schema::table('measurement_markers', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
