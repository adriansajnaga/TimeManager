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
        // Przyrządy pomiarowe (miernik, numer seryjny, wzorcowanie; świadectwo jako załącznik).
        Schema::create('measurement_instruments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('serial_number')->nullable();
            $table->date('calibrated_on')->nullable();
            $table->date('calibration_valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Osoby wykonujące pomiary: świadectwa kwalifikacyjne (skany jako załączniki).
        Schema::create('measurement_performers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('certificates')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Protokół z pomiarów elektrycznych (projekt pomiarów): strona tytułowa i parametry sieci.
        Schema::create('measurement_protocols', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('sequence');
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('investor')->nullable();
            $table->string('place');
            $table->string('description')->nullable();
            $table->date('measured_on');
            $table->foreignId('instrument_id')->nullable()->constrained('measurement_instruments')->nullOnDelete();
            $table->string('network', 10)->default('TN-C-S');
            $table->unsignedSmallInteger('phase_voltage')->default(230);
            $table->unsignedSmallInteger('line_voltage')->default(400);
            $table->unsignedTinyInteger('touch_voltage')->default(50);
            $table->decimal('disconnection_time', 3, 1)->default(0.4);
            $table->string('weather')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->text('remarks')->nullable();
            $table->text('verdict')->nullable();
            $table->date('next_test_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['year', 'month', 'sequence']);
        });

        Schema::create('measurement_performer_protocol', function (Blueprint $table) {
            $table->foreignId('protocol_id')->constrained('measurement_protocols')->cascadeOnDelete();
            $table->foreignId('performer_id')->constrained('measurement_performers')->cascadeOnDelete();
            $table->primary(['protocol_id', 'performer_id']);
        });

        // Oględziny: punkty z normą i oceną.
        Schema::create('measurement_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('measurement_protocols')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('section');
            $table->text('item');
            $table->string('standard')->nullable();
            $table->string('result', 20)->default('compliant');
        });

        // Rozdzielnice (i WLZ jako odcinek zasilający).
        Schema::create('measurement_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('measurement_protocols')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 10)->default('board');
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Wyłączniki różnicowoprądowe rozdzielnicy.
        Schema::create('measurement_rcds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('measurement_boards')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('designation');
            $table->string('model')->nullable();
            $table->string('type', 5)->default('A');
            $table->boolean('selective')->default(false);
            $table->decimal('rated_current', 6, 1)->nullable();
            $table->unsignedSmallInteger('rated_residual')->default(30);
            $table->decimal('trip_time', 6, 1)->nullable();
            $table->decimal('trip_current', 6, 1)->nullable();
            $table->decimal('contact_voltage', 6, 2)->nullable();
            $table->boolean('test_button')->default(true);
            $table->timestamps();
        });

        // Obwody: zabezpieczenie, przewód, izolacja (pary żył w JSON).
        Schema::create('measurement_circuits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('measurement_boards')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('number')->nullable();
            $table->string('name');
            $table->unsignedTinyInteger('phases')->default(1);
            $table->string('protection_type', 5)->nullable();
            $table->decimal('protection_current', 6, 1)->nullable();
            $table->decimal('trip_current_override', 8, 1)->nullable();
            $table->string('cable')->nullable();
            $table->foreignId('rcd_id')->nullable()->constrained('measurement_rcds')->nullOnDelete();
            $table->unsignedSmallInteger('insulation_voltage')->default(500);
            $table->json('insulation')->nullable();
            $table->timestamps();
        });

        // Punkty pomiaru impedancji pętli zwarcia (gniazdo, faza, oprawa).
        Schema::create('measurement_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuit_id')->constrained('measurement_circuits')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('symbol')->nullable();
            $table->string('location')->nullable();
            $table->string('loop', 5)->default('L-PE');
            $table->decimal('impedance', 8, 2)->nullable();
            $table->decimal('impedance_npe', 8, 2)->nullable();
            $table->timestamps();
        });

        // Uziemienia: RE × Kp ≤ Ra.
        Schema::create('measurement_earthings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('measurement_protocols')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->string('drawing')->nullable();
            $table->decimal('resistance', 8, 2)->nullable();
            $table->decimal('correction', 4, 2)->default(1);
            $table->decimal('limit', 8, 2)->default(10);
            $table->timestamps();
        });

        // Ciągłość przewodów ochronnych i połączeń wyrównawczych.
        Schema::create('measurement_continuities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('measurement_protocols')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->decimal('resistance', 8, 3)->nullable();
            $table->decimal('limit', 8, 3)->nullable();
            $table->timestamps();
        });

        // Izolacja kabli (WLZ, zasilanie podrozdzielnicy): pary żył w JSON, napięcie probiercze 1000 V.
        Schema::create('measurement_cable_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('measurement_protocols')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->string('cable_type')->nullable();
            $table->string('cross_section')->nullable();
            $table->decimal('length', 7, 1)->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->unsignedSmallInteger('test_voltage')->default(1000);
            $table->json('values')->nullable();
            $table->decimal('limit', 8, 2)->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('measurement_cable_tests');
        Schema::dropIfExists('measurement_continuities');
        Schema::dropIfExists('measurement_earthings');
        Schema::dropIfExists('measurement_points');
        Schema::dropIfExists('measurement_circuits');
        Schema::dropIfExists('measurement_rcds');
        Schema::dropIfExists('measurement_boards');
        Schema::dropIfExists('measurement_inspections');
        Schema::dropIfExists('measurement_performer_protocol');
        Schema::dropIfExists('measurement_protocols');
        Schema::dropIfExists('measurement_performers');
        Schema::dropIfExists('measurement_instruments');
    }
};
