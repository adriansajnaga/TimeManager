<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Każde logowanie aplikacji do serwera poczty (IMAP/SMTP) — żeby było widać, ile prób robi i skąd.
     */
    public function up(): void
    {
        Schema::create('mail_login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('protocol', 8);
            $table->boolean('succeeded');
            $table->string('message', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('page', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_login_attempts');
    }
};
