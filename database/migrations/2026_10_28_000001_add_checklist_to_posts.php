<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daftar centang di kartu (Tahap 17): [{text, done}]
        Schema::table('posts', function (Blueprint $table) {
            $table->jsonb('checklist')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('checklist');
        });
    }
};
