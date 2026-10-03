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
        // Nagłówek dokumentów klienta (np. Stundennachweis Gärtnera): faks, strona www, logo.
        Schema::table('contractors', function (Blueprint $table) {
            $table->string('fax', 50)->nullable()->after('phone');
            $table->string('website')->nullable()->after('fax');
            $table->string('logo_path')->nullable()->after('website');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropColumn(['fax', 'website', 'logo_path']);
        });
    }
};
