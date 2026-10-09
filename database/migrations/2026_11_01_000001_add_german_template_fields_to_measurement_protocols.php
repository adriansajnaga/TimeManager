<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pola protokołu niemieckiego (klient z DE): powód badania i numery zlecenia.
     */
    public function up(): void
    {
        Schema::table('measurement_protocols', function (Blueprint $table) {
            $table->string('inspection_reason', 20)->default('new');
            $table->string('external_order', 100)->nullable();
            $table->string('internal_order', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('measurement_protocols', function (Blueprint $table) {
            $table->dropColumn(['inspection_reason', 'external_order', 'internal_order']);
        });
    }
};
