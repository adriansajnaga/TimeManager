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
        // Podpis pliku (np. „Umowa ramowa 2026”) i opcjonalna data ważności — alert na pulpicie przed jej upływem.
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
            $table->date('expires_at')->nullable()->after('size')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['description', 'expires_at']);
        });
    }
};
