<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Odrzucone logowanie do serwera poczty — do czasu nowego hasła albo ręcznego testu aplikacja nie próbuje ponownie.
     */
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->timestamp('login_failed_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->dropColumn('login_failed_at');
        });
    }
};
