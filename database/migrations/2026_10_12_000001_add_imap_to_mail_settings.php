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
        // Odbiór poczty (IMAP) tej samej skrzynki: login i hasło jak przy SMTP.
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->string('imap_host')->nullable()->after('bcc');
            $table->unsignedSmallInteger('imap_port')->default(993)->after('imap_host');
            $table->string('imap_encryption', 10)->default('ssl')->after('imap_port');
            $table->string('sent_folder')->nullable()->after('imap_encryption');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->dropColumn(['imap_host', 'imap_port', 'imap_encryption', 'sent_folder']);
        });
    }
};
