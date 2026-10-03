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
        // Klienci i dostawcy w jednej tabeli.
        Schema::create('contractors', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->default('client');
            $table->string('name')->index();
            $table->string('street')->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('city')->nullable();
            $table->char('country_code', 2)->default('PL');
            $table->string('vat_prefix', 2)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();

            // Dokumenty i faktury
            $table->string('document_language', 2)->default('pl');
            $table->string('invoice_language', 5)->default('pl');
            $table->char('currency', 3)->default('PLN');
            $table->string('vat_code', 10)->default('23');
            $table->string('invoice_line_mode', 10)->default('single');
            $table->text('invoice_description_template')->nullable();
            $table->unsignedSmallInteger('payment_days')->default(14);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->json('package_documents')->nullable();

            // Rozliczenie
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('km_rate', 10, 4)->nullable();
            $table->string('base_address')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();

            // E-mail do klienta
            $table->json('email_to')->nullable();
            $table->json('email_cc')->nullable();
            $table->string('email_subject_template')->nullable();
            $table->text('email_body_template')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractors');
    }
};
