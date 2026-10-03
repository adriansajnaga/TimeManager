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
        // Dzień kilometrówki wynika z wpisów godzin („licz kilometry”); tu tylko poprawki:
        // km wpisane ręcznie (kilka projektów jednego dnia) i inny opis trasy.
        Schema::create('mileage_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_id')->constrained()->cascadeOnDelete();
            $table->date('trip_date');
            $table->decimal('km', 7, 1)->nullable();
            $table->string('route', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'contractor_id', 'trip_date']);
        });

        // Rozliczenie zamkniętych części tygodni klienta → szkic faktury.
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('hours', 9, 2);
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('km', 9, 1)->default(0);
            $table->decimal('km_rate', 10, 4)->default(0);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('settlement_work_week', function (Blueprint $table) {
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_week_id')->constrained()->cascadeOnDelete();
            $table->primary(['settlement_id', 'work_week_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlement_work_week');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('mileage_days');
    }
};
