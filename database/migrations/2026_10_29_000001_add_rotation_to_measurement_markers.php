<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Obrót symbolu na rzucie (0/90/180/270°) — gniazdo ustawione do ściany, przy której jest.
     */
    public function up(): void
    {
        Schema::table('measurement_markers', function (Blueprint $table) {
            $table->unsignedSmallInteger('rotation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('measurement_markers', function (Blueprint $table) {
            $table->dropColumn('rotation');
        });
    }
};
