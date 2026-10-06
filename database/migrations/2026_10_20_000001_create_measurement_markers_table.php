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
        // Znacznik na rzucie (obraz załączony do protokołu): pozycja w % szerokości/wysokości obrazu.
        // Jeden znacznik może obejmować kilka punktów (np. 1–5 gniazd obok siebie).
        Schema::create('measurement_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('measurement_protocols')->cascadeOnDelete();
            $table->foreignId('attachment_id')->constrained('attachments')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->decimal('x', 6, 3);
            $table->decimal('y', 6, 3);
            $table->timestamps();
        });

        Schema::table('measurement_points', function (Blueprint $table) {
            $table->foreignId('marker_id')->nullable()->after('circuit_id')->constrained('measurement_markers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurement_points', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marker_id');
        });

        Schema::dropIfExists('measurement_markers');
    }
};
