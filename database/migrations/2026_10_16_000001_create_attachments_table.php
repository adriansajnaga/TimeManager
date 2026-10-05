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
        // Jedna tabela plików dla notatek i kartotek kontrahentów (pliki zostają tam, gdzie leżały).
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('name');
            $table->string('path');
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        if (Schema::hasTable('note_attachments')) {
            DB::table('attachments')->insertUsing(
                ['attachable_type', 'attachable_id', 'name', 'path', 'mime', 'size', 'created_at', 'updated_at'],
                DB::table('note_attachments')->selectRaw('?, note_id, name, path, mime, size, created_at, updated_at', ['App\Models\Note']),
            );

            Schema::drop('note_attachments');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('note_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        DB::table('note_attachments')->insertUsing(
            ['note_id', 'name', 'path', 'mime', 'size', 'created_at', 'updated_at'],
            DB::table('attachments')->where('attachable_type', 'App\Models\Note')->select(['attachable_id', 'name', 'path', 'mime', 'size', 'created_at', 'updated_at']),
        );

        Schema::dropIfExists('attachments');
    }
};
