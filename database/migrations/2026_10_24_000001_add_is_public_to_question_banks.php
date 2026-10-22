<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bank soal bisa dibagikan ke guru lain di sekolah yang sama (bawaan: pribadi). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('description');
            $table->index(['school_id', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'is_public']);
            $table->dropColumn('is_public');
        });
    }
};
