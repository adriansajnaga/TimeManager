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
        // Jeden wiersz: serwer SMTP do wysyłki faktur (hasło szyfrowane kluczem APP_KEY).
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(465);
            $table->string('encryption', 10)->default('ssl');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('bcc')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // Dziennik wysłanych e-maili (także nieudanych prób).
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->json('to');
            $table->json('cc')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->string('attachment')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('emailed_at')->nullable()->after('ksef_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('emailed_at');
        });

        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('mail_settings');
    }
};
