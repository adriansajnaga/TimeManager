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
        // Własne wskazówki użytkownika dołączane do instrukcji asystenta (słownictwo, styl, nazwy).
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->text('instructions')->nullable()->after('model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('instructions');
        });
    }
};
