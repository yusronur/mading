<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tahap 18: penilaian rubrik kartu, template papan, komentar cepat guru. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('grader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('scores');                 // [skor per kriteria, urut sesuai rubrik]
            $table->decimal('total', 7, 2);
            $table->decimal('max', 7, 2);
            $table->unsignedSmallInteger('grade');   // 0-100
            $table->text('feedback')->nullable();
            $table->timestampsTz();
            $table->index(['school_id', 'board_id', 'student_id']);
        });
        Rls::enable('post_grades');

        Schema::table('boards', function (Blueprint $table) {
            $table->boolean('is_template')->default(false);
            $table->boolean('template_shared')->default(false);
        });

        Schema::table('user_preferences', function (Blueprint $table) {
            $table->jsonb('quick_comments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', fn (Blueprint $table) => $table->dropColumn('quick_comments'));
        Schema::table('boards', fn (Blueprint $table) => $table->dropColumn(['is_template', 'template_shared']));
        Schema::dropIfExists('post_grades');
    }
};
