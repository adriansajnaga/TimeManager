<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Elewacja rozdzielnicy: szyny z modułami (RCD, obwody, F0/WG/SPD, puste miejsca) — JSON BoardLayout.
        Schema::table('measurement_boards', function (Blueprint $table) {
            $table->json('layout')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurement_boards', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
