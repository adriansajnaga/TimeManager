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
        // Montageauftrag: opis tygodnia (części tygodnia w miesiącu) dla jednego projektu.
        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_week_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->text('performed_work')->nullable();
            $table->text('remaining_work')->nullable();
            $table->unsignedInteger('legacy_id')->nullable();
            $table->timestamps();

            $table->unique(['work_week_id', 'project_id']);
        });

        Schema::create('material_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_report_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name');
            $table->decimal('quantity', 10, 3)->nullable();
            $table->string('unit', 10)->default('Stk');
            $table->decimal('unit_price_net', 12, 2)->nullable();
            $table->boolean('billable')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_entries');
        Schema::dropIfExists('weekly_reports');
    }
};
