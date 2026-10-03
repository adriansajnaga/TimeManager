<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('employee')->after('password');
            $table->string('locale', 5)->default('pl')->after('role');
            $table->boolean('is_active')->default(true)->after('locale');
            $table->string('personnel_no', 30)->nullable()->after('is_active');
            $table->unsignedInteger('legacy_id')->nullable()->unique()->after('personnel_no');
        });

        // Konta zakłada administrator, więc istniejący pierwszy użytkownik (np. zarejestrowany
        // przed tą zmianą) zostaje administratorem z potwierdzonym adresem e-mail.
        $first = DB::table('users')->orderBy('id')->first();

        if ($first !== null) {
            DB::table('users')->where('id', $first->id)->update([
                'role' => 'admin',
                'email_verified_at' => $first->email_verified_at ?? now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn(['role', 'locale', 'is_active', 'personnel_no', 'legacy_id']);
        });
    }
};
