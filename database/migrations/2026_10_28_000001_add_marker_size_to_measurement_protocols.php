<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wielkość symboli na rzucie w % szerokości rysunku — ta sama na ekranie i w wydruku protokołu.
     */
    public function up(): void
    {
        Schema::table('measurement_protocols', function (Blueprint $table) {
            $table->decimal('marker_size', 4, 2)->default(2.5);
        });
    }

    public function down(): void
    {
        Schema::table('measurement_protocols', function (Blueprint $table) {
            $table->dropColumn('marker_size');
        });
    }
};
