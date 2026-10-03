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
        // Saldo konta Claude wpisane po doładowaniu (API nie udostępnia salda) — od niego odejmujemy koszty.
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->decimal('balance_usd', 10, 2)->nullable()->after('model');
            $table->timestamp('balance_set_at')->nullable()->after('balance_usd');
        });

        // Każde zapytanie do Claude: tokeny z odpowiedzi API i koszt wg cennika.
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 50);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['balance_usd', 'balance_set_at']);
        });
    }
};
