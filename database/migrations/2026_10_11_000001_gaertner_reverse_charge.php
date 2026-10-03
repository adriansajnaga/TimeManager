<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Decyzja 16: Gärtner (DE 286771111) fakturowany ze stawką „oo” (odwrotne obciążenie),
     * jak w fakturach z Aplikacji Podatnika KSeF. Import ustawiał „np II”.
     */
    public function up(): void
    {
        DB::table('contractors')
            ->where('tax_id', '286771111')
            ->where('vat_code', 'np II')
            ->update(['vat_code' => 'oo']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('contractors')
            ->where('tax_id', '286771111')
            ->where('vat_code', 'oo')
            ->update(['vat_code' => 'np II']);
    }
};
