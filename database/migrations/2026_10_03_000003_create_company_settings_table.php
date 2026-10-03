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
        // Jeden wiersz: dane sprzedawcy na dokumentach i fakturach.
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('street')->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('city')->nullable();
            $table->char('country_code', 2)->default('PL');
            $table->string('nip', 20)->nullable();
            $table->string('vat_prefix', 2)->default('PL');
            $table->string('regon', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('document_footer')->nullable();
            $table->string('issue_place')->nullable();
            $table->unsignedSmallInteger('default_payment_days')->default(14);
            $table->string('default_vat_code', 10)->default('23');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
