<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tahap 12: kuis mandiri, penugasan, percobaan, jawaban (QZ-08, 09, 14, 15, 18). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('description', 1000)->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['school_id', 'owner_id', 'updated_at']);
        });
        Rls::enable('quizzes');

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedSmallInteger('points')->nullable(); // null = poin bawaan soal
            $table->timestampsTz();
            $table->unique(['quiz_id', 'question_id']);
            $table->index(['school_id', 'quiz_id', 'position']);
        });
        Rls::enable('quiz_questions');

        // Penugasan kuis ke satu rombel; soal dan pengaturan disalin (snapshot)
        Schema::create('quiz_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('host_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('mode', 10)->default('self'); // self | live (Fase 2)
            $table->char('code', 6);
            $table->string('title', 150);
            $table->timestampTz('opens_at')->nullable();
            $table->timestampTz('closes_at')->nullable();
            $table->timestampTz('closed_at')->nullable(); // ditutup manual oleh guru
            $table->jsonb('settings');
            $table->jsonb('questions');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'class_id', 'closes_at']);
            $table->index(['school_id', 'quiz_id']);
        });
        Rls::enable('quiz_sessions');

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('quiz_sessions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->unsignedInteger('seed');
            $table->jsonb('question_order');
            $table->timestampTz('started_at');
            $table->timestampTz('deadline_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->decimal('score', 8, 2)->default(0);
            $table->unsignedInteger('max_score')->default(0);
            $table->unsignedInteger('focus_lost')->default(0);
            $table->timestampsTz();
            $table->unique(['session_id', 'user_id', 'attempt_no']);
            $table->index(['school_id', 'session_id', 'user_id']);
        });
        Rls::enable('quiz_attempts');

        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->uuid('question_id'); // id soal di snapshot
            $table->jsonb('response')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->decimal('points', 8, 2)->default(0);
            $table->timestampTz('answered_at');
            $table->unique(['attempt_id', 'question_id']);
            $table->index(['school_id', 'question_id']);
        });
        Rls::enable('quiz_answers');
    }

    public function down(): void
    {
        foreach (['quiz_answers', 'quiz_attempts', 'quiz_sessions', 'quiz_questions', 'quizzes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
