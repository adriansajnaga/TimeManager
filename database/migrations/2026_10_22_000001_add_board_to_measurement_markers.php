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
        // Znacznik rozdzielnicy na rzucie (prostokąt z nazwą) — obok numerowanych punktów.
        Schema::table('measurement_markers', function (Blueprint $table) {
            $table->foreignId('board_id')->nullable()->after('attachment_id')->constrained('measurement_boards')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('measurement_markers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('board_id');
        });
    }
};
