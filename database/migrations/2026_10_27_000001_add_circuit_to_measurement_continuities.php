<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wiersz ciągłości przewodu ochronnego generowany dla obwodu (usunięcie obwodu usuwa wiersz);
     * wiersze bez obwodu to pomiary dopisane ręcznie (np. połączenia wyrównawcze).
     */
    public function up(): void
    {
        Schema::table('measurement_continuities', function (Blueprint $table) {
            $table->foreignId('circuit_id')->nullable()->after('protocol_id')->constrained('measurement_circuits')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('measurement_continuities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('circuit_id');
        });
    }
};
