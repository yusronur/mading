<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tahap 11: bank soal pribadi guru (QZ-01, QZ-03, QZ-04, QZ-06). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('subject', 60)->nullable();
            $table->unsignedSmallInteger('grade')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['school_id', 'owner_id']);
        });
        Rls::enable('question_banks');

        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 10); // mc | mcm | tf | short
            $table->text('prompt');
            $table->text('explanation')->nullable();
            // Opsi dan kunci; kunci tidak pernah dikirim ke peramban siswa (QZ-18)
            $table->jsonb('data');
            $table->jsonb('tags')->default('[]');
            $table->unsignedSmallInteger('difficulty')->default(2); // 1 mudah, 2 sedang, 3 sulit
            $table->unsignedSmallInteger('points')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['school_id', 'bank_id', 'position']);
            $table->index(['school_id', 'author_id']);
        });
        Rls::enable('questions');
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_banks');
    }
};
