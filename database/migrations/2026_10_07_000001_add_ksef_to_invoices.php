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
        // Jeden wiersz: środowisko i token KSeF (token szyfrowany kluczem APP_KEY).
        Schema::create('ksef_settings', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 10)->default('test');
            $table->string('nip', 20)->nullable();
            $table->text('token')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->date('synced_until')->nullable();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            // app = wystawiona tutaj, ksef = pobrana z KSeF, manual = wpisana ręcznie (zakup spoza KSeF)
            $table->string('source', 10)->default('app')->after('status');
            $table->string('ksef_status', 10)->nullable()->after('cancelled_at');
            $table->string('ksef_number', 50)->nullable()->unique()->after('ksef_status');
            $table->string('ksef_environment', 10)->nullable()->after('ksef_number');
            $table->string('ksef_session', 100)->nullable()->after('ksef_environment');
            $table->string('ksef_reference', 100)->nullable()->after('ksef_session');
            $table->timestamp('ksef_sent_at')->nullable()->after('ksef_reference');
            $table->text('ksef_error')->nullable()->after('ksef_sent_at');
            $table->longText('xml')->nullable()->after('ksef_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['ksef_number']);
            $table->dropColumn(['source', 'ksef_status', 'ksef_number', 'ksef_environment', 'ksef_session', 'ksef_reference', 'ksef_sent_at', 'ksef_error', 'xml']);
        });

        Schema::dropIfExists('ksef_settings');
    }
};
