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
        // Część tygodnia już zafakturowana (rozliczenie albo stara aplikacja) — nie trafia do kolejnego rozliczenia.
        Schema::table('work_weeks', function (Blueprint $table) {
            $table->timestamp('invoiced_at')->nullable()->after('closed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_weeks', function (Blueprint $table) {
            $table->dropColumn('invoiced_at');
        });
    }
};
