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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('name');
            $table->string('invoice_label')->nullable();

            // Miejsce realizacji (klient końcowy / inwestor)
            $table->string('site_name')->nullable();
            $table->string('site_street')->nullable();
            $table->string('site_zip', 20)->nullable();
            $table->string('site_city')->nullable();
            $table->char('site_country', 2)->nullable();

            $table->string('billing_type', 10)->default('hourly');
            $table->decimal('km_one_way', 6, 1)->nullable();
            $table->boolean('mileage_default')->default(false);
            $table->string('status', 10)->default('active');
            $table->decimal('contract_value', 14, 2)->nullable();
            $table->char('contract_currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['contractor_id', 'number']);
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
    }
};
